<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Webhooks\OutboundWebhookService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\BlankSlateSchema;

/**
 * Tests outbound webhook signing/envelope (pure) and event fan-out (emit).
 */
class OutboundWebhookTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use BlankSlateSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAllTables();
        $this->createSchema();
    }

    private function createSchema(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;
        $db->query("CREATE TABLE IF NOT EXISTS {$p}tenants (id INTEGER PRIMARY KEY, name TEXT, slug TEXT)");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}webhook_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, url TEXT, secret TEXT,
            events TEXT, is_active INTEGER DEFAULT 1, last_status INTEGER, last_delivered_at TEXT,
            failure_count INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, type TEXT NOT NULL,
            source_type TEXT, source_id INTEGER, payload TEXT, run_at TEXT NOT NULL, status TEXT DEFAULT 'pending',
            attempts INTEGER DEFAULT 0, max_attempts INTEGER DEFAULT 5, locked_at TEXT, locked_by TEXT,
            last_error TEXT, created_at TEXT, updated_at TEXT
        )");
        foreach (['tenants','webhook_subscriptions','jobs'] as $t) {
            $db->query("DELETE FROM {$p}{$t}");
        }
        $db->query("INSERT INTO {$p}tenants (id,name,slug) VALUES (1,'T','t')");
    }

    private function addSub(array $events, int $active = 1): int
    {
        db_connect()->table('webhook_subscriptions')->insert([
            'tenant_id' => 1, 'url' => 'https://hooks.example.com/x', 'secret' => 'whsec_test',
            'events' => json_encode($events), 'is_active' => $active,
            'created_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00',
        ]);
        return (int) db_connect()->insertID();
    }

    // ── Pure helpers ────────────────────────────────────────────────────

    public function testSignatureIsStableHmac(): void
    {
        $body = '{"event":"contact.created"}';
        $this->assertSame(
            hash_hmac('sha256', $body, 's3cret'),
            OutboundWebhookService::sign($body, 's3cret')
        );
    }

    public function testEnvelopeShape(): void
    {
        $body = OutboundWebhookService::envelope('payment.paid', ['amount_paise' => 5000], 1_750_000_000);
        $decoded = json_decode($body, true);
        $this->assertSame('payment.paid', $decoded['event']);
        $this->assertSame(5000, $decoded['data']['amount_paise']);
        $this->assertArrayHasKey('delivered_at', $decoded);
    }

    // ── emit() fan-out ──────────────────────────────────────────────────

    public function testEmitEnqueuesOnlyMatchingSubscriptions(): void
    {
        $this->addSub(['contact.created']);            // matches
        $this->addSub(['*']);                          // wildcard matches
        $this->addSub(['payment.paid']);               // no match
        $this->addSub(['contact.created'], 0);         // inactive → skip

        $count = OutboundWebhookService::emit(1, 'contact.created', ['contact_id' => 9]);
        $this->assertSame(2, $count);

        $jobs = db_connect()->table('jobs')->where('type', 'webhook_deliver')->get()->getResultArray();
        $this->assertCount(2, $jobs);
        $payload = json_decode($jobs[0]['payload'], true);
        $this->assertSame('contact.created', $payload['event']);
        $this->assertSame(9, $payload['data']['contact_id']);
    }

    public function testEmitNoSubscriptionsReturnsZero(): void
    {
        $this->assertSame(0, OutboundWebhookService::emit(1, 'order.placed', []));
    }
}
