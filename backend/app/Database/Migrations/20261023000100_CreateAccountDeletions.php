<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Platform-level record that a workspace (or one user) was deleted on request: who asked, who ran it, what was removed. Holds no personal data. */
class CreateAccountDeletions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'unsigned' => true, 'auto_increment' => true],
            'scope'       => ['type' => 'ENUM', 'constraint' => ['workspace', 'user']],
            'tenant_id'   => ['type' => 'INT', 'unsigned' => true],
            'user_id'     => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'request_ref' => ['type' => 'VARCHAR', 'constraint' => 120],
            'executed_by' => ['type' => 'VARCHAR', 'constraint' => 120],
            'kept_tax_docs' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'summary'     => ['type' => 'JSON', 'null' => true],
            'executed_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('tenant_id');
        $this->forge->createTable('account_deletions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('account_deletions', true);
    }
}
