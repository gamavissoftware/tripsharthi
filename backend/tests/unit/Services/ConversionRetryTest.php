<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\ConversionFeedbackService;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\Group;

/** A platform that keeps answering with a temporary error must end as 'failed' after the last attempt, not stay 'pending' forever. MySQL group. */
#[Group('mysql')]
final class ConversionRetryTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = 'App';

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        foreach (['conversion_events', 'lead_attributions', 'contacts', 'integrations', 'tenants'] as $t) { $db->table($t)->truncate(); }
        $db->query('SET FOREIGN_KEY_CHECKS=1');
        $db->table('tenants')->insert(['id' => 1, 'name' => 'T', 'slug' => 't', 'plan' => 'pro', 'status' => 'active', 'mode' => 'saas', 'created_at' => '2026-01-01 00:00:00']);
        $db->table('contacts')->insert(['id' => 1, 'tenant_id' => 1, 'name' => 'A', 'wa_number' => '+919800000001', 'status' => 'new', 'source' => 'manual', 'opt_in' => 1, 'created_at' => '2026-01-01 00:00:00']);
        $db->table('integrations')->insert(['tenant_id' => 1, 'type' => 'meta_capi', 'status' => 'active', 'config' => json_encode(['dataset_id' => '123456789012345', 'access_token_enc' => TokenCipher::encrypt('tok-' . str_repeat('x', 30))]), 'created_at' => '2026-01-01 00:00:00']);
    }

    private function event(): int
    {
        db_connect()->table('conversion_events')->insert(['tenant_id' => 1, 'contact_id' => 1, 'platform' => 'meta', 'event_name' => 'Lead', 'event_id' => 'e' . uniqid(), 'value_amount' => 0, 'currency' => 'INR',
            'event_time' => date('Y-m-d H:i:s'), 'status' => 'pending', 'attempts' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        return (int) db_connect()->insertID();
    }

    private function row(int $id): array { return db_connect()->table('conversion_events')->where('id', $id)->get()->getRowArray(); }

    public function testATemporaryErrorRetriesAndTheFifthAttemptEndsAsFailedNotPending(): void
    {
        $id  = $this->event();
        $svc = new ConversionFeedbackService(static fn () => ['status' => 503, 'body' => 'unavailable']);
        for ($i = 1; $i <= 4; $i++) {
            $svc->dispatch();
            $this->assertSame(['pending', $i], [$this->row($id)['status'], (int) $this->row($id)['attempts']], "attempt $i");
        }
        $out = $svc->dispatch();
        $this->assertSame(['failed', 5], [$this->row($id)['status'], (int) $this->row($id)['attempts']]);
        $this->assertSame(1, $out['failed']);
        $this->assertSame(0, $svc->dispatch()['failed'], 'a failed event is not picked up again');
    }

    public function testARefusalFromThePlatformFailsAtOnceAndASuccessIsSent(): void
    {
        $bad = $this->event();
        (new ConversionFeedbackService(static fn () => ['status' => 400, 'body' => '{"error":"bad token"}']))->dispatch();
        $this->assertSame('failed', $this->row($bad)['status']);
        $good = $this->event();
        (new ConversionFeedbackService(static fn () => ['status' => 200, 'body' => '{"events_received":1}']))->dispatch();
        $this->assertSame('sent', $this->row($good)['status']);
    }
}
