<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase L: native calendar / meetings. A lightweight scheduling record attached
 * to a contact and/or deal — no external (Google/Outlook) sync, agenda lives in
 * TravelPilot. Drives the `meeting_scheduled` flow trigger.
 */
class CreateMeetings extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'title'      => ['type' => 'VARCHAR', 'constraint' => 255],
            'contact_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'deal_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'owner_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'start_at'   => ['type' => 'DATETIME'],
            'end_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'location'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'notes'      => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'status'     => ['type' => 'ENUM', 'constraint' => ['scheduled', 'completed', 'canceled'], 'default' => 'scheduled'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'start_at']);
        $this->forge->addKey('contact_id');
        $this->forge->createTable('meetings');
    }

    public function down(): void
    {
        $this->forge->dropTable('meetings');
    }
}
