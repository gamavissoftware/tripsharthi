<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\ClickEventModel;
use CodeIgniter\Database\BaseConnection;

/**
 * Everything one broadcast did, assembled in one place.
 *
 * The campaign report is the product's answer to "what happened when I pressed
 * send": how many left, how many landed, how many were opened, who clicked,
 * which half of the A/B won, what the failures were, and what Meta will bill
 * for it. The pieces already exist — this composes them against a single
 * campaign_id filter so the controller stays thin (CLAUDE.md §9).
 */
final class CampaignReport
{
    private BaseConnection $db;

    public function __construct(
        private readonly MessageStats $stats = new MessageStats(),
        ?BaseConnection $db = null,
    ) {
        $this->db = $db ?? db_connect();
    }

    /**
     * @param  array<string, mixed> $campaign A campaigns row, already tenant-scoped.
     * @param  array<string, mixed> $filters  Normalised filters (tz_offset is honoured).
     * @return array<string, mixed>
     */
    public function build(int $tenantId, array $campaign, array $filters = []): array
    {
        $campaignId = (int) $campaign['id'];

        // The campaign IS the slice: any date range the caller passed describes
        // a different question, so only the timezone survives.
        $scope = [
            'direction'   => 'out',
            'campaign_id' => $campaignId,
            'tz_offset'   => $filters['tz_offset'] ?? 0,
        ];

        $funnel = $this->stats->funnel($tenantId, $scope);

        $clickModel = new ClickEventModel();

        $categories = $this->stats->categoryBreakdown($tenantId, $scope);
        $estCost    = array_sum(array_column($categories, 'est_cost_paise'));

        return [
            'campaign' => [
                'id'             => $campaignId,
                'name'           => $campaign['name'] ?? '',
                'status'         => $campaign['status'] ?? null,
                'created_at'     => $campaign['created_at'] ?? null,
                'scheduled_at'   => $campaign['scheduled_at'] ?? null,
                'total_contacts' => (int) ($campaign['total_contacts'] ?? 0),
                'template'       => $this->template((int) ($campaign['template_id'] ?? 0), $tenantId),
            ],
            'funnel'         => $funnel,
            'timeseries'     => $this->stats->timeseries($tenantId, $scope),
            'failures'       => $this->stats->failureBreakdown($tenantId, $scope),
            'categories'     => $categories,
            'est_cost_paise' => $estCost,
            'buttons'        => $clickModel->breakdownForCampaign($tenantId, $campaignId),
            'variants'       => $this->variants($tenantId, $campaignId),
            // Send-time bookkeeping the message rows cannot show: contacts the
            // sender deliberately skipped never produce a message row at all, so
            // without this the audience arithmetic looks like it lost people.
            'send_stats'     => $this->sendStats($campaign),
        ];
    }

    /**
     * A/B variant comparison — empty unless the campaign actually ran a split.
     *
     * @return list<array<string, mixed>>
     */
    private function variants(int $tenantId, int $campaignId): array
    {
        $rows = $this->db->table('messages')
            ->select('variant')
            ->select('COUNT(*) AS total', false)
            ->select("SUM(CASE WHEN status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent", false)
            ->select("SUM(CASE WHEN status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered", false)
            ->select("SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) AS reads", false)
            ->where('tenant_id', $tenantId)
            ->where('campaign_id', $campaignId)
            ->where('direction', 'out')
            ->where('variant IS NOT NULL', null, false)
            ->groupBy('variant')
            ->orderBy('variant', 'ASC')
            ->get()
            ->getResultArray();

        if ($rows === []) {
            return [];
        }

        $clickRows = $this->db->table('click_events ce')
            ->join('messages m', 'm.id = ce.message_id')
            ->select('m.variant AS variant')
            ->select('COUNT(ce.id) AS clicks', false)
            ->where('ce.tenant_id', $tenantId)
            ->where('ce.campaign_id', $campaignId)
            ->where('m.variant IS NOT NULL', null, false)
            ->groupBy('m.variant')
            ->get()
            ->getResultArray();

        $clicks = [];
        foreach ($clickRows as $r) {
            $clicks[(string) $r['variant']] = (int) $r['clicks'];
        }

        return array_map(static function (array $r) use ($clicks): array {
            $sent = (int) $r['sent'];
            $v    = (string) $r['variant'];

            return [
                'variant'       => $v,
                'total'         => (int) $r['total'],
                'sent'          => $sent,
                'delivered'     => (int) $r['delivered'],
                'read'          => (int) $r['reads'],
                'clicks'        => $clicks[$v] ?? 0,
                'delivery_rate' => MessageStats::rate((int) $r['delivered'], $sent),
                'read_rate'     => MessageStats::rate((int) $r['reads'], $sent),
            ];
        }, $rows);
    }

    /** @return array<string, mixed> */
    private function template(int $templateId, int $tenantId): ?array
    {
        if ($templateId === 0) {
            return null;
        }

        $row = $this->db->table('templates')
            ->select('id, name, category, language, meta_status')
            ->where('id', $templateId)
            ->where('tenant_id', $tenantId)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /** @return array<string, mixed> */
    private function sendStats(array $campaign): array
    {
        $raw = $campaign['stats'] ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (is_object($raw)) {
            $raw = (array) $raw;
        }

        return is_array($raw) ? $raw : [];
    }
}
