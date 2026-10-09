<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Audit trail of documents (invoice, receipt, credit note, voucher) sent to customers on WhatsApp. */
class CreateDocumentDeliveries extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'doc_kind'    => ['type' => 'VARCHAR', 'constraint' => 20],           // invoice | voucher (invoice covers receipts/credit notes)
            'doc_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'doc_label'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
            'booking_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'contact_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'channel'     => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'whatsapp'],
            'mode'        => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'default' => null],   // document | link | template
            'status'      => ['type' => 'ENUM', 'constraint' => ['sent', 'failed'], 'default' => 'sent'],
            'message_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'error'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'sent_by'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'  => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'doc_kind', 'doc_id']);
        $this->forge->addKey(['tenant_id', 'booking_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('document_deliveries');
    }

    public function down(): void
    {
        $this->forge->dropTable('document_deliveries', true);
    }
}
