<?php

declare(strict_types=1);

namespace App\Controllers\Webhooks;

use App\Models\IntegrationModel;
use App\Services\Email\InboundEmailService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Inbound email webhook — POST /webhooks/inbound-email/:token
 *
 * A mail provider's inbound-parse webhook (Mailgun / SendGrid / Postmark / etc.)
 * posts a parsed message here. The tenant is resolved from the unguessable
 * per-tenant token in the path (stored as the email_smtp integration's
 * verify_token) — that token is the bearer secret for this endpoint. The body is
 * normalized across the common provider field names. Always returns 200 so the
 * provider doesn't retry-storm; auth/parse failures are logged.
 */
class InboundEmailWebhookController extends Controller
{
    public function receive(?string $token = null): ResponseInterface
    {
        $token = trim((string) $token);
        if ($token === '') {
            return $this->ok();
        }

        $integration = (new IntegrationModel())->findByTypeCrossTenant('email_smtp', 'verify_token', $token);
        if ($integration === null) {
            log_message('info', 'InboundEmailWebhook: unknown token — ignored.');
            return $this->ok();
        }
        $integration = is_array($integration) ? $integration : (array) $integration;
        // findByTypeCrossTenant ignores status/deleted_at — don't ingest into a
        // tenant who has deactivated or removed their email integration.
        if (($integration['status'] ?? 'active') !== 'active' || ! empty($integration['deleted_at'])) {
            return $this->ok();
        }

        [$from, $to, $subject, $body] = $this->normalize();
        if ($from === '') {
            return $this->ok();
        }

        try {
            (new InboundEmailService())->ingest((int) $integration['tenant_id'], $from, $to, $subject, $body);
        } catch (\Throwable $e) {
            log_message('error', 'InboundEmailWebhook: ' . $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * Normalize the common inbound-parse payload shapes (JSON or form-encoded)
     * into [from, to, subject, body]. Providers vary: Mailgun uses
     * sender/recipient/body-html, SendGrid uses from/to/html, Postmark uses
     * From/To/HtmlBody — we accept the union.
     *
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function normalize(): array
    {
        $json = $this->request->getJSON(true);
        $p    = is_array($json) && $json !== [] ? $json : $this->request->getPost();

        $pick = static function (array $src, array $keys): string {
            foreach ($keys as $k) {
                foreach ([$k, strtolower($k), ucfirst($k)] as $variant) {
                    if (isset($src[$variant]) && is_scalar($src[$variant]) && trim((string) $src[$variant]) !== '') {
                        return (string) $src[$variant];
                    }
                }
            }
            return '';
        };

        $from    = $pick($p, ['from', 'sender', 'From']);
        $to      = $pick($p, ['to', 'recipient', 'To']);
        $subject = $pick($p, ['subject', 'Subject']);
        $body    = $pick($p, ['html', 'body-html', 'HtmlBody', 'text', 'body-plain', 'TextBody', 'body']);

        return [$from, $to, $subject, $body];
    }

    private function ok(): ResponseInterface
    {
        return $this->response->setStatusCode(200)->setBody('OK');
    }
}
