<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** E-invoicing (IRN from the government's Invoice Registration Portal): per-tenant settings + the IRN/QR stored on the invoice itself. */
class AddEinvoicing extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'enabled'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'mode'          => ['type' => 'ENUM', 'constraint' => ['demo', 'gsp'], 'default' => 'gsp'],      // demo = local simulator, only when EINVOICE_MOCK_MODE is on
            'aato_confirmed' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],                      // the user confirmed their turnover requires e-invoicing
            'config_enc'    => ['type' => 'TEXT', 'null' => true],                                              // GSP endpoints + credentials, encrypted (TokenCipher)
            'enabled_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'updated_by'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('tenant_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('einvoice_settings');

        // invoices.einvoice_irn already exists (reserved when invoices were built).
        $this->forge->addColumn('invoices', [
            'einvoice_status'        => ['type' => 'ENUM', 'constraint' => ['none', 'generated', 'cancelled'], 'default' => 'none'],
            'einvoice_ack_no'        => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => true, 'default' => null],
            'einvoice_ack_dt'        => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'einvoice_qr'            => ['type' => 'TEXT', 'null' => true],                                       // the SIGNED QR payload issued by the IRP (a JWT), printed as a QR code
            'einvoice_cancelled_at'  => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'einvoice_cancel_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
        ]);
        $this->db->query('CREATE INDEX idx_invoices_einvoice ON invoices (tenant_id, einvoice_status)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX idx_invoices_einvoice ON invoices');
        $this->forge->dropColumn('invoices', ['einvoice_status', 'einvoice_ack_no', 'einvoice_ack_dt', 'einvoice_qr', 'einvoice_cancelled_at', 'einvoice_cancel_reason']);
        $this->forge->dropTable('einvoice_settings', true);
    }
}
