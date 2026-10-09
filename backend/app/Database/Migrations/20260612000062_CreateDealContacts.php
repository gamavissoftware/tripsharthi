<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * deal_contacts — M:N between deals and contacts, with a role
 * (decision_maker / influencer / champion / …). The deal's primary_contact_id
 * is the headline contact; this captures the wider buying group.
 */
class CreateDealContacts extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'deal_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'contact_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'role'       => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true, 'default' => null],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['deal_id', 'contact_id']);
        $this->forge->addKey(['tenant_id', 'contact_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('deal_id', 'deals', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('contact_id', 'contacts', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('deal_contacts');
    }

    public function down(): void
    {
        $this->forge->dropTable('deal_contacts', true);
    }
}
