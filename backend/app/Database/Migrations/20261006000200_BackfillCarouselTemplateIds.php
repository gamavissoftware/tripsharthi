<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Link past carousel sends to their template so the inbox can show the cards.
 *
 * 1. Campaign sends: the campaign knows its template (variant B uses the
 *    variant template).
 * 2. Everything else: a carousel template whose intro text equals the stored
 *    body, picking the newest such template that already existed when the
 *    message was sent (templates are often re-created as "_v2" with the same
 *    text, so the body alone is ambiguous).
 *
 * Only touches template messages with no template_id; safe to re-run.
 */
class BackfillCarouselTemplateIds extends Migration
{
    public function up(): void
    {
        $carousel = "t.cards IS NOT NULL AND t.cards NOT IN ('', '[]', 'null')";

        $this->db->query(
            "UPDATE messages m
             JOIN campaigns c ON c.id = m.campaign_id AND c.tenant_id = m.tenant_id
             JOIN templates t ON t.id = IF(m.variant = 'B' AND c.variant_template_id IS NOT NULL, c.variant_template_id, c.template_id)
             SET m.template_id = t.id
             WHERE m.type = 'template' AND m.template_id IS NULL AND {$carousel}"
        );

        $this->db->query(
            "UPDATE messages m
             JOIN templates t ON t.id = (
                 SELECT t2.id FROM templates t2
                 WHERE t2.tenant_id = m.tenant_id AND t2.body = m.body
                   AND t2.cards IS NOT NULL AND t2.cards NOT IN ('', '[]', 'null')
                   AND t2.created_at <= m.created_at
                 ORDER BY t2.created_at DESC, t2.id DESC LIMIT 1)
             SET m.template_id = t.id
             WHERE m.type = 'template' AND m.template_id IS NULL"
        );
    }

    public function down(): void
    {
        // Data backfill; nothing to undo.
    }
}
