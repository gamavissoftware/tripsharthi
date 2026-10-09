<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Creates three demo users under tenant id=1.
 *
 * owner@demo.test  / password  — full access
 * admin@demo.test  / password  — admin access
 * agent@demo.test  / password  — agent (inbox only)
 *
 * Safe to run multiple times — skips rows that already exist.
 */
class DemoUserSeeder extends Seeder
{
    private array $users = [
        [
            'name'  => 'Demo Owner',
            'email' => 'owner@demo.test',
            'role'  => 'owner',
        ],
        [
            'name'  => 'Demo Admin',
            'email' => 'admin@demo.test',
            'role'  => 'admin',
        ],
        [
            'name'  => 'Demo Agent',
            'email' => 'agent@demo.test',
            'role'  => 'agent',
        ],
    ];

    public function run(): void
    {
        $passwordHash = password_hash('password', PASSWORD_DEFAULT);

        foreach ($this->users as $userData) {
            $existing = $this->db->table('users')
                ->where('email', $userData['email'])
                ->where('tenant_id', 1)
                ->get()
                ->getRow();

            if ($existing !== null) {
                CLI::write("DemoUserSeeder: {$userData['email']} already exists, skipping.", 'yellow');
                continue;
            }

            $this->db->table('users')->insert([
                'tenant_id'     => 1,
                'name'          => $userData['name'],
                'email'         => $userData['email'],
                'password_hash' => $passwordHash,
                'role'          => $userData['role'],
                'api_token'     => null,
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

            CLI::write("DemoUserSeeder: created {$userData['role']} {$userData['email']}.", 'green');
        }
    }
}
