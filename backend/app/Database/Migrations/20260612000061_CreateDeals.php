<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * deals — opportunities moving through a pipeline. value in paise; status is
 * open until a won/lost stage move closes it.
 */
class CreateDeals extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'title'               => ['type' => 'VARCHAR', 'constraint' => 255],
            'pipeline_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'stage_id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'account_id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'primary_contact_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'owner_id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'value_amount'        => ['type' => 'BIGINT', 'constraint' => 20, 'default' => 0], // paise
            'currency'            => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'INR'],
            'expected_close_date' => ['type' => 'DATE', 'null' => true, 'default' => null],
            'status'              => ['type' => 'ENUM', 'constraint' => ['open', 'won', 'lost'], 'default' => 'open'],
            'source'              => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true, 'default' => null],
            'lost_reason'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'won_at'              => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'last_activity_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'          => ['type' => 'DATETIME', 'null' => true],
            'updated_at'          => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'          => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'pipeline_id', 'stage_id']);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addKey(['tenant_id', 'owner_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('pipeline_id', 'pipelines', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('stage_id', 'pipeline_stages', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('account_id', 'accounts', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('primary_contact_id', 'contacts', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('owner_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('deals');
    }

    public function down(): void
    {
        $this->forge->dropTable('deals', true);
    }
}
