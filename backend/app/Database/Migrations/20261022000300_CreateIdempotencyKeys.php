<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * "Do this once" keys for requests a phone may have to RETRY (it saved a task while offline and the connection dropped again while sending).
 * The first answer is stored; a retry with the same key gets the same answer and creates nothing new. Kept for 3 days.
 */
class CreateIdempotencyKeys extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id'    => ['type' => 'INT', 'unsigned' => true],
            'idem_key'   => ['type' => 'VARCHAR', 'constraint' => 64],
            'method'     => ['type' => 'VARCHAR', 'constraint' => 8],
            'path_hash'  => ['type' => 'CHAR', 'constraint' => 32],
            'status'     => ['type' => 'SMALLINT', 'unsigned' => true, 'null' => true],
            'response'   => ['type' => 'MEDIUMTEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['user_id', 'idem_key']);
        $this->forge->addKey('created_at');
        $this->forge->createTable('idempotency_keys', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('idempotency_keys', true);
    }
}
