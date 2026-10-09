<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\CampaignModel;
use App\Services\Leads\CampaignRetargeter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CampaignTestSchema;

/**
 * Tests outcome-based recipient resolution and retarget campaign creation.
 */
class CampaignRetargeterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CampaignTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    private int $tplId;
    private int $campId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCampaignSchema();
        $this->tplId  = $this->seedApprovedTemplate();
        $this->campId = $this->seedCampaign($this->tplId, ['status' => 'done']);
    }

    /** Seed an outbound campaign message for a contact with a given status. */
    private function seedOutbound(int $contactId, string $status, string $sentAt = '2026-06-01 10:00:00'): void
    {
        db_connect()->table('messages')->insert([
            'tenant_id'       => 1,
            'contact_id'      => $contactId,
            'conversation_id' => 1,
            'campaign_id'     => $this->campId,
            'direction'       => 'out',
            'type'            => 'template',
            'status'          => $status,
            'sent_at'         => $status === 'failed' ? null : $sentAt,
            'created_at'      => $sentAt,
            'updated_at'      => $sentAt,
        ]);
    }

    private function seedInbound(int $contactId, string $at): void
    {
        db_connect()->table('messages')->insert([
            'tenant_id'       => 1,
            'contact_id'      => $contactId,
            'conversation_id' => 1,
            'direction'       => 'in',
            'type'            => 'text',
            'status'          => 'delivered',
            'created_at'      => $at,
            'updated_at'      => $at,
        ]);
    }

    public function testNotDeliveredSelectsQueuedSentFailed(): void
    {
        $c1 = $this->seedContact('+919000000001'); $this->seedOutbound($c1, 'sent');
        $c2 = $this->seedContact('+919000000002'); $this->seedOutbound($c2, 'failed');
        $c3 = $this->seedContact('+919000000003'); $this->seedOutbound($c3, 'delivered');
        $c4 = $this->seedContact('+919000000004'); $this->seedOutbound($c4, 'read');

        $ids = (new CampaignRetargeter())->resolveContactIds(1, $this->campId, 'not_delivered');
        sort($ids);
        $this->assertSame([$c1, $c2], $ids);
    }

    public function testNotReadExcludesOnlyRead(): void
    {
        $c1 = $this->seedContact('+919000000011'); $this->seedOutbound($c1, 'delivered');
        $c2 = $this->seedContact('+919000000012'); $this->seedOutbound($c2, 'read');
        $c3 = $this->seedContact('+919000000013'); $this->seedOutbound($c3, 'sent');

        $ids = (new CampaignRetargeter())->resolveContactIds(1, $this->campId, 'not_read');
        sort($ids);
        $this->assertSame([$c1, $c3], $ids);
    }

    public function testNoReplyExcludesContactsWhoRepliedAfterSend(): void
    {
        // Replied after the broadcast → excluded
        $replied = $this->seedContact('+919000000021');
        $this->seedOutbound($replied, 'delivered', '2026-06-01 10:00:00');
        $this->seedInbound($replied, '2026-06-01 11:00:00');

        // Replied BEFORE the broadcast only → still counts as no_reply
        $staleReply = $this->seedContact('+919000000022');
        $this->seedOutbound($staleReply, 'delivered', '2026-06-01 10:00:00');
        $this->seedInbound($staleReply, '2026-05-30 09:00:00');

        // Never replied → included
        $silent = $this->seedContact('+919000000023');
        $this->seedOutbound($silent, 'delivered', '2026-06-01 10:00:00');

        $ids = (new CampaignRetargeter())->resolveContactIds(1, $this->campId, 'no_reply');
        sort($ids);
        $this->assertSame([$staleReply, $silent], $ids);
    }

    public function testRejectsUnknownOutcome(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CampaignRetargeter())->resolveContactIds(1, $this->campId, 'bogus');
    }

    public function testCreateRetargetCampaignSnapshotsContactIds(): void
    {
        $newId = (new CampaignRetargeter())->createRetargetCampaign(1, $this->campId, [5, 6, 6, 7]);

        $camp = (new CampaignModel())->setTenant(1)->find($newId);
        $this->assertSame('draft', $camp['status']);
        $this->assertSame($this->tplId, (int) $camp['template_id']);
        $this->assertStringStartsWith('Retarget:', $camp['name']);

        $segment = json_decode($camp['segment'], true);
        $this->assertSame([5, 6, 7], $segment['contact_ids'], 'Duplicate ids must be de-duped');
    }
}
