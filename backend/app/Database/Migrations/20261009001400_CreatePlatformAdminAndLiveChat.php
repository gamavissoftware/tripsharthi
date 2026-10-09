<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Platform ("owner of TripSarthi") admin + website live chat.
 *  - users.is_platform_admin: granted ONLY from the server (`php spark admin:grant email`), never by a request or a registration.
 *  - chat_sessions / chat_messages: the website live chat (platform-level, no tenant). A session's token is the visitor's credential.
 */
class CreatePlatformAdminAndLiveChat extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('users', ['is_platform_admin' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'role']]);

        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'token'           => ['type' => 'CHAR', 'constraint' => 32],
            'visitor_name'    => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
            'visitor_email'   => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'default' => null],
            'status'          => ['type' => 'ENUM', 'constraint' => ['open', 'closed'], 'default' => 'open'],
            'page_url'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'ip_hash'         => ['type' => 'CHAR', 'constraint' => 32, 'null' => true, 'default' => null],
            'user_agent'      => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true, 'default' => null],
            'unread_agent'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],               // visitor messages the team has not opened yet
            'last_visitor_at' => ['type' => 'DATETIME', 'null' => true],
            'last_agent_at'   => ['type' => 'DATETIME', 'null' => true],
            'last_poll_at'    => ['type' => 'DATETIME', 'null' => true],                              // visitor's widget is open (online)
            'last_notified_at' => ['type' => 'DATETIME', 'null' => true],                             // last "new chat" email to the team
            'last_message_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token');
        $this->forge->addKey(['status', 'last_message_at']);
        $this->forge->createTable('chat_sessions');

        $this->forge->addField([
            'id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'session_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'sender'     => ['type' => 'ENUM', 'constraint' => ['visitor', 'agent', 'system'], 'default' => 'visitor'],
            'body'       => ['type' => 'TEXT'],
            'agent_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['session_id', 'id']);
        $this->forge->addForeignKey('session_id', 'chat_sessions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('chat_messages');
    }

    public function down(): void
    {
        $this->forge->dropTable('chat_messages', true);
        $this->forge->dropTable('chat_sessions', true);
        $this->forge->dropColumn('users', 'is_platform_admin');
    }
}
