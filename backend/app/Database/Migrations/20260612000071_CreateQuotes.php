<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * quotes — a priced proposal generated from a deal (CRM Phase F / CPQ). The
 * line items are snapshotted as JSON so the quote stays fixed even if the deal
 * later changes. Amounts in paise.
 */
class CreateQuotes extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'deal_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'number'      => ['type' => 'VARCHAR', 'constraint' => 40],
            'status'      => ['type' => 'ENUM', 'constraint' => ['draft', 'sent', 'accepted', 'rejected', 'expired'], 'default' => 'draft'],
            'valid_until' => ['type' => 'DATE', 'null' => true, 'default' => null],
            'currency'    => ['type' => 'VARCHAR', 'constraint' => 3, 'default' => 'INR'],
            'subtotal'    => ['type' => 'BIGINT', 'constraint' => 20, 'default' => 0],
            'total'       => ['type' => 'BIGINT', 'constraint' => 20, 'default' => 0],
            'items'       => ['type' => 'TEXT', 'null' => true, 'default' => null], // JSON snapshot
            'notes'       => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'deal_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('deal_id', 'deals', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('quotes');
    }

    public function down(): void
    {
        $this->forge->dropTable('quotes', true);
    }
}
