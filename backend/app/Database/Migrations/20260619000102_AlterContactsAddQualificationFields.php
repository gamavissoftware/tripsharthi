<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Lead qualification fields (CRM richness, phase 1). Brings the contact/lead
 * record up to a full B2B lead profile so it's useful across industries:
 * location, business/requirement context, budget + timeline, qualification and
 * priority, and free-form remarks. All nullable — purely additive, no behaviour
 * change for existing tenants.
 */
class AlterContactsAddQualificationFields extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('contacts', [
            'city'                 => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'after' => 'phone_secondary'],
            'state'                => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'after' => 'city'],
            'country'              => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'after' => 'state'],
            'business_type'        => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'after' => 'country'],
            'requirement_type'     => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true, 'after' => 'business_type'],
            'current_process'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'requirement_type'],
            'budget_amount'        => ['type' => 'BIGINT', 'null' => true, 'after' => 'current_process'],
            'timeline'             => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'after' => 'budget_amount'],
            'qualification_status' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'after' => 'timeline'],
            'ai_call_status'       => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'after' => 'qualification_status'],
            'priority'             => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => false, 'default' => 'medium', 'after' => 'ai_call_status'],
            'remarks'              => ['type' => 'TEXT', 'null' => true, 'after' => 'priority'],
        ]);
    }

    public function down(): void
    {
        foreach (['city', 'state', 'country', 'business_type', 'requirement_type', 'current_process',
            'budget_amount', 'timeline', 'qualification_status', 'ai_call_status', 'priority', 'remarks'] as $col) {
            $this->forge->dropColumn('contacts', $col);
        }
    }
}
