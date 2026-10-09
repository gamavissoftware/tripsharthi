<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Multi-currency, with INR as the ONLY accounting currency (GST, TCS, invoices, receipts, reports stay in INR).
 * Foreign currencies are used for (1) supplier COSTS and what we owe/pay suppliers, and (2) an indicative equivalent shown to the customer.
 * Foreign amounts are integer MINOR units of that currency (cents, fils…); rates are INR per ONE major unit with 8 decimals (IDR is ~0.005).
 */
class AddMultiCurrency extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'currency'   => ['type' => 'CHAR', 'constraint' => 3],
            'rate'       => ['type' => 'DECIMAL', 'constraint' => '18,8'],                 // INR per 1 unit of `currency`, mid-market
            'buffer_pct' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 2],   // added to COSTS only, to cover FX movement between quote and payment
            'source'     => ['type' => 'ENUM', 'constraint' => ['manual', 'auto'], 'default' => 'manual'],   // manual = never overwritten by the daily refresh
            'as_of'      => ['type' => 'DATETIME', 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'currency']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('fx_rates');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'currency'    => ['type' => 'CHAR', 'constraint' => 3],
            'rate'        => ['type' => 'DECIMAL', 'constraint' => '18,8'],
            'source'      => ['type' => 'VARCHAR', 'constraint' => 20],
            'recorded_at' => ['type' => 'DATETIME', 'null' => true],
            'recorded_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'currency', 'recorded_at']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('fx_rate_history');

        // A quote line priced in a foreign currency: unit_cost (INR paise) is DERIVED from unit_cost_fx at fx_rate (buffer included) and then
        // everything downstream (markup, GST, TCS, invoices) works unchanged. The rate is locked on the line so the quote total does not drift.
        $this->forge->addColumn('itinerary_items', [
            'cost_currency' => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'INR', 'after' => 'rate_id'],
            'unit_cost_fx'  => ['type' => 'BIGINT', 'null' => true, 'default' => null, 'after' => 'cost_currency'],
            'fx_rate'       => ['type' => 'DECIMAL', 'constraint' => '18,8', 'null' => true, 'default' => null, 'after' => 'unit_cost_fx'],
        ]);
        // Indicative currency shown to the customer next to the INR total (display only — the customer still pays in INR).
        $this->forge->addColumn('itineraries', [
            'display_currency' => ['type' => 'CHAR', 'constraint' => 3, 'null' => true, 'default' => null],
        ]);
        // What we OWE the supplier, tracked in the supplier's currency; cost_amount stays the INR figure margin is computed from.
        $this->forge->addColumn('booking_services', [
            'cost_currency' => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'INR'],
            'cost_fx'       => ['type' => 'BIGINT', 'null' => true, 'default' => null],
            'paid_fx'       => ['type' => 'BIGINT', 'default' => 0],
            'fx_rate'       => ['type' => 'DECIMAL', 'constraint' => '18,8', 'null' => true, 'default' => null],
            'cost_quoted_inr' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'default' => null],   // the INR cost at quote time, kept once the real INR paid replaces cost_amount
            'fx_variance'   => ['type' => 'BIGINT', 'null' => true, 'default' => null],                          // actual INR paid - quoted INR, set when the service is fully paid (+ = we paid more)
        ]);
        $this->forge->addColumn('supplier_payments', [
            'fx_currency' => ['type' => 'CHAR', 'constraint' => 3, 'null' => true, 'default' => null],
            'fx_amount'   => ['type' => 'BIGINT', 'null' => true, 'default' => null],
            'fx_rate'     => ['type' => 'DECIMAL', 'constraint' => '18,8', 'null' => true, 'default' => null],   // realised INR per unit = INR debited / foreign paid
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('supplier_payments', ['fx_currency', 'fx_amount', 'fx_rate']);
        $this->forge->dropColumn('booking_services', ['cost_currency', 'cost_fx', 'paid_fx', 'fx_rate', 'cost_quoted_inr', 'fx_variance']);
        $this->forge->dropColumn('itineraries', 'display_currency');
        $this->forge->dropColumn('itinerary_items', ['cost_currency', 'unit_cost_fx', 'fx_rate']);
        $this->forge->dropTable('fx_rate_history', true);
        $this->forge->dropTable('fx_rates', true);
    }
}
