<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** GSTR-3B inputs TravelPilot cannot derive (input tax credit) and a filing tracker for GSTR-1 / GSTR-3B. */
class CreateGstReturnsTracking extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'period'      => ['type' => 'CHAR', 'constraint' => 7],                                 // YYYY-MM
            'itc_igst'    => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],             // eligible ITC for the month, paise
            'itc_cgst'    => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'itc_sgst'    => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'rev_igst'    => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],             // ITC reversed
            'rev_cgst'    => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'rev_sgst'    => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'opening_set' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],             // 1 = the opening balance below was typed (first month / correction); 0 = carry forward from last month
            'open_igst'   => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'open_cgst'   => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'open_sgst'   => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'notes'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'updated_by'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
            'updated_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'period']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('gst_itc_entries');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'period'      => ['type' => 'CHAR', 'constraint' => 7],
            'return_type' => ['type' => 'ENUM', 'constraint' => ['GSTR1', 'GSTR3B'], 'default' => 'GSTR3B'],
            'filed_on'    => ['type' => 'DATE'],
            'arn'         => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'default' => null],
            'tax_paid_cash' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'default' => null],
            'notes'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'created_by'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'period', 'return_type']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('gst_filings');
    }

    public function down(): void
    {
        $this->forge->dropTable('gst_filings', true);
        $this->forge->dropTable('gst_itc_entries', true);
    }
}
