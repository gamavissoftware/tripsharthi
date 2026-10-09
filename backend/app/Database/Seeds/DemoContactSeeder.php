<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Seeds 10 demo contacts under tenant id=1 so the app is immediately
 * testable without importing a CSV.
 */
class DemoContactSeeder extends Seeder
{
    private array $contacts = [
        ['+919999900101', 'Aarav Shah',     'aarav@example.com',   'new',       'manual'],
        ['+919999900102', 'Priya Mehta',    'priya@example.com',   'contacted', 'csv_import'],
        ['+919999900103', 'Rohit Verma',    null,                  'qualified', 'web_form'],
        ['+919999900104', 'Sneha Joshi',    'sneha@example.com',   'won',       'manual'],
        ['+919999900105', 'Vikram Nair',    null,                  'lost',      'csv_import'],
        ['+919999900106', 'Ananya Reddy',   'ananya@example.com',  'new',       'meta_lead_ads'],
        ['+919999900107', 'Karan Kapoor',   null,                  'contacted', 'manual'],
        ['+919999900108', 'Diya Iyer',      'diya@example.com',    'qualified', 'web_form'],
        ['+919999900109', 'Arjun Patel',    null,                  'new',       'csv_import'],
        ['+919999900110', 'Meera Singh',    'meera@example.com',   'new',       'manual'],
    ];

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach ($this->contacts as [$wa, $name, $email, $status, $source]) {
            $exists = $this->db->table('contacts')
                ->where('tenant_id', 1)
                ->where('wa_number', $wa)
                ->get()->getRow();

            if ($exists) {
                CLI::write("DemoContactSeeder: $wa already exists, skipping.", 'yellow');
                continue;
            }

            $this->db->table('contacts')->insert([
                'tenant_id'  => 1,
                'wa_number'  => $wa,
                'name'       => $name,
                'email'      => $email,
                'status'     => $status,
                'source'     => $source,
                'opt_in'     => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        CLI::write('DemoContactSeeder: 10 demo contacts seeded.', 'green');
    }
}
