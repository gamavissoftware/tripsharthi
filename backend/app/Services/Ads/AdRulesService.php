<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Models\AdRuleModel;
use App\Services\Crm\NotificationService;

/**
 * Automatic ad rules. By design these can only PAUSE a campaign or NOTIFY a person:
 *  - never enable a campaign, never raise a budget (risk is asymmetric: a wrongly paused ad loses a day,
 *    a wrongly boosted one burns money);
 *  - only touch ACTIVE campaigns, so anything a human paused stays paused;
 *  - a rule needs a minimum amount of spend before it can fire, so one early cheap lead can't mislead it;
 *  - each (rule, campaign, day) fires at most once.
 */
final class AdRulesService
{
    public function __construct(private readonly ?AdCampaignManager $manager = null, private readonly ?int $now = null) {}

    private function today(): string { return date('Y-m-d', $this->now ?? time()); }

    /** Pure decision for one rule + one campaign's window metrics. @return string|null reason it fires */
    public static function evaluate(array $rule, array $m): ?string
    {
        $spend = (int) $m['spend'];
        $leads = max((int) $m['crm_leads'], (int) $m['platform_leads']);
        $thr   = (int) $rule['threshold'];
        if ($spend < (int) $rule['min_spend']) { return null; }
        switch ($rule['metric']) {
            case 'spend_no_leads':
                return ($spend >= $thr && $leads === 0) ? 'Spent ' . AdGuardrails::inr($spend) . " in {$rule['window_days']} days with no leads." : null;
            case 'cpl':
                return ($leads > 0 && intdiv($spend, $leads) > $thr)
                    ? 'Cost per lead ' . AdGuardrails::inr(intdiv($spend, $leads)) . ' is above ' . AdGuardrails::inr($thr) . '.' : null;
            case 'cost_per_booking':
                $b = (int) $m['bookings'];
                if ($b > 0) { return intdiv($spend, $b) > $thr ? 'Cost per booking ' . AdGuardrails::inr(intdiv($spend, $b)) . ' is above ' . AdGuardrails::inr($thr) . '.' : null; }
                return $spend >= $thr ? 'Spent ' . AdGuardrails::inr($spend) . ' with no bookings — more than one booking is worth.' : null;
        }
        return null;
    }

    /** @return array{checked:int,paused:int,notified:int} */
    public function runTenant(int $tenantId): array
    {
        $mgr = $this->manager ?? new AdCampaignManager();
        $out = ['checked' => 0, 'paused' => 0, 'notified' => 0];
        if (! $mgr->settings($tenantId)['rules_enabled']) { return $out; }

        $rules = (new AdRuleModel())->setTenant($tenantId)->where('enabled', 1)->findAll();
        foreach ($rules as $rule) {
            $report = (new AdReportService())->campaigns($tenantId, max(1, (int) $rule['window_days']));
            foreach ($report as $c) {
                if ($c['status'] !== 'ACTIVE' || ($rule['platform'] !== 'any' && $rule['platform'] !== $c['platform'])) { continue; }
                $out['checked']++;
                $why = self::evaluate($rule, ['spend' => $c['spend'], 'crm_leads' => $c['crm_leads'], 'platform_leads' => $c['platform_leads'], 'bookings' => $c['bookings']] + ['window_days' => $rule['window_days']]);
                if ($why === null || ! $this->claim($tenantId, (int) $rule['id'], (int) $c['id'])) { continue; }

                $msg = "Rule “{$rule['name']}” on “{$c['name']}”: {$why}";
                try {
                    if ($rule['action'] === 'pause') { $mgr->pause($tenantId, (int) $c['id'], null, true, $msg); $out['paused']++; $msg .= ' The campaign was paused.'; }
                    else { $out['notified']++; }
                } catch (\Throwable $e) {
                    $msg .= ' (Could not pause automatically: ' . $e->getMessage() . ')';
                }
                $this->notifyOwners($tenantId, $msg);
            }
            (new AdRuleModel())->setTenant($tenantId)->update((int) $rule['id'], ['last_run_at' => date('Y-m-d H:i:s', $this->now ?? time())]);
        }
        return $out;
    }

    /** @return bool true when this call won the once-per-day claim */
    private function claim(int $tenantId, int $ruleId, int $campaignId): bool
    {
        $db = db_connect();
        $db->table('travel_trigger_log')->ignore(true)->insert(['tenant_id' => $tenantId, 'trigger_type' => 'ad_rule', 'entity_type' => 'ad_campaign', 'entity_id' => $campaignId,
            'claim_key' => 'rule' . $ruleId . ':' . $this->today(), 'fired_at' => date('Y-m-d H:i:s', $this->now ?? time())]);
        return $db->affectedRows() > 0;
    }

    private function notifyOwners(int $tenantId, string $body): void
    {
        foreach (db_connect()->table('users')->select('id')->where('tenant_id', $tenantId)->whereIn('role', ['owner', 'admin'])->where('deleted_at', null)->get()->getResultArray() as $u) {
            NotificationService::notify($tenantId, (int) $u['id'], 'ad_rule', $body, '/ads/campaigns');
        }
    }
}
