<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Models\AdCampaignModel;
use App\Models\AdSettingModel;
use App\Services\Crm\AuditLogger;

/**
 * The single door for everything that changes a campaign. Order of operations is fixed and deliberate:
 *   guardrails -> (write local row first) -> platform call -> update local row -> audit log.
 * Nothing here ever raises spend without passing AdGuardrails; pausing is always allowed.
 */
final class AdCampaignManager
{
    /** @param null|callable(string,int):?AdPlatformAdapter $adapterFactory tests inject fakes */
    public function __construct(private readonly mixed $adapterFactory = null) {}

    // ---- settings -------------------------------------------------------------------------------------

    public function settings(int $tenantId): array
    {
        $r = (new AdSettingModel())->setTenant($tenantId)->first();
        return [
            'daily_spend_cap'  => (int) ($r['daily_spend_cap'] ?? 0),
            'min_daily_budget' => (int) ($r['min_daily_budget'] ?? 10_000),
            'max_increase_pct' => (int) ($r['max_increase_pct'] ?? 30),
            'rules_enabled'    => (bool) ($r['rules_enabled'] ?? false),
        ];
    }

    public function saveSettings(int $tenantId, array $in, ?int $actorId = null): array
    {
        $before = $this->settings($tenantId);
        $data = [
            'daily_spend_cap'  => max(0, (int) ($in['daily_spend_cap'] ?? $before['daily_spend_cap'])),
            'min_daily_budget' => max(100, (int) ($in['min_daily_budget'] ?? $before['min_daily_budget'])),
            'max_increase_pct' => max(0, min(100, (int) ($in['max_increase_pct'] ?? $before['max_increase_pct']))),
            'rules_enabled'    => ! empty($in['rules_enabled']) ? 1 : 0,
        ];
        $row = (new AdSettingModel())->setTenant($tenantId)->first();
        $row ? (new AdSettingModel())->setTenant($tenantId)->update((int) $row['id'], $data) : (new AdSettingModel())->setTenant($tenantId)->insert($data);
        AuditLogger::log('ad_settings.update', 'ad_settings', null, $before, $data, $tenantId, $actorId);
        return $this->settings($tenantId);
    }

    // ---- helpers --------------------------------------------------------------------------------------

    private function adapter(string $platform, int $tenantId): AdPlatformAdapter
    {
        $a = is_callable($this->adapterFactory)
            ? ($this->adapterFactory)($platform, $tenantId)
            : ($platform === 'meta' ? MetaAdapter::forTenant($tenantId, AdsMockHttp::enabled() ? AdsMockHttp::handler($tenantId) : null)
                                     : GoogleAdapter::forTenant($tenantId, AdsMockHttp::enabled() ? AdsMockHttp::handler($tenantId) : null));
        if (! $a) {
            throw new AdsApiException(($platform === 'meta' ? 'Meta' : 'Google') . ' is not connected for campaign management. Connect it first.', AdsApiException::AUTH);
        }
        return $a;
    }

    /** Sum of daily budgets of every ACTIVE campaign (ours and synced) — what the cap is measured against. */
    public function activeDailyTotal(int $tenantId): int
    {
        $r = db_connect()->table('ad_campaigns')->select('COALESCE(SUM(daily_budget),0) t', false)
            ->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->where('deleted_at', null)->get()->getRowArray();
        return (int) ($r['t'] ?? 0);
    }

    private function get(int $tenantId, int $id): array
    {
        $c = (new AdCampaignModel())->setTenant($tenantId)->find($id);
        if (! $c) { throw new \InvalidArgumentException('Campaign not found.'); }
        return $c;
    }

    private function guard(int $tenantId, string $currency, int $newDaily, ?int $oldDaily, bool $active): void
    {
        $v = AdGuardrails::check($this->settings($tenantId), $currency, $this->activeDailyTotal($tenantId), $newDaily, $oldDaily, $active);
        if ($v) { throw new AdsGuardrailException($v); }
    }

    // ---- create ---------------------------------------------------------------------------------------

    /** Everything the review step shows. Creates nothing. */
    public function preview(int $tenantId, array $spec): array
    {
        $a = $this->adapter((string) ($spec['platform'] ?? ''), $tenantId);
        $errors = $a->validate($spec);
        $warnings = [];
        if ($spec['platform'] === 'google') { $warnings = GooglePlanBuilder::validate($spec)['warnings']; }
        else { $warnings = $this->metaWarnings($spec); }
        if ($errors) { return ['ok' => false, 'errors' => $errors, 'warnings' => $warnings, 'guardrails' => [], 'notes' => []]; }

        $pre = $a->preflight($spec);
        $cap = AdGuardrails::check($this->settings($tenantId), $pre['currency'], $this->activeDailyTotal($tenantId), (int) $spec['daily_budget'], null, false);
        $launch = AdGuardrails::check($this->settings($tenantId), $pre['currency'], $this->activeDailyTotal($tenantId), (int) $spec['daily_budget'], null, true);
        return ['ok' => ! $cap, 'errors' => $cap, 'warnings' => $warnings, 'notes' => $pre['notes'], 'currency' => $pre['currency'],
            'launch_blockers' => $launch, 'monthly_estimate' => (int) $spec['daily_budget'] * 30];
    }

    private function metaWarnings(array $spec): array
    {
        $c = $spec['creative'] ?? [];
        return AdCopyLint::checkAll(['ad text' => $c['primary_text'] ?? '', 'headline' => $c['headline'] ?? '', 'description' => $c['description'] ?? ''], 'meta')['warnings'];
    }

    public function create(int $tenantId, ?int $userId, array $spec): array
    {
        $platform = (string) ($spec['platform'] ?? '');
        $a = $this->adapter($platform, $tenantId);
        if ($e = $a->validate($spec)) { throw new \InvalidArgumentException(implode(' ', $e)); }
        if ($platform === 'meta' && ($l = AdCopyLint::checkAll(['ad text' => $spec['creative']['primary_text'] ?? '', 'headline' => $spec['creative']['headline'] ?? ''], 'meta')['errors'])) {
            throw new \InvalidArgumentException(implode(' ', $l));
        }
        $pre = $a->preflight($spec);
        $this->guard($tenantId, $pre['currency'], (int) $spec['daily_budget'], null, false);

        // Local row FIRST: a crash between the remote create and our insert must not leave an unknown remote campaign.
        $placeholder = 'pending_' . bin2hex(random_bytes(6));
        $id = (int) (new AdCampaignModel())->setTenant($tenantId)->insert([
            'platform' => $platform, 'origin' => 'travelpilot', 'kind' => $spec['kind'], 'external_id' => $placeholder, 'name' => trim((string) $spec['name']),
            'status' => 'PAUSED', 'effective_status' => 'CREATING', 'currency' => $pre['currency'] ?: 'INR', 'daily_budget' => (int) $spec['daily_budget'],
            'destination_id' => ! empty($spec['destination_id']) ? (int) $spec['destination_id'] : null,
            'start_date' => ($spec['start_date'] ?? null) ?: null, 'end_date' => ($spec['end_date'] ?? null) ?: null, 'spec' => json_encode($spec, JSON_UNESCAPED_UNICODE),
            'account_ref' => $spec['account_id'] ?? $spec['customer_id'] ?? null, 'created_by' => $userId,
        ], true);

        try {
            $r = $a->create($spec);
        } catch (AdsApiException $e) {
            (new AdCampaignModel())->setTenant($tenantId)->update($id, ['effective_status' => 'ERROR', 'last_error' => $e->getMessage()]);
            AuditLogger::log('ad_campaign.create_failed', 'ad_campaign', $id, null, ['error' => $e->getMessage()], $tenantId, $userId);
            throw $e;
        }
        (new AdCampaignModel())->setTenant($tenantId)->update($id, [
            'external_id' => $r['external_id'], 'objective' => $r['objective'], 'children' => json_encode($r['children']), 'effective_status' => 'PAUSED', 'last_error' => null,
            'account_ref' => $r['account_ref'],
        ]);
        AuditLogger::log('ad_campaign.create', 'ad_campaign', $id, null, ['platform' => $platform, 'name' => $spec['name'], 'daily_budget' => (int) $spec['daily_budget'], 'status' => 'PAUSED'], $tenantId, $userId);
        return $this->get($tenantId, $id);
    }

    // ---- lifecycle ------------------------------------------------------------------------------------

    public function launch(int $tenantId, int $id, ?int $userId): array
    {
        $c = $this->get($tenantId, $id);
        if ($c['status'] === 'ACTIVE') { return $c; }
        if (in_array($c['status'], ['DELETED', 'ARCHIVED'], true) || str_starts_with((string) $c['external_id'], 'pending_')) {
            throw new \DomainException('This campaign cannot be launched (it was deleted or never finished creating).');
        }
        $this->guard($tenantId, (string) $c['currency'], (int) $c['daily_budget'], null, true);
        $this->adapter($c['platform'], $tenantId)->setStatus($c, 'ACTIVE');
        (new AdCampaignModel())->setTenant($tenantId)->update($id, ['status' => 'ACTIVE', 'effective_status' => 'PENDING_SYNC', 'launched_at' => date('Y-m-d H:i:s'), 'paused_by_rule' => 0]);
        AuditLogger::log('ad_campaign.launch', 'ad_campaign', $id, ['status' => $c['status']], ['status' => 'ACTIVE', 'daily_budget' => (int) $c['daily_budget']], $tenantId, $userId);
        return $this->get($tenantId, $id);
    }

    public function pause(int $tenantId, int $id, ?int $userId, bool $byRule = false, string $why = ''): array
    {
        $c = $this->get($tenantId, $id);
        if ($c['status'] === 'PAUSED') { return $c; }
        $this->adapter($c['platform'], $tenantId)->setStatus($c, 'PAUSED');      // never blocked by guardrails
        (new AdCampaignModel())->setTenant($tenantId)->update($id, ['status' => 'PAUSED', 'effective_status' => 'PAUSED', 'paused_by_rule' => $byRule ? 1 : 0]);
        AuditLogger::log($byRule ? 'ad_campaign.pause_by_rule' : 'ad_campaign.pause', 'ad_campaign', $id, ['status' => $c['status']], ['status' => 'PAUSED', 'why' => $why], $tenantId, $userId);
        return $this->get($tenantId, $id);
    }

    public function setBudget(int $tenantId, int $id, int $dailyPaise, ?int $userId): array
    {
        $c = $this->get($tenantId, $id);
        $old = (int) $c['daily_budget'];
        if ($dailyPaise === $old) { return $c; }
        $this->guard($tenantId, (string) $c['currency'], $dailyPaise, $old, $c['status'] === 'ACTIVE');
        $this->adapter($c['platform'], $tenantId)->setBudget($c, $dailyPaise);
        (new AdCampaignModel())->setTenant($tenantId)->update($id, ['daily_budget' => $dailyPaise]);
        AuditLogger::log('ad_campaign.budget', 'ad_campaign', $id, ['daily_budget' => $old], ['daily_budget' => $dailyPaise], $tenantId, $userId);
        return $this->get($tenantId, $id);
    }

    // ---- sync -----------------------------------------------------------------------------------------

    /** @return array<string,array|string> per-platform result or the reason it was skipped */
    public function syncAll(int $tenantId): array
    {
        $out = [];
        foreach (['meta', 'google'] as $p) {
            try { $out[$p] = $this->adapter($p, $tenantId)->sync($tenantId); }
            catch (AdsApiException $e) { $out[$p] = ['skipped' => $e->getMessage(), 'kind' => $e->kind]; }
        }
        return $out;
    }
}
