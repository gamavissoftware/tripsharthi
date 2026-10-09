<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\PriceBookEntryModel;
use App\Models\PriceBookModel;
use App\Models\ProductModel;
use App\Services\Crm\PriceBookService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase J3: price resolution — book entry overrides base, falls back to base,
 * tenant-scoped.
 */
class PriceBookServiceTest extends CIUnitTestCase
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

    private function product(int $tenant, int $price): int
    {
        db_connect()->table('products')->insert(['tenant_id' => $tenant, 'name' => 'Widget', 'price_paise' => $price, 'status' => 'active']);
        return (int) db_connect()->insertID();
    }

    public function testBookEntryOverridesBasePrice(): void
    {
        $pid  = $this->product(1, 100000);                 // base ₹1,000
        $book = (int) (new PriceBookModel())->setTenant(1)->insert(['name' => 'Wholesale', 'currency' => 'INR'], true);
        (new PriceBookEntryModel())->setTenant(1)->insert(['price_book_id' => $book, 'product_id' => $pid, 'price_paise' => 75000]); // ₹750

        $svc = new PriceBookService();
        $this->assertSame(75000, $svc->priceFor(1, $pid, $book), 'book price wins');
        $this->assertSame(100000, $svc->priceFor(1, $pid, null), 'no book → base price');
    }

    public function testFallsBackToBaseWhenNoEntry(): void
    {
        $pid  = $this->product(1, 50000);
        $book = (int) (new PriceBookModel())->setTenant(1)->insert(['name' => 'Empty', 'currency' => 'INR'], true);
        $this->assertSame(50000, (new PriceBookService())->priceFor(1, $pid, $book), 'no entry → base price');
    }

    public function testTenantScoped(): void
    {
        $pid  = $this->product(1, 100000);
        $book = (int) (new PriceBookModel())->setTenant(1)->insert(['name' => 'B', 'currency' => 'INR'], true);
        (new PriceBookEntryModel())->setTenant(1)->insert(['price_book_id' => $book, 'product_id' => $pid, 'price_paise' => 1]);

        // Tenant 2 cannot read tenant 1's entry → base 0 (no such product for t2).
        $this->assertSame(0, (new PriceBookService())->priceFor(2, $pid, $book));
    }
}
