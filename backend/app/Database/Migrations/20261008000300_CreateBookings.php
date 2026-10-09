<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Bookings — a confirmed trip: instalment schedule (customer receivables),
 * supplier services (payables / vouchers) and travellers (passport + visa).
 * Passport numbers are stored encrypted (passport_no_enc) — DPDP Act.
 */
class CreateBookings extends Migration
{
    private function stamps(): array
    {
        return [
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ];
    }

    private function nullId(): array
    {
        return ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null];
    }

    private function money(): array
    {
        return ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0];
    }

    private function head(): array
    {
        return [
            'id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
        ];
    }

    public function up(): void
    {
        $this->forge->addField($this->head() + [
            'booking_ref'     => ['type' => 'VARCHAR', 'constraint' => 30],
            'trip_id'         => $this->nullId(),
            'deal_id'         => $this->nullId(),
            'itinerary_id'    => $this->nullId(),
            'contact_id'      => $this->nullId(),
            'owner_id'        => $this->nullId(),
            'title'           => ['type' => 'VARCHAR', 'constraint' => 255],
            'status'          => ['type' => 'ENUM', 'constraint' => ['confirmed', 'documents_pending', 'ready', 'travelling', 'completed', 'cancelled'], 'default' => 'confirmed'],
            'travel_start'    => ['type' => 'DATE', 'null' => true, 'default' => null],
            'travel_end'      => ['type' => 'DATE', 'null' => true, 'default' => null],
            'is_international' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'subtotal'        => $this->money(),
            'gst_amount'      => $this->money(),
            'tcs_amount'      => $this->money(),
            'total_amount'    => $this->money(),
            'paid_amount'     => $this->money(),
            'cost_total'      => $this->money(),
            'supplier_paid'   => $this->money(),
            'cancelled_at'    => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'cancel_reason'   => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'notes'           => ['type' => 'TEXT', 'null' => true, 'default' => null],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'booking_ref']);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addKey(['tenant_id', 'travel_start']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('trip_id', 'trips', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('contact_id', 'contacts', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('bookings');

        // Customer payment schedule (booking amount, instalments, balance).
        $this->forge->addField($this->head() + [
            'booking_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'label'           => ['type' => 'VARCHAR', 'constraint' => 100],
            'due_date'        => ['type' => 'DATE', 'null' => true, 'default' => null],
            'amount'          => $this->money(),
            'status'          => ['type' => 'ENUM', 'constraint' => ['pending', 'paid', 'overdue', 'waived'], 'default' => 'pending'],
            'paid_at'         => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'mode'            => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'default' => null],
            'reference'       => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
            'payment_link_id' => $this->nullId(),
            'reminder_count'  => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 0],
            'last_reminded_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'status', 'due_date']);
        $this->forge->addKey('booking_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('booking_id', 'bookings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_payments');

        // Supplier services (hotel/transfer confirmations, vouchers, payables).
        $this->forge->addField($this->head() + [
            'booking_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'item_id'         => $this->nullId(),
            'supplier_id'     => $this->nullId(),
            'service_type'    => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'other'],
            'title'           => ['type' => 'VARCHAR', 'constraint' => 255],
            'service_date'    => ['type' => 'DATE', 'null' => true, 'default' => null],
            'status'          => ['type' => 'ENUM', 'constraint' => ['to_book', 'requested', 'confirmed', 'cancelled'], 'default' => 'to_book'],
            'confirmation_no' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'cost_amount'     => $this->money(),
            'paid_amount'     => $this->money(),
            'pay_by'          => ['type' => 'DATE', 'null' => true, 'default' => null],
            'voucher_token'   => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'notes'           => ['type' => 'TEXT', 'null' => true, 'default' => null],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addKey('booking_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('booking_id', 'bookings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('booking_services');

        // Travellers.
        $this->forge->addField($this->head() + [
            'booking_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'contact_id'      => $this->nullId(),
            'full_name'       => ['type' => 'VARCHAR', 'constraint' => 200],
            'pax_type'        => ['type' => 'ENUM', 'constraint' => ['adult', 'child', 'infant'], 'default' => 'adult'],
            'gender'          => ['type' => 'ENUM', 'constraint' => ['male', 'female', 'other'], 'null' => true, 'default' => null],
            'dob'             => ['type' => 'DATE', 'null' => true, 'default' => null],
            'nationality'     => ['type' => 'VARCHAR', 'constraint' => 60, 'default' => 'Indian'],
            'passport_no_enc' => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'passport_expiry' => ['type' => 'DATE', 'null' => true, 'default' => null],
            'visa_status'     => ['type' => 'ENUM', 'constraint' => ['not_required', 'pending', 'applied', 'approved', 'rejected'], 'default' => 'pending'],
            'meal_pref'       => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true, 'default' => null],
            'is_lead'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'docs'            => ['type' => 'JSON', 'null' => true],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addKey('booking_id');
        $this->forge->addKey(['tenant_id', 'passport_expiry']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('booking_id', 'bookings', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('travelers');
    }

    public function down(): void
    {
        foreach (['travelers', 'booking_services', 'booking_payments', 'bookings'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
