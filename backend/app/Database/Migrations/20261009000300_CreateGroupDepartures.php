<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Group / fixed departures with seat inventory. Seats are held (with expiry) or confirmed; availability is always computed, never stored. */
class CreateGroupDepartures extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'code'          => ['type' => 'VARCHAR', 'constraint' => 30],
            'title'         => ['type' => 'VARCHAR', 'constraint' => 200],
            'destination_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'start_date'    => ['type' => 'DATE'],
            'end_date'      => ['type' => 'DATE'],
            'total_seats'   => ['type' => 'SMALLINT', 'unsigned' => true],
            'min_pax'       => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'price_pax'     => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],       // sell price per person, ex-tax (paise)
            'cost_pax'      => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],       // internal cost per person (paise) — never customer-facing
            'child_price_pct' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 100],
            'single_supplement'      => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'single_supplement_cost' => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'is_international' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'gst_rate'      => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 5],
            'sell_cutoff'   => ['type' => 'DATE', 'null' => true, 'default' => null],
            'status'        => ['type' => 'ENUM', 'constraint' => ['open', 'closed', 'cancelled', 'completed'], 'default' => 'open'],
            'inclusions'    => ['type' => 'TEXT', 'null' => true],
            'exclusions'    => ['type' => 'TEXT', 'null' => true],
            'notes'         => ['type' => 'TEXT', 'null' => true],
            'created_by'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['tenant_id', 'code']);
        $this->forge->addKey(['tenant_id', 'status', 'start_date']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('departures');

        $this->forge->addField([
            'id'             => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'      => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'departure_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'trip_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'contact_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'itinerary_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'booking_id'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'adults'         => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 1],
            'children'       => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'single_rooms'   => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'seats'          => ['type' => 'SMALLINT', 'unsigned' => true],
            'status'         => ['type' => 'ENUM', 'constraint' => ['held', 'confirmed', 'released'], 'default' => 'held'],
            'hold_expires_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'released_reason' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'default' => null],
            'created_by'     => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'departure_id', 'status']);
        $this->forge->addKey(['tenant_id', 'trip_id']);
        $this->forge->addKey('itinerary_id');
        $this->forge->addKey('booking_id');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('departure_id', 'departures', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('departure_seats');
    }

    public function down(): void
    {
        $this->forge->dropTable('departure_seats', true);
        $this->forge->dropTable('departures', true);
    }
}
