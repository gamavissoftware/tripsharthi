<?php

declare(strict_types=1);

namespace App\Services\Email\Marketing;

/**
 * Campaign and account-level email metrics, all counted from the recipient
 * rows in `emails` so the funnel and the recipient list cannot disagree.
 * Rates are over messages that actually left (sent).
 */
final class EmailCampaignReport
{
    public const RECIPIENT_FILTERS = ['all', 'sent', 'failed', 'opened', 'clicked', 'replied', 'unsubscribed', 'not_opened'];

    /** @param list<int>|null $campaignIds null = every campaign of the tenant */
    public function funnel(int $tenantId, ?array $campaignIds = null, ?string $since = null): array
    {
        $b = db_connect()->table('emails')
            ->select("COUNT(*) AS recipients,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
                SUM(CASE WHEN unsubscribed_at IS NOT NULL THEN 1 ELSE 0 END) AS unsubscribed,
                SUM(CASE WHEN replied_at IS NOT NULL THEN 1 ELSE 0 END) AS replied")
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->where('email_campaign_id IS NOT NULL');

        if ($campaignIds !== null) {
            $b->whereIn('email_campaign_id', $campaignIds !== [] ? $campaignIds : [0]);
        }
        if ($since !== null) {
            $b->where('created_at >=', $since);
        }

        $r = $b->get()->getRowArray() ?: [];
        $n = static fn (string $k) => (int) ($r[$k] ?? 0);

        $sent = $n('sent');
        $rate = static fn (int $x) => $sent > 0 ? round($x / $sent * 100, 1) : 0.0;

        return [
            'recipients'       => $n('recipients'),
            'sent'             => $sent,
            'failed'           => $n('failed'),
            'queued'           => $n('queued'),
            'opened'           => $n('opened'),
            'clicked'          => $n('clicked'),
            'unsubscribed'     => $n('unsubscribed'),
            'replied'          => $n('replied'),
            'reply_rate'       => $rate($n('replied')),
            'open_rate'        => $rate($n('opened')),
            'click_rate'       => $rate($n('clicked')),
            'unsubscribe_rate' => $rate($n('unsubscribed')),
        ];
    }

    /** @param list<int> $campaignIds @return array<int,array> campaign id => funnel */
    public function funnelByCampaign(int $tenantId, array $campaignIds): array
    {
        $out = [];
        foreach ($campaignIds as $id) {
            $out[$id] = $this->funnel($tenantId, [$id]);
        }

        return $out;
    }

    /** @return list<array{url:string, clicks:int, unique_clicks:int}> */
    public function topLinks(int $tenantId, int $campaignId, int $limit = 20): array
    {
        $rows = db_connect()->table('email_clicks')
            ->select('url, COUNT(*) AS clicks, COUNT(DISTINCT email_id) AS unique_clicks')
            ->where('tenant_id', $tenantId)
            ->where('email_campaign_id', $campaignId)
            ->groupBy('url')
            ->orderBy('clicks', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        return array_map(static fn ($r) => [
            'url' => (string) $r['url'], 'clicks' => (int) $r['clicks'], 'unique_clicks' => (int) $r['unique_clicks'],
        ], $rows);
    }

    /** @return array{rows:list<array>, total:int, page:int, per_page:int} */
    public function recipients(int $tenantId, int $campaignId, string $filter = 'all', int $page = 1, int $perPage = 50, string $search = ''): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $build   = $this->recipientQuery($tenantId, $campaignId, $filter, $search);

        $total = $build()->countAllResults();
        $rows  = $build()
            ->select(self::RECIPIENT_COLUMNS)
            ->orderBy('e.id', 'ASC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** The recipient log as CSV — same filter vocabulary as the on-screen list. */
    public function recipientsCsv(int $tenantId, int $campaignId, string $filter = 'all', string $search = ''): string
    {
        $rows = ($this->recipientQuery($tenantId, $campaignId, $filter, $search))()
            ->select(self::RECIPIENT_COLUMNS)
            ->orderBy('e.id', 'ASC')
            ->limit(50000)
            ->get()->getResultArray();

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Name', 'Email', 'Status', 'Sent at (UTC)', 'Opened', 'First opened (UTC)', 'Opens',
                       'Clicked', 'First clicked (UTC)', 'Clicks', 'Replied (UTC)', 'Unsubscribed (UTC)', 'Error']);
        foreach ($rows as $r) {
            fputcsv($out, [
                // A leading = + - @ would be run as a formula by Excel.
                self::cell((string) ($r['contact_name'] ?? '')),
                self::cell((string) $r['to_email']),
                $r['status'],
                $r['sent_at'],
                $r['opened_at'] ? 'Yes' : 'No',
                $r['opened_at'],
                (int) $r['open_count'],
                $r['clicked_at'] ? 'Yes' : 'No',
                $r['clicked_at'],
                (int) $r['click_count'],
                $r['replied_at'],
                $r['unsubscribed_at'],
                self::cell((string) ($r['error'] ?? '')),
            ]);
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    private const RECIPIENT_COLUMNS = 'e.id, e.contact_id, c.name AS contact_name, e.to_email, e.status, e.error, e.sent_at,
                      e.opened_at, e.open_count, e.clicked_at, e.click_count, e.unsubscribed_at, e.replied_at';

    private static function cell(string $v): string
    {
        return $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) ? "'" . $v : $v;
    }

    private function recipientQuery(int $tenantId, int $campaignId, string $filter, string $search): \Closure
    {
        $filter = in_array($filter, self::RECIPIENT_FILTERS, true) ? $filter : 'all';

        return function () use ($tenantId, $campaignId, $filter, $search) {
            $b = db_connect()->table('emails e')
                ->join('contacts c', 'c.id = e.contact_id', 'left')
                ->where('e.tenant_id', $tenantId)
                ->where('e.email_campaign_id', $campaignId)
                ->where('e.deleted_at', null);

            match ($filter) {
                'sent'         => $b->where('e.status', 'sent'),
                'failed'       => $b->where('e.status', 'failed'),
                'opened'       => $b->where('e.opened_at IS NOT NULL'),
                'clicked'      => $b->where('e.clicked_at IS NOT NULL'),
                'replied'      => $b->where('e.replied_at IS NOT NULL'),
                'unsubscribed' => $b->where('e.unsubscribed_at IS NOT NULL'),
                'not_opened'   => $b->where('e.status', 'sent')->where('e.opened_at', null),
                default        => null,
            };

            $search = trim($search);
            if ($search !== '') {
                $b->groupStart()->like('e.to_email', $search)->orLike('c.name', $search)->groupEnd();
            }

            return $b;
        };
    }
}
