<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * WhatsApp at platform level (admin app, stage 4).
 *  - platform_settings: small key/value store for the TripSarthi team's own settings (e.g. which workspace runs TripSarthi's own WhatsApp marketing).
 *  - tenants.wa_marketing_paused (+ reason/at): the team can stop MARKETING sends from a workspace (abuse, spam reports). Honoured by
 *    SendingGate, which every campaign/drip/flow send already passes through. Utility messages are never blocked.
 *  - CONSENT for TripSarthi's own marketing (WhatsApp policy + DPDP: no marketing without an explicit yes): users, website enquiries and
 *    partners each get an opt-in flag (default 0). users also get a phone number.
 *  - platform_audience_syncs: history of "add this segment to the marketing workspace" runs.
 */
class CreateWhatsappPlatformControls extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'setting_key' => ['type' => 'VARCHAR', 'constraint' => 60],
            'value'       => ['type' => 'TEXT', 'null' => true],
            'updated_by'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('setting_key', true);
        $this->forge->createTable('platform_settings');

        $this->forge->addColumn('tenants', [
            'wa_marketing_paused'        => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'wa_marketing_paused_reason' => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true, 'default' => null],
            'wa_marketing_paused_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addColumn('users', [
            'phone'              => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'default' => null],
            'wa_marketing_opt_in' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'wa_opt_in_at'       => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addColumn('contact_enquiries', ['wa_opt_in' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0]]);
        $this->forge->addColumn('partners', ['wa_marketing_opt_in' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0]]);

        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'segment'             => ['type' => 'VARCHAR', 'constraint' => 40],
            'marketing_tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'added'               => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'already_there'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'withdrawn'           => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'skipped'             => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'created_by'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'          => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['segment', 'id']);
        $this->forge->createTable('platform_audience_syncs');
    }

    public function down(): void
    {
        $this->forge->dropTable('platform_audience_syncs', true);
        $this->forge->dropColumn('partners', 'wa_marketing_opt_in');
        $this->forge->dropColumn('contact_enquiries', 'wa_opt_in');
        $this->forge->dropColumn('users', ['phone', 'wa_marketing_opt_in', 'wa_opt_in_at']);
        $this->forge->dropColumn('tenants', ['wa_marketing_paused', 'wa_marketing_paused_reason', 'wa_marketing_paused_at']);
        $this->forge->dropTable('platform_settings', true);
    }
}
