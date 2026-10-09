<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * The delivery log: one row per message, naming who it went to and what
 * happened to it — sent, delivered, read, failed (and why), replied.
 *
 * This is the report an operator opens after a broadcast to answer the only
 * question that really matters: "who got it, and did they read it?" Aggregates
 * live in MessageStats; this class is deliberately row-level, because a 62%
 * read rate is not actionable until you can see the 38%.
 */
final class MessageReportQuery
{
    public const DEFAULT_PER_PAGE = 50;
    public const MAX_PER_PAGE     = 200;

    /** Ceiling on a single synchronous CSV export, to keep memory bounded. */
    public const MAX_EXPORT_ROWS = 20000;

    /** How much of the message body travels with a log row. */
    private const BODY_PREVIEW = 180;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * One page of the delivery log.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function page(int $tenantId, array $filters, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page    = max(1, $page);

        $total = $this->count($tenantId, $filters);
        $pages = (int) ceil($total / $perPage);

        // Asking for page 9 of a 3-page result should show page 3's rows, not an
        // empty table — the filters change under the operator as sends land.
        if ($pages > 0 && $page > $pages) {
            $page = $pages;
        }

        $rows = $this->select($tenantId, $filters)
            ->orderBy('m.created_at', 'DESC')
            ->orderBy('m.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return [
            'rows'     => array_map([$this, 'shape'], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $pages,
        ];
    }

    /**
     * Every matching row, oldest-first, for CSV export.
     *
     * Oldest-first because an exported log is read as a send history, and capped
     * because this runs synchronously inside the request.
     *
     * @return list<array<string, mixed>>
     */
    public function exportRows(int $tenantId, array $filters, int $limit = self::MAX_EXPORT_ROWS): array
    {
        $limit = max(1, min(self::MAX_EXPORT_ROWS, $limit));

        $rows = $this->select($tenantId, $filters)
            ->orderBy('m.created_at', 'ASC')
            ->orderBy('m.id', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        return array_map([$this, 'shape'], $rows);
    }

    /** How many messages match, ignoring pagination. */
    public function count(int $tenantId, array $filters): int
    {
        $b = $this->joined();
        MessageFilters::apply($b, $tenantId, $filters);

        return $b->countAllResults();
    }

    // ------------------------------------------------------------------
    // Query construction
    // ------------------------------------------------------------------

    private function select(int $tenantId, array $filters): BaseBuilder
    {
        $b = $this->joined();

        $b->select([
            'm.id', 'm.wa_message_id', 'm.contact_id', 'm.conversation_id',
            'm.campaign_id', 'm.variant', 'm.direction', 'm.type', 'm.category',
            'm.status', 'm.billable', 'm.error', 'm.body',
            'm.created_at', 'm.sent_at', 'm.delivered_at', 'm.read_at',
            'ct.name AS contact_name',
            'cp.name AS campaign_name',
            'tp.name AS template_name',
        ]);

        // The number is the identity of a WhatsApp recipient, and it survives on
        // the conversation even when the contact row was deleted — so a log of a
        // send never degrades to an anonymous row.
        $b->select('COALESCE(ct.wa_number, cv.wa_number) AS wa_number', false);
        $b->select(ReplyAttribution::firstReplySql('m', $this->db) . ' AS replied_at', false);

        MessageFilters::apply($b, $tenantId, $filters);

        return $b;
    }

    private function joined(): BaseBuilder
    {
        return $this->db->table('messages m')
            ->join('contacts ct',      'ct.id = m.contact_id',      'left')
            ->join('conversations cv', 'cv.id = m.conversation_id', 'left')
            ->join('campaigns cp',     'cp.id = m.campaign_id',     'left')
            ->join('templates tp',     'tp.id = cp.template_id',    'left');
    }

    /**
     * Normalise a raw DB row into the report's wire shape.
     *
     * @param  array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function shape(array $r): array
    {
        $body = (string) ($r['body'] ?? '');

        return [
            'id'             => (int) $r['id'],
            'wa_message_id'  => $r['wa_message_id'] ?? null,
            'contact_id'     => $r['contact_id'] !== null ? (int) $r['contact_id'] : null,
            'contact_name'   => $r['contact_name'] ?? null,
            'wa_number'      => $r['wa_number'] ?? null,
            'campaign_id'    => $r['campaign_id'] !== null ? (int) $r['campaign_id'] : null,
            'campaign_name'  => $r['campaign_name'] ?? null,
            'template_name'  => $r['template_name'] ?? null,
            'variant'        => $r['variant'] ?? null,
            'direction'      => $r['direction'],
            'type'           => $r['type'],
            'category'       => $r['category'],
            'status'         => $r['status'],
            'billable'       => (int) ($r['billable'] ?? 0) === 1,
            'error'          => $r['error'] ?? null,
            'preview'        => self::preview($body),
            'created_at'     => $r['created_at'],
            'sent_at'        => $r['sent_at'],
            'delivered_at'   => $r['delivered_at'],
            'read_at'        => $r['read_at'],
            'replied_at'     => $r['replied_at'] ?? null,
        ];
    }

    private static function preview(string $body): string
    {
        $flat = trim(preg_replace('/\s+/u', ' ', $body) ?? '');

        return mb_strlen($flat) > self::BODY_PREVIEW
            ? mb_substr($flat, 0, self::BODY_PREVIEW - 1) . '…'
            : $flat;
    }
}
