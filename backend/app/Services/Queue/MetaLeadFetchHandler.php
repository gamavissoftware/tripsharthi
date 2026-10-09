<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Models\IntegrationModel;
use App\Models\MetaLeadEventModel;
use App\Services\Leads\ContactDedupeService;
use App\Services\Leads\LeadCustomFields;
use App\Services\Leads\MetaLeadMapper;
use App\Services\Leads\MetaLeadsService;

/**
 * Handles 'meta_lead_fetch' jobs — the second stage of the Lead Ads pipeline.
 *
 * ── Pipeline (webhook → job → this handler) ──────────────────────────
 *
 * 1. Webhook arrives → signature verified → leadgen_id inserted into
 *    meta_lead_events (idempotency guard) → job dispatched → 200 returned.
 *
 * 2. THIS HANDLER (runs in the worker):
 *    a. Load meta_lead_events row (skip if already processed)
 *    b. Resolve integration → decrypt Page Access Token
 *    c. Fetch full lead from Graph API (MetaLeadsService — mock-able)
 *    d. Map field_data → contact array (MetaLeadMapper)
 *    e. If no phone number: mark event 'failed', return (no retry needed)
 *    f. ContactDedupeService::upsert() → creates/updates contact
 *       ↳ Automatically fires lead_created + meta_lead_received via Phase 3 wiring
 *    g. Mark event 'processed', store contact_id
 *
 * ── Durability ────────────────────────────────────────────────────────
 * If the handler throws before step (g) (e.g. on a Graph API timeout),
 * the job retries with exponential backoff.  Steps (a–d) are idempotent
 * (no side effects until upsert).  Step (f) is idempotent (dedupe on wa_number).
 * Step (g) is the final commit; a retry after a partial failure is safe.
 *
 * ── Injectable service (for testing) ─────────────────────────────────
 * Pass a MetaLeadsService constructed with mock data to test without Meta.
 */
class MetaLeadFetchHandler
{
    public function __construct(
        private readonly ?MetaLeadsService $serviceOverride = null,
    ) {}

    public function handle(array $job, int $tenantId): void
    {
        $payload = json_decode($job['payload'] ?? '{}', true) ?: [];
        $eventId = (int) ($payload['meta_lead_event_id'] ?? 0);

        if ($eventId <= 0 || $tenantId <= 0) {
            throw new \RuntimeException("meta_lead_fetch job missing meta_lead_event_id or tenant_id.");
        }

        // ── Load event row ────────────────────────────────────────────
        $eventModel = new MetaLeadEventModel();
        $event      = $eventModel->setTenant($tenantId)->find($eventId);

        if ($event === null) {
            log_message('warning', "meta_lead_fetch: event #{$eventId} not found, skipping.");
            return;
        }
        if ($event['status'] === 'processed') {
            log_message('info', "meta_lead_fetch: event #{$eventId} already processed, skipping (idempotent).");
            return;
        }

        // ── Resolve integration → decrypted page token ────────────────
        $integrationModel = new IntegrationModel();
        $integration      = $integrationModel->withoutTenantScope()->find((int) $event['integration_id']);

        if ($integration === null) {
            $eventModel->setTenant($tenantId)->update($eventId, [
                'status' => 'failed',
                'error'  => "Integration #{$event['integration_id']} no longer exists.",
            ]);
            log_message('error', "meta_lead_fetch: integration #{$event['integration_id']} not found.");
            return;
        }

        $pageToken   = $integrationModel->decryptPageToken($integration);
        $countryCode = $integrationModel->getDefaultCountryCode($integration);

        // ── Fetch from Graph API ──────────────────────────────────────
        // If this throws, the job retries with backoff. Safe — no side effects yet.
        $service  = $this->serviceOverride ?? new MetaLeadsService();
        $leadData = $service->fetchLead($event['leadgen_id'], $pageToken);

        // ── Map fields ────────────────────────────────────────────────
        $contactData = MetaLeadMapper::map($leadData['field_data'] ?? [], $countryCode);

        // ── Guard: phone number required ──────────────────────────────
        if (empty($contactData['wa_number'])) {
            $rawPhone = '';
            $names    = [];
            foreach ($leadData['field_data'] ?? [] as $f) {
                $fname   = strtolower(trim((string) ($f['name'] ?? '')));
                $names[] = $fname;
                if ($fname === 'phone_number' || $fname === 'phone') {
                    $rawPhone = trim((string) ($f['values'][0] ?? ''));
                }
            }
            // Name the fields that DID arrive: the usual cause is a form that
            // asks for the phone under a custom question, and this is the only
            // place that can say so.
            // Field list first and each name clipped: the column is 255 chars
            // and a form's custom questions are whole sentences.
            // Contact fields (no '?') first — a custom question is a whole
            // sentence and would push the names that matter off the end.
            usort($names, static fn (string $a, string $b): int => (int) str_contains($a, '?') <=> (int) str_contains($b, '?'));
            $shortNames = array_map(static fn (string $n): string => mb_substr($n, 0, 24), $names);
            $reason     = $rawPhone === ''
                ? 'No usable WhatsApp/phone number (Meta test leads carry dummy values). Fields: ' . implode(', ', $shortNames)
                : "phone_number \"{$rawPhone}\" is not a usable WhatsApp number (Meta test leads carry dummy values).";
            $eventModel->setTenant($tenantId)->update($eventId, [
                'status' => 'failed',
                'error'  => mb_substr($reason, 0, 255),
            ]);
            log_message('warning',
                "meta_lead_fetch: leadgen_id {$event['leadgen_id']} has no phone_number field — "
                . "contact cannot be created. Event marked failed (no retry)."
            );
            return; // Permanent failure — don't throw, don't retry
        }

        // ── The form's questions become custom fields ─────────────────
        // bulkSetForContact() only writes values for fields that already exist,
        // so without this every qualifying answer on the form would be dropped.
        LeadCustomFields::ensure($tenantId, $contactData['custom_fields'] ?? [], $leadData['field_data'] ?? []);

        // ── Dedupe + create/update contact ────────────────────────────
        // Automatically fires lead_created + meta_lead_received (Phase 3 wiring).
        $dedupe = new ContactDedupeService(new ContactModel(), new ContactFieldValueModel());
        $contactData['_attribution'] = \App\Services\Travel\AttributionService::fromMetaLead($leadData + ['id' => $event['leadgen_id']]);
        $result = $dedupe->upsert($tenantId, $contactData);

        // ── Mark event as processed ───────────────────────────────────
        $eventModel->setTenant($tenantId)->update($eventId, [
            'status'     => 'processed',
            'contact_id' => $result['contact_id'],
        ]);
    }
}
