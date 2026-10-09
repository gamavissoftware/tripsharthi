<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Customer self-service portal: a per-booking secret link + customer document uploads awaiting staff review. */
class CreateCustomerPortal extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('bookings', [
            'portal_token' => ['type' => 'CHAR', 'constraint' => 32, 'null' => true, 'default' => null, 'after' => 'customer_pan'],
        ]);
        $this->db->query('ALTER TABLE bookings ADD UNIQUE KEY uq_bookings_portal_token (portal_token)');
        $this->db->query("ALTER TABLE booking_checklist_items MODIFY status ENUM('pending','uploaded','received','not_applicable') NOT NULL DEFAULT 'pending'");

        $this->forge->addField([
            'id'                => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'booking_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'checklist_item_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'original_name'     => ['type' => 'VARCHAR', 'constraint' => 200],
            'mime'              => ['type' => 'VARCHAR', 'constraint' => 60],
            'size'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'path'              => ['type' => 'VARCHAR', 'constraint' => 255],                   // relative to writable/; file is ENCRYPTED at rest
            'sha256'            => ['type' => 'CHAR', 'constraint' => 64],                       // of the plaintext
            'status'            => ['type' => 'ENUM', 'constraint' => ['pending', 'accepted', 'rejected'], 'default' => 'pending'],
            'reject_reason'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'reviewed_by'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'reviewed_at'       => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'booking_id']);
        $this->forge->addKey('checklist_item_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('checklist_item_id', 'booking_checklist_items', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('portal_uploads');
    }

    public function down(): void
    {
        $this->forge->dropTable('portal_uploads', true);
        $this->db->query("UPDATE booking_checklist_items SET status = 'pending' WHERE status = 'uploaded'");
        $this->db->query("ALTER TABLE booking_checklist_items MODIFY status ENUM('pending','received','not_applicable') NOT NULL DEFAULT 'pending'");
        $this->db->query('ALTER TABLE bookings DROP INDEX uq_bookings_portal_token');
        $this->forge->dropColumn('bookings', 'portal_token');
    }
}
