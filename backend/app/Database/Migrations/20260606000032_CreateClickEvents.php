<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Click / CTA engagement tracking (P1.4).
 *
 * One row per quick-reply / list / template-button tap received on the inbound
 * webhook. The tap is attributed back to the outbound message it replied to
 * (via the webhook's context.id), which carries campaign_id — so clicks roll up
 * per campaign.
 *
 * NOTE: WhatsApp URL ("call-to-action") buttons do NOT generate an inbound
 * webhook when tapped, so only interactive quick-reply / list / button taps are
 * trackable via the Cloud API. This is a platform limitation, not a gap here.
 */
class CreateClickEvents extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'campaign_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'message_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'contact_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'conversation_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],

            // What was tapped.
            'button_id'     => ['type' => 'VARCHAR', 'constraint' => 256, 'null' => true, 'default' => null],
            'button_title'  => ['type' => 'VARCHAR', 'constraint' => 256, 'null' => true, 'default' => null],
            'source'        => [
                'type'       => 'ENUM',
                'constraint' => ['quick_reply', 'list', 'button'],
                'default'    => 'quick_reply',
            ],

            // wa_message_id of the inbound reply — unique guard against webhook replays.
            'inbound_wa_id' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true, 'default' => null],

            'clicked_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('campaign_id');
        $this->forge->addKey(['tenant_id', 'clicked_at']);
        $this->forge->addUniqueKey('inbound_wa_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('click_events');
    }

    public function down(): void
    {
        $this->forge->dropTable('click_events', true);
    }
}
