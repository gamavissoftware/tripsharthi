<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A lead event that ends as `failed` said nothing about why. The first real
 * delivery from Meta's testing tool did exactly that — the dummy phone
 * number could not be normalised — and the only way to find out was to
 * reason backwards from the worker's exit status. Record the reason.
 */
class AddErrorToMetaLeadEvents extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('meta_lead_events', [
            'error' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'contact_id'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('meta_lead_events', 'error');
    }
}
