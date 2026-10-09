<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Lead sources for travel portals / aggregators / any system that can POST a webhook or email an enquiry. */
class CreatePortalLeadSources extends Migration
{
    public function up(): void
    {
        $p = $this->db->DBPrefix;
        // contacts.source is an ENUM: the DB and ContactModel's in_list must change together (see the email_inbound migration).
        $this->db->query("ALTER TABLE {$p}contacts MODIFY source ENUM('manual','csv_import','web_form','meta_lead_ads','google_lead_forms','whatsapp_inbound','email_inbound','shopify','woocommerce','portal') NOT NULL DEFAULT 'manual'");

        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'name'            => ['type' => 'VARCHAR', 'constraint' => 80],
            'kind'            => ['type' => 'ENUM', 'constraint' => ['webhook', 'email'], 'default' => 'webhook'],
            'token'           => ['type' => 'CHAR', 'constraint' => 32],                          // the webhook URL secret (also identifies the source)
            'email_match'     => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'default' => null],   // sender address or @domain of the portal's notification emails
            'field_map'       => ['type' => 'JSON', 'null' => true],                              // extra alias => canonical field overrides
            'create_trip'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'owner_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'enabled'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'received_count'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'last_received_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token');
        $this->forge->addKey(['tenant_id', 'kind', 'enabled']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('lead_sources');

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'lead_source_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'status'         => ['type' => 'ENUM', 'constraint' => ['created', 'existing', 'duplicate', 'rejected', 'error'], 'default' => 'created'],
            'reason'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'contact_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'trip_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'dedupe_hash'    => ['type' => 'CHAR', 'constraint' => 40],                           // sha1 of the normalised payload: a portal retrying the same lead is ignored
            'payload'        => ['type' => 'MEDIUMTEXT', 'null' => true],                          // raw lead (personal data) — purge per your retention policy
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'lead_source_id', 'created_at']);
        $this->forge->addKey(['lead_source_id', 'dedupe_hash']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('lead_source_id', 'lead_sources', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('lead_events');
    }

    public function down(): void
    {
        $p = $this->db->DBPrefix;
        $this->forge->dropTable('lead_events', true);
        $this->forge->dropTable('lead_sources', true);
        $this->db->query("UPDATE {$p}contacts SET source = 'manual' WHERE source = 'portal'");
        $this->db->query("ALTER TABLE {$p}contacts MODIFY source ENUM('manual','csv_import','web_form','meta_lead_ads','google_lead_forms','whatsapp_inbound','email_inbound','shopify','woocommerce') NOT NULL DEFAULT 'manual'");
    }
}
