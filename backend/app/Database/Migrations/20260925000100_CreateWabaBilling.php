<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Meta's own WhatsApp billing, one row per month × pricing category × pricing
 * type, pulled from the WABA's pricing_analytics edge. This is what Meta
 * actually charges the customer — TravelPilot's CostEstimator is a guess made
 * from rate cards, and the two should never be confused on a report.
 */
class CreateWabaBilling extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'waba_id'      => ['type' => 'VARCHAR', 'constraint' => 50],
            'month'        => ['type' => 'CHAR', 'constraint' => 7],       // YYYY-MM, in the WABA's timezone as Meta buckets it
            'category'     => ['type' => 'VARCHAR', 'constraint' => 32],   // marketing | utility | authentication | service | unknown
            'pricing_type' => ['type' => 'VARCHAR', 'constraint' => 40],   // regular | free_customer_service | free_entry_point | …
            'volume'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'cost'         => ['type' => 'DECIMAL', 'constraint' => '14,4', 'default' => 0],
            'currency'     => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'INR'],
            'source'       => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'pricing_analytics'],
            'fetched_at'   => ['type' => 'DATETIME', 'null' => true],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'waba_id', 'month', 'category', 'pricing_type'], 'uq_waba_billing_bucket');
        $this->forge->addKey(['tenant_id', 'month']);
        $this->forge->createTable('waba_billing');
    }

    public function down(): void
    {
        $this->forge->dropTable('waba_billing', true);
    }
}
