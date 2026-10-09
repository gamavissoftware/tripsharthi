<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Itineraries (quotes) — versioned, day-wise, costed per line item.
 *
 * cost_* is what the agency pays suppliers; sell_* is what the traveller pays.
 * The margin lives in the difference, applied by PricingService. Tax columns
 * (GST + TCS) are computed on the itinerary total, never per line. A template
 * is just an itinerary with is_template=1 and no trip_id.
 */
class CreateItineraries extends Migration
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

    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'trip_id'           => $this->nullId(),
            'parent_id'         => $this->nullId(),
            'version'           => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 1],
            'is_template'       => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'title'             => ['type' => 'VARCHAR', 'constraint' => 255],
            'subtitle'          => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'destination_id'    => $this->nullId(),
            'nights'            => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'adults'            => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 2],
            'children'          => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'is_international' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'status'            => ['type' => 'ENUM', 'constraint' => ['draft', 'sent', 'viewed', 'accepted', 'rejected', 'expired', 'superseded'], 'default' => 'draft'],
            'currency'          => ['type' => 'CHAR', 'constraint' => 3, 'default' => 'INR'],
            'markup_type'       => ['type' => 'ENUM', 'constraint' => ['percent', 'flat'], 'default' => 'percent'],
            'markup_value'      => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 15],
            'discount_amount'   => $this->money(),
            'cost_total'        => $this->money(),
            'sell_subtotal'     => $this->money(),
            'gst_rate'          => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 5],
            'gst_amount'        => $this->money(),
            'tcs_rate'          => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
            'tcs_amount'        => $this->money(),
            'grand_total'       => $this->money(),
            'margin_amount'     => ['type' => 'BIGINT', 'default' => 0],
            'inclusions'        => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'exclusions'        => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'terms'             => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'cover_image'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'default' => null],
            'share_token'       => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'default' => null],
            'valid_until'       => ['type' => 'DATE', 'null' => true, 'default' => null],
            'sent_at'           => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'viewed_at'         => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'view_count'        => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'accepted_at'       => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_by'        => $this->nullId(),
            'ai_generated'      => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'trip_id']);
        $this->forge->addKey(['tenant_id', 'is_template']);
        $this->forge->addUniqueKey('share_token');
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('trip_id', 'trips', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('itineraries');

        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'itinerary_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'day_no'       => ['type' => 'SMALLINT', 'unsigned' => true],
            'title'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'city'         => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'description'  => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'image'        => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'default' => null],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addKey(['itinerary_id', 'day_no']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('itinerary_id', 'itineraries', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('itinerary_days');

        $this->forge->addField([
            'id'           => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'    => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'itinerary_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'day_id'       => $this->nullId(),
            'type'         => ['type' => 'ENUM', 'constraint' => ['hotel', 'flight', 'train', 'transfer', 'sightseeing', 'activity', 'meal', 'visa', 'insurance', 'other'], 'default' => 'other'],
            'title'        => ['type' => 'VARCHAR', 'constraint' => 255],
            'details'      => ['type' => 'TEXT', 'null' => true, 'default' => null],
            'supplier_id'  => $this->nullId(),
            'rate_id'      => $this->nullId(),
            'quantity'     => ['type' => 'DECIMAL', 'constraint' => '8,2', 'default' => 1],
            'nights'       => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 1],
            'unit_cost'    => $this->money(),
            'cost_amount'  => $this->money(),
            'is_optional'  => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'position'     => ['type' => 'SMALLINT', 'unsigned' => true, 'default' => 0],
            'meta'         => ['type' => 'JSON', 'null' => true],
        ] + $this->stamps());
        $this->forge->addKey('id', true);
        $this->forge->addKey(['itinerary_id', 'position']);
        $this->forge->addKey(['tenant_id', 'supplier_id']);
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('itinerary_id', 'itineraries', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('itinerary_items');
    }

    public function down(): void
    {
        foreach (['itinerary_items', 'itinerary_days', 'itineraries'] as $t) {
            $this->forge->dropTable($t, true);
        }
    }
}
