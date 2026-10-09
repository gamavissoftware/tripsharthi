<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Per-booking document / visa checklist (what must be collected from each traveller before departure). */
class CreateBookingChecklists extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'booking_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'traveler_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],   // 0 = whole booking (UNIQUE key cannot use NULL)
            'item_key'    => ['type' => 'VARCHAR', 'constraint' => 40],
            'category'    => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'other'],
            'label'       => ['type' => 'VARCHAR', 'constraint' => 200],
            'required'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'status'      => ['type' => 'ENUM', 'constraint' => ['pending', 'received', 'not_applicable'], 'default' => 'pending'],
            'due_date'    => ['type' => 'DATE', 'null' => true, 'default' => null],
            'received_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'notes'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'updated_by'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['booking_id', 'traveler_id', 'item_key']);
        $this->forge->addKey(['tenant_id', 'status', 'due_date']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('booking_id', 'bookings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_checklist_items');
    }

    public function down(): void
    {
        $this->forge->dropTable('booking_checklist_items', true);
    }
}
