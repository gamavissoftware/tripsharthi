<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Messages from the public website's contact form. Platform-level (no tenant): they are for the TripSarthi team, not for a customer workspace. */
class CreateContactEnquiries extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'name'         => ['type' => 'VARCHAR', 'constraint' => 120],
            'email'        => ['type' => 'VARCHAR', 'constraint' => 190],
            'phone'        => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'default' => null],
            'company'      => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true, 'default' => null],
            'topic'        => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'other'],
            'message'      => ['type' => 'TEXT', 'null' => true],
            'status'       => ['type' => 'ENUM', 'constraint' => ['new', 'handled', 'spam'], 'default' => 'new'],
            'source'       => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
            'ip_hash'      => ['type' => 'CHAR', 'constraint' => 32, 'null' => true, 'default' => null],     // salted hash, never the address
            'user_agent'   => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true, 'default' => null],
            'notified_at'  => ['type' => 'DATETIME', 'null' => true],
            'notify_error' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'created_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'created_at']);
        $this->forge->addKey(['email', 'created_at']);
        $this->forge->createTable('contact_enquiries');
    }

    public function down(): void
    {
        $this->forge->dropTable('contact_enquiries', true);
    }
}
