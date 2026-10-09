<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AccountModel;
use App\Models\ContactModel;
use App\Models\DealModel;
use App\Services\Crm\SearchService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase G3: global search across entities, tenant isolation, and the
 * READ-ONLY guarantee over messaging tables.
 */
class CrmSearchTest extends CIUnitTestCase
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

    private function seed(): void
    {
        (new ContactModel())->setTenant(1)->insert(['wa_number' => '+9180', 'name' => 'Skyline Traders', 'status' => 'new', 'source' => 'manual']);
        (new AccountModel())->setTenant(1)->insert(['name' => 'Skyline Logistics', 'type' => 'prospect']);
        (new DealModel())->setTenant(1)->insert(['title' => 'Skyline annual deal', 'status' => 'open', 'pipeline_id' => 1, 'stage_id' => 1]);
        // A different tenant with a matching name — must never surface.
        (new ContactModel())->setTenant(2)->insert(['wa_number' => '+9181', 'name' => 'Skyline Foreign', 'status' => 'new', 'source' => 'manual']);
    }

    public function testSearchAcrossEntitiesTenantScoped(): void
    {
        $this->seed();
        $res = (new SearchService())->search(1, 'Skyline');

        $types = array_column($res['groups'], 'type');
        $this->assertContains('contact', $types);
        $this->assertContains('account', $types);
        $this->assertContains('deal', $types);

        // No tenant-2 row leaked in.
        $titles = [];
        foreach ($res['groups'] as $g) {
            foreach ($g['items'] as $it) {
                $titles[] = $it['title'];
            }
        }
        $this->assertContains('Skyline Traders', $titles);
        $this->assertNotContains('Skyline Foreign', $titles);
    }

    public function testShortQueryReturnsNothing(): void
    {
        $this->seed();
        $res = (new SearchService())->search(1, 'S');
        $this->assertSame(0, $res['total']);
        $this->assertSame([], $res['groups']);
    }

    public function testMessageSearchIsReadOnly(): void
    {
        $cid = (int) (new ContactModel())->setTenant(1)->insert(['wa_number' => '+9190', 'name' => 'Ravi', 'status' => 'new', 'source' => 'manual'], true);
        $db  = db_connect();
        $db->table('messages')->insert([
            'tenant_id' => 1, 'contact_id' => $cid, 'conversation_id' => 1,
            'direction' => 'in', 'type' => 'text', 'body' => 'Please send the invoice quotation',
        ]);

        // Snapshot the messaging table before searching.
        $before = $db->table('messages')->where('tenant_id', 1)->get()->getResultArray();

        $res = (new SearchService())->search(1, 'quotation');

        // The search surfaces the owning contact…
        $msgGroup = array_values(array_filter($res['groups'], static fn ($g) => $g['type'] === 'message'));
        $this->assertNotEmpty($msgGroup, 'Message search returns a result');
        $this->assertSame($cid, $msgGroup[0]['items'][0]['id']);
        $this->assertSame('/contacts/' . $cid, $msgGroup[0]['items'][0]['url']);

        // …and the messaging table is byte-for-byte unchanged (read-only guardrail).
        $after = $db->table('messages')->where('tenant_id', 1)->get()->getResultArray();
        $this->assertSame($before, $after, 'Search must never mutate messaging tables');
    }
}
