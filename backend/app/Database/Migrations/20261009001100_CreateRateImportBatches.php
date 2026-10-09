<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** A rate-sheet import is PREVIEWED first (nothing touches supplier_rates), then committed once, row by row chosen by a person. */
class CreateRateImportBatches extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'supplier_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'destination_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'source_name'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'source_sha'     => ['type' => 'CHAR', 'constraint' => 64],
            'mode'           => ['type' => 'ENUM', 'constraint' => ['table', 'ai', 'lines'], 'default' => 'table'],
            'rows'           => ['type' => 'LONGTEXT'],                                   // JSON: prepared rows + status + issues (price data, no personal data)
            'status'         => ['type' => 'ENUM', 'constraint' => ['previewed', 'committed'], 'default' => 'previewed'],
            'created_count'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'updated_count'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'created_by'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'committed_at'   => ['type' => 'DATETIME', 'null' => true],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'supplier_id']);
        $this->forge->addKey(['tenant_id', 'source_sha']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('supplier_id', 'suppliers', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('rate_import_batches');
    }

    public function down(): void
    {
        $this->forge->dropTable('rate_import_batches', true);
    }
}
