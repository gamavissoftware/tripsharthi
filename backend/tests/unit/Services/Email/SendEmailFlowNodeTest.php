<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Email;

use App\Services\Email\Marketing\SuppressionService;
use App\Services\Flow\FlowEngine;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\EmailMarketingTestSchema;

/**
 * The `send_email` flow action: a tracked email to the run's contact, from a
 * saved template; contacts without an address or on the suppression list are
 * skipped and the run carries on.
 */
class SendEmailFlowNodeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmailMarketingTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['WHATSAPP_MOCK_MODE'] = 'true';
        $_ENV['EMAIL_MOCK_MODE']    = 'true';
        $this->createEmailMarketingSchema();
    }

    protected function tearDown(): void
    {
        unset($_ENV['WHATSAPP_MOCK_MODE'], $_ENV['EMAIL_MOCK_MODE']);
        parent::tearDown();
    }

    private function runNode(int $contactId, array $data): void
    {
        $graph = $this->makeGraph(
            [['id' => 't', 'type' => 'lead_created', 'data' => []], ['id' => 'a', 'type' => 'send_email', 'data' => $data]],
            [['id' => 'e1', 'source' => 't', 'target' => 'a', 'sourceHandle' => 'next']]
        );
        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'a');
        $run    = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        (new FlowEngine(new \App\Services\WhatsApp\CloudApiClient('mock_phone', 'mock_token')))->advance($run, 1);
    }

    private function template(): int
    {
        db_connect()->table('email_templates')->insert([
            'tenant_id' => 1, 'name' => 'Welcome', 'subject' => 'Welcome, {{contact.first_name}}',
            'html_body' => '<p>Hi {{contact.name}}</p>', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int) db_connect()->insertID();
    }

    public function testSendsTrackedTemplateEmail(): void
    {
        $cid = $this->seedContact('+911', ['name' => 'Asha Verma', 'email' => 'asha@example.com']);
        $this->runNode($cid, ['email_template_id' => $this->template()]);

        $row = db_connect()->table('emails')->where('contact_id', $cid)->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('sent', $row['status']);
        $this->assertSame('Welcome, Asha', $row['subject']);
        $this->assertNotEmpty($row['tracking_token']);
        $this->assertNull($row['email_campaign_id']);

        $run = db_connect()->table('flow_runs')->where('contact_id', $cid)->get()->getRowArray();
        $this->assertSame('completed', $run['status'], 'the run carries on past the email');
    }

    public function testSuppressedOrAddresslessContactIsSkippedNotFailed(): void
    {
        $gone = $this->seedContact('+912', ['email' => 'gone@example.com']);
        (new SuppressionService())->suppress(1, 'gone@example.com', 'unsubscribed');
        $none = $this->seedContact('+913');
        $tpl  = $this->template();

        $this->runNode($gone, ['email_template_id' => $tpl]);
        $this->runNode($none, ['email_template_id' => $tpl]);

        $this->assertSame(0, db_connect()->table('emails')->countAllResults());
        $this->assertSame(2, db_connect()->table('flow_runs')->where('status', 'completed')->countAllResults());
    }
}
