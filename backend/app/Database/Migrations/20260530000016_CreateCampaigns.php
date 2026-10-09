<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCampaigns extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'template_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 255],

            // Segment filter: {"all":true} or {"tag_ids":[1,2], "status":"new", "source":"csv_import"}
            'segment' => ['type' => 'JSON', 'null' => true, 'default' => null],

            // Variable mapping: {"1":"name","2":"my_cf_key"}
            // Keys are {{n}} indices (as strings), values are field_key names.
            'variable_mapping' => ['type' => 'JSON', 'null' => true, 'default' => null],

            // Campaign-level per-variable fallback defaults used when a contact
            // has a blank value: {"1":"there","2":"your company"}
            'variable_defaults' => ['type' => 'JSON', 'null' => true, 'default' => null],

            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['draft', 'processing', 'done', 'failed', 'paused'],
                'default'    => 'draft',
            ],
            'scheduled_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],

            // KEYSET CURSOR: stores the id of the last contact processed.
            // Batch query: WHERE contacts.id > cursor ORDER BY id ASC LIMIT 500
            // Done when batch returns < BATCH_SIZE rows.
            'cursor' => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],

            // Snapshotted once before the first batch (opt-in pre-filter applied for marketing).
            'total_contacts' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'sent_count'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'failed_count'   => ['type' => 'INT', 'unsigned' => true, 'default' => 0],

            // {billable_sends:0, free_sends:0, failed:0, skipped_opt_out:0, missing_variables:0}
            'stats'      => ['type' => 'JSON',     'null' => true, 'default' => null],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addForeignKey('tenant_id',   'tenants',   'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('template_id', 'templates', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('campaigns');
    }

    public function down(): void
    {
        $this->forge->dropTable('campaigns', true);
    }
}
