<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\TenantModel;
use App\Services\Crm\CurrencyService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase J4: currency display formatting (no FX) + tenant default resolution.
 */
class CurrencyServiceTest extends CIUnitTestCase
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

    public function testFormatsPerCurrency(): void
    {
        $this->assertSame('₹1,500.00', CurrencyService::format(150000, 'INR'));
        $this->assertSame('$1,500.00', CurrencyService::format(150000, 'USD'));
        $this->assertSame('€1,500.00', CurrencyService::format(150000, 'EUR'));
        // JPY has no minor unit → no decimals.
        $this->assertSame('¥1,500', CurrencyService::format(150000, 'JPY'));
        // Unknown code falls back to the code as a prefix.
        $this->assertSame('ZAR 10.00', CurrencyService::format(1000, 'ZAR'));
    }

    public function testTenantDefaultReadsSettingsElseInr(): void
    {
        $this->assertSame('INR', CurrencyService::tenantDefault(1), 'default when unset');

        db_connect()->table('tenants')->where('id', 1)->update(['settings' => json_encode(['default_currency' => 'USD'])]);
        $this->assertSame('USD', CurrencyService::tenantDefault(1));

        // An unsupported stored code falls back to INR.
        db_connect()->table('tenants')->where('id', 1)->update(['settings' => json_encode(['default_currency' => 'XXX'])]);
        $this->assertSame('INR', CurrencyService::tenantDefault(1));
    }
}
