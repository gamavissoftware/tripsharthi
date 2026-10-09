<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\GoogleLeadEventModel;
use App\Models\IntegrationModel;
use App\Services\Flow\JobDispatcher;
use App\Services\Queue\GoogleLeadProcessHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\MetaLeadTestSchema;

/**
 * Tests for the Google Ads Lead Forms webhook → job → handler pipeline.
 *
 *   1. AUTH + MULTI-TENANT RESOLUTION: unknown google_key rejected; known key
 *      resolves to the correct tenant with no cross-tenant leakage.
 *   2. IDEMPOTENCY: same lead_id delivered twice → one event, one job.
 *   3. ASYNC PIPELINE: webhook stores the inline payload + queues a
 *      google_lead_process job; the handler maps, dedupes, fires triggers.
 *   4. NO-PHONE guard: a lead with no phone column is marked failed (no contact).
 */
class GoogleLeadWebhookTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use MetaLeadTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['WHATSAPP_MOCK_MODE'] = 'true';
        $this->createMetaLeadSchema();
    }

    protected function tearDown(): void
    {
        unset($_ENV['WHATSAPP_MOCK_MODE']);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helper: simulate GoogleLeadWebhookController::processLead()
    // ------------------------------------------------------------------

    private function processLead(string $googleKey, string $leadId, array $columns, bool $isTest = false): void
    {
        $integration = (new IntegrationModel())->findByGoogleKey($googleKey);
        if ($integration === null) return; // unknown key → rejected

        if ($isTest) return; // is_test ping → acknowledged, nothing stored

        $tenantId      = (int) $integration['tenant_id'];
        $integrationId = (int) $integration['id'];

        try {
            $eventId = (int) (new GoogleLeadEventModel())
                ->withoutTenantScope()
                ->insert([
                    'tenant_id'      => $tenantId,
                    'integration_id' => $integrationId,
                    'lead_id'        => $leadId,
                    'payload'        => json_encode(['user_column_data' => $columns]),
                    'status'         => 'queued',
                ], true);
        } catch (\Throwable $e) {
            return; // duplicate → silently skip
        }

        JobDispatcher::dispatch($tenantId, 'google_lead_process', [
            'google_lead_event_id' => $eventId,
        ]);
    }

    private function defaultColumns(): array
    {
        return [
            ['column_id' => 'FULL_NAME',    'string_value' => 'Test Lead'],
            ['column_id' => 'PHONE_NUMBER', 'string_value' => '+919999900001'],
            ['column_id' => 'EMAIL',        'string_value' => 'lead@example.com'],
        ];
    }

    private function runHandlerForQueuedJob(): void
    {
        $job = db_connect()->table('jobs')->where('type', 'google_lead_process')->get()->getRowArray();
        $this->assertNotNull($job, 'A google_lead_process job must have been queued.');
        (new GoogleLeadProcessHandler())->handle([
            'id'        => (int) $job['id'],
            'tenant_id' => (int) $job['tenant_id'],
            'payload'   => $job['payload'],
        ], (int) $job['tenant_id']);
    }

    // ------------------------------------------------------------------
    // 1. AUTH + MULTI-TENANT RESOLUTION
    // ------------------------------------------------------------------

    public function testUnknownGoogleKeyRejected(): void
    {
        $this->seedGoogleIntegration(1, 'realkey');
        $this->processLead('wrongkey', 'lead_001', $this->defaultColumns());

        $this->assertSame(0, db_connect()->table('google_lead_events')->countAllResults(),
            'Unknown google_key must not create any events');
        $this->assertSame(0, db_connect()->table('jobs')->where('type', 'google_lead_process')->countAllResults(),
            'Unknown google_key must not enqueue any jobs');
    }

    public function testKnownGoogleKeyResolvesToCorrectTenant(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;
        $db->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (2, 'Tenant2', 'tenant2')");

        $this->seedGoogleIntegration(1, 'key_tenant1');
        $this->seedGoogleIntegration(2, 'key_tenant2');

        $this->processLead('key_tenant1', 'lead_t1_001', $this->defaultColumns());

        $event = db_connect()->table('google_lead_events')->where('lead_id', 'lead_t1_001')->get()->getRowArray();
        $this->assertNotNull($event);
        $this->assertSame(1, (int) $event['tenant_id'], 'Must resolve to tenant 1');

        $t2 = db_connect()->table('google_lead_events')->where('tenant_id', 2)->countAllResults();
        $this->assertSame(0, $t2, 'Tenant 2 must not have any events — no cross-tenant leakage');
    }

    // ------------------------------------------------------------------
    // 2. IDEMPOTENCY
    // ------------------------------------------------------------------

    public function testSameLeadIdDeliveredTwiceCreatesOneEventAndOneJob(): void
    {
        $this->seedGoogleIntegration(1, 'key_idem');

        $this->processLead('key_idem', 'lead_dup_001', $this->defaultColumns());
        $this->processLead('key_idem', 'lead_dup_001', $this->defaultColumns()); // Google retry

        $this->assertSame(1, db_connect()->table('google_lead_events')->where('lead_id', 'lead_dup_001')->countAllResults(),
            'UNIQUE constraint must prevent duplicate event row');
        $this->assertSame(1, db_connect()->table('jobs')->where('type', 'google_lead_process')->countAllResults(),
            'Duplicate webhook must not enqueue a second job');
    }

    // ------------------------------------------------------------------
    // 3. ASYNC PIPELINE
    // ------------------------------------------------------------------

    public function testHandlerCreatesContactFromLead(): void
    {
        $this->seedGoogleIntegration(1, 'key_pipe');
        $this->processLead('key_pipe', 'lead_pipe_001', $this->defaultColumns());
        $this->runHandlerForQueuedJob();

        $contact = db_connect()->table('contacts')->where('wa_number', '+919999900001')->get()->getRowArray();
        $this->assertNotNull($contact, 'Contact must be created');
        $this->assertSame('google_lead_forms', $contact['source']);
        $this->assertSame('Test Lead',         $contact['name']);

        $event = db_connect()->table('google_lead_events')->where('lead_id', 'lead_pipe_001')->get()->getRowArray();
        $this->assertSame('processed', $event['status']);
        $this->assertSame((int) $contact['id'], (int) $event['contact_id']);
    }

    public function testHandlerFiresBothTriggers(): void
    {
        $this->seedGoogleIntegration(1, 'key_trig');
        $this->seedFlowForTrigger('lead_created');
        $this->seedFlowForTrigger('google_lead_received');

        $this->processLead('key_trig', 'lead_trig_001', $this->defaultColumns());
        $this->runHandlerForQueuedJob();

        $flowJobs = db_connect()->table('jobs')->where('type', 'flow_start')->get()->getResultArray();
        $hasLeadCreated = false;
        $hasGoogle      = false;
        foreach ($flowJobs as $j) {
            $payload = json_decode($j['payload'], true);
            $flow    = db_connect()->table('flows')->where('id', $payload['flow_id'])->get()->getRowArray();
            if ($flow['trigger_type'] === 'lead_created')          $hasLeadCreated = true;
            if ($flow['trigger_type'] === 'google_lead_received')  $hasGoogle = true;
        }

        $this->assertTrue($hasLeadCreated, 'lead_created trigger must fire');
        $this->assertTrue($hasGoogle,      'google_lead_received trigger must fire');
    }

    public function testHandlerIdempotentOnReprocess(): void
    {
        $this->seedGoogleIntegration(1, 'key_reproc');
        $this->seedFlowForTrigger('google_lead_received');

        $this->processLead('key_reproc', 'lead_reproc_001', $this->defaultColumns());
        $this->runHandlerForQueuedJob();
        $flowJobsAfterFirst = db_connect()->table('jobs')->where('type', 'flow_start')->countAllResults();

        // Re-run the same handler — event is now 'processed', must be skipped.
        $this->runHandlerForQueuedJob();
        $flowJobsAfterSecond = db_connect()->table('jobs')->where('type', 'flow_start')->countAllResults();

        $this->assertSame($flowJobsAfterFirst, $flowJobsAfterSecond,
            'Triggers must not fire again on handler reprocess of a processed event');
    }

    // ------------------------------------------------------------------
    // 4. NO-PHONE guard
    // ------------------------------------------------------------------

    public function testHandlerWithNoPhoneMarksFailed(): void
    {
        $this->seedGoogleIntegration(1, 'key_nophone');
        $this->processLead('key_nophone', 'lead_nophone_001', [
            ['column_id' => 'FULL_NAME', 'string_value' => 'No Phone'],
            ['column_id' => 'EMAIL',     'string_value' => 'nophone@example.com'],
        ]);
        $this->runHandlerForQueuedJob();

        $this->assertSame(0, db_connect()->table('contacts')->countAllResults(), 'No contact without phone');

        $event = db_connect()->table('google_lead_events')->where('lead_id', 'lead_nophone_001')->get()->getRowArray();
        $this->assertSame('failed', $event['status'], 'Event marked failed when no phone');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function seedFlowForTrigger(string $triggerType): int
    {
        db_connect()->table('flows')->insert([
            'tenant_id'      => 1,
            'name'           => "Test {$triggerType}",
            'status'         => 'active',
            'trigger_type'   => $triggerType,
            'graph'          => json_encode(['nodes' => [], 'edges' => []]),
            'reentry_policy' => 'always',
            'version'        => 1,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        return (int) db_connect()->insertID();
    }
}
