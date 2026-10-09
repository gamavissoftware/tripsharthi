<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Commerce\EcommerceContactMapper;
use App\Services\Commerce\EcommerceSignature;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests Shopify / WooCommerce webhook signature verification, topic→trigger
 * mapping, and contact extraction. All pure — no DB.
 */
class EcommerceIntegrationTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['ECOMMERCE_VERIFY_SIGNATURE'] = 'true';
    }

    protected function tearDown(): void
    {
        unset($_ENV['ECOMMERCE_VERIFY_SIGNATURE']);
        parent::tearDown();
    }

    // ── Signature (base64 HMAC-SHA256) ──────────────────────────────────

    public function testValidSignaturePasses(): void
    {
        $body   = '{"id":123,"total_price":"499.00"}';
        $secret = 'shpss_secret';
        $sig    = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $this->assertTrue(EcommerceSignature::verify($body, $sig, $secret));
    }

    public function testTamperedBodyFails(): void
    {
        $secret = 'shpss_secret';
        $sig    = base64_encode(hash_hmac('sha256', '{"id":123}', $secret, true));

        $this->assertFalse(EcommerceSignature::verify('{"id":999}', $sig, $secret));
    }

    public function testMissingSecretOrHeaderFails(): void
    {
        $this->assertFalse(EcommerceSignature::verify('body', '', 'secret'));
        $this->assertFalse(EcommerceSignature::verify('body', 'sig', ''));
    }

    // ── Topic → trigger mapping ─────────────────────────────────────────

    public function testShopifyTriggers(): void
    {
        $this->assertSame('order_placed',    EcommerceContactMapper::shopifyTrigger('orders/create'));
        $this->assertSame('order_fulfilled', EcommerceContactMapper::shopifyTrigger('fulfillments/create'));
        $this->assertSame('abandoned_cart',  EcommerceContactMapper::shopifyTrigger('checkouts/create'));
        $this->assertNull(EcommerceContactMapper::shopifyTrigger('orders/delete'));
    }

    public function testWooTriggers(): void
    {
        $this->assertSame('order_placed', EcommerceContactMapper::wooTrigger('order.created', []));
        $this->assertSame('order_fulfilled', EcommerceContactMapper::wooTrigger('order.updated', ['status' => 'completed']));
        $this->assertNull(EcommerceContactMapper::wooTrigger('order.updated', ['status' => 'processing']));
    }

    // ── Contact extraction ──────────────────────────────────────────────

    public function testShopifyContactExtraction(): void
    {
        $c = EcommerceContactMapper::shopifyContact([
            'email'    => 'jane@shop.com',
            'customer' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '+919812345678'],
        ]);
        $this->assertSame('+919812345678', $c['phone']);
        $this->assertSame('Jane Doe', $c['name']);
        $this->assertSame('jane@shop.com', $c['email']);
    }

    public function testShopifyFallsBackToShippingPhone(): void
    {
        $c = EcommerceContactMapper::shopifyContact([
            'shipping_address' => ['phone' => '+919800000000', 'first_name' => 'Sam', 'last_name' => 'Roy'],
        ]);
        $this->assertSame('+919800000000', $c['phone']);
        $this->assertSame('Sam Roy', $c['name']);
    }

    public function testWooContactExtraction(): void
    {
        $c = EcommerceContactMapper::wooContact([
            'billing' => ['first_name' => 'Ravi', 'last_name' => 'Kumar', 'phone' => '9876500000', 'email' => 'ravi@x.io'],
        ]);
        $this->assertSame('9876500000', $c['phone']);
        $this->assertSame('Ravi Kumar', $c['name']);
        $this->assertSame('ravi@x.io', $c['email']);
    }
}
