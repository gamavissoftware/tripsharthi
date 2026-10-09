<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ads attribution + closed-loop conversion feedback.
 *
 *  - lead_attributions: first/last-touch click IDs and UTMs per contact
 *    (gclid / fbclid / ctwa_clid / campaign ids). Written when a lead arrives.
 *  - conversion_events: an OUTBOX of CRM-stage events to report back to ad
 *    platforms (Meta Conversions API, Google offline conversion upload). Rows
 *    are created on stage change and delivered by a queue job with retry.
 *  - ad_campaigns: cache of campaigns pulled from / pushed to the platforms,
 *    tagged with a destination so ROAS can be reported per destination.
 */
class CreateAdsAttribution extends Migration
{
    private function nullId(): array
    {
        return ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null];
    }

    private function stamps(): array
    {
        return [
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ];
    }

    private function head(): array
    {
        return [
            'id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
        ];
    }

    public function up(): void
    {
        $this->forge->addField($this->head() + [
            'contact_id'    => $this->nullId(),
            'trip_id'       => $this->nullId(),
            'touch'         => ['type' => 'ENUM', 'constraint' => ['first', 'last'], 'default' => 'first'],
            'platform'      => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'direct'],
            'channel'       => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'default' => null],
            'campaign_id'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'campaign_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'adset_id'      => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'ad_id'         => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'form_id'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'lead_id'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'gclid'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'gbraid'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'wbraid'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'fbclid'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'fbp'           => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
            'fbc'           => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'ctwa_clid'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'utm'           => ['type' => 'JSON', 'null' => true],
            'landing_url'   => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'default' => null],
            'touched_at'    => ['type' => 'DATETIME', 'null' => true],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'contact_id']);
        $this->forge->addKey(['tenant_id', 'platform', 'campaign_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('lead_attributions');

        $this->forge->addField($this->head() + [
            'contact_id'   => $this->nullId(),
            'trip_id'      => $this->nullId(),
            'platform'     => ['type' => 'ENUM', 'constraint' => ['meta', 'google'], 'default' => 'meta'],
            'event_name'   => ['type' => 'VARCHAR', 'constraint' => 60],
            'event_id'     => ['type' => 'VARCHAR', 'constraint' => 80],
            'value_amount' => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'currency'     => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'INR'],
            'event_time'   => ['type' => 'DATETIME'],
            'payload'      => ['type' => 'JSON', 'null' => true],
            'status'       => ['type' => 'ENUM', 'constraint' => ['pending', 'sent', 'failed', 'skipped'], 'default' => 'pending'],
            'attempts'     => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'response'     => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'sent_at'      => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'platform', 'event_id']);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('conversion_events');

        $this->forge->addField($this->head() + [
            'platform'       => ['type' => 'ENUM', 'constraint' => ['meta', 'google'], 'default' => 'meta'],
            'external_id'    => ['type' => 'VARCHAR', 'constraint' => 64],
            'account_ref'    => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'name'           => ['type' => 'VARCHAR', 'constraint' => 255],
            'objective'      => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true, 'default' => null],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'UNKNOWN'],
            'daily_budget'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'default' => null],
            'destination_id' => $this->nullId(),
            'last_synced_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'platform', 'external_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ad_campaigns');
    }

    public function down(): void
    {
        foreach (['ad_campaigns', 'conversion_events', 'lead_attributions'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
