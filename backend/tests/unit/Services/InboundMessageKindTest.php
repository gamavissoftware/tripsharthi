<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\PhoneNumberModel;
use App\Services\WhatsApp\WebhookService;
use App\Services\WhatsApp\WindowService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\FlowTestSchema;

/**
 * An auto-acknowledgement has to answer what a lead WRITES without also firing
 * on a button they TAP — a tap is already being answered by a keyword_reply
 * flow, so firing on both sends two messages at once.
 *
 * inbound_message therefore takes config.message_kind. It reads an explicit
 * is_button flag from the webhook rather than re-deriving intent from Meta's raw
 * message type; deriving it twice is what made keyword_reply ignore every button
 * tap for the entire life of the feature.
 */
class InboundMessageKindTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FlowTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlowSchema();
        $this->seedContact('+919718991797');
    }

    public function testTypedOnlyFlowIgnoresAButtonTap(): void
    {
        $this->seedInboundFlow(['message_kind' => 'typed']);

        $this->service()->handle($this->templateButtonTap('Show me a demo'));

        $this->assertSame(0, $this->flowStartJobs(), 'a tap is already answered by the keyword flow');
    }

    public function testTypedOnlyFlowFiresOnAWrittenReply(): void
    {
        $this->seedInboundFlow(['message_kind' => 'typed']);

        $this->service()->handle($this->text('Machine manufacturing, 100 people'));

        $this->assertSame(1, $this->flowStartJobs());
    }

    public function testButtonOnlyFlowFiresOnlyOnTaps(): void
    {
        $this->seedInboundFlow(['message_kind' => 'button']);

        $this->service()->handle($this->text('hello there'));
        $this->assertSame(0, $this->flowStartJobs());

        $this->service()->handle($this->templateButtonTap('Show me a demo'));
        $this->assertSame(1, $this->flowStartJobs());
    }

    /**
     * Flows built before message_kind existed carry no config and must keep
     * firing on everything.
     */
    public function testFlowWithNoConfigStillFiresOnBoth(): void
    {
        $this->seedInboundFlow(null);

        $this->service()->handle($this->text('hello'));
        $this->service()->handle($this->templateButtonTap('Show me a demo'));

        $this->assertSame(2, $this->flowStartJobs());
    }

    public function testUnknownMessageKindFailsOpenRatherThanSilent(): void
    {
        $this->seedInboundFlow(['message_kind' => 'nonsense']);

        $this->service()->handle($this->text('hello'));

        $this->assertSame(1, $this->flowStartJobs(), 'a typo in config must not silently disable a flow');
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function flowStartJobs(): int
    {
        return db_connect()->table('jobs')->where('type', 'flow_start')->countAllResults();
    }

    private function seedInboundFlow(?array $config): int
    {
        return $this->seedFlow(
            ['nodes' => [['id' => 't1', 'type' => 'inbound_message', 'data' => []]], 'edges' => []],
            'inbound_message',
            [
                'name'           => 'Acknowledge reply',
                'trigger_config' => $config === null ? null : json_encode($config),
                'reentry_policy' => 'always',
            ]
        );
    }

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

    private function envelope(array $message): array
    {
        return ['entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => 'mock_phone_id'],
            'contacts' => [['wa_id' => '919718991797', 'profile' => ['name' => 'Manglesh']]],
            'messages' => [$message + ['from' => '919718991797', 'timestamp' => (string) time()]],
        ]]]]]];
    }

    private function templateButtonTap(string $label): array
    {
        return $this->envelope([
            'id'     => 'wamid.tpl.' . uniqid('', true),
            'type'   => 'button',
            'button' => ['text' => $label, 'payload' => 'p'],
        ]);
    }

    private function text(string $body): array
    {
        return $this->envelope([
            'id'   => 'wamid.txt.' . uniqid('', true),
            'type' => 'text',
            'text' => ['body' => $body],
        ]);
    }
}
