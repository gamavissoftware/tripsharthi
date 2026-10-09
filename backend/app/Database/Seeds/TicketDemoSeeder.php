<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use App\Models\TicketModel;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Seeds demo support tickets for tenant 1 so the Tickets page is populated.
 * Idempotent: skips if tickets already exist.
 */
class TicketDemoSeeder extends Seeder
{
    public function run(): void
    {
        if ($this->db->table('tickets')->where('tenant_id', 1)->countAllResults() > 0) {
            CLI::write('TicketDemoSeeder: tickets already present, skipping.', 'yellow');
            return;
        }

        $contacts = $this->db->table('contacts')->where('tenant_id', 1)->orderBy('id', 'ASC')->limit(4)->get()->getResultArray();
        $cid = static fn (int $i) => $contacts[$i]['id'] ?? null;
        $now = date('Y-m-d H:i:s');

        $tickets = [
            // [subject, status, priority, source, contact idx, sla_offset_seconds_from_now]
            ['Payment not reflecting after UPI',        'open',     'urgent', 'whatsapp', 0, -3600],   // breached
            ['How do I change my delivery address?',    'pending',  'medium', 'whatsapp', 1,  3600 * 12],
            ['Refund request for order #4821',          'open',     'high',   'email',    2,  3600 * 2],
            ['Feature request: dark mode',              'resolved', 'low',    'web',      3,  null],
        ];

        foreach ($tickets as [$subject, $status, $priority, $source, $idx, $slaOffset]) {
            $this->db->table('tickets')->insert([
                'tenant_id'   => 1,
                'subject'     => $subject,
                'contact_id'  => $cid($idx),
                'status'      => $status,
                'priority'    => $priority,
                'source'      => $source,
                'owner_id'    => 1,
                'sla_due_at'  => $slaOffset !== null ? date('Y-m-d H:i:s', time() + $slaOffset) : TicketModel::slaDueAt($priority),
                'resolved_at' => $status === 'resolved' ? $now : null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        CLI::write('TicketDemoSeeder: 4 demo tickets seeded.', 'green');
    }
}
