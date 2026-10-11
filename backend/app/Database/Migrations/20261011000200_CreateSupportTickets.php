<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Support tickets from TripSarthi's customers to the TripSarthi team (NOT the CRM `tickets` a travel agency keeps for its own travellers).
 * support_tickets: one per request, owned by a workspace + the user who raised it. support_messages: the thread -
 * author_type customer (visible to the customer), team (visible), event (status change, visible), note (internal, team only).
 */
class CreateSupportTickets extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'               => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'user_id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'subject'          => ['type' => 'VARCHAR', 'constraint' => 160],
            'category'         => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'other'],
            'priority'         => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'normal'],
            'status'           => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'open'],   // open | pending (waiting on the customer) | resolved | closed
            'assigned_to'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'last_from'        => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'customer'],
            'last_message_at'  => ['type' => 'DATETIME', 'null' => true],
            'team_unread'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'customer_unread'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'team_notified_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
            'closed_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addKey(['status', 'last_message_at']);
        $this->forge->createTable('support_tickets', true);

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'ticket_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'author_type' => ['type' => 'VARCHAR', 'constraint' => 10],
            'author_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'body'        => ['type' => 'TEXT'],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['ticket_id', 'id']);
        $this->forge->createTable('support_messages', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('support_messages', true);
        $this->forge->dropTable('support_tickets', true);
    }
}
