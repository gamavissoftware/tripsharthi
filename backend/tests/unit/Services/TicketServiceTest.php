<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\TicketModel;
use App\Services\Flow\FlowEngine;
use App\Services\Flow\FlowTriggerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase D (Service): SLA computation, ticket flow triggers + the
 * create_ticket action, and tenant isolation.
 */
class TicketServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CrmTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['WHATSAPP_MOCK_MODE'] = 'true';
        $this->now = mktime(12, 0, 0, 1, 15, 2026);
        $this->createCrmSchema();
    }

    protected function tearDown(): void
    {
        unset($_ENV['WHATSAPP_MOCK_MODE']);
        parent::tearDown();
    }

    // ── SLA computation ────────────────────────────────────────────────

    public function testSlaDueAtByPriority(): void
    {
        $base = $this->now;
        $this->assertSame(date('Y-m-d H:i:s', $base + 2 * 3600),  TicketModel::slaDueAt('urgent', $base));
        $this->assertSame(date('Y-m-d H:i:s', $base + 4 * 3600),  TicketModel::slaDueAt('high', $base));
        $this->assertSame(date('Y-m-d H:i:s', $base + 24 * 3600), TicketModel::slaDueAt('medium', $base));
        $this->assertSame(date('Y-m-d H:i:s', $base + 72 * 3600), TicketModel::slaDueAt('low', $base));
        // Unknown priority falls back to the medium SLA.
        $this->assertSame(date('Y-m-d H:i:s', $base + 24 * 3600), TicketModel::slaDueAt('bogus', $base));
    }

    public function testIsOpenStatus(): void
    {
        $this->assertTrue(TicketModel::isOpenStatus('open'));
        $this->assertTrue(TicketModel::isOpenStatus('pending'));
        $this->assertFalse(TicketModel::isOpenStatus('resolved'));
        $this->assertFalse(TicketModel::isOpenStatus('closed'));
    }

    // ── Ticket flow triggers ───────────────────────────────────────────

    public function testTicketCreatedAndResolvedFireFlows(): void
    {
        $contactId = $this->seedContact('+919990000030');
        $this->seedFlow($this->makeGraph([['id' => 't', 'type' => 'ticket_created', 'data' => []]], []), 'ticket_created', ['status' => 'active']);
        $this->seedFlow($this->makeGraph([['id' => 't', 'type' => 'ticket_resolved', 'data' => []]], []), 'ticket_resolved', ['status' => 'active']);

        $this->assertSame(1, FlowTriggerService::fire('ticket_created', 1, $contactId, ['ticket_id' => 9]));
        $this->assertSame(1, FlowTriggerService::fire('ticket_resolved', 1, $contactId, ['ticket_id' => 9]));
        $this->assertSame(2, db_connect()->table('jobs')->where('type', 'flow_start')->countAllResults());
    }

    // ── create_ticket action ───────────────────────────────────────────

    public function testCreateTicketAction(): void
    {
        $contactId = $this->seedContact('+919990000031');
        $graph = $this->makeGraph(
            [['id' => 't', 'type' => 'lead_created', 'data' => []], ['id' => 'a', 'type' => 'create_ticket', 'data' => ['subject' => 'Refund needed', 'priority' => 'high']]],
            [['id' => 'e1', 'source' => 't', 'target' => 'a', 'sourceHandle' => 'next']]
        );
        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'a');
        $run    = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();

        $engine = new FlowEngine(new \App\Services\WhatsApp\CloudApiClient('mock', 'mock'), null, $this->now);
        $engine->advance($run, 1);

        $ticket = db_connect()->table('tickets')->where('contact_id', $contactId)->get()->getRowArray();
        $this->assertNotNull($ticket, 'create_ticket must open a ticket for the contact');
        $this->assertSame('Refund needed', $ticket['subject']);
        $this->assertSame('high', $ticket['priority']);
        $this->assertSame('whatsapp', $ticket['source']);
        $this->assertNotNull($ticket['sla_due_at'], 'SLA must be set from priority');
    }

    public function testCreateTicketDryRunDoesNotMutate(): void
    {
        $contactId = $this->seedContact('+919990000032');
        $graph = $this->makeGraph(
            [['id' => 't', 'type' => 'lead_created', 'data' => []], ['id' => 'a', 'type' => 'create_ticket', 'data' => ['subject' => 'No-op']]],
            [['id' => 'e1', 'source' => 't', 'target' => 'a', 'sourceHandle' => 'next']]
        );
        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'a');
        $run    = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        $run['is_test'] = 1;

        (new FlowEngine(new \App\Services\WhatsApp\CloudApiClient('mock', 'mock'), null, $this->now))->advance($run, 1);

        $this->assertSame(0, db_connect()->table('tickets')->countAllResults(), 'flow TEST must not open real tickets');
    }

    // ── Tenant isolation ───────────────────────────────────────────────

    public function testTicketsAreTenantScoped(): void
    {
        (new TicketModel())->setTenant(1)->insert(['subject' => 'T1', 'priority' => 'medium', 'status' => 'open']);
        (new TicketModel())->setTenant(2)->insert(['subject' => 'T2', 'priority' => 'medium', 'status' => 'open']);

        $t1 = (new TicketModel())->setTenant(1)->findAll();
        $this->assertCount(1, $t1);
        $this->assertSame('T1', $t1[0]['subject']);
    }
}
