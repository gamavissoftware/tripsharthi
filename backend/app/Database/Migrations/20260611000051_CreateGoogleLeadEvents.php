<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tracks every Google Ads Lead Form lead received, serving two purposes:
 *
 * 1. IDEMPOTENCY — the UNIQUE(tenant_id, lead_id) constraint ensures that a
 *    re-delivered Google webhook (Google retries on non-200 / timeout) cannot
 *    enqueue a duplicate process job or fire triggers twice. The INSERT fails on
 *    a duplicate → the webhook handler silently returns 200.
 *
 * 2. AUDIT + PAYLOAD STORE — unlike Meta (which only stores a leadgen_id and
 *    re-fetches via the Graph API), Google pushes the FULL lead inline in the
 *    webhook body. We persist that payload here so the async worker can map it
 *    without any external call. Stores the processing outcome and contact_id.
 */
class CreateGoogleLeadEvents extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'integration_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            // Google's unique identifier for this lead submission
            'lead_id'        => ['type' => 'VARCHAR', 'constraint' => 100],
            // Raw inline lead payload (user_column_data + form_id + campaign_id) as JSON
            'payload'        => ['type' => 'TEXT', 'null' => true],
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
        $this->forge->addUniqueKey(['tenant_id', 'lead_id']);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addForeignKey('tenant_id',      'tenants',      'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('integration_id', 'integrations', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('google_lead_events');
    }

    public function down(): void
    {
        $this->forge->dropTable('google_lead_events', true);
    }
}
