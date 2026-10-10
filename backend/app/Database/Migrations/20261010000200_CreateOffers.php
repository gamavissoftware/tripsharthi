<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Offers (platform level - no tenant_id on coupons/promotions; they are TripSarthi's own price rules).
 *  - coupons: a code a customer types at checkout. Money is integer PAISE; percent is 1-90.
 *    duration_periods: 1 = first payment only, N = first N payments, 0 = every renewal.
 *    partner_id is reserved for the partner program (no FK until that table exists).
 *  - coupon_redemptions: 'pending' when the order is created, 'applied' once the payment is verified, 'void' if abandoned.
 *  - promotions: time-boxed percent-off on chosen plans, applied automatically (no code).
 *  - offer_grants: free trial days / period extensions given by the team (the audit trail next to audit_logs).
 */
class CreateOffers extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'code'             => ['type' => 'VARCHAR', 'constraint' => 30],
            'description'      => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true, 'default' => null],
            'type'             => ['type' => 'ENUM', 'constraint' => ['percent', 'flat'], 'default' => 'percent'],
            'value'            => ['type' => 'BIGINT', 'unsigned' => true],                        // percent (1-90) or paise
            'plans'            => ['type' => 'JSON', 'null' => true],                              // null = every plan
            'cycle'            => ['type' => 'ENUM', 'constraint' => ['any', 'monthly', 'annual'], 'default' => 'any'],
            'duration_periods' => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 1],
            'max_redemptions'  => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'default' => null],
            'max_per_tenant'   => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 1],
            'new_customers_only' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'starts_at'        => ['type' => 'DATETIME', 'null' => true],
            'expires_at'       => ['type' => 'DATETIME', 'null' => true],
            'partner_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'active'           => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'redeemed_count'   => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'created_by'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('code');
        $this->forge->addKey(['active', 'expires_at']);
        $this->forge->createTable('coupons');

        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'coupon_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'tenant_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'subscription_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],   // the order row the discount belongs to
            'partner_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'plan'            => ['type' => 'VARCHAR', 'constraint' => 20],
            'cycle'           => ['type' => 'VARCHAR', 'constraint' => 10],
            'list_paise'      => ['type' => 'BIGINT', 'unsigned' => true],
            'discount_paise'  => ['type' => 'BIGINT', 'unsigned' => true],
            'charged_paise'   => ['type' => 'BIGINT', 'unsigned' => true],
            'status'          => ['type' => 'ENUM', 'constraint' => ['pending', 'applied', 'void'], 'default' => 'pending'],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'applied_at'      => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['coupon_id', 'status']);
        $this->forge->addKey(['tenant_id', 'coupon_id', 'status']);
        $this->forge->addKey('subscription_id');
        $this->forge->createTable('coupon_redemptions');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 120],
            'badge'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'default' => null],       // short label shown to customers, e.g. "Festive 20% off"
            'percent'    => ['type' => 'TINYINT', 'unsigned' => true],
            'plans'      => ['type' => 'JSON', 'null' => true],
            'cycle'      => ['type' => 'ENUM', 'constraint' => ['any', 'monthly', 'annual'], 'default' => 'any'],
            'starts_at'  => ['type' => 'DATETIME'],
            'ends_at'    => ['type' => 'DATETIME'],
            'active'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['active', 'starts_at', 'ends_at']);
        $this->forge->createTable('promotions');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'kind'       => ['type' => 'ENUM', 'constraint' => ['trial', 'extension']],
            'plan'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'default' => null],
            'days'       => ['type' => 'SMALLINT', 'unsigned' => true],
            'reason'     => ['type' => 'VARCHAR', 'constraint' => 300],
            'period_end_before' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'period_end_after'  => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'granted_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('tenant_id');
        $this->forge->createTable('offer_grants');
    }

    public function down(): void
    {
        foreach (['offer_grants', 'promotions', 'coupon_redemptions', 'coupons'] as $t) { $this->forge->dropTable($t, true); }
    }
}
