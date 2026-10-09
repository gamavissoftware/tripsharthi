<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AccountModel;
use App\Models\DealModel;
use App\Models\LeadImportModel;
use App\Services\Crm\CrmImporter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase G4: generic CSV import for accounts / deals — mapping, dedupe-on-import,
 * per-row validation. The contact importer is untouched and not exercised here.
 */
class CrmImporterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CrmTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCrmSchema();
    }

    /** Write a CSV to disk and create a matching lead_imports row. */
    private function makeImport(int $tenant, string $entity, array $headers, array $rows, ?int $customObjectId = null): int
    {
        $dir = WRITEPATH . 'uploads/imports/' . $tenant . '/';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $fname = 'test_' . bin2hex(random_bytes(4)) . '.csv';
        $fp    = fopen($dir . $fname, 'w');
        fputcsv($fp, $headers);
        foreach ($rows as $r) {
            fputcsv($fp, $r);
        }
        fclose($fp);

        return (int) (new LeadImportModel())->withoutTenantScope()->insert([
            'tenant_id'         => $tenant,
            'entity_type'       => $entity,
            'custom_object_id'  => $customObjectId,
            'original_filename' => 't.csv',
            'stored_filename'   => $tenant . '/' . $fname,
            'headers'           => json_encode($headers),
            'total'             => count($rows),
            'status'            => 'pending',
        ], true);
    }

    public function testRecordImportDedupesWithinObjectOnly(): void
    {
        $db = db_connect();
        // Two custom objects, each with a same-named record.
        $db->table('custom_objects')->insert(['tenant_id' => 1, 'label_singular' => 'Property', 'api_name' => 'property']);
        $objA = (int) $db->insertID();
        $db->table('custom_objects')->insert(['tenant_id' => 1, 'label_singular' => 'Vehicle', 'api_name' => 'vehicle']);
        $objB = (int) $db->insertID();
        $db->table('custom_object_records')->insert(['tenant_id' => 1, 'custom_object_id' => $objA, 'name' => 'Acme', 'data' => '{}']);
        $recA = (int) $db->insertID();

        // Import a row named 'Acme' INTO object B.
        $id = $this->makeImport(1, 'custom_object_record', ['Name'], [['Acme']], $objB);
        $svc = new CrmImporter();
        $svc->saveMapping($id, 1, ['Name' => 'name']);
        $res = $svc->process($id, 1);

        // Object A's 'Acme' must be untouched; a NEW 'Acme' created under object B.
        $this->assertSame(1, $res['imported'], 'inserted into B, not an update of A');
        $this->assertSame(0, $res['updated']);
        $this->assertSame($objA, (int) (new \App\Models\CustomObjectRecordModel())->setTenant(1)->find($recA)['custom_object_id'], 'object A record not reassigned');
        $this->assertSame(2, $db->table('custom_object_records')->where('tenant_id', 1)->countAllResults(), 'two distinct records');
    }

    public function testAccountImportWithDedupe(): void
    {
        $id  = $this->makeImport(1, 'account', ['Company', 'Web'], [
            ['Acme', 'acme.com'],
            ['Acme Renamed', 'acme.com'],   // same domain → updates the first
            ['Globex', 'globex.com'],
        ]);
        $svc = new CrmImporter();
        $this->assertTrue($svc->saveMapping($id, 1, ['Company' => 'name', 'Web' => 'domain'])['ok']);

        $res = $svc->process($id, 1);
        $this->assertSame(2, $res['imported']);
        $this->assertSame(1, $res['updated']);
        $this->assertSame(0, $res['failed']);

        $accounts = (new AccountModel())->setTenant(1)->findAll();
        $this->assertCount(2, $accounts);
        $acme = array_values(array_filter($accounts, static fn ($a) => $a['domain'] === 'acme.com'))[0];
        $this->assertSame('Acme Renamed', $acme['name'], 'dedupe updated the existing row');
    }

    public function testSaveMappingRequiresRequiredField(): void
    {
        $id  = $this->makeImport(1, 'account', ['Industry'], [['Software']]);
        $res = (new CrmImporter())->saveMapping($id, 1, ['Industry' => 'industry']); // no name mapped
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('name', $res['errors'][0]);
    }

    public function testRowMissingRequiredIsFailed(): void
    {
        $id  = $this->makeImport(1, 'account', ['Company', 'Web'], [
            ['Acme', 'acme.com'],
            ['', 'noname.com'],    // name blank → row fails
        ]);
        $svc = new CrmImporter();
        $svc->saveMapping($id, 1, ['Company' => 'name', 'Web' => 'domain']);
        $res = $svc->process($id, 1);

        $this->assertSame(1, $res['imported']);
        $this->assertSame(1, $res['failed']);
        $this->assertStringContainsString('Missing required', $res['errors'][0]['reason']);
    }

    public function testDealImportSetsDefaultPipeline(): void
    {
        $id  = $this->makeImport(1, 'deal', ['Title', 'Value'], [['Big Deal', '150000']]);
        $svc = new CrmImporter();
        $svc->saveMapping($id, 1, ['Title' => 'title', 'Value' => 'value_amount']);
        $res = $svc->process($id, 1);

        $this->assertSame(1, $res['imported']);
        $deal = (new DealModel())->setTenant(1)->findAll()[0];
        $this->assertSame('Big Deal', $deal['title']);
        $this->assertSame(150000, (int) $deal['value_amount']);
        $this->assertGreaterThan(0, (int) $deal['pipeline_id'], 'default pipeline assigned');
        $this->assertGreaterThan(0, (int) $deal['stage_id'], 'first stage assigned');
    }
}
