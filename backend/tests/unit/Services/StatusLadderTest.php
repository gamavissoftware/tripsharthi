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
 * queued → sent → delivered → read is a ladder, and Meta's status callbacks are
 * not ordered: a retried `delivered` can arrive after `read`.
 *
 * Applied blindly, that retry silently un-reads a message — and "did they read
 * it" is the single question the whole delivery report exists to answer. One
 * late webhook would quietly deflate a campaign's read rate with no trace of
 * why.
 */
class StatusLadderTest extends CIUnitTestCase
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

    public function testALateDeliveredCallbackCannotUnreadAMessage(): void
    {
        $id = $this->seedOutbound('wamid.ladder.1');

        $this->service()->handle($this->statusPayload('wamid.ladder.1', 'read', 1789000000));
        $this->service()->handle($this->statusPayload('wamid.ladder.1', 'delivered', 1789000300));

        $msg = $this->find($id);

        $this->assertSame('read', $msg['status'], 'the message was read; a retry does not undo that');
        // The delivery timestamp is still true, so it is worth keeping — only
        // the backwards status is dropped.
        $this->assertNotNull($msg['delivered_at']);
    }

    public function testAStaleFailureCannotEraseADelivery(): void
    {
        $id = $this->seedOutbound('wamid.ladder.2');

        $this->service()->handle($this->statusPayload('wamid.ladder.2', 'delivered', 1789000000));
        $this->service()->handle($this->statusFailed('wamid.ladder.2', 131049));

        $msg = $this->find($id);

        $this->assertSame('delivered', $msg['status']);
        $this->assertNull($msg['error'], 'a landed message has no failure reason');
        $this->assertSame(1, (int) $msg['billable'], 'and it is still billed by Meta');
    }

    public function testTheLadderStillClimbsInOrder(): void
    {
        $id = $this->seedOutbound('wamid.ladder.3');
        $svc = $this->service();

        $svc->handle($this->statusPayload('wamid.ladder.3', 'sent', 1789000000));
        $this->assertSame('sent', $this->find($id)['status']);

        $svc->handle($this->statusPayload('wamid.ladder.3', 'delivered', 1789000060));
        $this->assertSame('delivered', $this->find($id)['status']);

        $svc->handle($this->statusPayload('wamid.ladder.3', 'read', 1789000120));
        $msg = $this->find($id);

        $this->assertSame('read', $msg['status']);
        $this->assertNotNull($msg['sent_at']);
        $this->assertNotNull($msg['delivered_at']);
        $this->assertNotNull($msg['read_at']);
    }

    public function testAGenuineFailureBeforeDeliveryStillApplies(): void
    {
        $id = $this->seedOutbound('wamid.ladder.4');

        $this->service()->handle($this->statusPayload('wamid.ladder.4', 'sent', 1789000000));
        $this->service()->handle($this->statusFailed('wamid.ladder.4', 131047));

        $msg = $this->find($id);

        $this->assertSame('failed', $msg['status']);
        $this->assertStringContainsString('#131047', (string) $msg['error']);
        $this->assertSame(0, (int) $msg['billable']);
    }

    // ── harness ───────────────────────────────────────────────────────

    private function find(int $id): array
    {
        return (new MessageModel())->withoutTenantScope()->find($id);
    }

    private function seedOutbound(string $waMessageId): int
    {
        $contactId = $this->seedContact('+919818899606');

        db_connect()->table('conversations')->insert([
            'tenant_id' => 1, 'contact_id' => $contactId, 'wa_number' => '+919818899606',
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $convId = (int) db_connect()->insertID();

        (new MessageModel())->withoutTenantScope()->insert([
            'tenant_id' => 1, 'contact_id' => $contactId, 'conversation_id' => $convId,
            'direction' => 'out', 'type' => 'template', 'category' => 'marketing',
            'body' => 'campaign body', 'wa_message_id' => $waMessageId,
            'status' => 'queued', 'billable' => 1,
        ]);

        return (int) db_connect()->insertID();
    }

    private function service(): WebhookService
    {
        return new WebhookService(
            new ConversationModel(), new MessageModel(), new ContactModel(),
            new PhoneNumberModel(), new WindowService(new ConversationModel()),
        );
    }

    private function statusPayload(string $waId, string $status, int $ts): array
    {
        return $this->payload(['id' => $waId, 'status' => $status, 'timestamp' => (string) $ts,
                               'recipient_id' => '919818899606']);
    }

    private function statusFailed(string $waId, int $code): array
    {
        return $this->payload([
            'id' => $waId, 'status' => 'failed', 'timestamp' => (string) time(),
            'recipient_id' => '919818899606',
            'errors' => [['code' => $code, 'title' => 'Message undeliverable',
                          'error_data' => ['details' => 'Meta declined to deliver this message.']]],
        ]);
    }

    private function payload(array $entry): array
    {
        return ['entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => 'mock_phone_id'],
            'statuses' => [$entry],
        ]]]]]];
    }
}
