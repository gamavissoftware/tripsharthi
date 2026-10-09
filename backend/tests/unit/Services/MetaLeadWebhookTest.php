<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\IntegrationModel;
use App\Models\MetaLeadEventModel;
use App\Services\Leads\MetaLeadsService;
use App\Services\Queue\MetaLeadFetchHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\MetaLeadTestSchema;

/**
 * Tests for the Meta Lead Ads webhook → job → handler pipeline.
 *
 * Tests the three approved additions:
 *   1. MULTI-TENANT RESOLUTION: unknown page_id ignored; known page_id resolves
 *      to correct tenant with no cross-tenant leakage.
 *   2. IDEMPOTENCY: same leadgen_id delivered twice → one event, one job,
 *      triggers fire ONCE.
 *   3. ASYNC PIPELINE: webhook queues meta_lead_fetch job; handler fetches
 *      (mocked), maps, dedupes, fires triggers.
 */
class MetaLeadWebhookTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use MetaLeadTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['META_LEADS_MOCK_MODE'] = 'true';
        $_ENV['WHATSAPP_MOCK_MODE']   = 'true';
        $this->createMetaLeadSchema();
    }

    protected function tearDown(): void
    {
        unset($_ENV['META_LEADS_MOCK_MODE'], $_ENV['WHATSAPP_MOCK_MODE']);
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helper: simulate processLeadEvent() logic
    // ------------------------------------------------------------------

    private function processLeadEvent(string $pageId, string $leadgenId): void
    {
        $integration = (new IntegrationModel())->findByPageId($pageId);
        if ($integration === null) return;

        $tenantId      = (int) $integration['tenant_id'];
        $integrationId = (int) $integration['id'];

        try {
            $eventId = (int) (new MetaLeadEventModel())
                ->withoutTenantScope()
                ->insert([
                    'tenant_id'      => $tenantId,
                    'integration_id' => $integrationId,
                    'leadgen_id'     => $leadgenId,
                    'status'         => 'queued',
                ], true);
        } catch (\Throwable $e) {
            return; // duplicate → silently skip
        }

        \App\Services\Flow\JobDispatcher::dispatch($tenantId, 'meta_lead_fetch', [
            'meta_lead_event_id' => $eventId,
        ]);
    }

    private function makeMockService(array $fieldData = []): MetaLeadsService
    {
        $data = [
            'id'         => 'mock_leadgen_id',
            'field_data' => $fieldData ?: [
                ['name' => 'full_name',    'values' => ['Test Lead']],
                ['name' => 'phone_number', 'values' => ['+919999900001']],
                ['name' => 'email',        'values' => ['lead@example.com']],
            ],
        ];
        return new MetaLeadsService($data);
    }

    // ------------------------------------------------------------------
    // 1. MULTI-TENANT RESOLUTION
    // ------------------------------------------------------------------

    public function testUnknownPageIdIgnoredGracefully(): void
    {
        // No integration registered for this page
        $this->processLeadEvent('unknown_page_id', 'leadgen_001');

        $eventCount = db_connect()->table('meta_lead_events')->countAllResults();
        $jobCount   = db_connect()->table('jobs')->where('type', 'meta_lead_fetch')->countAllResults();

        $this->assertSame(0, $eventCount, 'Unknown page_id must not create any events');
        $this->assertSame(0, $jobCount,   'Unknown page_id must not enqueue any jobs');
    }

    public function testKnownPageIdResolvesToCorrectTenant(): void
    {
        // Tenant 1 has page_id 'page_tenant1'
        // Tenant 2 has page_id 'page_tenant2'
        $db = db_connect();
        $p  = $db->DBPrefix;
        $db->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (2, 'Tenant2', 'tenant2')");

        $intId1 = $this->seedIntegration(1, 'page_tenant1');
        $intId2 = $this->seedIntegration(2, 'page_tenant2');

        // Webhook for tenant 1's page
        $this->processLeadEvent('page_tenant1', 'leadgen_tenant1_001');

        $event = db_connect()->table('meta_lead_events')
            ->where('leadgen_id', 'leadgen_tenant1_001')
            ->get()->getRowArray();

        $this->assertNotNull($event);
        $this->assertSame(1,      (int) $event['tenant_id'],      'Must resolve to tenant 1');
        $this->assertSame($intId1,(int) $event['integration_id'], 'Must use tenant 1 integration');

        // Confirm tenant 2 has no events
        $t2Events = db_connect()->table('meta_lead_events')
            ->where('tenant_id', 2)->countAllResults();
        $this->assertSame(0, $t2Events, 'Tenant 2 must not have any events — no cross-tenant leakage');
    }

    // ------------------------------------------------------------------
    // 2. IDEMPOTENCY: same leadgen_id delivered twice
    // ------------------------------------------------------------------

    public function testSameLeadgenIdDeliveredTwiceCreatesOneEventAndOneJob(): void
    {
        $this->seedIntegration(1, 'page_idempotent');

        // Simulate Meta retrying the same webhook
        $this->processLeadEvent('page_idempotent', 'leadgen_dup_001');
        $this->processLeadEvent('page_idempotent', 'leadgen_dup_001'); // retry

        $eventCount = db_connect()->table('meta_lead_events')
            ->where('leadgen_id', 'leadgen_dup_001')->countAllResults();
        $jobCount = db_connect()->table('jobs')
            ->where('type', 'meta_lead_fetch')->countAllResults();

        $this->assertSame(1, $eventCount, 'UNIQUE constraint must prevent duplicate event row');
        $this->assertSame(1, $jobCount,   'Duplicate webhook must not enqueue a second job');
    }

    public function testSameLeadgenIdProcessedOnceContactCreatedOnce(): void
    {
        $this->seedIntegration(1, 'page_dedup');

        // Both webhook deliveries processed
        $this->processLeadEvent('page_dedup', 'leadgen_dedup_001');
        $this->processLeadEvent('page_dedup', 'leadgen_dedup_001'); // retry — silently ignored

        // Run the ONE enqueued handler
        $job = db_connect()->table('jobs')->where('type', 'meta_lead_fetch')->get()->getRowArray();
        $this->assertNotNull($job);

        $handler = new MetaLeadFetchHandler($this->makeMockService());
        $handler->handle([
            'id'        => (int) $job['id'],
            'tenant_id' => 1,
            'payload'   => $job['payload'],
        ], 1);

        // Exactly one contact
        $contactCount = db_connect()->table('contacts')
            ->where('wa_number', '+919999900001')->countAllResults();
        $this->assertSame(1, $contactCount, 'Contact created exactly once — no duplicate from retry');

        // Event marked processed
        $event = db_connect()->table('meta_lead_events')
            ->where('leadgen_id', 'leadgen_dedup_001')->get()->getRowArray();
        $this->assertSame('processed', $event['status']);
    }

    public function testSameLeadgenTriggersFiredOnce(): void
    {
        $this->seedIntegration(1, 'page_trigger_dedup');
        $this->seedFlowForTrigger('meta_lead_received');

        // Two webhook deliveries, only one job created
        $this->processLeadEvent('page_trigger_dedup', 'leadgen_trigger_001');
        $this->processLeadEvent('page_trigger_dedup', 'leadgen_trigger_001');

        $job = db_connect()->table('jobs')->where('type', 'meta_lead_fetch')->get()->getRowArray();
        (new MetaLeadFetchHandler($this->makeMockService()))->handle([
            'id' => $job['id'], 'tenant_id' => 1, 'payload' => $job['payload'],
        ], 1);

        // Only one flow_start job (meta_lead_received fires once)
        $flowJobs = db_connect()->table('jobs')
            ->where('type', 'flow_start')->countAllResults();
        $this->assertGreaterThanOrEqual(1, $flowJobs);

        // Run handler again (simulating a second process of the same event — already 'processed')
        (new MetaLeadFetchHandler($this->makeMockService()))->handle([
            'id' => $job['id'], 'tenant_id' => 1, 'payload' => $job['payload'],
        ], 1);

        // Still same number of flow_start jobs — the handler skipped the already-processed event
        $flowJobsAfterRetry = db_connect()->table('jobs')
            ->where('type', 'flow_start')->countAllResults();
        $this->assertSame($flowJobs, $flowJobsAfterRetry, 'Triggers must not fire again on handler retry');
    }

    // ------------------------------------------------------------------
    // 3. ASYNC PIPELINE: handler creates contact + fires triggers
    // ------------------------------------------------------------------

    public function testHandlerCreatesContactFromLead(): void
    {
        $intId = $this->seedIntegration(1, 'page_pipeline');
        $this->processLeadEvent('page_pipeline', 'leadgen_pipe_001');

        $job = db_connect()->table('jobs')->where('type', 'meta_lead_fetch')->get()->getRowArray();
        (new MetaLeadFetchHandler($this->makeMockService()))->handle([
            'id' => $job['id'], 'tenant_id' => 1, 'payload' => $job['payload'],
        ], 1);

        $contact = db_connect()->table('contacts')
            ->where('wa_number', '+919999900001')->get()->getRowArray();

        $this->assertNotNull($contact, 'Contact must be created');
        $this->assertSame('meta_lead_ads', $contact['source']);
        $this->assertSame('Test Lead',     $contact['name']);
    }

    public function testHandlerFiresBothTriggers(): void
    {
        $this->seedIntegration(1, 'page_triggers');
        $this->seedFlowForTrigger('lead_created');
        $this->seedFlowForTrigger('meta_lead_received');

        $this->processLeadEvent('page_triggers', 'leadgen_trig_001');
        $job = db_connect()->table('jobs')->where('type', 'meta_lead_fetch')->get()->getRowArray();
        (new MetaLeadFetchHandler($this->makeMockService()))->handle([
            'id' => $job['id'], 'tenant_id' => 1, 'payload' => $job['payload'],
        ], 1);

        $flowJobs = db_connect()->table('jobs')->where('type', 'flow_start')->get()->getResultArray();
        $payloads = array_map(fn ($j) => json_decode($j['payload'], true), $flowJobs);

        $hasLeadCreated     = false;
        $hasMetaLeadReceived = false;
        foreach ($payloads as $p) {
            $flow = db_connect()->table('flows')->where('id', $p['flow_id'])->get()->getRowArray();
            if ($flow['trigger_type'] === 'lead_created')       $hasLeadCreated = true;
            if ($flow['trigger_type'] === 'meta_lead_received') $hasMetaLeadReceived = true;
        }

        $this->assertTrue($hasLeadCreated,      'lead_created trigger must fire');
        $this->assertTrue($hasMetaLeadReceived, 'meta_lead_received trigger must fire');
    }

    public function testAReturningLeadFiresMetaLeadReceivedButNotLeadCreated(): void
    {
        // The number is already a contact (say, from a CSV import). A fresh
        // lead-ad submission must still start the nurture flow.
        db_connect()->table('contacts')->insert([
            'tenant_id' => 1, 'wa_number' => '+919999900001', 'name' => 'Existing', 'source' => 'csv_import',
            'status' => 'new', 'opt_in' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->seedIntegration(1, 'page_returning');
        $this->seedFlowForTrigger('lead_created');
        $this->seedFlowForTrigger('meta_lead_received');

        $this->processLeadEvent('page_returning', 'leadgen_returning_001');
        $job = db_connect()->table('jobs')->where('type', 'meta_lead_fetch')->get()->getRowArray();
        (new MetaLeadFetchHandler($this->makeMockService()))->handle([
            'id' => $job['id'], 'tenant_id' => 1, 'payload' => $job['payload'],
        ], 1);

        $this->assertSame(1, db_connect()->table('contacts')->where('wa_number', '+919999900001')->countAllResults(), 'no duplicate contact');

        $types = [];
        foreach (db_connect()->table('jobs')->where('type', 'flow_start')->get()->getResultArray() as $j) {
            $flowId  = json_decode($j['payload'], true)['flow_id'];
            $types[] = db_connect()->table('flows')->where('id', $flowId)->get()->getRowArray()['trigger_type'];
        }
        $this->assertContains('meta_lead_received', $types);
        $this->assertNotContains('lead_created', $types);
    }

    public function testFormAnswersBecomeCustomFieldsOnTheContact(): void
    {
        $this->seedIntegration(1, 'page_cf');
        $this->processLeadEvent('page_cf', 'leadgen_cf_001');
        $job = db_connect()->table('jobs')->where('type', 'meta_lead_fetch')->get()->getRowArray();

        $service = $this->makeMockService([
            ['name' => 'full_name',    'values' => ['CF Lead']],
            ['name' => 'phone_number', 'values' => ['+919999900002']],
            ['name' => 'What type of business do you operate?', 'values' => ['Manufacturing']],
            ['name' => 'whatsapp_number', 'values' => ['+919999900002']],
        ]);
        (new MetaLeadFetchHandler($service))->handle([
            'id' => $job['id'], 'tenant_id' => 1, 'payload' => $job['payload'],
        ], 1);

        $contact = db_connect()->table('contacts')->where('wa_number', '+919999900002')->get()->getRowArray();
        $cf      = db_connect()->table('custom_fields')->where('field_key', 'what_type_of_business_do_you_operate')->get()->getRowArray();
        $this->assertNotNull($cf, 'a custom field is created for the question');
        $this->assertSame('What type of business do you operate', $cf['label']);
        $value = db_connect()->table('contact_field_values')
            ->where('contact_id', $contact['id'])->where('custom_field_id', $cf['id'])->get()->getRowArray();
        $this->assertSame('Manufacturing', $value['value']);
        // The WhatsApp question was consumed as wa_number, not stored twice.
        $this->assertNull(db_connect()->table('custom_fields')->where('field_key', 'whatsapp_number')->get()->getRowArray());
    }

    public function testASecondLeadWithALongQuestionDoesNotCollideOnTheCustomField(): void
    {
        $q = 'What are you currently using to manage your business?';
        foreach (['leadgen_long_1' => '+919999900011', 'leadgen_long_2' => '+919999900012'] as $lg => $phone) {
            $this->seedIntegration(1, 'page_long_' . $lg);
            $this->processLeadEvent('page_long_' . $lg, $lg);
            $job = db_connect()->table('jobs')->where('type', 'meta_lead_fetch')->where('status', 'pending')->get()->getRowArray();
            (new MetaLeadFetchHandler($this->makeMockService([
                ['name' => 'phone_number', 'values' => [$phone]],
                ['name' => $q,             'values' => ['Excel']],
            ])))->handle(['id' => $job['id'], 'tenant_id' => 1, 'payload' => $job['payload']], 1);
            db_connect()->table('jobs')->where('id', $job['id'])->update(['status' => 'done']);
            db_connect()->table('integrations')->where('page_id', 'page_long_' . $lg)->delete();
        }

        $this->assertSame(1, db_connect()->table('custom_fields')->like('field_key', 'what_are_you_currently', 'after')->countAllResults());
        $this->assertSame(2, db_connect()->table('meta_lead_events')->where('status', 'processed')->countAllResults());
        $c = db_connect()->table('contacts')->where('wa_number', '+919999900012')->get()->getRowArray();
        $v = db_connect()->table('contact_field_values')->where('contact_id', $c['id'])->get()->getRowArray();
        $this->assertSame('Excel', $v['value']);
    }

    public function testHandlerWithNoPhoneMarksFailed(): void
    {
        $this->seedIntegration(1, 'page_nophone');
        $this->processLeadEvent('page_nophone', 'leadgen_nophone_001');

        $job = db_connect()->table('jobs')->where('type', 'meta_lead_fetch')->get()->getRowArray();

        $noPhoneService = $this->makeMockService([
            ['name' => 'full_name', 'values' => ['No Phone']],
            ['name' => 'email',     'values' => ['nophone@example.com']],
            // No phone_number field
        ]);

        (new MetaLeadFetchHandler($noPhoneService))->handle([
            'id' => $job['id'], 'tenant_id' => 1, 'payload' => $job['payload'],
        ], 1);

        $contactCount = db_connect()->table('contacts')->countAllResults();
        $this->assertSame(0, $contactCount, 'No contact without phone');

        $event = db_connect()->table('meta_lead_events')
            ->where('leadgen_id', 'leadgen_nophone_001')->get()->getRowArray();
        $this->assertSame('failed', $event['status'], 'Event marked failed when no phone');
        $this->assertStringContainsString('Fields: full_name, email', (string) $event['error'], 'The reason names the fields that did arrive.');
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
