<?php

declare(strict_types=1);

namespace App\Controllers\Webhooks;

use App\Models\ConversationModel;
use App\Models\ContactModel;
use App\Models\MessageModel;
use App\Models\PhoneNumberModel;
use App\Models\WabaAccountModel;
use App\Services\WhatsApp\MetaSignatureVerifier;
use App\Services\WhatsApp\WebhookService;
use App\Services\WhatsApp\WindowService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public Meta webhook endpoints — no authentication.
 *
 * GET  /webhooks/whatsapp  — Meta verification challenge
 * POST /webhooks/whatsapp  — Inbound messages + status callbacks
 *
 * Security: POST payloads are verified against X-Hub-Signature-256
 * using META_APP_SECRET before any processing begins.
 * Set WEBHOOK_VERIFY_SIGNATURE=false ONLY for local curl testing.
 */
class WhatsAppWebhookController extends Controller
{
    // ------------------------------------------------------------------
    // GET /webhooks/whatsapp  — Meta sends this to verify the endpoint
    // ------------------------------------------------------------------

    public function verify(): ResponseInterface
    {
        $mode      = $this->request->getGet('hub_mode')         ?? $this->request->getGet('hub.mode');
        $token     = $this->request->getGet('hub_verify_token') ?? $this->request->getGet('hub.verify_token');
        $challenge = $this->request->getGet('hub_challenge')    ?? $this->request->getGet('hub.challenge');

        if ($mode !== 'subscribe' || empty($token) || empty($challenge)) {
            return $this->response->setStatusCode(400)->setBody('Bad Request');
        }

        // Look up account by verify_token (cross-tenant)
        $account = (new WabaAccountModel())->findByVerifyToken($token);
        if ($account === null) {
            log_message('warning', 'WhatsApp webhook verify: unknown verify_token ' . substr($token, 0, 8) . '...');
            return $this->response->setStatusCode(403)->setBody('Forbidden');
        }

        // Meta requires ONLY the challenge string — no JSON wrapper, no debug toolbar
        return $this->response
            ->setStatusCode(200)
            ->setContentType('text/plain')
            ->setBody($challenge);
    }

    // ------------------------------------------------------------------
    // POST /webhooks/whatsapp  — Inbound messages & status callbacks
    // ------------------------------------------------------------------

    public function receive(): ResponseInterface
    {
        $rawBody = $this->request->getBody();

        // ── Signature verification ────────────────────────────────────
        if (! $this->verifySignature($rawBody)) {
            log_message('error', 'WhatsApp webhook: X-Hub-Signature-256 mismatch — payload rejected.');
            // Return 200 to prevent Meta from retrying a legitimately rejected payload
            return $this->response->setStatusCode(200)->setBody('OK');
        }

        $payload = json_decode($rawBody, true);

        if (empty($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account') {
            return $this->response->setStatusCode(200)->setBody('OK');
        }

        try {
            $service = new WebhookService(
                new ConversationModel(),
                new MessageModel(),
                new ContactModel(),
                new PhoneNumberModel(),
                new WindowService(new ConversationModel()),
            );
            $processed = $service->handle($payload);
            log_message('debug', "WhatsApp webhook: processed {$processed} message(s).");
        } catch (\Throwable $e) {
            // Never return non-200 — Meta would keep retrying
            log_message('error', 'WhatsApp webhook processing error: ' . $e->getMessage() . ' ' . $e->getTraceAsString());
        }

        return $this->response->setStatusCode(200)->setBody('OK');
    }

    // ------------------------------------------------------------------
    // Signature verification — delegates to shared MetaSignatureVerifier
    // ------------------------------------------------------------------

    private function verifySignature(string $rawBody): bool
    {
        $sig = $this->request->getHeaderLine('X-Hub-Signature-256');
        return MetaSignatureVerifier::verify($rawBody, $sig);
    }
}
