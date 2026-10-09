<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Aggregates over the same message slice MessageReportQuery lists row by row.
 *
 * Both read through MessageFilters, so the funnel on screen and the rows behind
 * it can never describe different sets of messages.
 *
 * Status is a ladder, not a set: WhatsApp only reports the furthest rung each
 * message reached, so a message sitting at 'read' was also delivered and also
 * sent. Every count here rolls the ladder up accordingly — reading the raw
 * status column as if the buckets were exclusive is what makes a delivery rate
 * come out at 3%.
 */
final class MessageStats
{
    /** Distinct failure reasons surfaced before the tail is grouped as "other". */
    private const MAX_FAILURE_REASONS = 12;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * Headline delivery funnel for the slice.
     *
     * @return array<string, mixed>
     */
    public function funnel(int $tenantId, array $filters): array
    {
        $rows = $this->scoped($tenantId, $filters)
            ->select('m.status AS status')
            ->select('COUNT(*) AS c', false)
            ->select('SUM(CASE WHEN m.billable = 1 THEN 1 ELSE 0 END) AS billable', false)
            ->groupBy('m.status')
            ->get()
            ->getResultArray();

        $byStatus = [];
        $billable = 0;
        $total    = 0;
        foreach ($rows as $r) {
            $byStatus[(string) $r['status']] = (int) $r['c'];
            $billable += (int) $r['billable'];
            $total    += (int) $r['c'];
        }

        $queued    = $byStatus['queued'] ?? 0;
        $failed    = $byStatus['failed'] ?? 0;
        $read      = $byStatus['read'] ?? 0;
        $delivered = $read + ($byStatus['delivered'] ?? 0);
        $sent      = $delivered + ($byStatus['sent'] ?? 0);

        // Distinct conversations, not distinct contacts: a conversation is 1:1
        // with a WhatsApp number, while contact_id is nullable — counting
        // contacts silently drops every message whose contact row was deleted
        // or never linked, and reports "1 recipient" for a batch of hundreds.
        $recipients = (int) ($this->scoped($tenantId, $filters)
            ->select('COUNT(DISTINCT m.conversation_id) AS c', false)
            ->get()->getRowArray()['c'] ?? 0);

        $repliedBuilder = $this->scoped($tenantId, $filters)
            ->select('COUNT(DISTINCT m.conversation_id) AS c', false)
            ->where(ReplyAttribution::existsSql('m', $this->db), null, false);
        $replied = (int) ($repliedBuilder->get()->getRowArray()['c'] ?? 0);

        $clicks = $this->clicks($tenantId, $filters);

        return [
            'total'           => $total,
            'recipients'      => $recipients,
            'queued'          => $queued,
            'sent'            => $sent,
            'delivered'       => $delivered,
            'read'            => $read,
            'failed'          => $failed,
            'replied'         => $replied,
            'clicks'          => $clicks['total'],
            'unique_clickers' => $clicks['unique'],
            'billable'        => $billable,
            // Rates are all "of what actually left the system", so a batch still
            // queued or rejected outright never flatters the delivery rate.
            'delivery_rate'   => self::rate($delivered, $sent),
            'read_rate'       => self::rate($read, $sent),
            'reply_rate'      => self::rate($replied, $sent),
            'click_rate'      => self::rate($clicks['unique'], $delivered),
            'failure_rate'    => self::rate($failed, $total),
            'reply_window_hours' => ReplyAttribution::WINDOW_HOURS,
        ];
    }

    /**
     * Daily sent / delivered / read / failed / replied counts, in the operator's
     * own timezone, with empty days filled in so the chart has no false gaps.
     *
     * @return list<array<string, int|string>>
     */
    public function timeseries(int $tenantId, array $filters): array
    {
        $dateExpr = $this->localDateExpr('m.created_at', (int) ($filters['tz_offset'] ?? 0));

        $rows = $this->scoped($tenantId, $filters)
            ->select("{$dateExpr} AS d", false)
            ->select('m.status AS status')
            ->select('COUNT(*) AS c', false)
            ->groupBy(['d', 'm.status'])
            ->get()
            ->getResultArray();

        $buckets = [];
        foreach ($rows as $r) {
            $d = (string) $r['d'];
            $buckets[$d] ??= self::emptyDay($d);
            $n = (int) $r['c'];

            // Roll the ladder up: a read message was delivered and sent too.
            switch ((string) $r['status']) {
                case 'read':
                    $buckets[$d]['read']      += $n;
                    $buckets[$d]['delivered'] += $n;
                    $buckets[$d]['sent']      += $n;
                    break;
                case 'delivered':
                    $buckets[$d]['delivered'] += $n;
                    $buckets[$d]['sent']      += $n;
                    break;
                case 'sent':
                    $buckets[$d]['sent'] += $n;
                    break;
                case 'failed':
                    $buckets[$d]['failed'] += $n;
                    break;
                default:
                    $buckets[$d]['queued'] += $n;
            }
        }

        $repliedRows = $this->scoped($tenantId, $filters)
            ->select("{$dateExpr} AS d", false)
            ->select('COUNT(DISTINCT m.conversation_id) AS c', false)
            ->where(ReplyAttribution::existsSql('m', $this->db), null, false)
            ->groupBy('d')
            ->get()
            ->getResultArray();

        foreach ($repliedRows as $r) {
            $d = (string) $r['d'];
            $buckets[$d] ??= self::emptyDay($d);
            $buckets[$d]['replied'] = (int) $r['c'];
        }

        return $this->fillGaps($buckets, $filters);
    }

    /**
     * Spend by conversation category — the breakdown that explains the Meta bill.
     *
     * @return list<array<string, mixed>>
     */
    public function categoryBreakdown(int $tenantId, array $filters): array
    {
        $rows = $this->scoped($tenantId, $filters)
            ->select('m.category AS category')
            ->select('COUNT(*) AS c', false)
            ->select('SUM(CASE WHEN m.billable = 1 THEN 1 ELSE 0 END) AS billable', false)
            ->groupBy('m.category')
            ->orderBy('c', 'DESC')
            ->get()
            ->getResultArray();

        return array_map(static function (array $r): array {
            // A NULL category is a pre-Sprint-3 row; it is priced as marketing
            // by CostEstimator, so label it honestly rather than as free.
            $category = $r['category'] !== null && $r['category'] !== ''
                ? (string) $r['category']
                : 'uncategorised';
            $billable = (int) $r['billable'];

            return [
                'category'        => $category,
                'messages'        => (int) $r['c'],
                'billable'        => $billable,
                'rate_paise'      => CostEstimator::ratePaise($r['category'] ?: null),
                'est_cost_paise'  => CostEstimator::estimate([($r['category'] ?: 'marketing') => $billable]),
            ];
        }, $rows);
    }

    /**
     * Why sends failed, most common first.
     *
     * Meta's failure text carries the actionable part (#131049 frequency
     * capping, #131047 re-engagement required, #132000 parameter mismatch), so
     * the numeric code is parsed out for the UI to explain.
     *
     * @return list<array<string, mixed>>
     */
    public function failureBreakdown(int $tenantId, array $filters): array
    {
        $filters['statuses'] = ['failed'];

        $rows = $this->scoped($tenantId, $filters)
            ->select("COALESCE(m.error, 'Unknown error') AS reason", false)
            ->select('COUNT(*) AS c', false)
            ->groupBy('reason')
            ->orderBy('c', 'DESC')
            ->get()
            ->getResultArray();

        $out  = [];
        $tail = 0;
        foreach ($rows as $i => $r) {
            if ($i >= self::MAX_FAILURE_REASONS) {
                $tail += (int) $r['c'];
                continue;
            }
            $reason = (string) $r['reason'];
            preg_match('/#(\d+)/', $reason, $m);
            $out[] = [
                'reason' => $reason,
                'code'   => $m[1] ?? null,
                'count'  => (int) $r['c'],
            ];
        }

        if ($tail > 0) {
            $out[] = ['reason' => 'Other reasons', 'code' => null, 'count' => $tail];
        }

        return $out;
    }

    /**
     * Per-template broadcast performance.
     *
     * Only campaign sends can be attributed to a template: a message row records
     * the campaign that produced it, and the template hangs off the campaign.
     * Flow and inbox sends are therefore out of scope here by construction.
     *
     * @return list<array<string, mixed>>
     */
    public function templatePerformance(int $tenantId, array $filters): array
    {
        $rows = $this->scoped($tenantId, $filters)
            ->select('tp.id AS template_id')
            ->select('tp.name AS template_name')
            ->select('tp.category AS template_category')
            ->select('COUNT(*) AS total', false)
            ->select("SUM(CASE WHEN m.status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent", false)
            ->select("SUM(CASE WHEN m.status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered", false)
            ->select("SUM(CASE WHEN m.status = 'read' THEN 1 ELSE 0 END) AS `read`", false)
            ->select("SUM(CASE WHEN m.status = 'failed' THEN 1 ELSE 0 END) AS failed", false)
            ->where('m.campaign_id IS NOT NULL', null, false)
            ->where('tp.id IS NOT NULL', null, false)
            ->groupBy(['tp.id', 'tp.name', 'tp.category'])
            ->orderBy('total', 'DESC')
            ->limit(25)
            ->get()
            ->getResultArray();

        return array_map(static function (array $r): array {
            $sent = (int) $r['sent'];

            return [
                'template_id'   => (int) $r['template_id'],
                'template_name' => (string) $r['template_name'],
                'category'      => $r['template_category'],
                'total'         => (int) $r['total'],
                'sent'          => $sent,
                'delivered'     => (int) $r['delivered'],
                'read'          => (int) $r['read'],
                'failed'        => (int) $r['failed'],
                'delivery_rate' => self::rate((int) $r['delivered'], $sent),
                'read_rate'     => self::rate((int) $r['read'], $sent),
            ];
        }, $rows);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Clicks recorded against the messages in this slice.
     *
     * @return array{total: int, unique: int}
     */
    private function clicks(int $tenantId, array $filters): array
    {
        if (! $this->db->tableExists('click_events')) {
            return ['total' => 0, 'unique' => 0];
        }

        $b = $this->scoped($tenantId, $filters)
            ->join('click_events ce', 'ce.message_id = m.id', 'inner')
            ->select('COUNT(ce.id) AS total', false)
            ->select('COUNT(DISTINCT ce.contact_id) AS uniq', false);

        $row = $b->get()->getRowArray();

        return [
            'total'  => (int) ($row['total'] ?? 0),
            'unique' => (int) ($row['uniq'] ?? 0),
        ];
    }

    /** A builder over `messages` joined for filtering, already tenant-scoped. */
    private function scoped(int $tenantId, array $filters): BaseBuilder
    {
        $b = $this->db->table('messages m')
            ->join('contacts ct',      'ct.id = m.contact_id',      'left')
            ->join('conversations cv', 'cv.id = m.conversation_id', 'left')
            ->join('campaigns cp',     'cp.id = m.campaign_id',     'left')
            ->join('templates tp',     'tp.id = cp.template_id',    'left');

        MessageFilters::apply($b, $tenantId, $filters);

        return $b;
    }

    /**
     * Group a UTC timestamp by calendar day in the operator's timezone.
     *
     * The suite runs on SQLite and production on MySQL, which spell date
     * arithmetic differently (same dialect split as Crm\ReportService).
     */
    private function localDateExpr(string $column, int $tzOffsetMinutes): string
    {
        if ($tzOffsetMinutes === 0) {
            return $this->db->DBDriver === 'SQLite3' ? "date({$column})" : "DATE({$column})";
        }

        $signed = ($tzOffsetMinutes > 0 ? '+' : '') . $tzOffsetMinutes;

        return $this->db->DBDriver === 'SQLite3'
            ? "date({$column}, '{$signed} minutes')"
            : "DATE(DATE_ADD({$column}, INTERVAL {$signed} MINUTE))";
    }

    /** @return array<string, int|string> */
    private static function emptyDay(string $date): array
    {
        return [
            'date' => $date, 'sent' => 0, 'delivered' => 0, 'read' => 0,
            'failed' => 0, 'queued' => 0, 'replied' => 0,
        ];
    }

    /**
     * Turn the sparse bucket map into a dense, ordered series.
     *
     * A day with no sends is a real, meaningful zero on a trend line; leaving it
     * out silently redraws a two-week gap as a straight line between two points.
     *
     * @param  array<string, array<string, int|string>> $buckets
     * @return list<array<string, int|string>>
     */
    private function fillGaps(array $buckets, array $filters): array
    {
        $from = $filters['from'] ?? (empty($buckets) ? null : min(array_keys($buckets)));
        $to   = $filters['to']   ?? (empty($buckets) ? null : max(array_keys($buckets)));

        if ($from === null || $to === null) {
            ksort($buckets);

            return array_values($buckets);
        }

        $out = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
            $d     = date('Y-m-d', $t);
            $out[] = $buckets[$d] ?? self::emptyDay($d);
        }

        return $out;
    }

    /** Integer percentage, guarding the empty-slice divide. */
    public static function rate(int $part, int $whole): int
    {
        return $whole > 0 ? (int) round(($part / $whole) * 100) : 0;
    }
}
