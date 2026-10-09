<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Campaign A/B testing (P4.2).
 *
 * A campaign may carry a second (variant B) template and a split percentage.
 * Each recipient is deterministically bucketed (contact_id % 100 < ab_split → B),
 * and the chosen variant is stamped on the message so analytics can compare
 * delivery / read / click rates per variant.
 */
class AddAbTestingColumns extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('campaigns', [
            'variant_template_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null, 'after' => 'template_id'],
            'ab_split'            => ['type' => 'INT', 'constraint' => 3, 'default' => 0, 'after' => 'variant_template_id'],
        ]);

        $this->forge->addColumn('messages', [
            'variant' => ['type' => 'VARCHAR', 'constraint' => 1, 'null' => true, 'default' => null, 'after' => 'campaign_id'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('campaigns', ['variant_template_id', 'ab_split']);
        $this->forge->dropColumn('messages', 'variant');
    }
}
