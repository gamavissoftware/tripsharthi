<?php

declare(strict_types=1);

namespace App\Services\Ads;

/**
 * Campaign performance = what the platform says you SPENT, joined to what the CRM says it EARNED.
 * Revenue is ex-tax (booking subtotal): GST and TCS are pass-through and would flatter ROAS.
 * CRM leads are matched to a campaign by first-touch attribution (campaign id from lead ads / UTM id, or
 * the ad id of a TravelPilot-created Click-to-WhatsApp ad).
 */
final class AdReportService
{
    /** @return list<array<string,mixed>> */
    public function campaigns(int $tenantId, int $days = 30): array
    {
        $days  = max(1, min(365, $days));
        $since = date('Y-m-d', strtotime("-{$days} days"));
        $db    = db_connect();

        $camps = $db->table('ad_campaigns')->where('tenant_id', $tenantId)->where('deleted_at', null)->orderBy('status', 'ASC')->orderBy('id', 'DESC')->get()->getResultArray();
        if (! $camps) { return []; }

        $ins = [];
        foreach ($db->query('SELECT platform, campaign_external_id, SUM(spend) spend, SUM(impressions) impressions, SUM(clicks) clicks, SUM(leads) leads, SUM(conversations) conversations
                               FROM ad_insights_daily WHERE tenant_id = ? AND day >= ? GROUP BY platform, campaign_external_id', [$tenantId, $since])->getResultArray() as $r) {
            $ins[$r['platform'] . ':' . $r['campaign_external_id']] = $r;
        }

        $crm = [];
        $sql = "SELECT c.id cid, COUNT(DISTINCT la.contact_id) leads,
                       COUNT(DISTINCT CASE WHEN t.status IN ('quoted','negotiating','booked','travelling','completed') THEN t.id END) quoted,
                       COUNT(DISTINCT b.id) bookings, COALESCE(SUM(b.subtotal),0) revenue, COALESCE(SUM(b.subtotal - b.cost_total),0) margin
                  FROM ad_campaigns c
                  LEFT JOIN lead_attributions la ON la.tenant_id = c.tenant_id AND la.touch = 'first' AND la.platform = c.platform AND la.deleted_at IS NULL AND la.touched_at >= ?
                       AND (la.campaign_id = c.external_id
                            OR (la.ad_id IS NOT NULL AND la.ad_id = JSON_UNQUOTE(JSON_EXTRACT(c.children, '$.ad_id')))
                            OR (la.ad_id IS NOT NULL AND JSON_CONTAINS(COALESCE(JSON_EXTRACT(c.children, '$.sets[*].ad_id'), JSON_ARRAY()), JSON_QUOTE(la.ad_id))))
                  LEFT JOIN trips t ON t.contact_id = la.contact_id AND t.tenant_id = c.tenant_id AND t.deleted_at IS NULL
                  LEFT JOIN bookings b ON b.trip_id = t.id AND b.status <> 'cancelled' AND b.deleted_at IS NULL
                 WHERE c.tenant_id = ? AND c.deleted_at IS NULL GROUP BY c.id";
        foreach ($db->query($sql, [$since . ' 00:00:00', $tenantId])->getResultArray() as $r) { $crm[(int) $r['cid']] = $r; }

        $out = [];
        foreach ($camps as $c) {
            $i = $ins[$c['platform'] . ':' . $c['external_id']] ?? ['spend' => 0, 'impressions' => 0, 'clicks' => 0, 'leads' => 0, 'conversations' => 0];
            $m = $crm[(int) $c['id']] ?? ['leads' => 0, 'quoted' => 0, 'bookings' => 0, 'revenue' => 0, 'margin' => 0];
            $spend = (int) $i['spend'];
            $leads = max((int) $m['leads'], (int) $i['leads']);          // CRM is the floor; platform may count more (no phone, dupes)
            $out[] = [
                'id' => (int) $c['id'], 'platform' => $c['platform'], 'origin' => $c['origin'], 'kind' => $c['kind'], 'name' => $c['name'], 'objective' => $c['objective'],
                'status' => $c['status'], 'effective_status' => $c['effective_status'], 'daily_budget' => (int) $c['daily_budget'], 'currency' => $c['currency'],
                'last_error' => $c['last_error'], 'paused_by_rule' => (bool) $c['paused_by_rule'], 'external_id' => $c['external_id'], 'last_synced_at' => $c['last_synced_at'],
                'spend' => $spend, 'impressions' => (int) $i['impressions'], 'clicks' => (int) $i['clicks'], 'platform_leads' => (int) $i['leads'], 'conversations' => (int) $i['conversations'],
                'crm_leads' => (int) $m['leads'], 'quoted' => (int) $m['quoted'], 'bookings' => (int) $m['bookings'], 'revenue' => (int) $m['revenue'], 'margin' => (int) $m['margin'],
                'cpl' => $leads > 0 ? intdiv($spend, $leads) : null,
                'cost_per_booking' => (int) $m['bookings'] > 0 ? intdiv($spend, (int) $m['bookings']) : null,
                'roas' => $spend > 0 ? round(((int) $m['revenue']) / $spend, 2) : null,
            ];
        }
        return $out;
    }
}
