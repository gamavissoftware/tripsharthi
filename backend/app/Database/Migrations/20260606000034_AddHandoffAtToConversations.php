<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Marks the moment a flow handed a conversation to a human agent (P1.5).
 *
 * Set by the flow 'handoff' node so the shared inbox can surface
 * "needs a human" conversations. Cleared (left NULL) for purely automated chats.
 */
class AddHandoffAtToConversations extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('conversations', [
            'handoff_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
                'after'   => 'assigned_user_id',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('conversations', 'handoff_at');
    }
}
