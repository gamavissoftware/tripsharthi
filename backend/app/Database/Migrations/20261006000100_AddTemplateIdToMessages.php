<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Remember which template an outbound template message used, so the inbox can
 * show a carousel's cards (image + text + buttons) instead of just its intro.
 */
class AddTemplateIdToMessages extends Migration
{
    public function up(): void
    {
        if (! $this->db->fieldExists('template_id', 'messages')) {
            $this->forge->addColumn('messages', [
                'template_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true, 'after' => 'campaign_id'],
            ]);
        }

        // Backfill history: link past carousel sends whose stored body is
        // exactly the carousel template's intro text (unambiguous matches only).
        $this->db->query(
            "UPDATE messages m
             JOIN (SELECT tenant_id, body, MIN(id) AS id, COUNT(*) AS n FROM templates
                   WHERE cards IS NOT NULL AND cards NOT IN ('', '[]', 'null') GROUP BY tenant_id, body) t
               ON t.tenant_id = m.tenant_id AND t.body = m.body AND t.n = 1
             SET m.template_id = t.id
             WHERE m.type = 'template' AND m.template_id IS NULL"
        );
    }

    public function down(): void
    {
        $this->forge->dropColumn('messages', 'template_id');
    }
}
