<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Credit notes are billing CORRECTIONS (no customer refunds): every one carries a reason category. */
class AddReasonCodeToCreditNotes extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('platform_credit_notes', ['reason_code' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'other', 'after' => 'reason']]);
    }

    public function down(): void { $this->forge->dropColumn('platform_credit_notes', 'reason_code'); }
}
