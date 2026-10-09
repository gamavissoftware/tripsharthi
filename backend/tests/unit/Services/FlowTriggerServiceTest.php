<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Flow\FlowTriggerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\FlowTestSchema;

/**
 * Tests FlowTriggerService::fire().
 *
 * Verifies:
 *   - Active-only enqueue (paused/draft ignored)
 *   - trigger_config matching for all three variable types
 *   - reentry_policy enforcement
 *   - No inline engine execution (only jobs row created, no flow_runs row)
 *   - Multiple matching flows each get their own job
 */
class FlowTriggerServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FlowTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlowSchema();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function countJobs(string $type = 'flow_start'): int
    {
        return (int) db_connect()->table('jobs')->where('type', $type)->countAllResults();
    }

    private function seedActiveFlow(string $triggerType, ?array $triggerConfig = null, string $reentry = 'once'): int
    {
        db_connect()->table('flows')->insert([
            'tenant_id'      => 1,
            'name'           => "Test {$triggerType}",
            'status'         => 'active',
            'trigger_type'   => $triggerType,
            'trigger_config' => $triggerConfig ? json_encode($triggerConfig) : null,
            'reentry_policy' => $reentry,
            'graph'          => json_encode(['nodes' => [], 'edges' => []]),
            'version'        => 1,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        return (int) db_connect()->insertID();
    }

    private function seedFlowWithStatus(string $status, string $triggerType = 'lead_created'): int
    {
        db_connect()->table('flows')->insert([
            'tenant_id'      => 1,
            'name'           => 'Status test',
            'status'         => $status,
            'trigger_type'   => $triggerType,
            'trigger_config' => null,
            'reentry_policy' => 'once',
            'graph'          => json_encode(['nodes' => [], 'edges' => []]),
            'version'        => 1,
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
        return (int) db_connect()->insertID();
    }

    private function seedActiveRun(int $flowId, int $contactId, string $status = 'running'): void
    {
        db_connect()->table('flow_runs')->insert([
            'tenant_id'       => 1,
            'flow_id'         => $flowId,
            'contact_id'      => $contactId,
            'current_node_id' => 'node_1',
            'status'          => $status,
            'graph_snapshot'  => '{}',
            'steps_executed'  => 0,
            'entered_at'      => date('Y-m-d H:i:s'),
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    // ------------------------------------------------------------------
    // Active-only enqueue
    // ------------------------------------------------------------------

    public function testActiveFlowEnqueuesJob(): void
    {
        $contactId = $this->seedContact('+919994000001');
        $this->seedActiveFlow('lead_created');

        $count = FlowTriggerService::fire('lead_created', 1, $contactId);

        $this->assertSame(1, $count);
        $this->assertSame(1, $this->countJobs());
    }

    public function testPausedFlowNotEnqueued(): void
    {
        $contactId = $this->seedContact('+919994000002');
        $this->seedFlowWithStatus('paused', 'lead_created');

        $count = FlowTriggerService::fire('lead_created', 1, $contactId);

        $this->assertSame(0, $count);
        $this->assertSame(0, $this->countJobs());
    }

    public function testDraftFlowNotEnqueued(): void
    {
        $contactId = $this->seedContact('+919994000003');
        $this->seedFlowWithStatus('draft', 'lead_created');

        $count = FlowTriggerService::fire('lead_created', 1, $contactId);

        $this->assertSame(0, $count);
        $this->assertSame(0, $this->countJobs());
    }

    // ------------------------------------------------------------------
    // tag_added config matching
    // ------------------------------------------------------------------

    public function testTagAddedMatchesConfigTagId(): void
    {
        $contactId = $this->seedContact('+919994000004');
        $this->seedActiveFlow('tag_added', ['tag_id' => 5]);

        $count = FlowTriggerService::fire('tag_added', 1, $contactId, ['tag_id' => 5]);

        $this->assertSame(1, $count);
    }

    public function testTagAddedNoMatchOnDifferentTagId(): void
    {
        $contactId = $this->seedContact('+919994000005');
        $this->seedActiveFlow('tag_added', ['tag_id' => 5]);

        $count = FlowTriggerService::fire('tag_added', 1, $contactId, ['tag_id' => 6]);

        $this->assertSame(0, $count);
    }

    public function testTagAddedNullConfigMatchesAnyTag(): void
    {
        $contactId = $this->seedContact('+919994000006');
        // No trigger_config (null) = any tag
        $this->seedActiveFlow('tag_added', null);

        $count = FlowTriggerService::fire('tag_added', 1, $contactId, ['tag_id' => 99]);

        $this->assertSame(1, $count);
    }

    public function testTagAddedZeroTagIdInConfigMatchesAnyTag(): void
    {
        $contactId = $this->seedContact('+919994000007');
        $this->seedActiveFlow('tag_added', ['tag_id' => 0]);

        $count = FlowTriggerService::fire('tag_added', 1, $contactId, ['tag_id' => 42]);

        $this->assertSame(1, $count, 'config tag_id=0 is the catch-all');
    }

    // ------------------------------------------------------------------
    // keyword_reply config matching
    // ------------------------------------------------------------------

    public function testKeywordReplyExactMatch(): void
    {
        $contactId = $this->seedContact('+919994000008');
        $this->seedActiveFlow('keyword_reply', ['keywords' => ['stop'], 'match' => 'exact']);

        $count = FlowTriggerService::fire('keyword_reply', 1, $contactId, ['content' => 'stop']);

        $this->assertSame(1, $count);
    }

    public function testKeywordReplyExactNoPartialMatch(): void
    {
        $contactId = $this->seedContact('+919994000009');
        $this->seedActiveFlow('keyword_reply', ['keywords' => ['stop'], 'match' => 'exact']);

        $count = FlowTriggerService::fire('keyword_reply', 1, $contactId, ['content' => 'please stop']);

        $this->assertSame(0, $count, 'exact match must not match substrings');
    }

    public function testKeywordReplyContainsMatch(): void
    {
        $contactId = $this->seedContact('+919994000010');
        $this->seedActiveFlow('keyword_reply', ['keywords' => ['stop'], 'match' => 'contains']);

        $count = FlowTriggerService::fire('keyword_reply', 1, $contactId, ['content' => 'please stop now']);

        $this->assertSame(1, $count);
    }

    public function testKeywordReplyCaseInsensitive(): void
    {
        $contactId = $this->seedContact('+919994000011');
        $this->seedActiveFlow('keyword_reply', ['keywords' => ['stop'], 'match' => 'exact']);

        $count = FlowTriggerService::fire('keyword_reply', 1, $contactId, ['content' => 'STOP']);

        $this->assertSame(1, $count, 'matching must be case-insensitive');
    }

    public function testKeywordReplyEmptyKeywordsNeverMatch(): void
    {
        $contactId = $this->seedContact('+919994000012');
        $this->seedActiveFlow('keyword_reply', ['keywords' => [], 'match' => 'contains']);

        $count = FlowTriggerService::fire('keyword_reply', 1, $contactId, ['content' => 'anything']);

        $this->assertSame(0, $count);
    }

    public function testKeywordReplyMultipleKeywordsFirstMatchEnqueues(): void
    {
        $contactId = $this->seedContact('+919994000013');
        $this->seedActiveFlow('keyword_reply', ['keywords' => ['hi', 'hello', 'hey'], 'match' => 'exact']);

        $count = FlowTriggerService::fire('keyword_reply', 1, $contactId, ['content' => 'hello']);

        $this->assertSame(1, $count);
    }

    // ------------------------------------------------------------------
    // form_submitted config matching
    // ------------------------------------------------------------------

    public function testFormSubmittedMatchesFormId(): void
    {
        $contactId = $this->seedContact('+919994000014');
        $this->seedActiveFlow('form_submitted', ['form_id' => 2]);

        $count = FlowTriggerService::fire('form_submitted', 1, $contactId, ['form_id' => 2]);

        $this->assertSame(1, $count);
    }

    public function testFormSubmittedNoMatchOnDifferentFormId(): void
    {
        $contactId = $this->seedContact('+919994000015');
        $this->seedActiveFlow('form_submitted', ['form_id' => 2]);

        $count = FlowTriggerService::fire('form_submitted', 1, $contactId, ['form_id' => 3]);

        $this->assertSame(0, $count);
    }

    public function testFormSubmittedNullConfigMatchesAnyForm(): void
    {
        $contactId = $this->seedContact('+919994000016');
        $this->seedActiveFlow('form_submitted', null);

        $count = FlowTriggerService::fire('form_submitted', 1, $contactId, ['form_id' => 7]);

        $this->assertSame(1, $count);
    }

    // ------------------------------------------------------------------
    // reentry_policy
    // ------------------------------------------------------------------

    public function testOncePolicySkipsEnqueueWhenRunningRunExists(): void
    {
        $contactId = $this->seedContact('+919994000017');
        $flowId    = $this->seedActiveFlow('lead_created', null, 'once');
        $this->seedActiveRun($flowId, $contactId, 'running');

        $count = FlowTriggerService::fire('lead_created', 1, $contactId);

        $this->assertSame(0, $count, 'once + running run → must not enqueue');
        $this->assertSame(0, $this->countJobs());
    }

    public function testOncePolicySkipsEnqueueWhenWaitingRunExists(): void
    {
        $contactId = $this->seedContact('+919994000018');
        $flowId    = $this->seedActiveFlow('lead_created', null, 'once');
        $this->seedActiveRun($flowId, $contactId, 'waiting');

        $count = FlowTriggerService::fire('lead_created', 1, $contactId);

        $this->assertSame(0, $count, 'once + waiting run → must not enqueue');
    }

    public function testOncePolicyEnqueuesWhenNoActiveRun(): void
    {
        $contactId = $this->seedContact('+919994000019');
        $this->seedActiveFlow('lead_created', null, 'once');
        // No existing run

        $count = FlowTriggerService::fire('lead_created', 1, $contactId);

        $this->assertSame(1, $count);
    }

    public function testOncePolicyAllowsReenrollAfterCompletedRun(): void
    {
        $contactId = $this->seedContact('+919994000020');
        $flowId    = $this->seedActiveFlow('lead_created', null, 'once');
        // Prior run is COMPLETED — not blocking
        $this->seedActiveRun($flowId, $contactId, 'completed');

        $count = FlowTriggerService::fire('lead_created', 1, $contactId);

        $this->assertSame(1, $count,
            'Once policy: completed run allows fresh enrollment (each trigger is a new event)');
    }

    public function testAlwaysPolicyEnqueuesEvenWithActiveRun(): void
    {
        $contactId = $this->seedContact('+919994000021');
        $flowId    = $this->seedActiveFlow('lead_created', null, 'always');
        $this->seedActiveRun($flowId, $contactId, 'running');

        $count = FlowTriggerService::fire('lead_created', 1, $contactId);

        $this->assertSame(1, $count, 'always policy: must enqueue even with active run');
    }

    // ------------------------------------------------------------------
    // Inbound triggers re-enroll after completion (intended semantics)
    // ------------------------------------------------------------------

    public function testKeywordReplyEnrollsAfterCompletedRun(): void
    {
        $contactId = $this->seedContact('+919994000022');
        $flowId    = $this->seedActiveFlow('keyword_reply', ['keywords' => ['hello'], 'match' => 'exact'], 'once');
        $this->seedActiveRun($flowId, $contactId, 'completed');

        $count = FlowTriggerService::fire('keyword_reply', 1, $contactId, ['content' => 'hello']);

        $this->assertSame(1, $count,
            'keyword_reply once: after completed run, next inbound starts a fresh run — intended semantics');
    }

    // ------------------------------------------------------------------
    // No inline engine execution
    // ------------------------------------------------------------------

    public function testFireNeverCreatesFlowRunInline(): void
    {
        $contactId = $this->seedContact('+919994000023');
        $this->seedActiveFlow('lead_created');

        FlowTriggerService::fire('lead_created', 1, $contactId);

        $runCount = db_connect()->table('flow_runs')->countAllResults();
        $this->assertSame(0, $runCount,
            'fire() must never create a flow_run row — engine runs in the worker, not inline');
    }

    // ------------------------------------------------------------------
    // Multiple matching flows
    // ------------------------------------------------------------------

    public function testMultipleMatchingFlowsEachGetAJob(): void
    {
        $contactId = $this->seedContact('+919994000024');
        $this->seedActiveFlow('lead_created');
        $this->seedActiveFlow('lead_created');

        $count = FlowTriggerService::fire('lead_created', 1, $contactId);

        $this->assertSame(2, $count, 'Two active matching flows must each get a job');
        $this->assertSame(2, $this->countJobs());
    }

    // ------------------------------------------------------------------
    // Job payload integrity
    // ------------------------------------------------------------------

    public function testJobPayloadContainsCorrectFlowAndContactId(): void
    {
        $contactId = $this->seedContact('+919994000025');
        $flowId    = $this->seedActiveFlow('lead_created');

        FlowTriggerService::fire('lead_created', 1, $contactId, ['source' => 'manual']);

        $job = db_connect()->table('jobs')->where('type', 'flow_start')->get()->getRowArray();
        $this->assertNotNull($job);

        $payload = json_decode($job['payload'], true);
        $this->assertSame($flowId,    $payload['flow_id']);
        $this->assertSame($contactId, $payload['contact_id']);
        $this->assertSame('manual',   $payload['context']['source']);
        $this->assertSame('1',        (string) $job['tenant_id']);
    }

    // ------------------------------------------------------------------
    // Invalid inputs
    // ------------------------------------------------------------------

    public function testFireWithZeroTenantIdReturnsZero(): void
    {
        $this->assertSame(0, FlowTriggerService::fire('lead_created', 0, 1));
    }

    public function testFireWithZeroContactIdReturnsZero(): void
    {
        $this->assertSame(0, FlowTriggerService::fire('lead_created', 1, 0));
    }

    // ------------------------------------------------------------------
    // lead_created fires for all sources
    // ------------------------------------------------------------------

    public function testLeadCreatedFiresForAllSources(): void
    {
        $contactId = $this->seedContact('+919994000026');
        $this->seedActiveFlow('lead_created');

        // source field in context is informational only — no config gating
        $count = FlowTriggerService::fire('lead_created', 1, $contactId, ['source' => 'csv_import']);
        $this->assertSame(1, $count, 'lead_created must fire regardless of source');
    }
}
