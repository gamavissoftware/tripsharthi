<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\FlowRunModel;
use App\Services\Flow\FlowEngine;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\FlowTestSchema;

/**
 * Tests the core FlowEngine::advance() walk.
 */
class FlowEngineTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FlowTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    /** Fixed now: 2026-01-15 12:00:00 UTC */
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['WHATSAPP_MOCK_MODE'] = 'true';
        $this->now = mktime(12, 0, 0, 1, 15, 2026);
        $this->createFlowSchema();
    }

    protected function tearDown(): void
    {
        unset($_ENV['WHATSAPP_MOCK_MODE']);
        parent::tearDown();
    }

    private function engine(): FlowEngine
    {
        return new FlowEngine(
            new \App\Services\WhatsApp\CloudApiClient('mock_phone', 'mock_token'),
            null,
            $this->now
        );
    }

    // ------------------------------------------------------------------
    // Basic: trigger → add_tag → terminal
    // ------------------------------------------------------------------

    public function testSimpleFlowCompletesAndAssignsTag(): void
    {
        $contactId = $this->seedContact('+919990000001');
        // Ensure no stale tags from prior test runs (createFlowSchema already did this,
        // but be explicit so the assertion below is unambiguous)
        $p = db_connect()->DBPrefix;
        db_connect()->query("DELETE FROM {$p}contact_tags WHERE contact_id=?", [$contactId]);

        $graph = $this->makeGraph(
            [
                ['id' => 't', 'type' => 'lead_created', 'data' => []],
                ['id' => 'a', 'type' => 'add_tag', 'data' => ['tag_id' => 99]],
            ],
            [['id' => 'e1', 'source' => 't', 'target' => 'a', 'sourceHandle' => 'next']]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'a'); // start AFTER trigger

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine()->advance($run, 1);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->assertSame('completed', $run['status']);

        // Tag attached
        $tagged = db_connect()->table('contact_tags')
            ->where('contact_id', $contactId)->where('tag_id', 99)->countAllResults();
        $this->assertSame(1, $tagged, 'add_tag executor must attach the tag');
    }

    // A flow TEST (is_test=1 → dryRun) must NOT mutate real CRM data — the
    // add_tag executor previously wrote a real contact_tags row (and fired a real
    // tag_added trigger) during a "test" run.
    public function testDryRunDoesNotAttachRealTags(): void
    {
        $contactId = $this->seedContact('+919990009999');
        $p = db_connect()->DBPrefix;
        db_connect()->query("DELETE FROM {$p}contact_tags WHERE contact_id=?", [$contactId]);

        $graph = $this->makeGraph(
            [
                ['id' => 't', 'type' => 'lead_created', 'data' => []],
                ['id' => 'a', 'type' => 'add_tag', 'data' => ['tag_id' => 77]],
            ],
            [['id' => 'e1', 'source' => 't', 'target' => 'a', 'sourceHandle' => 'next']]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'a');

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $run['is_test'] = 1; // advance() reads dryRun from the run array
        $this->engine()->advance($run, 1);

        $tagged = db_connect()->table('contact_tags')
            ->where('contact_id', $contactId)->where('tag_id', 77)->countAllResults();
        $this->assertSame(0, $tagged, 'flow TEST (dry-run) must NOT attach real tags');
    }

    // ------------------------------------------------------------------
    // Delay: run parks as 'waiting', flow_resume job enqueued
    // ------------------------------------------------------------------

    public function testDelayNodeParksRunAndEnqueuesResumeJob(): void
    {
        $contactId = $this->seedContact('+919990000002');
        $graph = $this->makeGraph(
            [
                ['id' => 't',  'type' => 'lead_created', 'data' => []],
                ['id' => 'd',  'type' => 'delay',         'data' => ['value' => 1, 'unit' => 'hours']],
                ['id' => 'a2', 'type' => 'add_tag',        'data' => ['tag_id' => 5]],
            ],
            [
                ['id' => 'e1', 'source' => 't',  'target' => 'd',  'sourceHandle' => 'next'],
                ['id' => 'e2', 'source' => 'd',  'target' => 'a2', 'sourceHandle' => 'next'],
            ]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'd');

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine()->advance($run, 1);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->assertSame('waiting', $run['status']);
        $this->assertSame('a2', $run['current_node_id'], 'Should park AT next node (a2), not delay node');

        $expectedRunAt = date('Y-m-d H:i:s', $this->now + 3600);
        $this->assertSame($expectedRunAt, $run['next_run_at']);

        // flow_resume job enqueued
        $job = db_connect()->table('jobs')
            ->where('type', 'flow_resume')->where('tenant_id', 1)->get()->getRowArray();
        $this->assertNotNull($job, 'flow_resume job must be enqueued');
        $this->assertSame($expectedRunAt, $job['run_at']);
        $payload = json_decode($job['payload'], true);
        $this->assertSame($runId, $payload['flow_run_id']);
    }

    // ------------------------------------------------------------------
    // Resume: continues from saved node
    // ------------------------------------------------------------------

    public function testResumeAdvancesFromSavedCurrentNode(): void
    {
        $contactId = $this->seedContact('+919990000003');
        $graph = $this->makeGraph(
            [
                ['id' => 't',  'type' => 'lead_created', 'data' => []],
                ['id' => 'a1', 'type' => 'add_tag',       'data' => ['tag_id' => 10]],
                ['id' => 'a2', 'type' => 'add_tag',       'data' => ['tag_id' => 11]],
            ],
            [
                ['id' => 'e1', 'source' => 't',  'target' => 'a1', 'sourceHandle' => 'next'],
                ['id' => 'e2', 'source' => 'a1', 'target' => 'a2', 'sourceHandle' => 'next'],
            ]
        );

        $flowId = $this->seedFlow($graph);
        // Simulate: a1 already ran, run is waiting at a2
        $runId = $this->seedRun($flowId, $contactId, $graph, 'a2', [
            'status'         => 'waiting',
            'steps_executed' => 1,
        ]);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine()->advance($run, 1);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->assertSame('completed', $run['status']);
        $this->assertSame(2, (int)$run['steps_executed']);

        // Only tag 11 was added (not 10 — resume starts at a2, not a1)
        $tags = db_connect()->table('contact_tags')
            ->where('contact_id', $contactId)->get()->getResultArray();
        $tagIds = array_column($tags, 'tag_id');
        $this->assertContains(11, $tagIds);
        $this->assertNotContains(10, $tagIds, 'a1 must NOT re-run on resume');
    }

    // ------------------------------------------------------------------
    // Terminal node → completed
    // ------------------------------------------------------------------

    public function testTerminalNodeCompletesRun(): void
    {
        $contactId = $this->seedContact('+919990000004');
        // No outgoing edge from 'a' → terminal
        $graph = $this->makeGraph(
            [
                ['id' => 't', 'type' => 'lead_created', 'data' => []],
                ['id' => 'a', 'type' => 'add_tag',      'data' => ['tag_id' => 1]],
            ],
            [['id' => 'e1', 'source' => 't', 'target' => 'a', 'sourceHandle' => 'next']]
            // No edge from 'a' → terminal
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'a');

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine()->advance($run, 1);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->assertSame('completed', $run['status']);
        $this->assertNotNull($run['completed_at']);
    }

    // ------------------------------------------------------------------
    // Condition: true / false branches
    // ------------------------------------------------------------------

    public function testConditionTrueBranch(): void
    {
        $contactId = $this->seedContact('+919990000005', ['status' => 'qualified']);
        $graph = $this->makeGraph(
            [
                ['id' => 't',  'type' => 'lead_created', 'data' => []],
                ['id' => 'c',  'type' => 'condition',    'data' => ['type'=>'contact_field','field'=>'status','operator'=>'equals','value'=>'qualified']],
                ['id' => 'at', 'type' => 'add_tag',      'data' => ['tag_id' => 20]],
                ['id' => 'af', 'type' => 'add_tag',      'data' => ['tag_id' => 21]],
            ],
            [
                ['id' => 'e1', 'source' => 't', 'target' => 'c',  'sourceHandle' => 'next'],
                ['id' => 'e2', 'source' => 'c', 'target' => 'at', 'sourceHandle' => 'true'],
                ['id' => 'e3', 'source' => 'c', 'target' => 'af', 'sourceHandle' => 'false'],
            ]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'c');

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine()->advance($run, 1);

        // SQLite returns INTEGER columns as PHP int — use int literals in assertContains
        $tags = array_column(
            db_connect()->table('contact_tags')->where('contact_id', $contactId)->get()->getResultArray(),
            'tag_id'
        );
        $this->assertContains(20, $tags, 'true branch tag must be assigned');
        $this->assertNotContains(21, $tags, 'false branch tag must NOT be assigned');
    }

    public function testConditionFalseBranch(): void
    {
        $contactId = $this->seedContact('+919990000006', ['status' => 'new']);
        $graph = $this->makeGraph(
            [
                ['id' => 't',  'type' => 'lead_created', 'data' => []],
                ['id' => 'c',  'type' => 'condition',    'data' => ['type'=>'contact_field','field'=>'status','operator'=>'equals','value'=>'qualified']],
                ['id' => 'at', 'type' => 'add_tag',      'data' => ['tag_id' => 22]],
                ['id' => 'af', 'type' => 'add_tag',      'data' => ['tag_id' => 23]],
            ],
            [
                ['id' => 'e1', 'source' => 't', 'target' => 'c',  'sourceHandle' => 'next'],
                ['id' => 'e2', 'source' => 'c', 'target' => 'at', 'sourceHandle' => 'true'],
                ['id' => 'e3', 'source' => 'c', 'target' => 'af', 'sourceHandle' => 'false'],
            ]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'c');
        $run    = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine()->advance($run, 1);

        $tags = array_column(
            db_connect()->table('contact_tags')->where('contact_id', $contactId)->get()->getResultArray(),
            'tag_id'
        );
        $this->assertNotContains(22, $tags);
        $this->assertContains(23, $tags, 'false branch tag must be assigned');
    }

    // ------------------------------------------------------------------
    // graph_snapshot is immutable — modifying flows.graph after start has no effect
    // ------------------------------------------------------------------

    public function testGraphSnapshotIsImmutableAfterEnrollment(): void
    {
        $contactId = $this->seedContact('+919990000007');
        $graph = $this->makeGraph(
            [
                ['id' => 't', 'type' => 'lead_created', 'data' => []],
                ['id' => 'a', 'type' => 'add_tag',      'data' => ['tag_id' => 30]],
            ],
            [['id' => 'e1', 'source' => 't', 'target' => 'a', 'sourceHandle' => 'next']]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'a');

        // Simulate editing the flow AFTER enrollment
        $newGraph = $this->makeGraph(
            [
                ['id' => 't', 'type' => 'lead_created', 'data' => []],
                ['id' => 'a', 'type' => 'add_tag',      'data' => ['tag_id' => 99]], // different tag
            ],
            [['id' => 'e1', 'source' => 't', 'target' => 'a', 'sourceHandle' => 'next']]
        );
        db_connect()->table('flows')->where('id', $flowId)->update(['graph' => json_encode($newGraph)]);

        // Engine must use the SNAPSHOT (tag 30), not the live graph (tag 99)
        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $this->engine()->advance($run, 1);

        $tags = array_column(
            db_connect()->table('contact_tags')->where('contact_id', $contactId)->get()->getResultArray(),
            'tag_id'
        );
        $this->assertContains(30, $tags, 'Snapshot tag (30) must be used');
        $this->assertNotContains(99, $tags, 'Live graph tag (99) must not be used');
    }
}
