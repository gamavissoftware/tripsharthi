<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\QuoteModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * Quotes-for-a-lead (CRM richness): a contact's quotations are the quotes across
 * every deal where they are the primary contact, tenant-scoped, newest first.
 */
class QuoteForContactTest extends CIUnitTestCase
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
        // contact 1 owns deals 10 & 11; contact 2 owns deal 12.
        $db->table('deals')->insert(['id' => 10, 'tenant_id' => 1, 'title' => 'Deal A', 'pipeline_id' => 1, 'stage_id' => 1, 'primary_contact_id' => 1, 'status' => 'open']);
        $db->table('deals')->insert(['id' => 11, 'tenant_id' => 1, 'title' => 'Deal B', 'pipeline_id' => 1, 'stage_id' => 1, 'primary_contact_id' => 1, 'status' => 'open']);
        $db->table('deals')->insert(['id' => 12, 'tenant_id' => 1, 'title' => 'Deal C', 'pipeline_id' => 1, 'stage_id' => 1, 'primary_contact_id' => 2, 'status' => 'open']);
        $db->table('quotes')->insert(['id' => 100, 'tenant_id' => 1, 'deal_id' => 10, 'number' => 'Q-100', 'status' => 'sent', 'total' => 50000]);
        $db->table('quotes')->insert(['id' => 101, 'tenant_id' => 1, 'deal_id' => 11, 'number' => 'Q-101', 'status' => 'draft', 'total' => 75000]);
        $db->table('quotes')->insert(['id' => 102, 'tenant_id' => 1, 'deal_id' => 12, 'number' => 'Q-102', 'status' => 'sent', 'total' => 99000]);
        // Another tenant's quote must never appear.
        $db->table('deals')->insert(['id' => 13, 'tenant_id' => 2, 'title' => 'T2', 'pipeline_id' => 1, 'stage_id' => 1, 'primary_contact_id' => 1, 'status' => 'open']);
        $db->table('quotes')->insert(['id' => 103, 'tenant_id' => 2, 'deal_id' => 13, 'number' => 'Q-T2', 'status' => 'sent', 'total' => 1]);
    }

    public function testReturnsQuotesAcrossContactsDealsNewestFirst(): void
    {
        $rows = (new QuoteModel())->forContact(1, 1);
        $this->assertCount(2, $rows);
        $this->assertSame(['Q-101', 'Q-100'], array_column($rows, 'number'), 'newest (higher id) first');
        $this->assertSame('Deal B', $rows[0]['deal_title'], 'each quote carries its deal title');
    }

    public function testExcludesOtherContactsAndOtherTenants(): void
    {
        $rows = (new QuoteModel())->forContact(1, 1);
        $numbers = array_column($rows, 'number');
        $this->assertNotContains('Q-102', $numbers, "another contact's quote excluded");
        $this->assertNotContains('Q-T2', $numbers, "another tenant's quote excluded");
    }

    public function testNoQuotesForContactWithoutDeals(): void
    {
        $this->assertSame([], (new QuoteModel())->forContact(1, 999));
    }
}
