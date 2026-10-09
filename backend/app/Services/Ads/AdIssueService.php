<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Services\Crm\NotificationService;

/**
 * Ad disapprovals and policy limits. An ad that is disapproved simply stops delivering, so the loss is silent: spend sits idle
 * and the agency wonders why leads dried up. Each sync hands this service the COMPLETE current list for one platform; we
 *  - insert new problems and alert owners/admins ONCE (in-app + phone push),
 *  - refresh the reason on known ones (no repeat alert),
 *  - mark problems the platform no longer reports as resolved (and say so once).
 * A failed/partial fetch must never call this: "not in the list" means "fixed", so an incomplete list would falsely resolve issues.
 */
final class AdIssueService
{
    public function __construct(private readonly ?int $now = null) {}
    private function stamp(): string { return date('Y-m-d H:i:s', $this->now ?? time()); }

    /**
     * @param list<array{campaign:string,ad:string,ad_name?:string,kind:string,reason:string}> $seen the complete current set for $platform
     * @return array{open:int,new:int,resolved:int}
     */
    public function record(int $tenantId, string $platform, array $seen): array
    {
        $db = db_connect(); $new = []; $keys = [];
        foreach ($seen as $s) {
            $kind = $s['kind'] === 'limited' ? 'limited' : 'disapproved';
            $reason = mb_substr(trim((string) $s['reason']) !== '' ? trim((string) $s['reason']) : 'The platform did not give a reason — open the ad in its Ads Manager.', 0, 500);
            $key = $s['ad'] . '|' . $kind; $keys[$key] = true;
            $row = $db->table('ad_issues')->where('tenant_id', $tenantId)->where('platform', $platform)->where('ad_external_id', (string) $s['ad'])->where('kind', $kind)->get()->getRowArray();
            if (! $row) {
                $db->table('ad_issues')->insert(['tenant_id' => $tenantId, 'platform' => $platform, 'campaign_external_id' => (string) $s['campaign'], 'ad_external_id' => (string) $s['ad'], 'ad_name' => isset($s['ad_name']) ? mb_substr((string) $s['ad_name'], 0, 255) : null,
                    'kind' => $kind, 'reason' => $reason, 'first_seen_at' => $this->stamp(), 'last_seen_at' => $this->stamp()]);
                $new[] = ['campaign' => (string) $s['campaign'], 'kind' => $kind, 'reason' => $reason, 'name' => $s['ad_name'] ?? null, 'id' => (int) $db->insertID()];
            } else {
                $reopened = $row['resolved_at'] !== null;
                $db->table('ad_issues')->where('id', $row['id'])->update(['reason' => $reason, 'last_seen_at' => $this->stamp(), 'resolved_at' => null] + ($reopened ? ['first_seen_at' => $this->stamp(), 'notified_at' => null] : []));
                if ($reopened) { $new[] = ['campaign' => (string) $s['campaign'], 'kind' => $kind, 'reason' => $reason, 'name' => $s['ad_name'] ?? null, 'id' => (int) $row['id']]; }
            }
        }
        // Everything open that the platform no longer lists is fixed.
        $resolved = 0;
        foreach ($db->table('ad_issues')->where('tenant_id', $tenantId)->where('platform', $platform)->where('resolved_at', null)->get()->getResultArray() as $o) {
            if (! isset($keys[$o['ad_external_id'] . '|' . $o['kind']])) {
                $db->table('ad_issues')->where('id', $o['id'])->update(['resolved_at' => $this->stamp()]);
                $resolved++;
            }
        }
        foreach ($new as $n) { $this->alert($tenantId, $platform, $n); $db->table('ad_issues')->where('id', $n['id'])->update(['notified_at' => $this->stamp()]); }
        $open = (int) $db->table('ad_issues')->where('tenant_id', $tenantId)->where('platform', $platform)->where('resolved_at', null)->countAllResults();
        return ['open' => $open, 'new' => count($new), 'resolved' => $resolved];
    }

    private function alert(int $tenantId, string $platform, array $n): void
    {
        $c = db_connect()->table('ad_campaigns')->select('name')->where('tenant_id', $tenantId)->where('platform', $platform)->where('external_id', $n['campaign'])->get()->getRowArray();
        $where = $c['name'] ?? 'a campaign';
        $what = $n['kind'] === 'limited' ? 'has a policy limit' : 'was DISAPPROVED';
        $body = ($n['name'] ? "Ad “{$n['name']}” in" : 'An ad in') . " “{$where}” {$what} on " . ($platform === 'meta' ? 'Meta' : 'Google') . ": {$n['reason']} Fix or replace it — a disapproved ad stops delivering.";
        foreach (db_connect()->table('users')->select('id')->where('tenant_id', $tenantId)->whereIn('role', ['owner', 'admin'])->where('deleted_at', null)->get()->getResultArray() as $u) {
            NotificationService::notify($tenantId, (int) $u['id'], 'ad_issue', $body, '/ads/campaigns');
        }
    }

    /** Open problems for the campaigns screen. */
    public function open(int $tenantId): array
    {
        return array_map(static fn ($r) => ['id' => (int) $r['id'], 'platform' => $r['platform'], 'campaign_external_id' => $r['campaign_external_id'], 'ad_name' => $r['ad_name'], 'kind' => $r['kind'], 'reason' => $r['reason'], 'since' => $r['first_seen_at']],
            db_connect()->table('ad_issues')->where('tenant_id', $tenantId)->where('resolved_at', null)->orderBy('first_seen_at', 'DESC')->limit(100)->get()->getResultArray());
    }
}
