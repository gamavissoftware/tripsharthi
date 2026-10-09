<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactModel;
use App\Models\SavedViewModel;
use App\Services\Crm\CrmEntityRegistry;
use App\Services\Crm\FilterEngine;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase G: the FilterEngine whitelist/operators, tenant-scope safety under
 * OR, the entity registry, and saved-view visibility.
 */
class CrmListFilterTest extends CIUnitTestCase
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

    private function contact(int $tenantId, array $f): void
    {
        (new ContactModel())->setTenant($tenantId)->insert(array_merge(['status' => 'new', 'source' => 'manual'], $f));
    }

    private function filtered(int $tenantId, array $filters): array
    {
        $model = (new ContactModel())->setTenant($tenantId);
        (new FilterEngine())->apply($model, $filters, CrmEntityRegistry::get('contact')['filterable']);
        return $model->findAll();
    }

    // ── Registry ───────────────────────────────────────────────────────

    public function testRegistryKnownAndUnknown(): void
    {
        $this->assertTrue(CrmEntityRegistry::has('contact'));
        $this->assertFalse(CrmEntityRegistry::has('messages')); // messaging never registered
        $this->expectException(\InvalidArgumentException::class);
        CrmEntityRegistry::get('nope');
    }

    // ── Operators ──────────────────────────────────────────────────────

    public function testEqAndContainsAndIn(): void
    {
        // `contains` is exercised on `source` (a filterable text field); `name` is
        // searchable-only and is intentionally not in the filter whitelist.
        $this->contact(1, ['wa_number' => '+910000000001', 'name' => 'Alice', 'source' => 'web_form',          'lifecycle_stage' => 'lead']);
        $this->contact(1, ['wa_number' => '+910000000002', 'name' => 'Bob',   'source' => 'manual',            'lifecycle_stage' => 'customer']);
        $this->contact(1, ['wa_number' => '+910000000003', 'name' => 'Alicia','source' => 'google_lead_forms', 'lifecycle_stage' => 'mql']);

        $this->assertCount(1, $this->filtered(1, ['conditions' => [['field' => 'lifecycle_stage', 'op' => 'eq', 'value' => 'lead']]]));
        $this->assertCount(2, $this->filtered(1, ['conditions' => [['field' => 'source', 'op' => 'contains', 'value' => 'form']]]));
        $this->assertCount(2, $this->filtered(1, ['conditions' => [['field' => 'lifecycle_stage', 'op' => 'in', 'value' => ['lead', 'mql']]]]));
    }

    public function testAndVsOr(): void
    {
        $this->contact(1, ['wa_number' => '+910000000010', 'name' => 'X', 'lifecycle_stage' => 'lead', 'lead_score' => 80]);
        $this->contact(1, ['wa_number' => '+910000000011', 'name' => 'Y', 'lifecycle_stage' => 'customer', 'lead_score' => 10]);

        // AND: lead AND score>50 → 1
        $and = $this->filtered(1, ['match' => 'and', 'conditions' => [
            ['field' => 'lifecycle_stage', 'op' => 'eq', 'value' => 'lead'],
            ['field' => 'lead_score', 'op' => 'gt', 'value' => 50],
        ]]);
        $this->assertCount(1, $and);

        // OR: customer OR score>50 → 2
        $or = $this->filtered(1, ['match' => 'or', 'conditions' => [
            ['field' => 'lifecycle_stage', 'op' => 'eq', 'value' => 'customer'],
            ['field' => 'lead_score', 'op' => 'gt', 'value' => 50],
        ]]);
        $this->assertCount(2, $or);
    }

    public function testWhitelistDropsUnknownFieldNoInjection(): void
    {
        $this->contact(1, ['wa_number' => '+910000000020', 'name' => 'Z']);
        // Malicious column name is not in the whitelist → silently dropped → all rows.
        $rows = $this->filtered(1, ['conditions' => [['field' => "name); DROP TABLE contacts;--", 'op' => 'eq', 'value' => 'x']]]);
        $this->assertCount(1, $rows, 'Unknown/injection field is dropped, query still runs');
    }

    public function testEmptyFiltersReturnsAll(): void
    {
        $this->contact(1, ['wa_number' => '+910000000030', 'name' => 'A']);
        $this->contact(1, ['wa_number' => '+910000000031', 'name' => 'B']);
        $this->assertCount(2, $this->filtered(1, ['conditions' => []]));
    }

    // ── Tenant safety under OR ─────────────────────────────────────────

    public function testOrFilterCannotEscapeTenantScope(): void
    {
        $this->contact(1, ['wa_number' => '+910000000040', 'name' => 'T1', 'source' => 'manual', 'lifecycle_stage' => 'lead']);
        $this->contact(2, ['wa_number' => '+910000000041', 'name' => 'T2', 'source' => 'manual', 'lifecycle_stage' => 'lead']);

        // An OR filter from tenant 1 must never surface tenant 2's row — even though
        // tenant 2's row also matches both OR clauses, the wrapping group keeps the
        // trailing tenant AND outside the OR.
        $rows = $this->filtered(1, ['match' => 'or', 'conditions' => [
            ['field' => 'lifecycle_stage', 'op' => 'eq', 'value' => 'lead'],
            ['field' => 'source', 'op' => 'contains', 'value' => 'man'],
        ]]);
        $this->assertCount(1, $rows);
        $this->assertSame('T1', $rows[0]['name']);
    }

    // ── Saved views ────────────────────────────────────────────────────

    public function testSavedViewVisibility(): void
    {
        $sv = new SavedViewModel();
        $sv->setTenant(1)->insert(['entity_type' => 'contact', 'name' => 'Mine',   'owner_id' => 5, 'is_shared' => 0]);
        $sv->setTenant(1)->insert(['entity_type' => 'contact', 'name' => 'Shared', 'owner_id' => 9, 'is_shared' => 1]);
        $sv->setTenant(1)->insert(['entity_type' => 'contact', 'name' => 'Theirs', 'owner_id' => 9, 'is_shared' => 0]);

        $forUser5 = array_column((new SavedViewModel())->forUser(1, 'contact', 5), 'name');
        sort($forUser5);
        $this->assertSame(['Mine', 'Shared'], $forUser5, 'User sees own + shared, not others’ private');
    }

    public function testSavedViewsTenantScoped(): void
    {
        (new SavedViewModel())->setTenant(1)->insert(['entity_type' => 'deal', 'name' => 'T1 view', 'is_shared' => 1, 'owner_id' => 1]);
        (new SavedViewModel())->setTenant(2)->insert(['entity_type' => 'deal', 'name' => 'T2 view', 'is_shared' => 1, 'owner_id' => 1]);
        $this->assertCount(1, (new SavedViewModel())->forUser(1, 'deal', 1));
    }
}
