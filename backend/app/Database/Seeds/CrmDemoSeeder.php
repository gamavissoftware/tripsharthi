<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Seeds CRM (Phase A) demo data under tenant id=1: a few accounts, links some
 * demo contacts to them with lifecycle stages, and adds sample tasks, notes and
 * timeline activities — so a fresh install shows a populated CRM.
 *
 * Idempotent: skips if any accounts already exist for tenant 1.
 */
class CrmDemoSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        if ($this->db->table('accounts')->where('tenant_id', 1)->countAllResults() > 0) {
            CLI::write('CrmDemoSeeder: accounts already present, skipping.', 'yellow');
            return;
        }

        // ── Accounts ──────────────────────────────────────────────────
        $accounts = [
            ['Nimbus Retail',    'nimbusretail.com', 'Retail',   'customer'],
            ['Orbit Logistics',  'orbitlog.com',     'Logistics', 'prospect'],
            ['Vertex Solutions', 'vertex.io',        'Software',  'prospect'],
        ];
        $accountIds = [];
        foreach ($accounts as [$name, $domain, $industry, $type]) {
            $this->db->table('accounts')->insert([
                'tenant_id' => 1, 'name' => $name, 'domain' => $domain,
                'industry' => $industry, 'type' => $type, 'owner_id' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $accountIds[$name] = (int) $this->db->insertID();
        }

        // ── Link a few demo contacts → accounts + lifecycle ───────────
        $links = [
            ['+919999900101', 'Nimbus Retail',    'customer',    'Procurement Lead'],
            ['+919999900102', 'Nimbus Retail',    'opportunity', 'Store Manager'],
            ['+919999900103', 'Orbit Logistics',  'sql',         'Ops Head'],
            ['+919999900108', 'Vertex Solutions', 'mql',         'CTO'],
        ];
        $linkedContactId = null;
        foreach ($links as [$wa, $accName, $stage, $title]) {
            $c = $this->db->table('contacts')->where('tenant_id', 1)->where('wa_number', $wa)->get()->getRow();
            if (! $c) {
                continue;
            }
            $this->db->table('contacts')->where('id', $c->id)->update([
                'account_id'      => $accountIds[$accName] ?? null,
                'lifecycle_stage' => $stage,
                'job_title'       => $title,
                'owner_id'        => 1,
                'updated_at'      => $now,
            ]);
            $linkedContactId ??= (int) $c->id;
        }

        // ── Tasks, notes, activities on the first linked contact ──────
        if ($linkedContactId !== null) {
            $this->db->table('tasks')->insert([
                'tenant_id' => 1, 'title' => 'Send pricing proposal', 'type' => 'whatsapp',
                'priority' => 'high', 'status' => 'open', 'assigned_user_id' => 1,
                'related_type' => 'contact', 'related_id' => $linkedContactId,
                'due_at' => date('Y-m-d H:i:s', strtotime('+2 days')), 'created_by' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->db->table('notes')->insert([
                'tenant_id' => 1, 'body' => 'Budget approved for this quarter — decision maker confirmed.',
                'related_type' => 'contact', 'related_id' => $linkedContactId, 'is_pinned' => 1,
                'created_by' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->db->table('activities')->insert([
                'tenant_id' => 1, 'type' => 'call', 'subject' => 'Discovery call',
                'body' => '20 min — strong interest, asked for a quote.',
                'related_type' => 'contact', 'related_id' => $linkedContactId, 'actor_user_id' => 1,
                'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        CLI::write('CrmDemoSeeder: 3 accounts, 4 linked contacts, sample task/note/activity seeded.', 'green');
    }
}
