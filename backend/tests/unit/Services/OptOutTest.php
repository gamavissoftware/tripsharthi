<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\PhoneNumberModel;
use App\Services\Leads\OptOutDetector;
use App\Services\WhatsApp\WebhookService;
use App\Services\WhatsApp\WindowService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\FlowTestSchema;

/**
 * Marketing template footers promise "Reply STOP to opt out". Before this,
 * nothing in the app acted on STOP: opt_in was honoured when sending but never
 * set to 0 by an inbound message. A promise the product does not keep sends
 * people to the Report button instead, and reports are what get a WhatsApp
 * sending number restricted.
 *
 * The matching is deliberately EXACT — a manufacturing lead writing "we need to
 * stop using Tally" is a hot lead, not an unsubscribe.
 */
class OptOutTest extends CIUnitTestCase
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

    // ── matching ──────────────────────────────────────────────────────

    /**
     * @dataProvider optOutPhrases
     */
    public function testRecognisesOptOut(string $message): void
    {
        $this->assertTrue(
            (new OptOutDetector())->isOptOut($message),
            "'{$message}' should be treated as an opt-out"
        );
    }

    public static function optOutPhrases(): array
    {
        return [
            'bare'            => ['STOP'],
            'lowercase'       => ['stop'],
            'padded'          => ['  Stop  '],
            'full stop'       => ['Stop.'],
            'exclaimed'       => ['STOP!'],
            'whatsapp button' => ['Stop promotions'],
            'unsubscribe'     => ['Unsubscribe'],
            'two words'       => ['opt out'],
            'hyphenated'      => ['opt-out'],
            'remove me'       => ['Remove me'],
            'hinglish'        => ['band karo'],
        ];
    }

    /**
     * The expensive mistake is the false positive: silently unsubscribing a
     * live prospect, who then never hears from sales again.
     *
     * @dataProvider keepPhrases
     */
    public function testDoesNotUnsubscribeRealConversation(string $message): void
    {
        $this->assertFalse(
            (new OptOutDetector())->isOptOut($message),
            "'{$message}' is a genuine reply and must NOT opt the contact out"
        );
    }

    public static function keepPhrases(): array
    {
        return [
            'stop as a verb'   => ['We need to stop using Tally'],
            'stop by'          => ['stop by our factory next week'],
            'non-stop'         => ['our line runs non-stop'],
            'stoppage'         => ['we get stoppage on the packing line'],
            'question'         => ['Can you stop the machine remotely?'],
            'empty'            => [''],
            'interest'         => ['Show me a demo'],
        ];
    }

    // ── wiring ────────────────────────────────────────────────────────

    public function testInboundStopSetsOptInToZeroAndFiresNoFlow(): void
    {
        $contactId = $this->seedContact('+919718991797');

        $this->service()->handle($this->inbound('+919718991797', 'STOP', 'wamid.stop1'));

        $contact = (new ContactModel())->withoutTenantScope()->find($contactId);
        $this->assertSame(0, (int) $contact['opt_in'], 'STOP must clear opt_in');

        $this->assertSame(
            0,
            db_connect()->table('jobs')->where('type', 'flow_start')->countAllResults(),
            'replying to a STOP with automation is worse than ignoring it'
        );
    }

    /**
     * The message itself is still recorded — the team needs to see why the
     * contact went quiet.
     */
    public function testStopMessageIsStillStored(): void
    {
        $this->seedContact('+919718991797');

        $this->service()->handle($this->inbound('+919718991797', 'STOP', 'wamid.stop2'));

        $this->assertSame(
            1,
            db_connect()->table('messages')->where('direction', 'in')->countAllResults()
        );
    }

    public function testOrdinaryReplyLeavesOptInAlone(): void
    {
        $contactId = $this->seedContact('+919718991797');

        $this->service()->handle($this->inbound('+919718991797', 'Show me a demo', 'wamid.demo1'));

        $contact = (new ContactModel())->withoutTenantScope()->find($contactId);
        $this->assertSame(1, (int) $contact['opt_in']);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function service(): WebhookService
    {
        return new WebhookService(
            new ConversationModel(),
            new MessageModel(),
            new ContactModel(),
            new PhoneNumberModel(),
            new WindowService(new ConversationModel()),
        );
    }

    /**
     * A Meta inbound-text webhook payload, trimmed to the fields the service reads.
     */
    private function inbound(string $from, string $text, string $waMessageId): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'mock_phone_id'],
                        'contacts' => [['wa_id' => ltrim($from, '+'), 'profile' => ['name' => 'Test Contact']]],
                        'messages' => [[
                            'id'        => $waMessageId,
                            'from'      => ltrim($from, '+'),
                            'timestamp' => (string) time(),
                            'type'      => 'text',
                            'text'      => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }
}
