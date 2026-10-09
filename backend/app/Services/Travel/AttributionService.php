<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\LeadAttributionModel;

/**
 * Ad attribution. Every lead source normalises its click IDs / campaign IDs
 * into one shape and calls record(); ConversionFeedbackService later reads the
 * FIRST touch to report CRM outcomes back to the ad platform.
 *
 * First touch is written once and never overwritten; every later ad touch is
 * stored as a 'last' touch (replaced each time) so both models are reportable.
 */
final class AttributionService
{
    private const COLUMNS = ['platform', 'channel', 'campaign_id', 'campaign_name', 'adset_id', 'ad_id', 'form_id', 'lead_id',
        'gclid', 'gbraid', 'wbraid', 'fbclid', 'fbp', 'fbc', 'ctwa_clid', 'landing_url'];

    /** @param array<string,mixed> $touch normalised keys (see COLUMNS) + optional utm array */
    public function record(int $tenantId, int $contactId, array $touch): void
    {
        $row = array_intersect_key($touch, array_flip(self::COLUMNS));
        $row = array_filter($row, static fn ($v) => $v !== null && $v !== '');
        if (isset($touch['utm']) && is_array($touch['utm']) && $touch['utm']) {
            $row['utm'] = json_encode($touch['utm']);
        }
        // Nothing but a platform label is not worth a row.
        if (count($row) <= 1 && ($row['platform'] ?? 'direct') === 'direct') {
            return;
        }
        $row += ['platform' => 'direct'];
        $row['contact_id'] = $contactId;
        $row['touched_at'] = date('Y-m-d H:i:s');

        $m     = (new LeadAttributionModel())->setTenant($tenantId);
        $first = $m->where('contact_id', $contactId)->where('touch', 'first')->first();
        if ($first === null) {
            (new LeadAttributionModel())->setTenant($tenantId)->insert($row + ['touch' => 'first']);
            return;
        }
        // Same lead delivered twice (webhook retry) — don't create a "last" duplicate.
        if (! empty($row['lead_id']) && $first['lead_id'] === $row['lead_id']) {
            return;
        }
        $last = (new LeadAttributionModel())->setTenant($tenantId)->where('contact_id', $contactId)->where('touch', 'last')->first();
        if ($last) {
            (new LeadAttributionModel())->setTenant($tenantId)->update((int) $last['id'], $row);
        } else {
            (new LeadAttributionModel())->setTenant($tenantId)->insert($row + ['touch' => 'last']);
        }
    }

    /** First-touch row for a contact (falls back to last). */
    public function forContact(int $tenantId, int $contactId): ?array
    {
        $rows = (new LeadAttributionModel())->setTenant($tenantId)->where('contact_id', $contactId)->orderBy('touch', 'ASC')->findAll();
        foreach ($rows as $r) {
            if ($r['touch'] === 'first') { return $r; }
        }
        return $rows[0] ?? null;
    }

    // ---- adapters: one per lead source -------------------------------------

    /** Meta Lead Ads Graph lead object (id, ad_id, adset_id, campaign_id, form_id, ...). */
    public static function fromMetaLead(array $lead): array
    {
        return [
            'platform' => 'meta', 'channel' => 'lead_ad',
            'lead_id' => (string) ($lead['id'] ?? ''), 'form_id' => (string) ($lead['form_id'] ?? ''),
            'ad_id' => (string) ($lead['ad_id'] ?? ''), 'adset_id' => (string) ($lead['adset_id'] ?? ''),
            'campaign_id' => (string) ($lead['campaign_id'] ?? ''), 'campaign_name' => (string) ($lead['campaign_name'] ?? ''),
        ];
    }

    /** Google Ads lead form webhook payload (lead_id, gcl_id, campaign_id, form_id, ...). */
    public static function fromGoogleLead(array $p): array
    {
        return [
            'platform' => 'google', 'channel' => 'lead_form',
            'lead_id' => (string) ($p['lead_id'] ?? ''), 'gclid' => (string) ($p['gcl_id'] ?? $p['gclid'] ?? ''),
            'campaign_id' => (string) ($p['campaign_id'] ?? ''), 'adset_id' => (string) ($p['adgroup_id'] ?? ''),
            'ad_id' => (string) ($p['creative_id'] ?? ''), 'form_id' => (string) ($p['form_id'] ?? ''),
        ];
    }

    /** Website form hidden fields: gclid/gbraid/wbraid/fbclid/fbp/fbc + utm_*. */
    public static function fromForm(array $post): array
    {
        $utm = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id'] as $k) {
            if (! empty($post[$k])) { $utm[$k] = (string) $post[$k]; }
        }
        $platform = ! empty($post['gclid']) || ! empty($post['gbraid']) || ! empty($post['wbraid']) ? 'google'
            : (! empty($post['fbclid']) || ! empty($post['fbc']) ? 'meta' : (($utm['utm_source'] ?? '') ?: 'direct'));
        return [
            'platform' => $platform, 'channel' => 'web_form',
            'gclid' => $post['gclid'] ?? null, 'gbraid' => $post['gbraid'] ?? null, 'wbraid' => $post['wbraid'] ?? null,
            'fbclid' => $post['fbclid'] ?? null, 'fbp' => $post['fbp'] ?? null, 'fbc' => $post['fbc'] ?? null,
            // Meta url_tags send utm_id={{campaign.id}}; Google's suffix sends utm_campaign={campaignid} (numeric).
            'campaign_id'   => $utm['utm_id'] ?? (ctype_digit((string) ($utm['utm_campaign'] ?? '')) ? $utm['utm_campaign'] : null),
            'campaign_name' => ctype_digit((string) ($utm['utm_campaign'] ?? '')) ? null : ($utm['utm_campaign'] ?? null),
            'landing_url' => $post['landing_url'] ?? null, 'utm' => $utm,
        ];
    }

    /** Inbound WhatsApp message referral block (Click-to-WhatsApp ad). First message only. */
    public static function fromWhatsAppReferral(array $referral): array
    {
        if (empty($referral['ctwa_clid']) && empty($referral['source_id'])) {
            return ['platform' => 'direct'];
        }
        return [
            'platform' => 'meta', 'channel' => 'click_to_whatsapp',
            'ctwa_clid' => (string) ($referral['ctwa_clid'] ?? ''), 'ad_id' => (string) ($referral['source_id'] ?? ''),
            'landing_url' => (string) ($referral['source_url'] ?? ''),
            'campaign_name' => (string) ($referral['headline'] ?? ''),
        ];
    }
}
