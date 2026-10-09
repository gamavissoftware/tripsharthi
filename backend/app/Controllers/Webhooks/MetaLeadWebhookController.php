<?php

declare(strict_types=1);

namespace App\Controllers\Webhooks;

use App\Models\IntegrationModel;
use App\Models\MetaLeadEventModel;
use App\Services\Flow\JobDispatcher;
use App\Services\WhatsApp\MetaSignatureVerifier;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Meta Lead Ads webhook — public, no authentication.
 *
 * GET  /webhooks/meta-leads  — Meta verification challenge
 * POST /webhooks/meta-leads  — Receive leadgen events
 *
 * ── Three requirements implemented here ──────────────────────────────
 *
 * 1. MULTI-TENANT RESOLUTION (page_id → tenant):
 *    integrations.page_id is indexed; findByPageId() returns the tenant
 *    in O(1) — the webhook can never cross-contaminate tenants.
 *
 * 2. IDEMPOTENCY (meta_lead_events UNIQUE constraint):
 *    The INSERT into meta_lead_events fails with a unique violation if the
 *    leadgen_id has been seen before.  The exception is caught → return 200.
 *    This prevents duplicate jobs AND duplicate trigger firing on retry.
 *
 * 3. ASYNC FETCH (meta_lead_fetch job):
 *    The Graph API call is NOT made here.  Only the leadgen_id is recorded
 *    and a job is enqueued.  Meta's webhook timeout can't cause retry storms.
 *
 * ── Signature reuse ───────────────────────────────────────────────────
 * Uses MetaSignatureVerifier (same App Secret as WABA webhooks).
 * WEBHOOK_VERIFY_SIGNATURE=false disables verification for local testing.
 */
class MetaLeadWebhookController extends Controller
{
    // GET /webhooks/meta-leads — Meta challenge handshake
    public function verify(): ResponseInterface
    {
        $mode      = $this->request->getGet('hub_mode')         ?? $this->request->getGet('hub.mode');
        $token     = $this->request->getGet('hub_verify_token') ?? $this->request->getGet('hub.verify_token');
        $challenge = $this->request->getGet('hub_challenge')    ?? $this->request->getGet('hub.challenge');

        if ($mode !== 'subscribe' || empty($token) || empty($challenge)) {
            return $this->response->setStatusCode(400)->setBody('Bad Request');
        }

        // Resolve verify_token → integration
        $integration = (new IntegrationModel())->findByVerifyToken($token);
        if ($integration === null) {
            log_message('warning', "MetaLeadWebhook verify: unknown verify_token " . substr($token, 0, 8) . '...');
            return $this->response->setStatusCode(403)->setBody('Forbidden');
        }

        return $this->response
            ->setStatusCode(200)
            ->setContentType('text/plain')
            ->setBody($challenge);
    }

    // POST /webhooks/meta-leads — receive leadgen events
    public function receive(): ResponseInterface
    {
        $rawBody = $this->request->getBody();
        $sig     = $this->request->getHeaderLine('X-Hub-Signature-256');

        // ── Signature verification (shared with WABA webhook) ─────────
        if (! MetaSignatureVerifier::verify($rawBody, $sig)) {
            log_message('error', 'MetaLeadWebhook: X-Hub-Signature-256 mismatch — payload rejected.');
            return $this->response->setStatusCode(200)->setBody('OK'); // return 200 so Meta stops retrying
        }

        $payload = json_decode($rawBody, true);

        if (empty($payload) || ($payload['object'] ?? '') !== 'page') {
            return $this->response->setStatusCode(200)->setBody('OK');
        }

        try {
            foreach ($payload['entry'] ?? [] as $entry) {
                foreach ($entry['changes'] ?? [] as $change) {
                    if (($change['field'] ?? '') !== 'leadgen') continue;

                    $value     = $change['value'] ?? [];
                    $leadgenId = (string) ($value['leadgen_id'] ?? '');
                    $pageId    = (string) ($value['page_id'] ?? $entry['id'] ?? '');

                    if ($leadgenId === '' || $pageId === '') continue;

                    $this->processLeadEvent($pageId, $leadgenId);
                }
            }
        } catch (\Throwable $e) {
            // Never return non-200 — Meta would keep retrying
            log_message('error', 'MetaLeadWebhook processing error: ' . $e->getMessage());
        }

        return $this->response->setStatusCode(200)->setBody('OK');
    }

    // ------------------------------------------------------------------

    /**
     * Resolve page_id → tenant, guard idempotency, enqueue fetch job.
     *
     * Cross-tenant isolation: findByPageId() returns the integration for
     * exactly the tenant that owns this page.  No other tenant's data
     * is touched.
     */
    private function processLeadEvent(string $pageId, string $leadgenId): void
    {
        // ── Multi-tenant resolution: page_id → tenant ─────────────────
        $integration = (new IntegrationModel())->findByPageId($pageId);
        if ($integration === null) {
            log_message('info', "MetaLeadWebhook: unknown page_id {$pageId} — ignored.");
            return;
        }

        $tenantId      = (int) $integration['tenant_id'];
        $integrationId = (int) $integration['id'];

        // ── Idempotency guard: UNIQUE(tenant_id, leadgen_id) ──────────
        // On duplicate: INSERT throws → caught here → return without job.
        // This prevents re-enqueuing on Meta retry AND prevents double
        // trigger firing for already-processed leads.
        try {
            $eventId = (int) (new MetaLeadEventModel())
                ->withoutTenantScope()
                ->insert([
                    'tenant_id'      => $tenantId,
                    'integration_id' => $integrationId,
                    'leadgen_id'     => $leadgenId,
                    'status'         => 'queued',
                ], true);
        } catch (\Throwable $e) {
            log_message('info',
                "MetaLeadWebhook: leadgen_id {$leadgenId} for tenant {$tenantId} already seen "
                . "— skipping retry (idempotent)."
            );
            return;
        }

        // ── Async: enqueue fetch job — webhook returns 200 immediately ─
        // source_type + source_id are stored on the job row so the orphan sweeper
        // can detect a stranded event (this dispatch threw after INSERT committed)
        // via an indexed join — no JSON_EXTRACT on payload needed.
        JobDispatcher::dispatch(
            tenantId:   $tenantId,
            type:       'meta_lead_fetch',
            payload:    ['meta_lead_event_id' => $eventId],
            sourceType: 'meta_lead_events',
            sourceId:   $eventId,
        );
    }
}
