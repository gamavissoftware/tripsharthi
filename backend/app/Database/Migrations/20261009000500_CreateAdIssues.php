<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Ads the platform disapproved or limited. One row per (platform, ad, kind); resolved_at is set when the platform stops reporting it. */
class CreateAdIssues extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'platform'             => ['type' => 'ENUM', 'constraint' => ['meta', 'google'], 'default' => 'meta'],
            'campaign_external_id' => ['type' => 'VARCHAR', 'constraint' => 64],
            'ad_external_id'       => ['type' => 'VARCHAR', 'constraint' => 120],
            'ad_name'              => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'kind'                 => ['type' => 'ENUM', 'constraint' => ['disapproved', 'limited'], 'default' => 'disapproved'],
            'reason'               => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'default' => null],
            'first_seen_at'        => ['type' => 'DATETIME', 'null' => true],
            'last_seen_at'         => ['type' => 'DATETIME', 'null' => true],
            'resolved_at'          => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'notified_at'          => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'platform', 'ad_external_id', 'kind']);
        $this->forge->addKey(['tenant_id', 'resolved_at']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ad_issues');
    }

    public function down(): void
    {
        $this->forge->dropTable('ad_issues', true);
    }
}
