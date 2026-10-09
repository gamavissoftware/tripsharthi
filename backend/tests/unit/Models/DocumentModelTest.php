<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\DocumentModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * Document attachments: listing is scoped to the record AND the tenant, newest
 * first, and stored_path is an internal detail (never part of the contract the
 * controller exposes — asserted there by unsetting it).
 */
class DocumentModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CrmTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCrmSchema();
        $db = db_connect();
        $db->table('documents')->insert(['id' => 1, 'tenant_id' => 1, 'related_type' => 'contact', 'related_id' => 5, 'filename' => 'a.pdf', 'stored_path' => 'documents/1/x1.pdf']);
        $db->table('documents')->insert(['id' => 2, 'tenant_id' => 1, 'related_type' => 'contact', 'related_id' => 5, 'filename' => 'b.pdf', 'stored_path' => 'documents/1/x2.pdf']);
        $db->table('documents')->insert(['id' => 3, 'tenant_id' => 1, 'related_type' => 'deal', 'related_id' => 5, 'filename' => 'c.pdf', 'stored_path' => 'documents/1/x3.pdf']);
        $db->table('documents')->insert(['id' => 4, 'tenant_id' => 2, 'related_type' => 'contact', 'related_id' => 5, 'filename' => 't2.pdf', 'stored_path' => 'documents/2/x4.pdf']);
    }

    public function testForRecordIsScopedNewestFirst(): void
    {
        $rows = (new DocumentModel())->forRecord(1, 'contact', 5);
        $this->assertSame(['b.pdf', 'a.pdf'], array_column($rows, 'filename'), 'newest first, only this record');
    }

    public function testExcludesOtherTypesAndTenants(): void
    {
        $names = array_column((new DocumentModel())->forRecord(1, 'contact', 5), 'filename');
        $this->assertNotContains('c.pdf', $names, "a deal's document is not a contact's");
        $this->assertNotContains('t2.pdf', $names, "another tenant's document is excluded");
    }

    public function testWhitelistAndCapAreSane(): void
    {
        $this->assertContains('pdf', DocumentModel::ALLOWED_EXT);
        $this->assertContains('xlsx', DocumentModel::ALLOWED_EXT);
        $this->assertNotContains('php', DocumentModel::ALLOWED_EXT, 'executable types must not be allowed');
        $this->assertNotContains('exe', DocumentModel::ALLOWED_EXT);
        $this->assertSame(10 * 1024 * 1024, DocumentModel::MAX_BYTES);
    }
}
