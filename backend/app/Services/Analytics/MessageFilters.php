<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use CodeIgniter\Database\BaseBuilder;

/**
 * The single filter vocabulary every message-level report speaks.
 *
 * The delivery log, the stats aggregates and the CSV export must agree on what
 * "this slice of messages" means — otherwise the number on the funnel and the
 * number of rows you can export disagree, which is exactly the bug that makes a
 * report untrustworthy. So the query string is normalised once here and applied
 * once here; no report builds its own WHERE clause.
 *
 * Timezones: message timestamps are stored in UTC, but the operator picks
 * calendar days in their own zone ("what went out yesterday"). normalize()
 * takes tz_offset (minutes east of UTC, i.e. JS -getTimezoneOffset()) and
 * converts the local day boundaries into the UTC instants to compare against,
 * so the SQL itself stays dialect- and zone-free.
 */
final class MessageFilters
{
    public const STATUSES   = ['queued', 'sent', 'delivered', 'read', 'failed'];
    public const CATEGORIES = ['marketing', 'utility', 'authentication', 'service', 'free_form'];
    public const TYPES      = ['text', 'template', 'image', 'document', 'audio', 'video', 'interactive'];
    public const DIRECTIONS = ['in', 'out'];

    /** Widest range a single report may span, in days. Keeps one query bounded. */
    public const MAX_RANGE_DAYS = 400;

    /**
     * Normalise raw query-string input into a trusted filter array.
     *
     * Everything not recognised is dropped rather than passed through, so a
     * hand-crafted query string can never widen the slice past the tenant scope
     * or inject an expression into the builder.
     *
     * @param  array<string, mixed> $input Raw $_GET-shaped input.
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        $out = [];

        $dir = self::str($input['direction'] ?? null);
        // Outbound is the default: every "did it arrive / was it read" question
        // is about messages we sent. 'all' opts into both directions.
        $out['direction'] = in_array($dir, self::DIRECTIONS, true) ? $dir : ($dir === 'all' ? null : 'out');

        $statuses = self::list($input['status'] ?? null, self::STATUSES);
        if ($statuses !== []) {
            $out['statuses'] = $statuses;
        }

        $categories = self::list($input['category'] ?? null, self::CATEGORIES);
        if ($categories !== []) {
            $out['categories'] = $categories;
        }

        $types = self::list($input['type'] ?? null, self::TYPES);
        if ($types !== []) {
            $out['types'] = $types;
        }

        // campaign_id=none isolates everything NOT sent by a broadcast (flow
        // sends, inbox replies) — the slice operators reach for when a campaign
        // report and the tenant totals disagree.
        $campaign = self::str($input['campaign_id'] ?? null);
        if ($campaign === 'none') {
            $out['campaign_id'] = 'none';
        } elseif ($campaign !== null && ctype_digit($campaign)) {
            $out['campaign_id'] = (int) $campaign;
        }

        $q = self::str($input['q'] ?? null);
        if ($q !== null && $q !== '') {
            $out['q'] = mb_substr($q, 0, 100);
        }

        $replied = self::str($input['replied'] ?? null);
        if ($replied === 'yes' || $replied === 'no') {
            $out['replied'] = $replied;
        }

        $offset = (int) ($input['tz_offset'] ?? 0);
        // Guard against a nonsense offset turning day boundaries into garbage.
        $offset = max(-840, min(840, $offset));
        $out['tz_offset'] = $offset;

        [$from, $to] = self::range(
            self::str($input['from'] ?? null),
            self::str($input['to'] ?? null)
        );

        if ($from !== null) {
            $out['from']    = $from;
            $out['from_at'] = self::localDayStartToUtc($from, $offset);
        }
        if ($to !== null) {
            $out['to']    = $to;
            $out['to_at'] = self::localDayEndToUtc($to, $offset);
        }

        return $out;
    }

    /**
     * Apply a normalised filter array to a builder over `messages`.
     *
     * @param string $a Table alias used for messages in the builder.
     * @param string $c Table alias used for the joined contacts row (for `q`).
     * @param string $v Table alias used for the joined conversations row (for `q`).
     */
    public static function apply(
        BaseBuilder $b,
        int $tenantId,
        array $f,
        string $a = 'm',
        string $c = 'ct',
        string $v = 'cv'
    ): BaseBuilder {
        $b->where("{$a}.tenant_id", $tenantId);

        if (! empty($f['direction'])) {
            $b->where("{$a}.direction", $f['direction']);
        }
        if (! empty($f['statuses'])) {
            $b->whereIn("{$a}.status", $f['statuses']);
        }
        if (! empty($f['categories'])) {
            $b->whereIn("{$a}.category", $f['categories']);
        }
        if (! empty($f['types'])) {
            $b->whereIn("{$a}.type", $f['types']);
        }
        if (isset($f['campaign_id'])) {
            if ($f['campaign_id'] === 'none') {
                $b->where("{$a}.campaign_id IS NULL", null, false);
            } else {
                $b->where("{$a}.campaign_id", (int) $f['campaign_id']);
            }
        }
        if (! empty($f['from_at'])) {
            $b->where("{$a}.created_at >=", $f['from_at']);
        }
        if (! empty($f['to_at'])) {
            $b->where("{$a}.created_at <=", $f['to_at']);
        }

        if (! empty($f['q'])) {
            $like = $f['q'];
            $b->groupStart()
              ->like("{$c}.name", $like)
              ->orLike("{$c}.wa_number", $like)
              ->orLike("{$v}.wa_number", $like)
              ->orLike("{$a}.body", $like)
              ->groupEnd();
        }

        if (isset($f['replied'])) {
            $exists = ReplyAttribution::existsSql($a);
            $b->where($f['replied'] === 'yes' ? $exists : "NOT {$exists}", null, false);
        }

        return $b;
    }

    /** A short human label for the active range, used in export filenames. */
    public static function rangeLabel(array $f): string
    {
        $from = $f['from'] ?? null;
        $to   = $f['to']   ?? null;

        if ($from !== null && $to !== null) {
            return $from === $to ? $from : "{$from}_to_{$to}";
        }

        return $from ?? $to ?? 'all-time';
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private static function str(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : $v;
    }

    /**
     * Parse a comma-separated allow-listed set ("sent,read").
     *
     * @param  list<string> $allowed
     * @return list<string>
     */
    private static function list(mixed $raw, array $allowed): array
    {
        $s = self::str($raw);
        if ($s === null) {
            return [];
        }

        $parts = array_map('trim', explode(',', $s));

        return array_values(array_unique(array_filter(
            $parts,
            static fn (string $p): bool => in_array($p, $allowed, true)
        )));
    }

    /**
     * Validate a YYYY-MM-DD pair, ordering it and clamping the span.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function range(?string $from, ?string $to): array
    {
        $from = self::date($from);
        $to   = self::date($to);

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        if ($from !== null && $to !== null) {
            $days = (int) ((strtotime($to) - strtotime($from)) / 86400);
            if ($days > self::MAX_RANGE_DAYS) {
                $from = date('Y-m-d', strtotime($to) - self::MAX_RANGE_DAYS * 86400);
            }
        }

        return [$from, $to];
    }

    private static function date(?string $v): ?string
    {
        if ($v === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
            return null;
        }

        return checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))
            ? $v
            : null;
    }

    /** Local midnight on $day, expressed as the UTC instant to compare against. */
    public static function localDayStartToUtc(string $day, int $tzOffsetMinutes): string
    {
        return date('Y-m-d H:i:s', strtotime($day . ' 00:00:00 UTC') - $tzOffsetMinutes * 60);
    }

    /** The last second of local $day, expressed as a UTC instant. */
    public static function localDayEndToUtc(string $day, int $tzOffsetMinutes): string
    {
        return date('Y-m-d H:i:s', strtotime($day . ' 23:59:59 UTC') - $tzOffsetMinutes * 60);
    }
}
