<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Index for filtering the contact list by category.
 *
 * `business_type` carries the company category on imported CRM contacts, and
 * the contacts screen now filters and builds its category dropdown from it.
 * Both are full scans without this index — tolerable at a few hundred rows,
 * wasteful once a tenant imports tens of thousands.
 */
class AddContactsBusinessTypeIndex extends Migration
{
    public function up(): void
    {
        $this->db->query('CREATE INDEX idx_contacts_tenant_business_type ON contacts (tenant_id, business_type)');
    }

    public function down(): void
    {
        $this->db->query('DROP INDEX idx_contacts_tenant_business_type ON contacts');
    }
}
