<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Remember WHAT offer an order used, on the order itself: the list price, the discount and (for an automatic promotion) which one. Coupons were
 * already tracked in `coupon_redemptions`; promotions were not, so the admin could not see how much a promotion brought in or cost.
 * NULL = the order predates this (or used no offer).
 */
class AddOfferToSubscriptions extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('subscriptions', [
            'list_paise'     => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'discount_paise' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'promotion_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
        ]);
    }

    public function down(): void { $this->forge->dropColumn('subscriptions', ['list_paise', 'discount_paise', 'promotion_id']); }
}
