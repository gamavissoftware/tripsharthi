<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\WhatsApp\ProviderAdapter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Regression: the commerce/flow features call sendProduct / sendProductList /
 * sendFlow on the object returned by buildAdapter() — a ProviderAdapter, not a
 * CloudApiClient. These methods must exist on the adapter (mock mode here).
 */
class ProviderAdapterCommerceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['WHATSAPP_MOCK_MODE'] = 'true';
    }

    protected function tearDown(): void
    {
        unset($_ENV['WHATSAPP_MOCK_MODE']);
        parent::tearDown();
    }

    private function adapter(): ProviderAdapter
    {
        return ProviderAdapter::fromAccount(
            ['provider' => 'meta', 'phone_number_id' => 'mock_phone'],
            'mock_token',
        );
    }

    public function testAdapterExposesCommerceMethods(): void
    {
        $a = $this->adapter();
        $this->assertTrue(method_exists($a, 'sendProduct'));
        $this->assertTrue(method_exists($a, 'sendProductList'));
        $this->assertTrue(method_exists($a, 'sendFlow'));
    }

    public function testSendProductMockSucceeds(): void
    {
        $r = $this->adapter()->sendProduct('+919999900001', 'cat_1', 'SKU-1', 'Check this out');
        $this->assertTrue($r['success']);
        $this->assertNotEmpty($r['message_id']);
    }

    public function testSendFlowMockSucceeds(): void
    {
        $r = $this->adapter()->sendFlow('+919999900001', 'flow_1', 'ft_token', 'Open', 'Tap to start');
        $this->assertTrue($r['success']);
    }

    public function testSendProductListMockSucceeds(): void
    {
        $r = $this->adapter()->sendProductList('+919999900001', 'cat_1', 'Our picks', 'Body', [
            ['title' => 'Bestsellers', 'product_items' => ['SKU-1', 'SKU-2']],
        ]);
        $this->assertTrue($r['success']);
    }
}
