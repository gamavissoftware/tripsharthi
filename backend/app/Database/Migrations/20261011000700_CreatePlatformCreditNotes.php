<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Credit notes against TripSarthi's own GST invoices (refunds). Immutable once issued; own gap-free series per financial year
 * (`platform_doc_sequences`, series = the credit-note prefix). Seller and buyer are copied from the invoice so the note names the same parties.
 */
class CreatePlatformCreditNotes extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'number'          => ['type' => 'VARCHAR', 'constraint' => 20],
            'series'          => ['type' => 'VARCHAR', 'constraint' => 8],
            'fy'              => ['type' => 'CHAR', 'constraint' => 7],
            'seq'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'issue_date'      => ['type' => 'DATE'],
            'invoice_id'      => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'invoice_number'  => ['type' => 'VARCHAR', 'constraint' => 20],
            'invoice_date'    => ['type' => 'DATE'],
            'tenant_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'source'          => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'online'],
            'payment_ref'     => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'plan'            => ['type' => 'VARCHAR', 'constraint' => 20],
            'buyer_type'      => ['type' => 'VARCHAR', 'constraint' => 3],
            'buyer_name'      => ['type' => 'VARCHAR', 'constraint' => 200],
            'buyer_gstin'     => ['type' => 'VARCHAR', 'constraint' => 15, 'null' => true],
            'place_of_supply' => ['type' => 'CHAR', 'constraint' => 2],
            'supply_type'     => ['type' => 'VARCHAR', 'constraint' => 5],
            'sac'             => ['type' => 'VARCHAR', 'constraint' => 8],
            'gst_rate'        => ['type' => 'DECIMAL', 'constraint' => '5,2'],
            'taxable_value'   => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'cgst'            => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
            'sgst'            => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
            'igst'            => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
            'total'           => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'reason'          => ['type' => 'VARCHAR', 'constraint' => 300],
            'refund_ref'      => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],       // Razorpay refund id / bank reference, typed by the admin
            'seller'          => ['type' => 'TEXT'],
            'buyer'           => ['type' => 'TEXT'],
            'pdf_path'        => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'pdf_sha256'      => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'email_to'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'emailed_at'      => ['type' => 'DATETIME', 'null' => true],
            'email_attempts'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'email_error'     => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'created_by'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at'      => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('number');
        $this->forge->addUniqueKey(['series', 'fy', 'seq']);
        $this->forge->addKey(['invoice_id']);
        $this->forge->addKey(['issue_date']);
        $this->forge->addKey(['tenant_id', 'issue_date']);
        $this->forge->createTable('platform_credit_notes');
    }

    public function down(): void { $this->forge->dropTable('platform_credit_notes', true); }
}
