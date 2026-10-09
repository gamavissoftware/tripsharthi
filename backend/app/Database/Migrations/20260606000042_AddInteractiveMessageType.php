<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds 'interactive' to the messages.type ENUM.
 *
 * The flow engine's send_interactive node records the outbound message with
 * type='interactive', but the original ENUM omitted that value — so under MySQL
 * strict mode the insert was rejected after the message had already been sent.
 */
class AddInteractiveMessageType extends Migration
{
    public function up(): void
    {
        $this->db->query(
            "ALTER TABLE `messages`
             MODIFY COLUMN `type`
             ENUM('text','template','image','document','audio','video','interactive')
             NOT NULL DEFAULT 'text'"
        );
    }

    public function down(): void
    {
        $this->db->query(
            "ALTER TABLE `messages`
             MODIFY COLUMN `type`
             ENUM('text','template','image','document','audio','video')
             NOT NULL DEFAULT 'text'"
        );
    }
}
