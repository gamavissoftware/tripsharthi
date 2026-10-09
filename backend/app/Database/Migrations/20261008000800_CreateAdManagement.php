<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Campaign management for Meta + Google.
 *  - ad_campaigns (existing cache) gains what TravelPilot needs to own a campaign's lifecycle.
 *  - ad_insights_daily: per-campaign daily metrics pulled from the platforms, joined with CRM outcomes in reports.
 *  - ad_settings: per-tenant spend guardrails. daily_spend_cap = 0 means "managing spend is OFF" (the safe default).
 *  - ad_rules: automatic rules. They can only PAUSE or notify — never enable or raise a budget.
 * Money is integer paise throughout (Meta INR minor units; Google micros / 10,000).
 */
class CreateAdManagement extends Migration
{
    private function stamps(): array
    {
        return [
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ];
    }

    public function up(): void
    {
        $this->forge->addColumn('ad_campaigns', [
            'origin'           => ['type' => 'ENUM', 'constraint' => ['synced', 'travelpilot'], 'default' => 'synced', 'after' => 'platform'],
            'kind'             => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'default' => null],
            'effective_status' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'default' => null],
            'currency'         => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'INR'],
            'start_date'       => ['type' => 'DATE', 'null' => true, 'default' => null],
            'end_date'         => ['type' => 'DATE', 'null' => true, 'default' => null],
            'spec'             => ['type' => 'JSON', 'null' => true],
            'children'         => ['type' => 'JSON', 'null' => true],
            'last_error'       => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'created_by'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'launched_at'      => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'paused_by_rule'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ]);

        $this->forge->addField([
            'id'                  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'platform'            => ['type' => 'ENUM', 'constraint' => ['meta', 'google']],
            'campaign_external_id' => ['type' => 'VARCHAR', 'constraint' => 64],
            'day'                 => ['type' => 'DATE'],
            'spend'               => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'impressions'         => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'clicks'              => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'leads'               => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'conversations'       => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'synced_at'           => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'platform', 'campaign_external_id', 'day'], 'uq_ad_insights_day');
        $this->forge->addKey(['tenant_id', 'day']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ad_insights_daily');

        $this->forge->addField([
            'id'                     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'              => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'daily_spend_cap'        => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'min_daily_budget'       => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 10000],
            'max_increase_pct'       => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 30],
            'rules_enabled'          => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('tenant_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ad_settings');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'name'        => ['type' => 'VARCHAR', 'constraint' => 150],
            'platform'    => ['type' => 'ENUM', 'constraint' => ['any', 'meta', 'google'], 'default' => 'any'],
            // cpl: cost per platform-reported lead above threshold | spend_no_leads: spent ≥ threshold, zero leads
            // cost_per_booking: spend ÷ CRM bookings above threshold
            'metric'      => ['type' => 'ENUM', 'constraint' => ['cpl', 'spend_no_leads', 'cost_per_booking']],
            'threshold'   => ['type' => 'BIGINT', 'unsigned' => true],
            'window_days' => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 3],
            'min_spend'   => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'action'      => ['type' => 'ENUM', 'constraint' => ['pause', 'notify'], 'default' => 'notify'],
            'enabled'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'last_run_at' => ['type' => 'DATETIME', 'null' => true],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'enabled']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ad_rules');
    }

    public function down(): void
    {
        $this->forge->dropTable('ad_rules', true);
        $this->forge->dropTable('ad_settings', true);
        $this->forge->dropTable('ad_insights_daily', true);
        foreach (['origin', 'kind', 'effective_status', 'currency', 'start_date', 'end_date', 'spec', 'children', 'last_error', 'created_by', 'launched_at', 'paused_by_rule'] as $c) {
            $this->forge->dropColumn('ad_campaigns', $c);
        }
    }
}
