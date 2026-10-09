<?php

declare(strict_types=1);

namespace App\Models;

class ClickEventModel extends BaseModel
{
    protected $table      = 'click_events';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;
    protected $useTimestamps  = false; // clicked_at / created_at set explicitly

    protected $allowedFields = [
        'tenant_id', 'campaign_id', 'message_id', 'contact_id', 'conversation_id',
        'button_id', 'button_title', 'source', 'inbound_wa_id', 'clicked_at', 'created_at',
    ];

    /**
     * Per-campaign click aggregates for a set of campaign ids.
     *
     * @param  int[] $campaignIds
     * @return array<int, array{total_clicks:int, unique_clickers:int}>  keyed by campaign_id
     */
    public function aggregateForCampaigns(int $tenantId, array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }

        $rows = $this->db->table('click_events')
            ->select('campaign_id')
            ->selectCount('id', 'total_clicks')
            ->select('COUNT(DISTINCT contact_id) AS unique_clickers', false)
            ->where('tenant_id', $tenantId)
            ->whereIn('campaign_id', $campaignIds)
            ->groupBy('campaign_id')
            ->get()
            ->getResultArray();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['campaign_id']] = [
                'total_clicks'    => (int) $r['total_clicks'],
                'unique_clickers' => (int) $r['unique_clickers'],
            ];
        }
        return $map;
    }

    /**
     * Click breakdown by button for a single campaign.
     *
     * @return array<int, array{button:string, clicks:int}>
     */
    public function breakdownForCampaign(int $tenantId, int $campaignId): array
    {
        $rows = $this->db->table('click_events')
            ->select("COALESCE(button_title, button_id, '(unknown)') AS button", false)
            ->selectCount('id', 'clicks')
            ->where('tenant_id', $tenantId)
            ->where('campaign_id', $campaignId)
            ->groupBy('button')
            ->orderBy('clicks', 'DESC')
            ->get()
            ->getResultArray();

        return array_map(static fn ($r) => [
            'button' => (string) $r['button'],
            'clicks' => (int) $r['clicks'],
        ], $rows);
    }
}
