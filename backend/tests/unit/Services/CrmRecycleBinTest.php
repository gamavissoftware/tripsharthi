<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactModel;
use App\Services\Crm\RecycleBinService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase G6: recycle bin — list / restore / purge over soft deletes, with
 * tenant-scope safety on every mutation.
 */
class CrmRecycleBinTest extends CIUnitTestCase
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

    private function contact(int $tenantId, string $wa): int
    {
        return (int) (new ContactModel())->setTenant($tenantId)
            ->insert(['wa_number' => $wa, 'name' => 'C' . $wa, 'status' => 'new', 'source' => 'manual'], true);
    }

    public function testListRestorePurge(): void
    {
        $a = $this->contact(1, '+9201');
        $b = $this->contact(1, '+9202');
        $this->contact(1, '+9203');

        (new ContactModel())->setTenant(1)->delete([$a, $b]); // soft delete two

        $svc = new RecycleBinService();
        $this->assertCount(2, $svc->deleted('contact', 1));
        $this->assertCount(1, (new ContactModel())->setTenant(1)->findAll(), 'one alive');

        // Restore $a.
        $this->assertSame(['restored' => 1], $svc->restore('contact', 1, [$a]));
        $this->assertCount(2, (new ContactModel())->setTenant(1)->findAll(), 'two alive after restore');
        $this->assertCount(1, $svc->deleted('contact', 1), 'one still in bin');

        // Purge $b permanently.
        $this->assertSame(['purged' => 1], $svc->purge('contact', 1, [$b]));
        $this->assertCount(0, $svc->deleted('contact', 1), 'bin empty');
        $gone = (new ContactModel())->setTenant(1)->onlyDeleted()->withDeleted()->find($b);
        $this->assertNull($gone, 'purged row is permanently gone');
    }

    public function testRestoreCannotCrossTenant(): void
    {
        $mine = $this->contact(1, '+9211');
        (new ContactModel())->setTenant(1)->delete($mine);

        // Tenant 2 tries to restore tenant 1's deleted row — no-op.
        $this->assertSame(['restored' => 0], (new RecycleBinService())->restore('contact', 2, [$mine]));
        $this->assertCount(1, (new RecycleBinService())->deleted('contact', 1), 'still deleted for tenant 1');
    }

    public function testPurgeCannotCrossTenant(): void
    {
        $mine = $this->contact(1, '+9221');
        (new ContactModel())->setTenant(1)->delete($mine);

        $this->assertSame(['purged' => 0], (new RecycleBinService())->purge('contact', 2, [$mine]));
        // The row still exists (soft-deleted) for tenant 1.
        $still = (new ContactModel())->setTenant(1)->onlyDeleted()->find($mine);
        $this->assertNotNull($still, 'foreign purge did not touch tenant 1 row');
    }
}
