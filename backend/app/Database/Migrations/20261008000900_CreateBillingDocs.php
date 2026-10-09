<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Billing documents.
 *  - business_profiles: the agency's legal identity printed on every quote and invoice.
 *  - doc_sequences: gap-free numbering per tenant + document type + financial year (row-locked when issuing).
 *  - invoices: tax invoices, bills of supply, credit notes and payment receipts. IMMUTABLE legal records:
 *    no soft delete, no update path in code; corrections are credit notes. The seller/buyer/lines are SNAPSHOTS
 *    taken at issue, so later edits to a contact or profile can never change an issued invoice.
 */
class CreateBillingDocs extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'legal_name'    => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true, 'default' => null],
            'trade_name'    => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true, 'default' => null],
            'gstin'         => ['type' => 'CHAR', 'constraint' => 15, 'null' => true, 'default' => null],
            'pan'           => ['type' => 'CHAR', 'constraint' => 10, 'null' => true, 'default' => null],
            'address_line1' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true, 'default' => null],
            'address_line2' => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true, 'default' => null],
            'city'          => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'state_code'    => ['type' => 'CHAR', 'constraint' => 2, 'null' => true, 'default' => null],
            'pincode'       => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true, 'default' => null],
            'phone'         => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'default' => null],
            'email'         => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'default' => null],
            'website'       => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'default' => null],
            'sac_code'      => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => '998554'],
            'bank_name'         => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
            'bank_account_name' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true, 'default' => null],
            'bank_account_no'   => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'default' => null],
            'bank_ifsc'         => ['type' => 'CHAR', 'constraint' => 11, 'null' => true, 'default' => null],
            'upi_id'            => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'invoice_prefix'    => ['type' => 'VARCHAR', 'constraint' => 4, 'default' => 'INV'],
            'credit_prefix'     => ['type' => 'VARCHAR', 'constraint' => 4, 'default' => 'CN'],
            'receipt_prefix'    => ['type' => 'VARCHAR', 'constraint' => 4, 'default' => 'RC'],
            'signatory'         => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
            'logo_url'          => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'default' => null],
            'brand_color'       => ['type' => 'CHAR', 'constraint' => 7, 'default' => '#4f46e5'],
            'quote_terms'       => ['type' => 'TEXT', 'null' => true],
            'invoice_terms'     => ['type' => 'TEXT', 'null' => true],
            'cancellation_policy' => ['type' => 'TEXT', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('tenant_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('business_profiles');

        $this->forge->addField([
            'id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'doc_type'  => ['type' => 'VARCHAR', 'constraint' => 20],
            'fy'        => ['type' => 'CHAR', 'constraint' => 7],
            'last_seq'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'doc_type', 'fy'], 'uq_doc_sequence');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('doc_sequences');

        $this->forge->addField([
            'id'          => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'booking_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'payment_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'related_id'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'doc_type'    => ['type' => 'ENUM', 'constraint' => ['tax_invoice', 'bill_of_supply', 'credit_note', 'receipt']],
            'number'      => ['type' => 'VARCHAR', 'constraint' => 20],
            'fy'          => ['type' => 'CHAR', 'constraint' => 7],
            'seq'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'issue_date'  => ['type' => 'DATE'],
            'status'      => ['type' => 'ENUM', 'constraint' => ['issued', 'cancelled'], 'default' => 'issued'],
            'seller'      => ['type' => 'JSON'],
            'buyer'       => ['type' => 'JSON'],
            'lines'       => ['type' => 'JSON'],
            'place_of_supply' => ['type' => 'CHAR', 'constraint' => 2, 'null' => true, 'default' => null],
            'supply_type' => ['type' => 'ENUM', 'constraint' => ['intra', 'inter', 'none'], 'default' => 'none'],
            'sac'         => ['type' => 'VARCHAR', 'constraint' => 8, 'null' => true, 'default' => null],
            'taxable_value' => ['type' => 'BIGINT', 'default' => 0],
            'gst_rate'    => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
            'cgst'        => ['type' => 'BIGINT', 'default' => 0],
            'sgst'        => ['type' => 'BIGINT', 'default' => 0],
            'igst'        => ['type' => 'BIGINT', 'default' => 0],
            'tcs_rate'    => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
            'tcs'         => ['type' => 'BIGINT', 'default' => 0],
            'total'       => ['type' => 'BIGINT', 'default' => 0],
            'reason'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'pdf_path'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'pdf_sha256'  => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'share_token' => ['type' => 'CHAR', 'constraint' => 32],
            'einvoice_irn' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'created_by'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'  => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'doc_type', 'number'], 'uq_invoice_number');
        $this->forge->addUniqueKey('share_token');
        $this->forge->addUniqueKey(['tenant_id', 'doc_type', 'fy', 'seq'], 'uq_invoice_seq');
        $this->forge->addKey(['tenant_id', 'booking_id']);
        $this->forge->addKey(['tenant_id', 'issue_date']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('invoices');
        // One receipt per payment row (NULL payment_id on other documents does not collide in MySQL unique indexes).
        $this->db->query('ALTER TABLE invoices ADD UNIQUE KEY uq_receipt_per_payment (tenant_id, payment_id, doc_type)');
    }

    public function down(): void
    {
        $this->forge->dropTable('invoices', true);
        $this->forge->dropTable('doc_sequences', true);
        $this->forge->dropTable('business_profiles', true);
    }
}
