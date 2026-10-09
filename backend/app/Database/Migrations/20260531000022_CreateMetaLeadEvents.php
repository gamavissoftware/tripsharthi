<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tracks every Meta Lead Ad lead received, serving two purposes:
 *
 * 1. IDEMPOTENCY — the UNIQUE(tenant_id, leadgen_id) constraint ensures
 *    that a re-delivered Meta webhook (Meta retries on non-200 or network
 *    failures) cannot enqueue a duplicate fetch job or fire triggers twice.
 *    The INSERT fails on a duplicate → webhook handler silently returns 200.
 *
 * 2. AUDIT — stores the processing outcome (queued → processed | failed)
 *    and the resulting contact_id for debugging and analytics.
 */
class CreateMetaLeadEvents extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'integration_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            // Meta's globally unique identifier for this lead submission
            'leadgen_id'     => ['type' => 'VARCHAR', 'constraint' => 100],
            'status'         => [
                'type'       => 'ENUM',
                'constraint' => ['queued', 'processing', 'processed', 'failed'],
                'default'    => 'queued',
            ],
            // Set after successful processing
            'contact_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        // THE IDEMPOTENCY CONSTRAINT: INSERT fails on retry → no duplicate processing
        $this->forge->addUniqueKey(['tenant_id', 'leadgen_id']);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addForeignKey('tenant_id',      'tenants',      'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('integration_id', 'integrations', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('meta_lead_events');
    }

    public function down(): void
    {
        $this->forge->dropTable('meta_lead_events', true);
    }
}
