<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Stores submissions from Meta WhatsApp Flows (P3.3).
 *
 * When a contact completes a Flow, Meta delivers an inbound interactive
 * 'nfm_reply' containing the answers as response_json. We persist it and fire
 * a 'flow_response' trigger so automations can react to native form submissions.
 */
class CreateWhatsappFlowResponses extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'contact_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'conversation_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'flow_token'      => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true, 'default' => null],
            'response'        => ['type' => 'JSON', 'null' => true, 'default' => null],
            'wa_message_id'   => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true, 'default' => null],
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'contact_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('whatsapp_flow_responses');
    }

    public function down(): void
    {
        $this->forge->dropTable('whatsapp_flow_responses', true);
    }
}
