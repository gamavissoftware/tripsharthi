<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\MessageModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CampaignTestSchema;

/**
 * campaigns.stats records send-time bookkeeping (billable_sends, free_sends,
 * skipped_opt_out…) — never delivery outcomes. The campaign cards and the
 * analytics funnel used to read sent/delivered/read out of that JSON, so every
 * rate rendered as 0% while hundreds of messages sat delivered in the DB.
 * These counts must come from the message rows.
 */
class CampaignDeliveryCountsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CampaignTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCampaignSchema();
    }

    /** messages carries UNIQUE(campaign_id, contact_id) — one send per contact. */
    private int $nextContactId = 1;

    private function seedMessage(int $campaignId, string $status, string $direction = 'out'): void
    {
        db_connect()->table('messages')->insert([
            'tenant_id'   => 1,
            'contact_id'  => $this->nextContactId++,
            'conversation_id' => 1,
            'campaign_id' => $campaignId,
            'direction'   => $direction,
            'type'        => 'template',
            'category'    => 'marketing',
            'body'        => 'hi',
            'status'      => $status,
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function testDeliveredAndReadRollUpIntoSent(): void
    {
        $this->seedMessage(1, 'sent');
        $this->seedMessage(1, 'delivered');
        $this->seedMessage(1, 'read');
        $this->seedMessage(1, 'failed');

        $counts = (new MessageModel())->deliveryCountsByCampaign(1, [1])[1];

        // delivered and read both imply the message was sent
        $this->assertSame(3, $counts['sent']);
        // read implies delivered
        $this->assertSame(2, $counts['delivered']);
        $this->assertSame(1, $counts['read']);
        $this->assertSame(1, $counts['failed']);
    }

    public function testFailedMessagesAreNotCountedAsSent(): void
    {
        $this->seedMessage(2, 'failed');
        $this->seedMessage(2, 'failed');

        $counts = (new MessageModel())->deliveryCountsByCampaign(1, [2])[2];

        $this->assertSame(0, $counts['sent']);
        $this->assertSame(2, $counts['failed']);
    }

    public function testInboundMessagesAreExcluded(): void
    {
        $this->seedMessage(3, 'delivered', 'in');
        $this->seedMessage(3, 'delivered', 'out');

        $counts = (new MessageModel())->deliveryCountsByCampaign(1, [3])[3];

        $this->assertSame(1, $counts['sent'], 'inbound replies are not campaign sends');
    }

    public function testCountsAreKeyedPerCampaign(): void
    {
        $this->seedMessage(1, 'read');
        $this->seedMessage(2, 'sent');
        $this->seedMessage(2, 'sent');

        $counts = (new MessageModel())->deliveryCountsByCampaign(1, [1, 2]);

        $this->assertSame(1, $counts[1]['sent']);
        $this->assertSame(2, $counts[2]['sent']);
    }

    public function testAnotherTenantsMessagesAreNotCounted(): void
    {
        $this->seedMessage(1, 'read');
        db_connect()->table('messages')->insert([
            'tenant_id'   => 999,
            'contact_id'  => $this->nextContactId++,
            'conversation_id' => 1,
            'campaign_id' => 1,
            'direction'   => 'out',
            'type'        => 'template',
            'category'    => 'marketing',
            'body'        => 'other tenant',
            'status'      => 'read',
            'created_at'  => date('Y-m-d H:i:s'),
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $counts = (new MessageModel())->deliveryCountsByCampaign(1, [1])[1];

        $this->assertSame(1, $counts['sent']);
    }

    public function testCampaignWithNoMessagesIsAbsentSoCallersCanFallBack(): void
    {
        // Campaigns sent before messages.campaign_id existed have no rows; the
        // caller falls back to the campaign's own progress columns, which it
        // can only do if the key is missing rather than zero-filled.
        $counts = (new MessageModel())->deliveryCountsByCampaign(1, [42]);

        $this->assertArrayNotHasKey(42, $counts);
    }

    public function testEmptyIdListShortCircuits(): void
    {
        $this->assertSame([], (new MessageModel())->deliveryCountsByCampaign(1, []));
    }
}
