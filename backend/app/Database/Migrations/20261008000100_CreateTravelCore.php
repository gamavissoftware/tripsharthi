<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Travel vertical — core master data + the trip (enquiry requirements).
 *
 * A `trips` row is the travel-specific extension of a CRM `deals` row (1:1 via
 * deal_id), so pipelines, forecast, rotting alerts, assignment rules and flow
 * triggers all keep working unchanged. Money is stored in paise (BIGINT).
 */
class CreateTravelCore extends Migration
{
    private function base(): array
    {
        return [
            'id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
        ];
    }

    private function stamps(): array
    {
        return [
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
        ];
    }

    private function id(string $null = 'null'): array
    {
        return ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null];
    }

    public function up(): void
    {
        // ---- destinations -------------------------------------------------
        $this->forge->addField($this->base() + [
            'name'         => ['type' => 'VARCHAR', 'constraint' => 150],
            'country'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'region'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'is_domestic'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'best_months'  => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'tagline'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'description'  => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'cover_image'  => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'default' => null],
            'visa_info'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'is_active'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'name']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('destinations');

        // ---- suppliers ----------------------------------------------------
        $this->forge->addField($this->base() + [
            'name'            => ['type' => 'VARCHAR', 'constraint' => 200],
            'type'            => ['type' => 'ENUM', 'constraint' => ['hotel', 'transport', 'activity', 'dmc', 'airline', 'visa', 'insurance', 'guide', 'cruise', 'other'], 'default' => 'hotel'],
            'city'            => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'country'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'contact_name'    => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true, 'default' => null],
            'phone'           => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'default' => null],
            'whatsapp'        => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'default' => null],
            'email'           => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true, 'default' => null],
            'gstin'           => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'default' => null],
            'pan'             => ['type' => 'VARCHAR', 'constraint' => 12, 'null' => true, 'default' => null],
            'payment_terms'   => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true, 'default' => null],
            'commission_pct'  => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
            'rating'          => ['type' => 'TINYINT', 'constraint' => 1, 'null' => true, 'default' => null],
            'notes'           => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'is_active'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'type']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('suppliers');

        // ---- supplier_rates (rate cards, seasonal) ------------------------
        $this->forge->addField($this->base() + [
            'supplier_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'destination_id' => $this->id(),
            'service_name'   => ['type' => 'VARCHAR', 'constraint' => 200],
            'service_type'   => ['type' => 'ENUM', 'constraint' => ['hotel', 'transfer', 'sightseeing', 'activity', 'flight', 'visa', 'insurance', 'meal', 'other'], 'default' => 'hotel'],
            'unit'           => ['type' => 'ENUM', 'constraint' => ['per_night', 'per_pax', 'per_vehicle', 'per_day', 'per_room', 'flat'], 'default' => 'per_night'],
            'currency'       => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'INR'],
            'cost_amount'    => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'valid_from'     => ['type' => 'DATE', 'null' => true, 'default' => null],
            'valid_to'       => ['type' => 'DATE', 'null' => true, 'default' => null],
            'meta'           => ['type' => 'JSON', 'null' => true],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'supplier_id']);
        $this->forge->addKey(['tenant_id', 'destination_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('supplier_id', 'suppliers', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('supplier_rates');

        // ---- trips (enquiry requirements; 1:1 with a deal) ----------------
        $this->forge->addField($this->base() + [
            'deal_id'          => $this->id(),
            'contact_id'       => $this->id(),
            'owner_id'         => $this->id(),
            'title'            => ['type' => 'VARCHAR', 'constraint' => 255],
            'trip_type'        => ['type' => 'ENUM', 'constraint' => ['leisure', 'honeymoon', 'family', 'friends', 'solo', 'pilgrimage', 'adventure', 'corporate', 'mice', 'group_departure', 'student', 'umrah_hajj', 'visa_only', 'flight_only', 'hotel_only', 'other'], 'default' => 'leisure'],
            'destination_id'   => $this->id(),
            'destination_text' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'is_international' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'origin_city'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'start_date'       => ['type' => 'DATE', 'null' => true, 'default' => null],
            'end_date'         => ['type' => 'DATE', 'null' => true, 'default' => null],
            'flexible_dates'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'travel_month'     => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'default' => null],
            'nights'           => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true, 'default' => null],
            'adults'           => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 2],
            'children'         => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'infants'          => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'child_ages'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'budget_min'       => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'default' => null],
            'budget_max'       => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true, 'default' => null],
            'budget_basis'     => ['type' => 'ENUM', 'constraint' => ['per_person', 'total'], 'default' => 'per_person'],
            'hotel_category'   => ['type' => 'ENUM', 'constraint' => ['budget', '3_star', '4_star', '5_star', 'luxury', 'any'], 'default' => 'any'],
            'meal_plan'        => ['type' => 'ENUM', 'constraint' => ['none', 'breakfast', 'half_board', 'full_board', 'all_inclusive', 'any'], 'default' => 'any'],
            'interests'        => ['type' => 'JSON', 'null' => true],
            'requirements'     => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'passport_status'  => ['type' => 'ENUM', 'constraint' => ['unknown', 'valid', 'expiring', 'none', 'applied'], 'default' => 'unknown'],
            'visa_status'      => ['type' => 'ENUM', 'constraint' => ['not_required', 'unknown', 'needed', 'applied', 'approved', 'rejected'], 'default' => 'unknown'],
            'travel_intent'    => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true, 'default' => null],
            'ai_summary'       => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'status'           => ['type' => 'ENUM', 'constraint' => ['enquiry', 'quoted', 'negotiating', 'booked', 'travelling', 'completed', 'lost', 'cancelled'], 'default' => 'enquiry'],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'status']);
        $this->forge->addKey(['tenant_id', 'start_date']);
        $this->forge->addKey(['tenant_id', 'contact_id']);
        $this->forge->addKey(['tenant_id', 'deal_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('deal_id', 'deals', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('contact_id', 'contacts', 'id', 'SET NULL', 'CASCADE');
        $this->forge->addForeignKey('owner_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('trips');
    }

    public function down(): void
    {
        foreach (['trips', 'supplier_rates', 'suppliers', 'destinations'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
