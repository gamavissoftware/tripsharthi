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
 * Meta accepts a template send, marks it billable, and only later reports that
 * it was never delivered — #131049 frequency capping, #131026 undeliverable.
 *
 * A real 9-recipient campaign came back 4 delivered / 4 failed while the
 * campaign still reported billable_sends: 9. Meta does not charge for a message
 * it refused to deliver, so the operator was reading a cost that was never
 * incurred — and at 185 recipients that error compounds.
 */
class FailedSendBillingTest extends CIUnitTestCase
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

    /**
     * @dataProvider failureCodes
     */
    public function testAFailedDeliveryIsNoLongerBilled(int $code, string $title): void
    {
        $id = $this->seedOutbound('wamid.bill.' . $code);

        $this->service()->handle($this->statusPayload('wamid.bill.' . $code, 'failed', $code, $title));

        $msg = (new MessageModel())->withoutTenantScope()->find($id);

        $this->assertSame('failed', $msg['status']);
        $this->assertSame(0, (int) $msg['billable'], 'Meta does not charge for an undelivered message');
        $this->assertStringContainsString("#{$code}", (string) $msg['error'], 'the code is what the operator acts on');
    }

    public static function failureCodes(): array
    {
        return [
            'frequency cap'  => [131049, 'This message was not delivered to maintain healthy ecosystem engagement.'],
            'undeliverable'  => [131026, 'Message undeliverable'],
            're-engagement'  => [131047, 'Re-engagement message'],
        ];
    }

    /**
     * A delivery that succeeds must stay billable — the fix must not swing the
     * cost report the other way.
     */
    public function testADeliveredMessageStaysBillable(): void
    {
        $id = $this->seedOutbound('wamid.ok');

        $this->service()->handle($this->statusPayload('wamid.ok', 'delivered'));

        $msg = (new MessageModel())->withoutTenantScope()->find($id);

        $this->assertSame('delivered', $msg['status']);
        $this->assertSame(1, (int) $msg['billable']);
    }

    // ── harness ───────────────────────────────────────────────────────

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
            'status' => 'sent', 'billable' => 1, 'sent_at' => date('Y-m-d H:i:s'),
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

    private function statusPayload(string $waId, string $status, ?int $code = null, string $title = ''): array
    {
        $entry = ['id' => $waId, 'status' => $status, 'timestamp' => (string) time(),
                  'recipient_id' => '919818899606'];

        if ($code !== null) {
            $entry['errors'] = [[
                'code' => $code, 'title' => $title,
                'error_data' => ['details' => 'Meta declined to deliver this message.'],
            ]];
        }

        return ['entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => 'mock_phone_id'],
            'statuses' => [$entry],
        ]]]]]];
    }
}
