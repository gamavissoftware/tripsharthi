<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateLeadImports extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'original_filename'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'stored_filename'      => ['type' => 'VARCHAR', 'constraint' => 255],
            'default_country_code' => ['type' => 'VARCHAR', 'constraint' => 6, 'null' => true, 'default' => null],
            'headers'              => ['type' => 'JSON', 'null' => true, 'default' => null],
            'mapping'              => ['type' => 'JSON', 'null' => true, 'default' => null],
            'total'                => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'imported'             => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'updated_count'        => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'failed'               => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'errors'               => ['type' => 'JSON', 'null' => true, 'default' => null],
            // Byte offset in the uploaded file; 0 = start from after header row
            'cursor'               => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'mapped', 'processing', 'done', 'failed'],
                'default'    => 'pending',
            ],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('lead_imports');
    }

    public function down(): void
    {
        $this->forge->dropTable('lead_imports', true);
    }
}
