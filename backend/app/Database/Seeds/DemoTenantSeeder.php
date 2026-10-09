<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Creates the demo tenant (id=1, slug=demo-company).
 *
 * Safe to run multiple times — skips if the tenant already exists.
 * Works in both APP_MODE=saas and APP_MODE=self_hosted.
 */
class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        $existing = $this->db->table('tenants')->where('id', 1)->get()->getRow();

        if ($existing !== null) {
            CLI::write('DemoTenantSeeder: tenant id=1 already exists, skipping.', 'yellow');
            return;
        }

        $this->db->table('tenants')->insert([
            'id'         => 1,
            'name'       => 'Demo Company',
            'slug'       => 'demo-company',
            'plan'       => 'pro',
            'status'     => 'active',
            'mode'       => env('APP_MODE', 'saas'),
            'settings'   => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        CLI::write('DemoTenantSeeder: created tenant id=1 "Demo Company".', 'green');

        // ── Dev-only: seed a subscription row so billing UX is exercisable locally ──
        // Guard: CI_ENVIRONMENT=development — this block NEVER runs in production
        // (production .env has CI_ENVIRONMENT=production by default).
        // In production, subscriptions are created via the Razorpay checkout flow
        // and activated by the subscription.charged webhook.
        if (env('CI_ENVIRONMENT', 'production') === 'development') {
            $existingSub = $this->db->table('subscriptions')->where('tenant_id', 1)->get()->getRow();
            if ($existingSub === null) {
                $this->db->table('subscriptions')->insert([
                    'tenant_id'            => 1,
                    'razorpay_sub_id'      => 'sub_dev_demo_local',
                    'razorpay_customer_id' => 'cust_dev_demo',
                    'plan'                 => 'pro',
                    // Annual cycle to match the +1 year period below; list price in paise
                    // (₹6,999/mo × 12 × 0.8 = ₹67,190/yr) so billing history shows a real charge.
                    'amount_paise'         => 6719000,
                    'billing_cycle'        => 'annual',
                    'status'               => 'active',
                    'current_period_start' => date('Y-m-d H:i:s'),
                    'current_period_end'   => date('Y-m-d H:i:s', strtotime('+1 year')),
                    'created_at'           => date('Y-m-d H:i:s'),
                    'updated_at'           => date('Y-m-d H:i:s'),
                ]);
                CLI::write(
                    'DemoTenantSeeder: seeded dev subscription (plan=pro, status=active). '
                    . 'CI_ENVIRONMENT=development ONLY — never runs in production.',
                    'yellow'
                );
            }
        }
    }
}
