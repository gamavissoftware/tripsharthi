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
 * A tap on a template quick-reply button must start a keyword_reply flow.
 *
 * This is the whole basis of button-driven campaigns: the tap arrives as an
 * inbound message whose body is the button's own label, so a flow keyed to that
 * label can answer it. It also opens the 24-hour window, which is what makes the
 * answer free-form and free.
 *
 * It silently did not work. WebhookService gated the trigger on $msgType —
 * Meta's RAW message type — while the switch above it normalises into a separate
 * $type. A template tap is type 'button' and an interactive tap is 'interactive';
 * neither is 'text', so keyword_reply never fired for any button. Nothing caught
 * it because the one shipped button flow used parked interactive runs instead.
 */
class InboundButtonTriggerTest extends CIUnitTestCase
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
        $this->seedKeywordFlow('Show me a demo');
    }

    /**
     * A quick-reply button on a TEMPLATE — how a campaign recipient replies.
     */
    public function testTemplateQuickReplyTapStartsTheFlow(): void
    {
        $this->service()->handle($this->templateButtonTap('Show me a demo', 'btn_demo'));

        $this->assertSame(1, $this->flowStartJobs(), 'a template button tap must start the keyword flow');
    }

    /**
     * The same label sent as an interactive button (what a free-form test send
     * produces) must behave identically, or testing proves nothing.
     */
    public function testInteractiveButtonTapStartsTheFlow(): void
    {
        $this->service()->handle($this->interactiveButtonTap('Show me a demo', 'btn_demo'));

        $this->assertSame(1, $this->flowStartJobs());
    }

    public function testTypedTextStillStartsTheFlow(): void
    {
        $this->service()->handle($this->text('Show me a demo'));

        $this->assertSame(1, $this->flowStartJobs());
    }

    public function testNonMatchingLabelDoesNotStartTheFlow(): void
    {
        $this->service()->handle($this->templateButtonTap('What does it cost?', 'btn_pricing'));

        $this->assertSame(0, $this->flowStartJobs(), 'exact match must not fire on a different button');
    }

    /**
     * Media carries a synthetic body ("[image message]"), not something the
     * contact chose to say — it must not be matched against keywords.
     */
    public function testImageMessageDoesNotStartAKeywordFlow(): void
    {
        $this->service()->handle($this->image());

        $this->assertSame(0, $this->flowStartJobs());
    }

    /**
     * WhatsApp's own block button sends "Stop promotions" as type 'button'.
     * It is the commonest opt-out there is, and the same faulty gate hid it.
     */
    public function testStopPromotionsButtonOptsTheContactOut(): void
    {
        $this->service()->handle($this->templateButtonTap('Stop promotions', 'stop'));

        $contact = (new ContactModel())->withoutTenantScope()
            ->where('wa_number', '+919718991797')->first();

        $this->assertSame(0, (int) $contact['opt_in']);
        $this->assertSame(0, $this->flowStartJobs(), 'never auto-reply to an opt-out');
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function flowStartJobs(): int
    {
        return db_connect()->table('jobs')->where('type', 'flow_start')->countAllResults();
    }

    private function seedKeywordFlow(string $keyword): int
    {
        return $this->seedFlow(
            ['nodes' => [['id' => 't1', 'type' => 'keyword_reply', 'data' => []]], 'edges' => []],
            'keyword_reply',
            [
                'name'           => 'Keyword flow',
                'trigger_config' => json_encode(['keywords' => [$keyword], 'match' => 'exact']),
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
            'messages' => [$message + [
                'from'      => '919718991797',
                'timestamp' => (string) time(),
            ]],
        ]]]]]];
    }

    /** Meta's shape for a tap on a template quick-reply button. */
    private function templateButtonTap(string $label, string $payload): array
    {
        return $this->envelope([
            'id'     => 'wamid.tpl.' . $payload . '.' . uniqid(),
            'type'   => 'button',
            'button' => ['text' => $label, 'payload' => $payload],
        ]);
    }

    /** Meta's shape for a tap on an interactive reply button. */
    private function interactiveButtonTap(string $label, string $id): array
    {
        return $this->envelope([
            'id'          => 'wamid.int.' . $id . '.' . uniqid(),
            'type'        => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => $label]],
        ]);
    }

    private function text(string $body): array
    {
        return $this->envelope([
            'id'   => 'wamid.txt.' . uniqid(),
            'type' => 'text',
            'text' => ['body' => $body],
        ]);
    }

    private function image(): array
    {
        return $this->envelope([
            'id'    => 'wamid.img.' . uniqid(),
            'type'  => 'image',
            'image' => ['id' => 'media123'],
        ]);
    }
}
