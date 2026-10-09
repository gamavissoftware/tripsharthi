<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\ContactModel;
use App\Models\LeadScoringRuleModel;

/**
 * Lead scoring engine (Phase H1). Computes a 0–100 score per contact from
 * configurable, per-tenant weighted signals and derives a Hot/Warm/Cold tier.
 *
 * Signals: lifecycle stage, source quality, has-email, has-account, linked-deal
 * value band, engagement (count of INBOUND WhatsApp messages — a READ-ONLY scan
 * of the messages table, never a write), task-completion rate, and a recency
 * decay penalty. The per-signal contributions are returned as a breakdown so the
 * contact page can explain WHY a lead is Hot.
 *
 * GUARDRAIL: this service only ever READS messaging tables and only WRITES the
 * contact's own score columns — never conversations/messages/flow_runs.
 */
final class LeadScoringService
{
    /** Built-in defaults; a tenant's lead_scoring_rules row overrides `weights`. */
    public const DEFAULTS = [
        'lifecycle' => ['subscriber' => 5, 'lead' => 10, 'mql' => 25, 'sql' => 40, 'opportunity' => 60, 'customer' => 80, 'evangelist' => 90, 'other' => 0],
        'source'    => ['meta_lead_ads' => 15, 'google_lead_forms' => 15, 'web_form' => 12, 'whatsapp_inbound' => 10, 'shopify' => 8, 'woocommerce' => 8, 'manual' => 5, 'csv_import' => 3],
        'has_email'        => 5,
        'has_account'      => 5,
        'deal_band'        => ['high' => 20, 'mid' => 12, 'low' => 5],  // ≥10L / ≥1L / >0 (paise)
        'engagement_each'  => 2,    // per inbound message
        'engagement_cap'   => 20,
        'task_completion'  => 10,   // × completion ratio
        'decay_per_week'   => 3,    // points lost per idle week
        'decay_cap'        => 20,
    ];
    public const HOT  = 65;
    public const WARM = 40;

    public function __construct(
        private ContactModel $contacts = new ContactModel(),
        private LeadScoringRuleModel $rules = new LeadScoringRuleModel(),
    ) {}

    /** Merge a tenant's stored weights over the defaults; resolve thresholds. */
    public function rulesFor(int $tenantId): array
    {
        $row     = $this->rules->setTenant($tenantId)->where('tenant_id', $tenantId)->first();
        $weights = self::DEFAULTS;
        $hot     = self::HOT;
        $warm    = self::WARM;

        if ($row) {
            $stored  = json_decode($row['weights'] ?? '{}', true) ?: [];
            $weights = array_replace_recursive($weights, $stored);
            $hot     = (int) ($row['hot_threshold'] ?? $hot);
            $warm    = (int) ($row['warm_threshold'] ?? $warm);
        }

        return ['weights' => $weights, 'hot' => $hot, 'warm' => $warm];
    }

    /**
     * Compute (without persisting) the score, tier, and breakdown for a contact row.
     *
     * @param array $contact a contacts row
     * @return array{score:int, tier:string, breakdown:array<string,int>}
     */
    public function score(array $contact, int $tenantId, ?int $now = null): array
    {
        $now ??= time();
        $cfg = $this->rulesFor($tenantId);
        $w   = $cfg['weights'];
        $b   = [];

        $b['lifecycle'] = (int) ($w['lifecycle'][$contact['lifecycle_stage'] ?? ''] ?? 0);
        $b['source']    = (int) ($w['source'][$contact['source'] ?? ''] ?? 0);
        $b['email']     = ! empty($contact['email']) ? (int) $w['has_email'] : 0;
        $b['account']   = ! empty($contact['account_id']) ? (int) $w['has_account'] : 0;
        $b['deal']      = $this->dealBand((int) $contact['id'], $tenantId, $w);
        $b['engagement']= $this->engagement((int) $contact['id'], $tenantId, $w);
        $b['tasks']     = $this->taskCompletion((int) $contact['id'], $tenantId, $w);
        $b['decay']     = $this->decay($contact, $now, $w);

        $score = max(0, min(100, array_sum($b)));
        $tier  = $score >= $cfg['hot'] ? 'hot' : ($score >= $cfg['warm'] ? 'warm' : 'cold');

        return ['score' => $score, 'tier' => $tier, 'breakdown' => $b];
    }

    /** Compute and persist the score on the contact. Returns the result. */
    public function recalc(int $contactId, int $tenantId, ?int $now = null): array
    {
        $contact = $this->contacts->setTenant($tenantId)->find($contactId);
        if (! $contact) {
            return ['score' => 0, 'tier' => 'cold', 'breakdown' => []];
        }
        $res = $this->score($contact, $tenantId, $now);
        $this->contacts->setTenant($tenantId)->update($contactId, [
            'lead_score'      => $res['score'],
            'score_tier'      => $res['tier'],
            'score_breakdown' => json_encode($res['breakdown']),
        ]);
        return $res;
    }

    /** Fire-and-forget recalc for a hook — never lets a scoring error break the caller. */
    public static function recalcQuietly(int $contactId, int $tenantId): void
    {
        try {
            (new self())->recalc($contactId, $tenantId);
        } catch (\Throwable $e) {
            log_message('error', 'Lead score recalc failed for contact ' . $contactId . ': ' . $e->getMessage());
        }
    }

    /** Recalc every contact for a tenant (nightly job / manual recompute). */
    public function recalcTenant(int $tenantId, ?int $now = null): int
    {
        $now ??= time();
        $ids = array_column($this->contacts->setTenant($tenantId)->findAll(), 'id');
        foreach ($ids as $id) {
            $this->recalc((int) $id, $tenantId, $now);
        }
        return count($ids);
    }

    // ── Signals ─────────────────────────────────────────────────────────

    private function dealBand(int $contactId, int $tenantId, array $w): int
    {
        $db      = db_connect();
        $dealIds = array_column($db->table('deal_contacts')->select('deal_id')
            ->where('tenant_id', $tenantId)->where('contact_id', $contactId)->get()->getResultArray(), 'deal_id');

        $q = $db->table('deals')->selectMax('value_amount', 'v')
            ->where('tenant_id', $tenantId)->where('deleted_at', null)
            ->groupStart()->where('primary_contact_id', $contactId);
        if ($dealIds) {
            $q->orWhereIn('id', $dealIds);
        }
        $max = (int) ($q->groupEnd()->get()->getRow('v') ?? 0);

        if ($max >= 1000000_00) return (int) $w['deal_band']['high'];   // ≥ ₹10,00,000
        if ($max >= 100000_00)  return (int) $w['deal_band']['mid'];    // ≥ ₹1,00,000
        if ($max > 0)           return (int) $w['deal_band']['low'];
        return 0;
    }

    /** READ-ONLY count of inbound WhatsApp messages for this contact. */
    private function engagement(int $contactId, int $tenantId, array $w): int
    {
        $count = (int) db_connect()->table('messages')
            ->where('tenant_id', $tenantId)->where('contact_id', $contactId)->where('direction', 'in')
            ->countAllResults();
        return min($count * (int) $w['engagement_each'], (int) $w['engagement_cap']);
    }

    private function taskCompletion(int $contactId, int $tenantId, array $w): int
    {
        $db    = db_connect();
        $total = (int) $db->table('tasks')->where('tenant_id', $tenantId)
            ->where('related_type', 'contact')->where('related_id', $contactId)->where('deleted_at', null)
            ->countAllResults();
        if ($total === 0) {
            return 0;
        }
        $done = (int) $db->table('tasks')->where('tenant_id', $tenantId)
            ->where('related_type', 'contact')->where('related_id', $contactId)
            ->where('status', 'done')->where('deleted_at', null)
            ->countAllResults();
        return (int) round(($done / $total) * (int) $w['task_completion']);
    }

    private function decay(array $contact, int $now, array $w): int
    {
        $ref = $contact['last_inbound_at'] ?? $contact['updated_at'] ?? null;
        if (! $ref) {
            return 0;
        }
        $ts = strtotime((string) $ref);
        if ($ts === false || $ts >= $now) {
            return 0;
        }
        $weeks = (int) floor(($now - $ts) / (7 * 86400));
        return -min($weeks * (int) $w['decay_per_week'], (int) $w['decay_cap']);
    }
}
