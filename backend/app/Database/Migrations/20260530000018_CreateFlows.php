<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateFlows extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'name'      => ['type' => 'VARCHAR', 'constraint' => 255],
            'status'    => [
                'type'       => 'ENUM',
                'constraint' => ['draft', 'active', 'paused'],
                'default'    => 'draft',
            ],
            'trigger_type' => [
                'type'       => 'ENUM',
                'constraint' => ['lead_created', 'tag_added', 'form_submitted',
                                 'meta_lead_received', 'keyword_reply', 'inbound_message'],
            ],
            // e.g. {"tag_id":5}, {"keywords":["hello"],"match":"contains"}, {"form_id":2}
            'trigger_config'  => ['type' => 'JSON',    'null' => true, 'default' => null],
            // {nodes:[{id,type,data}], edges:[{id,source,target,sourceHandle}]}
            'graph'           => ['type' => 'JSON',    'null' => true, 'default' => null],
            'reentry_policy'  => [
                'type'       => 'ENUM',
                'constraint' => ['once', 'always'],
                'default'    => 'once',
            ],
            'version'     => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            // {started:0, completed:0, waiting:0, stopped:0, failed:0}
            'stats'       => ['type' => 'JSON',     'null' => true, 'default' => null],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('flows');
    }

    public function down(): void
    {
        $this->forge->dropTable('flows', true);
    }
}
