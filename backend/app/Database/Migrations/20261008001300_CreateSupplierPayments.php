<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Supplier payment ledger. booking_services.paid_amount is the SUM of these rows (never edited directly). */
class CreateSupplierPayments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                 => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'booking_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'booking_service_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'supplier_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'amount'             => ['type' => 'BIGINT', 'unsigned' => true],
            'paid_on'            => ['type' => 'DATE'],
            'mode'               => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'bank'],
            'reference'          => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
            'notes'              => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'created_by'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'         => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'booking_service_id']);
        $this->forge->addKey(['tenant_id', 'supplier_id', 'paid_on']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('booking_service_id', 'booking_services', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('supplier_payments');

        // Carry over amounts that were typed straight into booking_services.paid_amount before the ledger existed.
        $this->db->query("INSERT INTO supplier_payments (tenant_id, booking_id, booking_service_id, supplier_id, amount, paid_on, mode, notes, created_at)
            SELECT tenant_id, booking_id, id, supplier_id, paid_amount, DATE(COALESCE(updated_at, created_at)), 'other', 'Opening balance (entered before the payment ledger)', NOW()
            FROM booking_services WHERE paid_amount > 0 AND deleted_at IS NULL");
    }

    public function down(): void
    {
        $this->forge->dropTable('supplier_payments', true);
    }
}
