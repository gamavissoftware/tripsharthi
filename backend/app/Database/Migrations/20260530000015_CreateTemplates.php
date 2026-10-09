<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTemplates extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'tenant_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],

            // Meta template name: lowercase, underscores, no spaces (e.g. welcome_message)
            'name'         => ['type' => 'VARCHAR', 'constraint' => 512],
            // Human-readable label shown in the UI
            'display_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'default' => null],
            'language'     => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'en'],

            // Drives billable computation (BillableComputer)
            'category' => [
                'type'       => 'ENUM',
                'constraint' => ['marketing', 'utility', 'authentication'],
                'default'    => 'marketing',
            ],
            'header_type' => [
                'type'       => 'ENUM',
                'constraint' => ['none', 'text', 'image', 'document', 'video'],
                'default'    => 'none',
            ],
            // For text header: the text. For media headers: public URL or Meta media handle.
            'header_content' => ['type' => 'TEXT',        'null' => true, 'default' => null],
            'body'           => ['type' => 'TEXT'],
            'footer'         => ['type' => 'VARCHAR', 'constraint' => 60,  'null' => true, 'default' => null],
            'buttons'        => ['type' => 'JSON',         'null' => true, 'default' => null],
            // [{"index":1,"example":"John Doe"}, …] — required by Meta for submission
            'variables'      => ['type' => 'JSON',         'null' => true, 'default' => null],

            // Meta-assigned ID after successful submission
            'meta_template_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true, 'default' => null],
            'meta_status' => [
                'type'       => 'ENUM',
                'constraint' => ['draft', 'pending', 'approved', 'rejected', 'paused', 'disabled'],
                'default'    => 'draft',
            ],
            'rejection_reason' => ['type' => 'TEXT',     'null' => true, 'default' => null],
            'submitted_at'     => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            'created_at'       => ['type' => 'DATETIME', 'null' => true],
            'updated_at'       => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'       => ['type' => 'DATETIME', 'null' => true],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['tenant_id', 'meta_status']);
        $this->forge->addKey('meta_template_id'); // cross-tenant lookup for webhook status updates
        $this->forge->addForeignKey('tenant_id', 'tenants', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('templates');
    }

    public function down(): void
    {
        $this->forge->dropTable('templates', true);
    }
}
