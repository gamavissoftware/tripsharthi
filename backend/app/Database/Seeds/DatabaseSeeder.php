<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Master seeder — run with:  php spark db:seed DatabaseSeeder
 *
 * Runs all seeders in dependency order.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call('DemoTenantSeeder');
        $this->call('DemoUserSeeder');
        $this->call('DemoContactSeeder');
        $this->call('DemoFeaturesSeeder');
        $this->call('CrmDemoSeeder');
        $this->call('DealDemoSeeder');
        $this->call('TicketDemoSeeder');
        $this->call('CustomObjectDemoSeeder');
    }
}
