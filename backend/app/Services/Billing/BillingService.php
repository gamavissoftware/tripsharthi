<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Razorpay subscription billing — full Sprint 6 implementation.
 *
 * ── Write-only-from-webhook invariant ────────────────────────────────────
 * createSubscription() and cancelSubscription() do NOT update tenants.plan or
 * tenants.status.  Those columns are written ONLY by RazorpayWebhookController
 * after a verified X-Razorpay-Signature.  A forged or mis-delivered API call
 * cannot elevate a tenant's plan.
 *
 * ── RAZORPAY_SUBSCRIPTION_CYCLES ─────────────────────────────────────────
 * Razorpay requires a finite total_count.  120 = 12 months × 10 years.
 * When a subscriber reaches cycle 120 (~2036 for someone who started in 2026),
 * Razorpay marks the subscription complete and stops charging.  The customer
 * will need to create a new subscription.  This is a deliberate design choice,
 * not a bug.  Without a finite count, behaviour varies across Razorpay's gateway
 * integrations; a very large finite number is safer than an "unlimited" flag.
 *
 * ── RAZORPAY_MOCK_MODE ───────────────────────────────────────────────────
 * Set RAZORPAY_MOCK_MODE=true in local dev to exercise the full billing flow
 * without hitting the Razorpay API or needing test credentials.  Logs at alert
 * level (same discipline as all other mock modes).  Must be false in production.
 */
class BillingService
{
    /**
     * Razorpay subscription billing cycle ceiling.
     *
     * 120 = 12 monthly cycles × 10 years.  Razorpay requires a finite total_count.
     * After 120 cycles (~2036 for a 2026 subscriber) the subscription completes and
     * the customer re-subscribes.  Documented here so it is never mistaken for a bug.
     */
    public const RAZORPAY_SUBSCRIPTION_CYCLES = 120;

    /**
     * Days a subscription stays 'halted' before subscription:check downgrades
     * the tenant to 'free'.  Mirrors LicenseService::GRACE_PERIOD_DAYS semantics.
     * Overridable via RAZORPAY_HALT_GRACE_DAYS env.
     */
    public const HALT_GRACE_DAYS = 7;

    private const VALID_PLANS = ['starter', 'growth', 'pro'];

    /** Plan amounts in INR (monthly). Annual = monthly × 12 × 0.8 (20% off). */
    private const PLAN_AMOUNTS_MONTHLY = [
        'starter' => 1499,
        'growth'  => 3999,
        'pro'     => 6999,
    ];

    // ------------------------------------------------------------------

    /**
     * Create a Razorpay Order for embedded checkout (no redirect).
     * Returns order_id, amount (paise), currency, key_id, and plan metadata.
     */
    public function createOrder(int $tenantId, string $plan, string $billing = 'monthly', ?string $coupon = null): array
    {
        if (! in_array($plan, self::VALID_PLANS, true)) {
            throw new \InvalidArgumentException("Invalid plan '{$plan}'.");
        }

        $billingType = ($billing === 'annual') ? 'annual' : 'monthly';

        // List price (INR) from env override or the built-in list, then the platform's offers. The SERVER decides the amount:
        // a coupon/promotion is priced here and the Razorpay Order is created for exactly that figure.
        $listPaise   = $this->resolveAmountInr($plan, $billingType) * 100;
        $offers      = new OfferService();
        $quote       = $offers->quote($tenantId, $plan, $billingType, $listPaise, $coupon);   // \DomainException = a typed code that cannot be used
        $amountPaise = $quote['charged_paise'];                                                // Razorpay uses the smallest currency unit
        $amountInr   = (int) round($amountPaise / 100);

        $periodDays = ($billingType === 'annual') ? 365 : 30;
        $receipt    = 'tp_' . $tenantId . '_' . $plan . '_' . time();
        $note       = "TravelPilot {$plan} plan ({$billingType})";

        if ($this->isMockMode()) {
            $orderId = 'order_mock_' . bin2hex(random_bytes(8));
        } else {
            $result  = $this->razorpayPost('/orders', [
                'amount'          => $amountPaise,
                'currency'        => 'INR',
                'receipt'         => $receipt,
                'notes'           => [
                    'tenant_id'    => (string) $tenantId,
                    'plan'         => $plan,
                    'billing_type' => $billingType,
                    'offer'        => (string) ($quote['coupon_code'] ?? ($quote['source'] === 'promotion' ? 'promo-' . $quote['promotion_id'] : '')),
                ],
            ]);
            $orderId = $result['id'];
        }

        // Store a 'created' subscription row linked to this order
        $db = db_connect();
        // Remove any existing 'created' rows for this tenant (unpaid prior attempts)
        $db->table('subscriptions')
           ->where('tenant_id', $tenantId)
           ->where('status', 'created')
           ->delete();

        $db->table('subscriptions')->insert([
            'tenant_id'       => $tenantId,
            'razorpay_sub_id' => $orderId,   // order_id stored here until payment verified
            'plan'            => $plan,
            'amount_paise'    => $amountPaise,
            'billing_cycle'   => $billingType,
            'status'          => 'created',
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
        $offers->reserve($tenantId, (int) $db->insertID(), $plan, $billingType, $quote);

        return [
            'order_id'     => $orderId,
            'amount'       => $amountPaise,
            'amount_inr'   => $amountInr,
            'currency'     => 'INR',
            'key_id'       => env('RAZORPAY_KEY_ID', ''),
            'plan'         => $plan,
            'billing_type' => $billingType,
            'period_days'  => $periodDays,
            'description'  => $note,
            'mock'         => $this->isMockMode(),
            'list_amount'  => $quote['list_paise'],
            'discount'     => $quote['discount_paise'],
            'offer_label'  => $quote['label'],
            'coupon_code'  => $quote['coupon_code'],
            'notice'       => $quote['notice'],
        ];
    }

    /**
     * Verify Razorpay payment signature and activate the subscription.
     * Called after the frontend receives a successful payment callback.
     *
     * Signature formula: HMAC-SHA256( order_id + "|" + payment_id, key_secret )
     */
    public function verifyPayment(
        int $tenantId,
        string $orderId,
        string $paymentId,
        string $signature,
        string $plan,
        string $billingType = 'monthly'
    ): void {
        if (! $this->isMockMode()) {
            $keySecret = env('RAZORPAY_KEY_SECRET', '');
            $expected  = hash_hmac('sha256', $orderId . '|' . $paymentId, $keySecret);

            if (! hash_equals($expected, $signature)) {
                throw new \RuntimeException('Payment signature verification failed. Possible tampering.');
            }
        }

        $db = db_connect();

        // SECURITY: never trust the plan/cycle from the request. The Razorpay
        // signature only covers order_id|payment_id, so a caller could pay for
        // 'starter' and then claim 'pro'. Derive the charged plan from the
        // 'created' subscription row we persisted at createOrder() time (keyed
        // by order_id), and refuse activation if there is no matching order.
        $order = $db->table('subscriptions')
            ->where('tenant_id', $tenantId)
            ->where('razorpay_sub_id', $orderId)
            ->where('status', 'created')
            ->get()->getRowArray();

        if ($order === null) {
            throw new \RuntimeException('No matching pending order for this tenant — refusing to activate.');
        }

        $plan        = $order['plan'];
        $cycle       = ($order['billing_cycle'] === 'annual') ? 'annual' : 'monthly';
        $amountPaise = (int) ($order['amount_paise'] ?: ($this->resolveAmountInr($plan, $cycle) * 100));
        $periodDays  = ($cycle === 'annual') ? 365 : 30;
        $now         = new \DateTime();
        $periodEnd   = (clone $now)->modify("+{$periodDays} days");

        // Activate that exact order row.
        $db->table('subscriptions')
            ->where('id', $order['id'])
            ->update([
                'razorpay_sub_id'      => $paymentId,   // store payment_id as the subscription reference
                'razorpay_customer_id' => $paymentId,
                'amount_paise'         => $amountPaise,
                'billing_cycle'        => $cycle,
                'status'               => 'active',
                'current_period_start' => $now->format('Y-m-d H:i:s'),
                'current_period_end'   => $periodEnd->format('Y-m-d H:i:s'),
                'updated_at'           => $now->format('Y-m-d H:i:s'),
            ]);

        // Update the tenant's plan to the verified, paid-for plan.
        $db->table('tenants')->where('id', $tenantId)->update([
            'plan'       => $plan,
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        // The reserved coupon (if any) now counts as used. The customer has ALREADY paid and the plan is active, so a bookkeeping
        // problem here must never undo or fail the activation - log it loudly instead.
        try { (new OfferService())->confirm($tenantId, (int) $order['id']); }
        catch (\Throwable $e) { log_message('critical', "BillingService: could not record the coupon redemption for tenant#{$tenantId} order row {$order['id']}: " . $e->getMessage()); }

        log_message('info', "BillingService: tenant#{$tenantId} activated plan={$plan} via payment={$paymentId}");
    }

    /**
     * Resolve the charge amount in INR for a plan+cycle: an explicit
     * RAZORPAY_AMOUNT_<PLAN>_<CYCLE> env override if set, else the list price.
     * Single source of truth shared by order creation and payment verification.
     */
    /** List price of a plan/cycle in PAISE (before any offer). */
    public function listPricePaise(string $plan, string $billingType): int
    {
        if (! in_array($plan, self::VALID_PLANS, true)) { throw new \InvalidArgumentException("Invalid plan '{$plan}'."); }
        return $this->resolveAmountInr($plan, $billingType === 'annual' ? 'annual' : 'monthly') * 100;
    }

    private function resolveAmountInr(string $plan, string $billingType): int
    {
        $key = 'RAZORPAY_AMOUNT_' . strtoupper($plan) . '_' . strtoupper($billingType);
        return (int) env($key, $this->getDefaultAmount($plan, $billingType));
    }

    private function getDefaultAmount(string $plan, string $billingType): int
    {
        $monthly = self::PLAN_AMOUNTS_MONTHLY[$plan] ?? 999;
        if ($billingType === 'annual') {
            // 20% discount, rounded to nearest integer
            return (int) round($monthly * 12 * 0.8);
        }
        return $monthly;
    }

    // ------------------------------------------------------------------

    /**
     * Create a Razorpay subscription and store a 'created' row locally.
     * Returns the Razorpay-hosted checkout URL to redirect the user to.
     *
     * tenants.plan is NOT updated here.  It is updated only when Razorpay
     * fires subscription.charged / subscription.activated with a valid signature.
     */
    public function createSubscription(int $tenantId, string $plan): string
    {
        if (! in_array($plan, self::VALID_PLANS, true)) {
            throw new \InvalidArgumentException("Invalid plan '{$plan}'. Must be one of: " . implode(', ', self::VALID_PLANS));
        }

        if ($this->isMockMode()) {
            $subId       = 'sub_mock_' . bin2hex(random_bytes(8));
            $customerId  = 'cust_mock';
            $checkoutUrl = base_url('mock-razorpay-checkout?plan=' . $plan);
        } else {
            $planId = env('RAZORPAY_PLAN_ID_' . strtoupper($plan), '');
            if (empty($planId)) {
                throw new \RuntimeException("RAZORPAY_PLAN_ID_" . strtoupper($plan) . " is not configured.");
            }

            $result = $this->razorpayPost('/subscriptions', [
                'plan_id'     => $planId,
                'total_count' => self::RAZORPAY_SUBSCRIPTION_CYCLES,
                'quantity'    => 1,
            ]);

            $subId       = $result['id'];
            $customerId  = $result['customer_id'] ?? '';
            $checkoutUrl = $result['short_url'];
        }

        db_connect()->table('subscriptions')->insert([
            'tenant_id'            => $tenantId,
            'razorpay_sub_id'      => $subId,
            'razorpay_customer_id' => $customerId,
            'plan'                 => $plan,
            'status'               => 'created',
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        return $checkoutUrl;
    }

    /**
     * Request cancellation of the active subscription at end of current cycle.
     * Local status is updated immediately; tenant plan stays until the webhook fires.
     */
    public function cancelSubscription(int $tenantId): void
    {
        $db  = db_connect();
        $sub = $db->table('subscriptions')
                  ->where('tenant_id', $tenantId)
                  ->whereIn('status', ['active', 'authenticated'])
                  ->orderBy('id', 'DESC')
                  ->limit(1)
                  ->get()
                  ->getRowArray();

        if ($sub === null) {
            throw new \RuntimeException("No active subscription found for tenant #{$tenantId}.");
        }

        if (! $this->isMockMode()) {
            $this->razorpayPost('/subscriptions/' . $sub['razorpay_sub_id'] . '/cancel', [
                'cancel_at_cycle_end' => 1,
            ]);
        }

        $db->table('subscriptions')->where('id', $sub['id'])->update([
            'status'       => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Return the subscription row that best represents the tenant's current state.
     *
     * Prefers the most recent subscription that progressed past checkout. A trailing
     * 'created' row is an abandoned checkout attempt and must not mask a real active
     * (or cancelled) subscription that came before it. Falls back to the latest row
     * only when every row is still 'created'. Returns null when there are none.
     */
    public function getStatus(int $tenantId): ?array
    {
        $db = db_connect();

        $real = $db->table('subscriptions')
            ->where('tenant_id', $tenantId)
            ->where('status !=', 'created')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        if ($real) {
            return $real;
        }

        return $db->table('subscriptions')
            ->where('tenant_id', $tenantId)
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray() ?: null;
    }

    /**
     * Billing history for the tenant: every subscription that reached a paid or
     * post-paid state ('created' rows are abandoned checkouts and are excluded).
     *
     * Amount and cycle come from the recorded charge (amount_paise / billing_cycle).
     * Rows created before those columns existed have neither, so for them the cycle
     * is inferred from the period length (≈365 days → annual, else monthly) and the
     * amount falls back to the current list price — flagged with estimated=true.
     *
     * @return list<array{plan:string,status:string,cycle:string,amount_inr:int,estimated:bool,period_start:?string,period_end:?string,date:?string}>
     */
    public function getHistory(int $tenantId): array
    {
        $rows = db_connect()->table('subscriptions')
            ->where('tenant_id', $tenantId)
            ->where('status !=', 'created')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();

        return array_map(function (array $row): array {
            $hasRecorded = isset($row['amount_paise']) && $row['amount_paise'] !== null;

            if ($hasRecorded) {
                $cycle     = $row['billing_cycle'] ?: $this->inferCycle($row['current_period_start'] ?? null, $row['current_period_end'] ?? null);
                $amountInr = (int) ((int) $row['amount_paise'] / 100);
            } else {
                // Legacy row — reconstruct from period length + current list price.
                $cycle     = $this->inferCycle($row['current_period_start'] ?? null, $row['current_period_end'] ?? null);
                $amountInr = $this->getDefaultAmount($row['plan'], $cycle);
            }

            return [
                'plan'         => $row['plan'],
                'status'       => $row['status'],
                'cycle'        => $cycle,
                'amount_inr'   => $amountInr,
                'estimated'    => ! $hasRecorded,
                'period_start' => $row['current_period_start'] ?? null,
                'period_end'   => $row['current_period_end'] ?? null,
                'date'         => $row['created_at'] ?? null,
            ];
        }, $rows);
    }

    /** Infer 'annual' vs 'monthly' from the stored period length; default monthly. */
    private function inferCycle(?string $start, ?string $end): string
    {
        if ($start === null || $end === null) {
            return 'monthly';
        }
        $days = (strtotime($end) - strtotime($start)) / 86400;
        return $days >= 360 ? 'annual' : 'monthly';
    }

    // ------------------------------------------------------------------

    private function razorpayPost(string $path, array $data): array
    {
        $keyId     = env('RAZORPAY_KEY_ID', '');
        $keySecret = env('RAZORPAY_KEY_SECRET', '');
        $baseUrl   = 'https://api.razorpay.com/v1';

        $ch = curl_init($baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => 10_000,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_USERPWD        => "{$keyId}:{$keySecret}",
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("Razorpay API cURL error: {$err}");
        }

        $parsed = json_decode($resp, true) ?? [];

        if ($code < 200 || $code >= 300) {
            $msg = $parsed['error']['description'] ?? "HTTP {$code}";
            throw new \RuntimeException("Razorpay API error ({$path}): {$msg}");
        }

        return $parsed;
    }

    private function isMockMode(): bool
    {
        $mock = filter_var(env('RAZORPAY_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
        if ($mock) {
            log_message('alert',
                'BillingService: RAZORPAY_MOCK_MODE enabled — '
                . 'no real Razorpay API calls are made. DO NOT use in production.'
            );
        }
        return $mock;
    }
}
