<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** TripSarthi brand blue replaces the old indigo as the default colour of generated documents (only rows still on the old default move). */
class RebrandDefaultColor extends Migration
{
    public function up(): void
    {
        $this->db->query("ALTER TABLE business_profiles MODIFY brand_color CHAR(7) NOT NULL DEFAULT '#0a6cc4'");
        $this->db->query("UPDATE business_profiles SET brand_color = '#0a6cc4' WHERE brand_color = '#4f46e5'");
    }

    public function down(): void
    {
        $this->db->query("ALTER TABLE business_profiles MODIFY brand_color CHAR(7) NOT NULL DEFAULT '#4f46e5'");
    }
}
