<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ConversationModel;
use App\Services\Flow\FlowEngine;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\WindowService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\FlowTestSchema;

/**
 * Token substitution in free-form copy.
 *
 * This runs on the hot path every live drip already uses, so the tests that
 * matter most are the ones asserting what does NOT change: copy with no tokens,
 * and copy with tokens the engine has never heard of, must come out byte for
 * byte as the author typed it.
 */
class FlowFreeformTokensTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FlowTestSchema;

    protected $migrate = false;
    protected $refresh = true;

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

    /**
     * Run a one-node free-form flow with an OPEN window and return the body
     * that was actually handed to WhatsApp.
     */
    private function sentBody(string $content, array $state = [], array $contact = []): string
    {
        static $seq = 0;
        $wa = '+9199910200' . str_pad((string) ++$seq, 2, '0', STR_PAD_LEFT);

        $contactId = $this->seedContact($wa, $contact);
        db_connect()->table('conversations')->insert([
            'tenant_id'         => 1,
            'wa_number'         => $wa,
            'window_expires_at' => date('Y-m-d H:i:s', $this->now + 86400),
            'unread_count'      => 0,
            'status'            => 'open',
            'created_at'        => date('Y-m-d H:i:s', $this->now),
            'updated_at'        => date('Y-m-d H:i:s', $this->now),
        ]);

        $graph = $this->makeGraph(
            [
                ['id' => 't', 'type' => 'lead_created',  'data' => []],
                ['id' => 'f', 'type' => 'send_freeform', 'data' => ['content' => $content]],
            ],
            [['id' => 'e1', 'source' => 't', 'target' => 'f', 'sourceHandle' => 'next']]
        );

        $flowId = $this->seedFlow($graph);
        $runId  = $this->seedRun($flowId, $contactId, $graph, 'f', ['state' => json_encode($state)]);

        $run = db_connect()->table('flow_runs')->where('id', $runId)->get()->getRowArray();
        (new FlowEngine(
            new CloudApiClient('mock_phone', 'mock_token'),
            new WindowService(new ConversationModel(), $this->now),
            $this->now
        ))->advance($run, 1);

        $msg = db_connect()->table('messages')
            ->where('contact_id', $contactId)->where('direction', 'out')
            ->get()->getRowArray();

        return (string) ($msg['body'] ?? '');
    }

    // ------------------------------------------------------------------
    // What must not change
    // ------------------------------------------------------------------

    public function testCopyWithNoTokensIsUntouched(): void
    {
        $copy = "One more thing while it's fresh.\n\nWhich part of the day costs your team the most time?";

        $this->assertSame($copy, $this->sentBody($copy));
    }

    /** An unknown token is left visible rather than silently blanked. */
    public function testAnUnknownTokenSurvivesVerbatim(): void
    {
        $this->assertSame('Hi {{order.id}} there', $this->sentBody('Hi {{order.id}} there'));
    }

    /** A known token with nothing behind it stays put — a blank reads as broken. */
    public function testAKnownTokenWithNoValueIsLeftInPlace(): void
    {
        $this->assertSame(
            'Your demo is at {{meeting.time}}.',
            $this->sentBody('Your demo is at {{meeting.time}}.', [])
        );
    }

    // ------------------------------------------------------------------
    // What it resolves
    // ------------------------------------------------------------------

    public function testMeetingTokensComeFromRunState(): void
    {
        $body = $this->sentBody(
            'Your demo is at {{meeting.time}}, about {{meeting.minutes}} minutes from now.',
            ['meeting_time' => 'Thu 17 Sep, 10:30 AM', 'meeting_minutes' => '30']
        );

        $this->assertSame('Your demo is at Thu 17 Sep, 10:30 AM, about 30 minutes from now.', $body);
    }

    public function testTheContactNameResolves(): void
    {
        $this->assertSame('Hi Rajesh —', $this->sentBody('Hi {{contact.name}} —', [], ['name' => 'Rajesh']));
    }

    /** Matches VariableResolver: a nameless contact reads "Hi there", not "Hi {{contact.name}}". */
    public function testANamelessContactFallsBackToThere(): void
    {
        $this->assertSame('Hi there —', $this->sentBody('Hi {{contact.name}} —', [], ['name' => '']));
    }

    public function testEveryOccurrenceIsReplacedNotJustTheFirst(): void
    {
        $body = $this->sentBody(
            '{{meeting.time}} — see you at {{meeting.time}}.',
            ['meeting_time' => '10:30 AM']
        );

        $this->assertSame('10:30 AM — see you at 10:30 AM.', $body);
    }

    /**
     * The body stored on the message row is what the customer received, not the
     * raw template — otherwise the inbox and the phone disagree.
     */
    public function testTheLoggedBodyIsTheRenderedOne(): void
    {
        $body = $this->sentBody('At {{meeting.time}}.', ['meeting_time' => '9:00 AM']);

        $this->assertStringNotContainsString('{{', $body);
        $this->assertSame('At 9:00 AM.', $body);
    }
}
