<?php

declare(strict_types=1);

namespace App\Controllers\Webhooks;

use App\Services\Billing\BillingService;
use App\Services\Billing\RazorpaySignatureVerifier;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Razorpay subscription webhook — POST /webhooks/razorpay
 *
 * Public endpoint, no session/auth.  Every response is 200 so Razorpay
 * does not retry (same pattern as Meta webhooks).
 *
 * ── THE write-only-from-webhook invariant ────────────────────────────────
 * tenants.plan and tenants.status are updated INSIDE handleCharged(),
 * handleActivated(), and handleCancelled() — AFTER the signature is verified.
 * NO other controller or service writes those columns.  A forged webhook
 * without a valid X-Razorpay-Signature never reaches any handler.
 *
 * ── halted handling ───────────────────────────────────────────────────────
 * subscription.halted: sets subscriptions.halted_at but does NOT change
 * tenants.plan.  The subscription:check cron downgrades the tenant after
 * BillingService::HALT_GRACE_DAYS if the subscription remains halted.
 * This mirrors the LicenseService grace-period approach for payment failures.
 */
class RazorpayWebhookController extends Controller
{
    public function receive(): ResponseInterface
    {
        $rawBody = $this->request->getBody() ?? '';
        $sig     = $this->request->getHeaderLine('X-Razorpay-Signature');

        // ── Signature verification — the ONLY gate before any DB write ──
        if (! RazorpaySignatureVerifier::verify($rawBody, $sig)) {
            log_message('error', 'RazorpayWebhook: X-Razorpay-Signature mismatch — payload rejected.');
            return $this->response->setStatusCode(200)->setBody('OK');
        }

        $payload = json_decode($rawBody, true);
        $event   = $payload['event'] ?? '';

        $subEntity = $payload['payload']['subscription']['entity'] ?? null;
        if (! $subEntity) {
            return $this->response->setStatusCode(200)->setBody('OK');
        }

        $razorpaySubId = $subEntity['id'] ?? '';
        $db            = db_connect();

        $sub = $db->table('subscriptions')
                  ->where('razorpay_sub_id', $razorpaySubId)
                  ->get()->getRowArray();

        if ($sub === null) {
            log_message('info', "RazorpayWebhook: unknown razorpay_sub_id '{$razorpaySubId}' — ignored (may be a test event).");
            return $this->response->setStatusCode(200)->setBody('OK');
        }

        try {
            match ($event) {
                'subscription.charged'       => $this->handleCharged($db, $sub, $subEntity),
                'subscription.activated'     => $this->handleActivated($db, $sub),
                'subscription.authenticated' => $this->handleAuthenticated($db, $sub),
                'subscription.halted'        => $this->handleHalted($db, $sub),
                'subscription.cancelled'     => $this->handleCancelled($db, $sub),
                default                      => log_message('info', "RazorpayWebhook: unhandled event '{$event}' — no action."),
            };
        } catch (\Throwable $e) {
            log_message('error', "RazorpayWebhook: error handling event '{$event}': " . $e->getMessage());
        }

        return $this->response->setStatusCode(200)->setBody('OK');
    }

    // ------------------------------------------------------------------
    // Event handlers — all write DB only, never call Razorpay API
    // ------------------------------------------------------------------

    /**
     * Payment succeeded — activate the subscription for this billing cycle.
     * This is the primary path that updates tenants.plan and tenants.status.
     * Timestamps are PHP-parsed from the webhook payload — never MySQL NOW().
     */
    private function handleCharged(mixed $db, array $sub, array $entity): void
    {
        $periodStart = isset($entity['current_start'])
            ? date('Y-m-d H:i:s', (int) $entity['current_start'])
            : null;
        $periodEnd = isset($entity['current_end'])
            ? date('Y-m-d H:i:s', (int) $entity['current_end'])
            : null;

        $db->table('subscriptions')->where('id', $sub['id'])->update([
            'status'               => 'active',
            'current_period_start' => $periodStart,
            'current_period_end'   => $periodEnd,
            'halted_at'            => null,
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        // ── Write-only-from-webhook: tenants.plan + tenants.status ──────
        $db->table('tenants')->where('id', $sub['tenant_id'])->update([
            'plan'       => $sub['plan'],
            'status'     => 'active',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        log_message('info',
            "RazorpayWebhook: subscription.charged — tenant #{$sub['tenant_id']} "
            . "active on plan '{$sub['plan']}', period_end={$periodEnd}."
        );
    }

    /** Mandate confirmed; first charge may not have happened yet. */
    private function handleActivated(mixed $db, array $sub): void
    {
        $db->table('subscriptions')->where('id', $sub['id'])->update([
            'status'     => 'active',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        // Write-only-from-webhook
        $db->table('tenants')->where('id', $sub['tenant_id'])->update([
            'plan'       => $sub['plan'],
            'status'     => 'active',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        log_message('info', "RazorpayWebhook: subscription.activated — tenant #{$sub['tenant_id']}.");
    }

    /** Mandate setup initiated; payment has NOT yet succeeded. */
    private function handleAuthenticated(mixed $db, array $sub): void
    {
        $db->table('subscriptions')->where('id', $sub['id'])->update([
            'status'     => 'authenticated',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        // tenants row untouched — plan/status only set on confirmed charge
        log_message('info', "RazorpayWebhook: subscription.authenticated — tenant #{$sub['tenant_id']} (payment pending).");
    }

    /**
     * Payment failed; Razorpay will retry.
     * tenants.plan is NOT changed here — the subscription:check cron will
     * downgrade after BillingService::HALT_GRACE_DAYS if still halted.
     */
    private function handleHalted(mixed $db, array $sub): void
    {
        $db->table('subscriptions')->where('id', $sub['id'])->update([
            'status'     => 'halted',
            'halted_at'  => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        // Intentionally NOT writing tenants.plan — grace window is HALT_GRACE_DAYS
        log_message('warning',
            "RazorpayWebhook: subscription.halted — tenant #{$sub['tenant_id']}. "
            . 'Will downgrade to free after ' . BillingService::HALT_GRACE_DAYS . ' days if not resolved.'
        );
    }

    /**
     * Subscription definitively cancelled.
     * Downgrade tenant to free immediately — no more billing cycles expected.
     */
    private function handleCancelled(mixed $db, array $sub): void
    {
        $db->table('subscriptions')->where('id', $sub['id'])->update([
            'status'       => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
        // Write-only-from-webhook: downgrade to free
        $db->table('tenants')->where('id', $sub['tenant_id'])->update([
            'plan'       => 'free',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        log_message('info', "RazorpayWebhook: subscription.cancelled — tenant #{$sub['tenant_id']} downgraded to free.");
    }
}
