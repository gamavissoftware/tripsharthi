<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Meta custom + lookalike audiences built from CRM contacts. Only SHA-256 hashes of phone/email are kept for members (never the raw values), so removals still work after a contact is deleted. */
class CreateAdAudiences extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'platform'       => ['type' => 'ENUM', 'constraint' => ['meta'], 'default' => 'meta'],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 120],
            'kind'           => ['type' => 'ENUM', 'constraint' => ['custom', 'lookalike'], 'default' => 'custom'],
            'source_type'    => ['type' => 'VARCHAR', 'constraint' => 30],                  // booked | enquiries_unbooked | segment | all_optin | lookalike
            'source_ref'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],   // segment id, or the seed audience (local id) of a lookalike
            'account_ref'    => ['type' => 'VARCHAR', 'constraint' => 40],
            'external_id'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'status'         => ['type' => 'ENUM', 'constraint' => ['creating', 'ready', 'error'], 'default' => 'creating'],
            'member_count'   => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'params'         => ['type' => 'JSON', 'null' => true],
            'last_error'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'default' => null],
            'last_synced_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_by'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'platform', 'status']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ad_audiences');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'audience_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'contact_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'phone_hash'  => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'email_hash'  => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'added_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['audience_id', 'contact_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('audience_id', 'ad_audiences', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ad_audience_members');
    }

    public function down(): void
    {
        $this->forge->dropTable('ad_audience_members', true);
        $this->forge->dropTable('ad_audiences', true);
    }
}
