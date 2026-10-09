<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Record document attachments (CRM richness). Files uploaded against any CRM
 * record (contact/deal/ticket/…) — stored on disk under a server-generated name;
 * this table keeps the metadata. The original filename is display-only; the
 * stored_path is never user-controlled (no path traversal).
 */
class CreateDocuments extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'            => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'tenant_id'     => ['type' => 'BIGINT', 'unsigned' => true],
            'related_type'  => ['type' => 'VARCHAR', 'constraint' => 32],
            'related_id'    => ['type' => 'BIGINT', 'unsigned' => true],
            'filename'      => ['type' => 'VARCHAR', 'constraint' => 255], // original, display-only
            'stored_path'   => ['type' => 'VARCHAR', 'constraint' => 255], // relative to writable/uploads, server-generated
            'mime'          => ['type' => 'VARCHAR', 'constraint' => 127, 'null' => true],
            'size_bytes'    => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'uploaded_by'   => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'related_type', 'related_id']);
        $this->forge->createTable('documents', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('documents', true);
    }
}
