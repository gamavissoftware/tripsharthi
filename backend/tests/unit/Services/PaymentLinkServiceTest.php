<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\PaymentLinkModel;
use App\Services\Commerce\PaymentLinkService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\BlankSlateSchema;

/**
 * Tests payment link creation (mock Razorpay) + webhook status application.
 */
class PaymentLinkServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use BlankSlateSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAllTables();
        $_ENV['RAZORPAY_MOCK_MODE'] = 'true';
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        unset($_ENV['RAZORPAY_MOCK_MODE']);
        parent::tearDown();
    }

    private function createSchema(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;
        $db->query("CREATE TABLE IF NOT EXISTS {$p}tenants (id INTEGER PRIMARY KEY, name TEXT, slug TEXT)");
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
        foreach (['tenants','integrations','payment_links'] as $t) {
            $db->query("DELETE FROM {$p}{$t}");
        }
        $db->query("INSERT INTO {$p}tenants (id,name,slug) VALUES (1,'Test','test')");
    }

    private function connectRazorpay(): void
    {
        db_connect()->table('integrations')->insert([
            'tenant_id' => 1, 'type' => 'razorpay_payments',
            'config' => json_encode(['key_id' => 'rzp_test_x']),
            'status' => 'active',
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
    }

    // ── toPaise (pure) ──────────────────────────────────────────────────

    public function testToPaiseConvertsRupees(): void
    {
        $this->assertSame(10000, PaymentLinkService::toPaise(100));
        $this->assertSame(14999, PaymentLinkService::toPaise(149.99));
        $this->assertSame(50, PaymentLinkService::toPaise(0.5));
    }

    // ── createLink ──────────────────────────────────────────────────────

    public function testCreateLinkPersistsRow(): void
    {
        $this->connectRazorpay();

        $link = (new PaymentLinkService())->createLink(1, 250, [
            'contact_id'  => 7,
            'description' => 'Invoice #42',
        ]);

        $this->assertSame(25000, (int) $link['amount_paise']);
        $this->assertSame('created', $link['status']);
        $this->assertSame('Invoice #42', $link['description']);
        $this->assertNotEmpty($link['short_url']);
        $this->assertStringStartsWith('tp_pl_1_', $link['reference_id']);
    }

    public function testCreateLinkFailsWithoutIntegration(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PaymentLinkService())->createLink(1, 250);
    }

    public function testCreateLinkRejectsTinyAmount(): void
    {
        $this->connectRazorpay();
        $this->expectException(\InvalidArgumentException::class);
        (new PaymentLinkService())->createLink(1, 0.5); // 50 paise < ₹1 minimum
    }

    // ── applyWebhookStatus ──────────────────────────────────────────────

    public function testWebhookMarksLinkPaid(): void
    {
        $this->connectRazorpay();
        $link = (new PaymentLinkService())->createLink(1, 100, ['description' => 'x']);

        $updated = (new PaymentLinkService())->applyWebhookStatus(
            'payment_link.paid',
            ['id' => $link['razorpay_link_id'], 'reference_id' => $link['reference_id'], 'status' => 'paid'],
            'pay_mock123',
        );

        $this->assertSame('paid', $updated['status']);

        $row = (new PaymentLinkModel())->setTenant(1)->find((int) $link['id']);
        $this->assertSame('paid', $row['status']);
        $this->assertSame('pay_mock123', $row['razorpay_payment_id']);
        $this->assertNotNull($row['paid_at']);
    }

    public function testWebhookUnknownLinkReturnsNull(): void
    {
        $result = (new PaymentLinkService())->applyWebhookStatus(
            'payment_link.paid',
            ['id' => 'plink_nope', 'reference_id' => 'tp_pl_1_nope'],
        );
        $this->assertNull($result);
    }
}
