<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Models\GoogleLeadEventModel;
use App\Services\Leads\ContactDedupeService;
use App\Services\Leads\GoogleLeadMapper;

/**
 * Handles 'google_lead_process' jobs — the second stage of the Google Ads Lead
 * Forms pipeline.
 *
 * ── Pipeline (webhook → job → this handler) ──────────────────────────
 *
 * 1. Webhook arrives → google_key verified → lead_id + full inline payload
 *    inserted into google_lead_events (idempotency guard) → job dispatched →
 *    200 returned.
 *
 * 2. THIS HANDLER (runs in the worker):
 *    a. Load google_lead_events row (skip if already processed)
 *    b. Decode the stored inline payload (Google pushes the full lead — NO fetch)
 *    c. Map user_column_data → contact array (GoogleLeadMapper)
 *    d. If no phone number: mark event 'failed', return (no retry needed)
 *    e. ContactDedupeService::upsert() → creates/updates contact
 *       ↳ Automatically fires lead_created + google_lead_received
 *    f. Mark event 'processed', store contact_id
 *
 * ── Durability ────────────────────────────────────────────────────────
 * Unlike the Meta handler there is no external Graph API call, so steps (a–c)
 * cannot fail transiently. Step (e) is idempotent (dedupe on wa_number); step
 * (f) is the final commit. A retry after a partial failure is safe.
 */
class GoogleLeadProcessHandler
{
    public function handle(array $job, int $tenantId): void
    {
        $payload = json_decode($job['payload'] ?? '{}', true) ?: [];
        $eventId = (int) ($payload['google_lead_event_id'] ?? 0);

        if ($eventId <= 0 || $tenantId <= 0) {
            throw new \RuntimeException('google_lead_process job missing google_lead_event_id or tenant_id.');
        }

        // ── Load event row ────────────────────────────────────────────
        $eventModel = new GoogleLeadEventModel();
        $event      = $eventModel->setTenant($tenantId)->find($eventId);

        if ($event === null) {
            log_message('warning', "google_lead_process: event #{$eventId} not found, skipping.");
            return;
        }
        if ($event['status'] === 'processed') {
            log_message('info', "google_lead_process: event #{$eventId} already processed, skipping (idempotent).");
            return;
        }

        // ── Resolve integration → default country code ────────────────
        $integrationModel = new \App\Models\IntegrationModel();
        $integration      = $integrationModel->withoutTenantScope()->find((int) $event['integration_id']);

        if ($integration === null) {
            $eventModel->setTenant($tenantId)->update($eventId, ['status' => 'failed']);
            log_message('error', "google_lead_process: integration #{$event['integration_id']} not found.");
            return;
        }
        $countryCode = $integrationModel->getDefaultCountryCode($integration);

        // ── Decode the stored inline payload (no external fetch) ──────
        $stored  = json_decode($event['payload'] ?? '{}', true) ?: [];
        $columns = $stored['user_column_data'] ?? [];

        // ── Map fields ────────────────────────────────────────────────
        $contactData = GoogleLeadMapper::map($columns, $countryCode);

        // ── Guard: phone number required ──────────────────────────────
        if (empty($contactData['wa_number'])) {
            $eventModel->setTenant($tenantId)->update($eventId, ['status' => 'failed']);
            log_message('warning',
                "google_lead_process: lead_id {$event['lead_id']} has no phone column — "
                . 'contact cannot be created. Event marked failed (no retry).'
            );
            return; // Permanent failure — don't throw, don't retry
        }

        // ── Dedupe + create/update contact ────────────────────────────
        // Automatically fires lead_created + google_lead_received.
        $dedupe = new ContactDedupeService(new ContactModel(), new ContactFieldValueModel());
        $contactData['_attribution'] = \App\Services\Travel\AttributionService::fromGoogleLead($stored + ['lead_id' => $event['lead_id'] ?? '']);
        $result = $dedupe->upsert($tenantId, $contactData);

        // ── Mark event as processed ───────────────────────────────────
        $eventModel->setTenant($tenantId)->update($eventId, [
            'status'     => 'processed',
            'contact_id' => $result['contact_id'],
        ]);
    }
}
