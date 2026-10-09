<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Billing\BillingService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Daily subscription halt-grace enforcement — the revenue-leak mirror of license:check.
 *
 * A halted subscription (failed card, Razorpay retrying) must not keep premium limits
 * forever.  This cron downgrades the tenant to 'free' after HALT_GRACE_DAYS.
 *
 * Cron (daily, SaaS mode only):
 *   0 3 * * * /usr/bin/php /path/to/backend/spark subscription:check >> /dev/null 2>&1
 *
 * What it does:
 *   1. Finds subscriptions with status='halted' AND halted_at < now - HALT_GRACE_DAYS.
 *   2. Sets subscriptions.status='downgraded' (distinguishable from user-cancelled).
 *   3. Sets tenants.plan='free' — enforces free limits immediately.
 *   4. Logs the downgrade at warning level.
 *
 * If the customer resolves the payment failure before the cron runs, Razorpay fires
 * subscription.charged which sets status='active' and restores tenants.plan.
 * The cron only acts on subscriptions still halted after the grace window.
 *
 * HALT_GRACE_DAYS: BillingService::HALT_GRACE_DAYS (default 7), overridable via
 * RAZORPAY_HALT_GRACE_DAYS env for operators who want a different window.
 */
class SubscriptionCheck extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'subscription:check';
    protected $description = 'Downgrade tenants with halted subscriptions past grace (cron: daily, SaaS only).';

    public function run(array $params): void
    {
        CLI::write('[subscription:check] Checking for halted subscriptions past grace...', 'cyan');

        $graceDays = (int) env('RAZORPAY_HALT_GRACE_DAYS', BillingService::HALT_GRACE_DAYS);
        $cutoff    = date('Y-m-d H:i:s', time() - $graceDays * 86400);
        $db        = db_connect();

        $halted = $db->table('subscriptions')
                     ->where('status', 'halted')
                     ->where('halted_at <', $cutoff)
                     ->get()
                     ->getResultArray();

        $downgraded = 0;

        foreach ($halted as $sub) {
            $tenantId = (int) $sub['tenant_id'];
            $subId    = (int) $sub['id'];

            // Mark subscription as downgraded (separate from user-initiated cancel)
            $db->table('subscriptions')->where('id', $subId)->update([
                'status'     => 'downgraded',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // Downgrade tenant to free plan immediately
            $db->table('tenants')->where('id', $tenantId)->update([
                'plan'       => 'free',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $downgraded++;

            log_message('warning', sprintf(
                '[subscription:check] Tenant #%d downgraded to free — '
                . 'subscription #%d halted since %s (>%d days, grace=%d days).',
                $tenantId, $subId, $sub['halted_at'], $graceDays, $graceDays
            ));

            CLI::write(
                "[subscription:check] Tenant #{$tenantId} downgraded to free (sub #{$subId} halted since {$sub['halted_at']}).",
                'yellow'
            );
        }

        // Plans given by hand from the platform admin (razorpay_sub_id 'admin-…') end on their own date: back to the free plan.
        $expired = $db->table('subscriptions')->where('status', 'active')->like('razorpay_sub_id', 'admin-', 'after')->where('current_period_end <', date('Y-m-d H:i:s'))->get()->getResultArray();
        foreach ($expired as $sub) {
            $db->table('subscriptions')->where('id', $sub['id'])->update(['status' => 'downgraded', 'updated_at' => date('Y-m-d H:i:s')]);
            $db->table('tenants')->where('id', $sub['tenant_id'])->update(['plan' => 'free', 'updated_at' => date('Y-m-d H:i:s')]);
            $downgraded++;
            CLI::write("[subscription:check] Tenant #{$sub['tenant_id']}: complimentary/manual {$sub['plan']} plan ended — back to free.", 'yellow');
        }

        CLI::write(
            "[subscription:check] Done. {$downgraded} subscription(s) downgraded.",
            $downgraded > 0 ? 'yellow' : 'green'
        );
    }
}
