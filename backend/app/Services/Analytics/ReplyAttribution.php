<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use CodeIgniter\Database\BaseConnection;

/**
 * "Did they write back?" — expressed once, as SQL, so every report agrees.
 *
 * A contact counts as having replied to an outbound message when they send an
 * inbound message AFTER it, within WINDOW_HOURS. The horizon matters: without
 * one, an old campaign keeps accruing "replies" forever from conversations that
 * have nothing to do with it, and the read/reply funnel drifts upward for
 * months. 24 hours is the product's own unit — it is exactly the WhatsApp
 * customer-service window a reply opens (CLAUDE.md §1), so a reply inside it is
 * also the reply that makes free-form follow-up possible.
 *
 * Attribution is per contact, not per message: WhatsApp gives no reply-to link
 * for a plain text answer, so "replied within 24h of this send" is the strongest
 * claim the data supports. The UI labels it as such.
 *
 * A message that failed or is still queued never reached anyone, so it can have
 * no reply — attributing one to it puts a "Replied" badge next to a send the
 * recipient never saw.
 */
final class ReplyAttribution
{
    public const WINDOW_HOURS = 24;

    /**
     * A correlated EXISTS predicate — for filtering rows to replied / not replied.
     *
     * @param string $a Alias of the outbound `messages` row in the outer query.
     */
    public static function existsSql(string $a = 'm', ?BaseConnection $db = null): string
    {
        $t = self::table($db);

        return "EXISTS (SELECT 1 FROM {$t} r"
            . " WHERE {$a}.status IN ('sent', 'delivered', 'read')"
            . " AND r.tenant_id = {$a}.tenant_id"
            . " AND r.contact_id = {$a}.contact_id"
            . " AND r.direction = 'in'"
            . " AND r.created_at > {$a}.created_at"
            . ' AND r.created_at <= ' . self::windowEnd($a, $db)
            . ')';
    }

    /**
     * A correlated scalar subquery yielding the first reply timestamp, or NULL.
     * Selected per row so the log can show WHEN they answered, not just whether.
     */
    public static function firstReplySql(string $a = 'm', ?BaseConnection $db = null): string
    {
        $t = self::table($db);

        return "(SELECT MIN(r.created_at) FROM {$t} r"
            . " WHERE {$a}.status IN ('sent', 'delivered', 'read')"
            . " AND r.tenant_id = {$a}.tenant_id"
            . " AND r.contact_id = {$a}.contact_id"
            . " AND r.direction = 'in'"
            . " AND r.created_at > {$a}.created_at"
            . ' AND r.created_at <= ' . self::windowEnd($a, $db)
            . ')';
    }

    /**
     * The end of the attribution window for a given outbound row.
     *
     * MySQL and SQLite spell date arithmetic differently and the test suite runs
     * on SQLite, so the dialect is resolved from the live connection rather than
     * assumed (same approach as Crm\ReportService).
     */
    private static function windowEnd(string $a, ?BaseConnection $db = null): string
    {
        $db ??= db_connect();

        return $db->DBDriver === 'SQLite3'
            ? "datetime({$a}.created_at, '+" . self::WINDOW_HOURS . " hours')"
            : "DATE_ADD({$a}.created_at, INTERVAL " . self::WINDOW_HOURS . ' HOUR)';
    }

    private static function table(?BaseConnection $db = null): string
    {
        $db ??= db_connect();

        return $db->DBPrefix . 'messages';
    }
}
