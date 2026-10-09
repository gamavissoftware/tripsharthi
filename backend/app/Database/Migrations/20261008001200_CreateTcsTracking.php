<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** TCS (Income-tax s.206C(1G)) tracking: customer PAN on the booking + challans the agency deposited. */
class CreateTcsTracking extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('bookings', [
            'customer_pan' => ['type' => 'CHAR', 'constraint' => 10, 'null' => true, 'default' => null, 'after' => 'cancel_reason'],
        ]);
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'period'        => ['type' => 'CHAR', 'constraint' => 7],                         // collection month YYYY-MM this challan pays for
            'amount'        => ['type' => 'BIGINT', 'unsigned' => true],                      // paise
            'bsr_code'      => ['type' => 'CHAR', 'constraint' => 7],
            'challan_serial' => ['type' => 'VARCHAR', 'constraint' => 5],
            'deposit_date'  => ['type' => 'DATE'],
            'notes'         => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'created_by'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'period']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('tcs_challans');
    }

    public function down(): void
    {
        $this->forge->dropTable('tcs_challans', true);
        $this->forge->dropColumn('bookings', 'customer_pan');
    }
}
