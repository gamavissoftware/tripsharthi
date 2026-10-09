<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use App\Models\AssociationModel;
use App\Models\CustomObjectRecordModel;
use App\Services\Tenancy\IndustryTemplateService;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Demo custom objects for tenant 1: applies the Real Estate template (Property)
 * and seeds a couple of property records linked to demo contacts — so the
 * multi-industry layer is visible on a fresh install. Idempotent.
 */
class CustomObjectDemoSeeder extends Seeder
{
    public function run(): void
    {
        $object = (new IndustryTemplateService())->apply(1, 'real_estate');
        $objectId = (int) $object['id'];

        $recordModel = new CustomObjectRecordModel();
        if ($recordModel->setTenant(1)->where('custom_object_id', $objectId)->countAllResults() > 0) {
            CLI::write('CustomObjectDemoSeeder: property records already present, skipping.', 'yellow');
            return;
        }

        $records = [
            ['Sea View Apartment', ['address' => '12 Marine Drive, Mumbai', 'type' => 'Apartment', 'bedrooms' => '3', 'area_sqft' => '1450', 'price' => '25000000', 'status' => 'Available']],
            ['Green Acres Villa',   ['address' => '7 Palm Grove, Goa',       'type' => 'Villa',     'bedrooms' => '4', 'area_sqft' => '3200', 'price' => '48000000', 'status' => 'Under Offer']],
        ];

        $contact = $this->db->table('contacts')->where('tenant_id', 1)->orderBy('id', 'ASC')->get()->getRow();

        foreach ($records as $i => [$name, $data]) {
            $id = (int) $recordModel->setTenant(1)->insert([
                'custom_object_id' => $objectId,
                'name'             => $name,
                'owner_id'         => 1,
                'data'             => json_encode($data),
            ], true);

            // Link the first property to the first demo contact.
            if ($i === 0 && $contact) {
                (new AssociationModel())->link(1, 'contact', (int) $contact->id, 'custom_object_record', $id, 'interested in');
            }
        }

        CLI::write('CustomObjectDemoSeeder: Property object + 2 records seeded.', 'green');
    }
}
