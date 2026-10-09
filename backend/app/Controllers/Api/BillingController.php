<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Billing\BillingService;
use App\Services\Tenancy\FeatureGate;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Subscription management — SaaS mode only.
 *
 * POST /api/v1/billing/subscribe   — create a Razorpay subscription
 * POST /api/v1/billing/cancel      — cancel at end of current cycle
 * GET  /api/v1/billing/status      — current subscription state
 *
 * All endpoints gate on FeatureGate::isBillingEnabled() → 403 in self_hosted.
 * tenants.plan is NEVER written here — only the Razorpay webhook does that.
 */
class BillingController extends ResourceController
{
    protected $format = 'json';

    // POST /api/v1/billing/subscribe
    public function subscribe(): ResponseInterface
    {
        if (! FeatureGate::isBillingEnabled()) {
            return $this->fail('Billing is not available in self-hosted mode.', 403);
        }

        $rules = ['plan' => 'required|in_list[starter,growth,pro]'];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $tenantId = CurrentUser::tenantId();
        $plan     = $this->request->getJsonVar('plan');

        try {
            $checkoutUrl = (new BillingService())->createSubscription($tenantId, $plan);
        } catch (\Exception $e) {
            log_message('error', "BillingController::subscribe tenant#{$tenantId}: " . $e->getMessage());
            return $this->fail('Failed to create subscription. Please try again.', 500);
        }

        return $this->respond([
            'success'      => true,
            'checkout_url' => $checkoutUrl,
            'message'      => 'Redirect the user to checkout_url to complete payment.',
        ], 201);
    }

    // POST /api/v1/billing/cancel
    public function cancel(): ResponseInterface
    {
        if (! FeatureGate::isBillingEnabled()) {
            return $this->fail('Billing is not available in self-hosted mode.', 403);
        }

        $tenantId = CurrentUser::tenantId();

        try {
            (new BillingService())->cancelSubscription($tenantId);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (\Exception $e) {
            log_message('error', "BillingController::cancel tenant#{$tenantId}: " . $e->getMessage());
            return $this->fail('Failed to cancel subscription. Please try again.', 500);
        }

        return $this->respond([
            'success' => true,
            'message' => 'Subscription will cancel at the end of the current billing cycle.',
        ]);
    }

    // POST /api/v1/billing/create-order
    // Creates a Razorpay Order for the embedded checkout flow.
    // Returns order_id + key_id — the secret never leaves the backend.
    public function createOrder(): ResponseInterface
    {
        if (! FeatureGate::isBillingEnabled()) {
            return $this->fail('Billing is not available in self-hosted mode.', 403);
        }

        $rules = [
            'plan'    => 'required|in_list[starter,growth,pro]',
            'billing' => 'permit_empty|in_list[monthly,annual]',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $tenantId = CurrentUser::tenantId();
        $plan     = $this->request->getJsonVar('plan');
        $billing  = $this->request->getJsonVar('billing') ?? 'monthly';

        try {
            $order = (new BillingService())->createOrder($tenantId, $plan, $billing);
        } catch (\Exception $e) {
            log_message('error', "BillingController::createOrder tenant#{$tenantId}: " . $e->getMessage());
            return $this->fail('Failed to create order: ' . $e->getMessage(), 500);
        }

        return $this->respond(['success' => true, 'data' => $order]);
    }

    // POST /api/v1/billing/verify-payment
    // Verifies Razorpay payment signature and activates the subscription.
    public function verifyPayment(): ResponseInterface
    {
        if (! FeatureGate::isBillingEnabled()) {
            return $this->fail('Billing is not available in self-hosted mode.', 403);
        }

        $rules = [
            'razorpay_order_id'   => 'required',
            'razorpay_payment_id' => 'required',
            'razorpay_signature'  => 'required',
            'plan'                => 'required|in_list[starter,growth,pro]',
            'billing'             => 'permit_empty|in_list[monthly,annual]',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $tenantId  = CurrentUser::tenantId();
        $body      = $this->request->getJSON(true);

        try {
            (new BillingService())->verifyPayment(
                $tenantId,
                $body['razorpay_order_id'],
                $body['razorpay_payment_id'],
                $body['razorpay_signature'],
                $body['plan'],
                $body['billing'] ?? 'monthly'
            );
        } catch (\RuntimeException $e) {
            log_message('error', "BillingController::verifyPayment tenant#{$tenantId}: " . $e->getMessage());
            return $this->fail($e->getMessage(), 422);
        } catch (\Exception $e) {
            log_message('error', "BillingController::verifyPayment tenant#{$tenantId}: " . $e->getMessage());
            return $this->fail('Payment verification failed.', 500);
        }

        return $this->respond([
            'success' => true,
            'message' => 'Payment verified. Your plan has been activated.',
            'plan'    => $body['plan'],
        ]);
    }

    // GET /api/v1/billing/status
    public function status(): ResponseInterface
    {
        if (! FeatureGate::isBillingEnabled()) {
            return $this->fail('Billing is not available in self-hosted mode.', 403);
        }

        $tenantId = CurrentUser::tenantId();
        $sub      = (new BillingService())->getStatus($tenantId);

        return $this->respond([
            'success' => true,
            'data'    => $sub ? [
                'plan'                => $sub['plan'],
                'status'              => $sub['status'],
                'current_period_end'  => $sub['current_period_end'],
            ] : null,
        ]);
    }

    // GET /api/v1/billing/history
    public function history(): ResponseInterface
    {
        if (! FeatureGate::isBillingEnabled()) {
            return $this->fail('Billing is not available in self-hosted mode.', 403);
        }

        $tenantId = CurrentUser::tenantId();
        $history  = (new BillingService())->getHistory($tenantId);

        return $this->respond([
            'success' => true,
            'data'    => $history,
        ]);
    }
}
