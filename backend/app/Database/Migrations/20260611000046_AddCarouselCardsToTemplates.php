<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Carousel template support.
 *
 * `cards` holds a JSON array of carousel cards (NULL for a normal template):
 *   [{ "image_url": "...", "body": "...", "buttons": [{type,text,url}, …] }, …]
 *
 * The carousel's intro message reuses the existing `body` column. A template is
 * a carousel when `cards` is a non-empty JSON array.
 */
class AddCarouselCardsToTemplates extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('templates', [
            'cards' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'buttons',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('templates', 'cards');
    }
}
