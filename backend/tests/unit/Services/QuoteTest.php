<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\QuoteModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase F (CPQ): quote numbering, snapshot round-trip, tenant isolation.
 */
class QuoteTest extends CIUnitTestCase
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

    private function seedQuote(int $tenantId, array $extra = []): int
    {
        $qm = new QuoteModel();
        return (int) $qm->setTenant($tenantId)->insert(array_merge([
            'deal_id' => 1,
            'number'  => $qm->nextNumber($tenantId),
            'status'  => 'draft',
            'subtotal'=> 100000,
            'total'   => 118000,
            'items'   => json_encode([['name' => 'Item', 'quantity' => 1, 'unit_price' => 100000, 'total' => 118000]]),
        ], $extra), true);
    }

    public function testNextNumberIsSequentialPerTenant(): void
    {
        $this->assertSame('Q-0001', (new QuoteModel())->nextNumber(1));
        $this->seedQuote(1);
        $this->assertSame('Q-0002', (new QuoteModel())->nextNumber(1));
        $this->seedQuote(1);
        $this->assertSame('Q-0003', (new QuoteModel())->nextNumber(1));

        // Separate tenant numbers independently.
        $this->assertSame('Q-0001', (new QuoteModel())->nextNumber(2));
    }

    public function testSnapshotRoundTrips(): void
    {
        $id = $this->seedQuote(1, [
            'items' => json_encode([
                ['name' => 'Setup', 'quantity' => 1, 'unit_price' => 5000000, 'total' => 5900000],
                ['name' => 'Plan',  'quantity' => 12, 'unit_price' => 1000000, 'total' => 14160000],
            ]),
            'total' => 20060000,
        ]);
        $q     = (new QuoteModel())->setTenant(1)->find($id);
        $items = json_decode($q['items'], true);
        $this->assertCount(2, $items);
        $this->assertSame('Setup', $items[0]['name']);
        $this->assertSame(20060000, (int) $q['total']);
    }

    public function testQuotesAreTenantScoped(): void
    {
        $this->seedQuote(1, ['deal_id' => 7]);
        $this->seedQuote(2, ['deal_id' => 7]);

        $this->assertCount(1, (new QuoteModel())->forDeal(1, 7));
        $this->assertCount(1, (new QuoteModel())->forDeal(2, 7));
    }
}
