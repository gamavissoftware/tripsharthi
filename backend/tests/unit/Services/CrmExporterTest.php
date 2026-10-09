<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactModel;
use App\Services\Crm\CrmExporter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase G4: CSV export honours columns, filters, and tenant scope.
 */
class CrmExporterTest extends CIUnitTestCase
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

    private function contact(int $tenantId, string $wa, string $stage): void
    {
        (new ContactModel())->setTenant($tenantId)
            ->insert(['wa_number' => $wa, 'name' => 'C' . $wa, 'status' => 'new', 'source' => 'manual', 'lifecycle_stage' => $stage]);
    }

    public function testExportHeaderAndRows(): void
    {
        $this->contact(1, '+9301', 'lead');
        $this->contact(1, '+9302', 'customer');

        $csv   = (new CrmExporter())->export('contact', 1);
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        $this->assertStringContainsString('name', $lines[0]);
        $this->assertStringContainsString('lifecycle_stage', $lines[0]);
        $this->assertCount(3, $lines, 'header + 2 rows');
    }

    public function testExportRespectsFilter(): void
    {
        $this->contact(1, '+9311', 'lead');
        $this->contact(1, '+9312', 'customer');

        $csv   = (new CrmExporter())->export('contact', 1, ['conditions' => [['field' => 'lifecycle_stage', 'op' => 'eq', 'value' => 'lead']]]);
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $lines, 'header + 1 matching row');
        $this->assertStringContainsString('+9311', $csv);
        $this->assertStringNotContainsString('+9312', $csv);
    }

    public function testExportIsTenantScoped(): void
    {
        $this->contact(1, '+9321', 'lead');
        $this->contact(2, '+9322', 'lead');

        $csv = (new CrmExporter())->export('contact', 1);
        $this->assertStringContainsString('+9321', $csv);
        $this->assertStringNotContainsString('+9322', $csv);
    }
}
