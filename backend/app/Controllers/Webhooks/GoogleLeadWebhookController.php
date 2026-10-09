<?php

declare(strict_types=1);

namespace App\Controllers\Webhooks;

use App\Models\GoogleLeadEventModel;
use App\Models\IntegrationModel;
use App\Services\Flow\JobDispatcher;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Google Ads Lead Forms webhook — public, no session authentication.
 *
 * POST /webhooks/google-leads  — Receive lead submissions
 *
 * ── How Google differs from Meta ─────────────────────────────────────
 * Google pushes the FULL lead inline in the webhook body (no OAuth, no Graph
 * API fetch). Each delivery carries a shared secret `google_key` that the
 * business pastes into the Google Ads lead-form delivery settings AND that we
 * generated when the integration was connected. There is no GET challenge.
 *
 * ── Three requirements implemented here ──────────────────────────────
 *
 * 1. AUTH + MULTI-TENANT RESOLUTION (google_key → tenant):
 *    integrations.verify_token stores the per-tenant google_key (indexed via the
 *    type+token lookup). findByGoogleKey() both authenticates the request and
 *    resolves the owning tenant — a request with an unknown key is rejected.
 *
 * 2. IDEMPOTENCY (google_lead_events UNIQUE(tenant_id, lead_id)):
 *    The INSERT fails on a duplicate lead_id → caught → return 200. Prevents
 *    duplicate jobs AND duplicate trigger firing on Google's retries.
 *
 * 3. ASYNC PROCESSING (google_lead_process job):
 *    The inline payload is stored on the event row and a job is enqueued; the
 *    mapping + contact upsert + trigger firing happen in the worker so the
 *    webhook returns 200 immediately (Google's timeout can't cause retry storms).
 */
class GoogleLeadWebhookController extends Controller
{
    // POST /webhooks/google-leads — receive a lead submission
    public function receive(): ResponseInterface
    {
        $payload = json_decode($this->request->getBody() ?? '', true);

        if (empty($payload) || ! is_array($payload)) {
            return $this->ok();
        }

        // ── Auth + tenant resolution via shared google_key ────────────
        $googleKey = (string) ($payload['google_key'] ?? '');
        if ($googleKey === '') {
            log_message('warning', 'GoogleLeadWebhook: payload missing google_key — rejected.');
            return $this->ok();
        }

        $integration = (new IntegrationModel())->findByGoogleKey($googleKey);
        if ($integration === null) {
            log_message('warning', 'GoogleLeadWebhook: unknown google_key ' . substr($googleKey, 0, 8) . '… — rejected.');
            return $this->ok();
        }

        // ── Google's "Send test data" ping — ack without storing ──────
        // Google posts a connectivity check with is_test=true and placeholder
        // values. Acknowledge it so the form validates, but create no contact.
        if (! empty($payload['is_test'])) {
            log_message('info', 'GoogleLeadWebhook: received is_test ping — acknowledged.');
            return $this->ok();
        }

        $leadId = (string) ($payload['lead_id'] ?? '');
        if ($leadId === '') {
            log_message('warning', 'GoogleLeadWebhook: payload missing lead_id — ignored.');
            return $this->ok();
        }

        try {
            $this->processLead($integration, $leadId, $payload);
        } catch (\Throwable $e) {
            // Never return non-200 — Google would keep retrying.
            log_message('error', 'GoogleLeadWebhook processing error: ' . $e->getMessage());
        }

        return $this->ok();
    }

    // ------------------------------------------------------------------

    /**
     * Guard idempotency (UNIQUE tenant_id+lead_id), persist the inline payload,
     * enqueue the process job. Cross-tenant isolation: the integration was
     * resolved from the google_key, so we only ever touch its owning tenant.
     */
    private function processLead(array|object $integration, string $leadId, array $payload): void
    {
        $integration   = (array) $integration;
        $tenantId      = (int) $integration['tenant_id'];
        $integrationId = (int) $integration['id'];

        // Persist only the parts the worker needs (keeps the row lean).
        $stored = json_encode([
            'user_column_data' => $payload['user_column_data'] ?? [],
            'form_id'          => $payload['form_id']     ?? null,
            'campaign_id'      => $payload['campaign_id'] ?? null,
        ]);

        // ── Idempotency guard: UNIQUE(tenant_id, lead_id) ─────────────
        try {
            $eventId = (int) (new GoogleLeadEventModel())
                ->withoutTenantScope()
                ->insert([
                    'tenant_id'      => $tenantId,
                    'integration_id' => $integrationId,
                    'lead_id'        => $leadId,
                    'payload'        => $stored,
                    'status'         => 'queued',
                ], true);
        } catch (\Throwable $e) {
            log_message('info',
                "GoogleLeadWebhook: lead_id {$leadId} for tenant {$tenantId} already seen "
                . '— skipping retry (idempotent).'
            );
            return;
        }

        // ── Async: enqueue process job — webhook returns 200 immediately ─
        // source_type + source_id let the orphan sweeper rescue a stranded event
        // (dispatch threw after the INSERT committed) via an indexed join.
        JobDispatcher::dispatch(
            tenantId:   $tenantId,
            type:       'google_lead_process',
            payload:    ['google_lead_event_id' => $eventId],
            sourceType: 'google_lead_events',
            sourceId:   $eventId,
        );
    }

    /** Google expects a 200 for every delivery; any other code triggers retries. */
    private function ok(): ResponseInterface
    {
        return $this->response->setStatusCode(200)->setContentType('text/plain')->setBody('OK');
    }
}
