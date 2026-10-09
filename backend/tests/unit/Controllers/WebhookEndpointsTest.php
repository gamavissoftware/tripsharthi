<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\Webhooks\RazorpayPaymentsWebhookController;
use App\Controllers\Webhooks\ShopifyWebhookController;
use App\Controllers\Webhooks\WooCommerceWebhookController;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\FlowTestSchema;

/**
 * Controller-level tests for the public webhook receivers — the untested
 * "glue": header reading → tenant resolution → SIGNATURE GATE → processing.
 *
 * The per-tenant secret is round-tripped through TokenCipher within the test,
 * so it works regardless of the test encryption key.
 */
class WebhookEndpointsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FlowTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['RAZORPAY_VERIFY_SIGNATURE']  = 'true';
        $_ENV['ECOMMERCE_VERIFY_SIGNATURE'] = 'true';
        $this->createFlowSchema();
        $this->createWebhookSchema();
    }

    protected function tearDown(): void
    {
        unset($_ENV['RAZORPAY_VERIFY_SIGNATURE'], $_ENV['ECOMMERCE_VERIFY_SIGNATURE']);
        parent::tearDown();
    }

    private function createWebhookSchema(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;
        $db->query("CREATE TABLE IF NOT EXISTS {$p}integrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, type TEXT, page_id TEXT,
            verify_token TEXT, config TEXT, status TEXT DEFAULT 'active',
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}payment_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, contact_id INTEGER,
            conversation_id INTEGER, amount_paise INTEGER, currency TEXT DEFAULT 'INR', description TEXT,
            reference_id TEXT, razorpay_link_id TEXT, razorpay_payment_id TEXT, short_url TEXT,
            status TEXT DEFAULT 'created', message_id INTEGER, paid_at TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        foreach (['integrations', 'payment_links'] as $t) {
            $db->query("DELETE FROM {$p}{$t}");
        }
    }

    /** Run a webhook controller with a crafted request; returns the response. */
    private function call(string $controllerClass, string $body, array $headers): \CodeIgniter\HTTP\ResponseInterface
    {
        $request = new IncomingRequest(new \Config\App(), new URI('http://localhost/'), $body, new UserAgent());
        foreach ($headers as $k => $v) {
            $request->setHeader($k, $v);
        }
        $controller = new $controllerClass();
        $response   = \Config\Services::response(null, false);
        $controller->initController($request, $response, \Config\Services::logger());
        return $controller->receive();
    }

    private function seedIntegration(string $type, string $secret, array $extra = []): void
    {
        db_connect()->table('integrations')->insert(array_merge([
            'tenant_id' => 1, 'type' => $type, 'status' => 'active',
            'config' => json_encode(array_merge(['webhook_secret_enc' => TokenCipher::encrypt($secret)], $extra['config'] ?? [])),
            'page_id' => $extra['page_id'] ?? null,
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]));
    }

    // ── Razorpay payment-link webhook ───────────────────────────────────

    public function testRazorpayPaymentPaidUpdatesLink(): void
    {
        $this->seedIntegration('razorpay_payments', 'whsec_rzp');
        db_connect()->table('payment_links')->insert([
            'tenant_id' => 1, 'amount_paise' => 50000, 'currency' => 'INR', 'description' => 'x',
            'reference_id' => 'ref_1', 'razorpay_link_id' => 'plink_1', 'short_url' => 'https://rzp.io/i/x',
            'status' => 'sent', 'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);

        $body = json_encode([
            'event'   => 'payment_link.paid',
            'payload' => [
                'payment_link' => ['entity' => ['id' => 'plink_1', 'reference_id' => 'ref_1', 'status' => 'paid']],
                'payment'      => ['entity' => ['id' => 'pay_abc']],
            ],
        ]);
        $sig = hash_hmac('sha256', $body, 'whsec_rzp');

        $res = $this->call(RazorpayPaymentsWebhookController::class, $body, ['X-Razorpay-Signature' => $sig]);
        $this->assertSame(200, $res->getStatusCode());

        $link = db_connect()->table('payment_links')->where('razorpay_link_id', 'plink_1')->get()->getRowArray();
        $this->assertSame('paid', $link['status']);
        $this->assertSame('pay_abc', $link['razorpay_payment_id']);
    }

    public function testRazorpayBadSignatureIsRejected(): void
    {
        $this->seedIntegration('razorpay_payments', 'whsec_rzp');
        db_connect()->table('payment_links')->insert([
            'tenant_id' => 1, 'amount_paise' => 50000, 'reference_id' => 'ref_2', 'razorpay_link_id' => 'plink_2',
            'status' => 'sent', 'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);

        $body = json_encode(['event' => 'payment_link.paid', 'payload' => ['payment_link' => ['entity' => ['id' => 'plink_2']]]]);
        $res  = $this->call(RazorpayPaymentsWebhookController::class, $body, ['X-Razorpay-Signature' => 'deadbeef']);

        $this->assertSame(200, $res->getStatusCode()); // always 200 (no retry storm)
        $link = db_connect()->table('payment_links')->where('razorpay_link_id', 'plink_2')->get()->getRowArray();
        $this->assertSame('sent', $link['status'], 'forged signature must not change the link');
    }

    // ── Shopify webhook ─────────────────────────────────────────────────

    public function testShopifyValidOrderCreatesContact(): void
    {
        $this->seedIntegration('shopify', 'shpss', ['page_id' => 'store.myshopify.com', 'config' => ['default_country_code' => '91']]);

        $body = json_encode([
            'id' => 1001, 'email' => 'jane@shop.com',
            'customer' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '+919812345678'],
        ]);
        $sig = base64_encode(hash_hmac('sha256', $body, 'shpss', true));

        $res = $this->call(ShopifyWebhookController::class, $body, [
            'X-Shopify-Topic'       => 'orders/create',
            'X-Shopify-Shop-Domain' => 'store.myshopify.com',
            'X-Shopify-Hmac-Sha256' => $sig,
        ]);
        $this->assertSame(200, $res->getStatusCode());

        $contact = db_connect()->table('contacts')
            ->where('tenant_id', 1)->where('wa_number', '+919812345678')->get()->getRowArray();
        $this->assertNotNull($contact, 'valid Shopify order must upsert a contact');
        $this->assertSame('shopify', $contact['source']);
    }

    public function testShopifyBadSignatureCreatesNothing(): void
    {
        $this->seedIntegration('shopify', 'shpss', ['page_id' => 'store.myshopify.com']);
        $body = json_encode(['id' => 1, 'customer' => ['phone' => '+919800000000']]);

        $res = $this->call(ShopifyWebhookController::class, $body, [
            'X-Shopify-Topic'       => 'orders/create',
            'X-Shopify-Shop-Domain' => 'store.myshopify.com',
            'X-Shopify-Hmac-Sha256' => 'd3adb33f',
        ]);
        $this->assertSame(200, $res->getStatusCode());
        $count = db_connect()->table('contacts')->where('wa_number', '+919800000000')->countAllResults();
        $this->assertSame(0, $count, 'forged Shopify signature must not create a contact');
    }

    public function testShopifyUnknownStoreIgnored(): void
    {
        // No integration seeded for this shop → ignored, no contact, still 200.
        $body = json_encode(['id' => 1, 'customer' => ['phone' => '+919700000000']]);
        $res  = $this->call(ShopifyWebhookController::class, $body, [
            'X-Shopify-Topic'       => 'orders/create',
            'X-Shopify-Shop-Domain' => 'unknown.myshopify.com',
            'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, 'whatever', true)),
        ]);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(0, db_connect()->table('contacts')->where('wa_number', '+919700000000')->countAllResults());
    }

    // ── WooCommerce webhook ─────────────────────────────────────────────

    public function testWooValidOrderCreatesContact(): void
    {
        $this->seedIntegration('woocommerce', 'wcsecret', ['page_id' => 'https://store.example.com', 'config' => ['default_country_code' => '91']]);

        $body = json_encode([
            'id' => 55, 'status' => 'processing',
            'billing' => ['first_name' => 'Ravi', 'last_name' => 'Kumar', 'phone' => '9876512345', 'email' => 'ravi@x.io'],
        ]);
        $sig = base64_encode(hash_hmac('sha256', $body, 'wcsecret', true));

        $res = $this->call(WooCommerceWebhookController::class, $body, [
            'X-WC-Webhook-Topic'     => 'order.created',
            'X-WC-Webhook-Source'    => 'https://store.example.com',
            'X-WC-Webhook-Signature' => $sig,
        ]);
        $this->assertSame(200, $res->getStatusCode());

        $contact = db_connect()->table('contacts')
            ->where('tenant_id', 1)->where('source', 'woocommerce')->get()->getRowArray();
        $this->assertNotNull($contact, 'valid Woo order must upsert a contact');
        $this->assertStringContainsString('9876512345', $contact['wa_number']);
    }
}
