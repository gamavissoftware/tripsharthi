<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactModel;
use App\Services\Crm\AuditLogger;
use App\Services\Crm\BulkActionService;
use App\Services\Crm\DedupeMergeService;
use App\Services\Crm\RecycleBinService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase K1: audit logger lean diff + that CRM mutations record an audit row.
 */
class AuditLoggerTest extends CIUnitTestCase
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

    private function lastAudit(): array
    {
        return db_connect()->table('audit_logs')->orderBy('id', 'DESC')->get(1)->getRowArray() ?? [];
    }
    private function contact(int $t, string $wa): int
    {
        return (int) (new ContactModel())->setTenant($t)->insert(['wa_number' => $wa, 'name' => 'C', 'status' => 'new', 'source' => 'manual'], true);
    }

    public function testUpdateStoresOnlyChangedFields(): void
    {
        AuditLogger::log('updated', 'contact', 5,
            ['name' => 'Old', 'email' => 'same@x.com', 'status' => 'new'],
            ['name' => 'New', 'email' => 'same@x.com', 'status' => 'new'],
            1, 9);

        $row    = $this->lastAudit();
        $before = json_decode($row['before'], true);
        $after  = json_decode($row['after'], true);
        $this->assertSame(['name' => 'Old'], $before, 'only changed field captured');
        $this->assertSame(['name' => 'New'], $after);
        $this->assertSame('updated', $row['action']);
        $this->assertSame(9, (int) $row['actor_user_id']);
    }

    public function testCreateAndDeleteKeepFullSnapshot(): void
    {
        AuditLogger::log('created', 'deal', 3, null, ['title' => 'D', 'value' => 100], 1);
        $this->assertNull(json_decode($this->lastAudit()['before'] ?? 'null', true));
        $this->assertSame(['title' => 'D', 'value' => 100], json_decode($this->lastAudit()['after'], true));

        AuditLogger::log('deleted', 'deal', 3, ['title' => 'D'], null, 1);
        $this->assertSame(['title' => 'D'], json_decode($this->lastAudit()['before'], true));
        $this->assertNull(json_decode($this->lastAudit()['after'] ?? 'null', true));
    }

    public function testBulkMergeRecycleAreAudited(): void
    {
        $a = $this->contact(1, '+9701');
        $b = $this->contact(1, '+9702');

        (new BulkActionService())->run('contact', 1, [$a, $b], 'set', ['field' => 'status', 'value' => 'won']);
        $this->assertSame('bulk_set', $this->lastAudit()['action']);

        // merge a + b
        (new DedupeMergeService())->merge('contact', 1, $a, [$b]);
        $this->assertSame('merged', $this->lastAudit()['action']);

        // soft-delete then restore
        (new ContactModel())->setTenant(1)->delete($a);
        (new RecycleBinService())->restore('contact', 1, [$a]);
        $this->assertSame('restored', $this->lastAudit()['action']);
    }
}
