<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * One row per follow-up we have decided to send, written before the trigger
 * fires.
 *
 * It exists to answer two questions that nothing else in the schema can:
 *
 *  1. "Have we already chased this particular send?" — the unique key on
 *     (source_message_id, step) makes a duplicate physically impossible, even
 *     if the scanner runs twice in the same minute or a cron overlaps itself.
 *
 *  2. "How many times have we chased this person, ever?" — messages cannot
 *     answer this, because an outbound template might be a fresh campaign
 *     rather than a nudge. Contact fatigue is the thing that produces blocks,
 *     and blocks are what restrict a sending number, so the cap has to be
 *     countable.
 */
class CreateFollowUps extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'         => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'contact_id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            // The outbound marketing template that went unanswered.
            'source_message_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'source_template_id'=> ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true, 'default' => null],
            // 1 = first nudge, 2 = second. Capped in FollowUpScanner.
            'step'              => ['type' => 'TINYINT', 'constraint' => 2, 'default' => 1],
            // The category-specific phrase handed to the template's {{2}}.
            'hook'              => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['source_message_id', 'step'], 'follow_ups_source_step');
        // Counting a contact's prior nudges is the hot read.
        $this->forge->addKey(['tenant_id', 'contact_id']);
        $this->forge->addKey('created_at');
        $this->forge->createTable('follow_ups');
    }

    public function down(): void
    {
        $this->forge->dropTable('follow_ups');
    }
}
