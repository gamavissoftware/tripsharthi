<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * TripSarthi's OWN GST tax invoices to its customers (a SaaS sale by Gamavis Software Solutions). Separate from the per-workspace `invoices`
 * table (those are what a travel agency issues to ITS travellers). Rows are immutable once issued: the seller and buyer are SNAPSHOTS and the
 * PDF is stored once with its sha256. Numbers are gap-free per series + financial year (`platform_doc_sequences`, row-locked).
 */
class CreatePlatformInvoices extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'              => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'number'          => ['type' => 'VARCHAR', 'constraint' => 20],
            'series'          => ['type' => 'VARCHAR', 'constraint' => 8],
            'fy'              => ['type' => 'CHAR', 'constraint' => 7],                       // 2026-27
            'seq'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'issue_date'      => ['type' => 'DATE'],                                          // India date
            'tenant_id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'subscription_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'source'          => ['type' => 'VARCHAR', 'constraint' => 8, 'default' => 'online'],   // online (Razorpay) | offline (recorded by hand)
            'payment_ref'     => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'plan'            => ['type' => 'VARCHAR', 'constraint' => 20],
            'billing_cycle'   => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
            'period_start'    => ['type' => 'DATETIME', 'null' => true],
            'period_end'      => ['type' => 'DATETIME', 'null' => true],
            'description'     => ['type' => 'VARCHAR', 'constraint' => 300],
            'buyer_type'      => ['type' => 'VARCHAR', 'constraint' => 3],                    // b2b (has GSTIN) | b2c
            'buyer_name'      => ['type' => 'VARCHAR', 'constraint' => 200],
            'buyer_gstin'     => ['type' => 'VARCHAR', 'constraint' => 15, 'null' => true],
            'place_of_supply' => ['type' => 'CHAR', 'constraint' => 2],
            'supply_type'     => ['type' => 'VARCHAR', 'constraint' => 5],                    // intra | inter
            'sac'             => ['type' => 'VARCHAR', 'constraint' => 8],
            'gst_rate'        => ['type' => 'DECIMAL', 'constraint' => '5,2'],
            'taxable_value'   => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'cgst'            => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
            'sgst'            => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
            'igst'            => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
            'total'           => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'seller'          => ['type' => 'TEXT'],                                          // JSON snapshot
            'buyer'           => ['type' => 'TEXT'],                                          // JSON snapshot
            'pdf_path'        => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'pdf_sha256'      => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'email_to'        => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'emailed_at'      => ['type' => 'DATETIME', 'null' => true],
            'email_attempts'  => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'email_error'     => ['type' => 'VARCHAR', 'constraint' => 300, 'null' => true],
            'created_at'      => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('number');
        $this->forge->addUniqueKey(['series', 'fy', 'seq']);
        $this->forge->addUniqueKey('subscription_id');                                        // one invoice per paid period, ever
        $this->forge->addKey(['issue_date']);
        $this->forge->addKey(['tenant_id', 'issue_date']);
        $this->forge->createTable('platform_invoices');

        $this->forge->addField([
            'series'   => ['type' => 'VARCHAR', 'constraint' => 8],
            'fy'       => ['type' => 'CHAR', 'constraint' => 7],
            'last_seq' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addKey(['series', 'fy'], true);
        $this->forge->createTable('platform_doc_sequences');
    }

    public function down(): void
    {
        $this->forge->dropTable('platform_doc_sequences', true);
        $this->forge->dropTable('platform_invoices', true);
    }
}
