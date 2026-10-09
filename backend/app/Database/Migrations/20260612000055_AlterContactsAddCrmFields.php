<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Extends contacts into a CRM contact: account membership, owner, job title,
 * lifecycle stage (HubSpot-style — a lead IS a contact from WhatsApp msg #1),
 * lead score, and a secondary (non-WhatsApp) phone.
 */
class AlterContactsAddCrmFields extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('contacts', [
            'account_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null, 'after' => 'email'],
            'owner_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null, 'after' => 'account_id'],
            'job_title'       => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true, 'default' => null, 'after' => 'owner_id'],
            'lifecycle_stage' => [
                'type'       => 'ENUM',
                'constraint' => ['subscriber', 'lead', 'mql', 'sql', 'opportunity', 'customer', 'evangelist', 'other'],
                'default'    => 'lead',
                'after'      => 'status',
            ],
            'lead_score'      => ['type' => 'INT', 'constraint' => 11, 'default' => 0, 'after' => 'lifecycle_stage'],
            'phone_secondary' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'default' => null, 'after' => 'lead_score'],
        ]);

        $p = $this->db->DBPrefix;
        $this->db->query("ALTER TABLE {$p}contacts ADD INDEX idx_contacts_tenant_account (tenant_id, account_id)");
        $this->db->query("ALTER TABLE {$p}contacts ADD INDEX idx_contacts_tenant_owner (tenant_id, owner_id)");
        $this->db->query("ALTER TABLE {$p}contacts ADD INDEX idx_contacts_tenant_lifecycle (tenant_id, lifecycle_stage)");
        $this->db->query("ALTER TABLE {$p}contacts ADD CONSTRAINT fk_contacts_account FOREIGN KEY (account_id) REFERENCES {$p}accounts(id) ON DELETE SET NULL ON UPDATE CASCADE");
        $this->db->query("ALTER TABLE {$p}contacts ADD CONSTRAINT fk_contacts_owner FOREIGN KEY (owner_id) REFERENCES {$p}users(id) ON DELETE SET NULL ON UPDATE CASCADE");
    }

    public function down(): void
    {
        $p = $this->db->DBPrefix;
        $this->db->query("ALTER TABLE {$p}contacts DROP FOREIGN KEY fk_contacts_account");
        $this->db->query("ALTER TABLE {$p}contacts DROP FOREIGN KEY fk_contacts_owner");
        $this->forge->dropColumn('contacts', ['account_id', 'owner_id', 'job_title', 'lifecycle_stage', 'lead_score', 'phone_secondary']);
    }
}
