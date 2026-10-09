<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Make contacts.wa_number nullable so a contact can be email-only (CRM lead with
 * no WhatsApp number yet, or an unknown inbound-email sender). The existing
 * UNIQUE(tenant_id, wa_number) is kept — in MySQL multiple NULLs do not collide,
 * so any number of email-only contacts can coexist, while real numbers stay
 * unique per tenant. Email-only contacts must store NULL (never '').
 */
class AlterContactsWaNumberNullable extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('contacts', [
            'wa_number' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'default' => null],
        ]);
    }

    public function down(): void
    {
        // Revert requires no NULLs present; callers should backfill before rolling back.
        $this->forge->modifyColumn('contacts', [
            'wa_number' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false],
        ]);
    }
}
