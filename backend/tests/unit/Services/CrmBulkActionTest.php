<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AccountModel;
use App\Models\ContactModel;
use App\Services\Crm\BulkActionService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use RuntimeException;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase G2: bulk set / delete / tag, with tenant-scope and whitelist safety.
 */
class CrmBulkActionTest extends CIUnitTestCase
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

    private function svc(): BulkActionService
    {
        return new BulkActionService();
    }

    // ── set ────────────────────────────────────────────────────────────

    public function testBulkSetWhitelistedField(): void
    {
        $a = $this->contact(1, '+9111');
        $b = $this->contact(1, '+9112');
        $this->contact(1, '+9113');

        $res = $this->svc()->run('contact', 1, [$a, $b], 'set', ['field' => 'owner_id', 'value' => 7]);
        $this->assertSame(2, $res['affected']);

        $owners = array_column((new ContactModel())->setTenant(1)->findAll(), 'owner_id', 'id');
        $this->assertSame(7, (int) $owners[$a]);
        $this->assertSame(7, (int) $owners[$b]);
    }

    public function testBulkSetRejectsNonWhitelistedField(): void
    {
        $a = $this->contact(1, '+9121');
        $this->expectException(RuntimeException::class);
        $this->svc()->run('contact', 1, [$a], 'set', ['field' => 'email', 'value' => 'x@y.z']);
    }

    public function testBulkSetCannotCrossTenant(): void
    {
        $mine    = $this->contact(1, '+9131');
        $foreign = $this->contact(2, '+9132');

        // Tenant 1 tries to set a tenant-2 row too — only its own row is touched.
        $res = $this->svc()->run('contact', 1, [$mine, $foreign], 'set', ['field' => 'status', 'value' => 'won']);
        $this->assertSame(1, $res['affected']);

        $foreignRow = (new ContactModel())->setTenant(2)->find($foreign);
        $this->assertSame('new', $foreignRow['status'], 'Foreign tenant row untouched');
    }

    // ── delete (soft) ──────────────────────────────────────────────────

    public function testBulkDeleteSoftDeletes(): void
    {
        $a = $this->contact(1, '+9141');
        $b = $this->contact(1, '+9142');

        $res = $this->svc()->run('contact', 1, [$a, $b], 'delete');
        $this->assertSame(2, $res['affected']);

        $this->assertCount(0, (new ContactModel())->setTenant(1)->findAll());
        $this->assertCount(2, (new ContactModel())->setTenant(1)->onlyDeleted()->findAll());
    }

    // ── tags ───────────────────────────────────────────────────────────

    public function testBulkAddAndRemoveTag(): void
    {
        $a = $this->contact(1, '+9151');
        $b = $this->contact(1, '+9152');
        db_connect()->table('tags')->insert(['tenant_id' => 1, 'name' => 'VIP']);
        $tagId = (int) db_connect()->insertID();

        $res = $this->svc()->run('contact', 1, [$a, $b], 'add_tag', ['tag_id' => $tagId]);
        $this->assertSame(2, $res['affected']);
        $this->assertSame(2, db_connect()->table('contact_tags')->where('tag_id', $tagId)->countAllResults());

        $this->svc()->run('contact', 1, [$a], 'remove_tag', ['tag_id' => $tagId]);
        $this->assertSame(1, db_connect()->table('contact_tags')->where('tag_id', $tagId)->countAllResults());
    }

    public function testBulkTagRejectsForeignTag(): void
    {
        $a = $this->contact(1, '+9161');
        db_connect()->table('tags')->insert(['tenant_id' => 2, 'name' => 'Theirs']);
        $foreignTag = (int) db_connect()->insertID();

        $this->expectException(RuntimeException::class);
        $this->svc()->run('contact', 1, [$a], 'add_tag', ['tag_id' => $foreignTag]);
    }

    public function testTagUnsupportedOnAccounts(): void
    {
        $id = (int) (new AccountModel())->setTenant(1)->insert(['name' => 'Acme'], true);
        $this->expectException(RuntimeException::class);
        $this->svc()->run('account', 1, [$id], 'add_tag', ['tag_id' => 1]);
    }
}
