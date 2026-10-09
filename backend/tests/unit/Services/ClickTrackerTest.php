<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ClickEventModel;
use App\Services\WhatsApp\ClickTracker;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CampaignTestSchema;

/**
 * Tests click attribution + idempotency.
 */
class ClickTrackerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CampaignTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCampaignSchema();
        $this->createClickEventsTable();
    }

    private function createClickEventsTable(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix;
        $db->query("CREATE TABLE IF NOT EXISTS {$p}click_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL,
            campaign_id INTEGER, message_id INTEGER, contact_id INTEGER, conversation_id INTEGER,
            button_id TEXT, button_title TEXT, source TEXT DEFAULT 'quick_reply',
            inbound_wa_id TEXT, clicked_at TEXT, created_at TEXT
        )");
        $db->query("DELETE FROM {$p}click_events");
    }

    /** Seed an outbound campaign message with a known wa_message_id. */
    private function seedOutbound(string $waMessageId, int $campaignId): int
    {
        db_connect()->table('messages')->insert([
            'tenant_id'       => 1,
            'contact_id'      => 7,
            'conversation_id' => 1,
            'campaign_id'     => $campaignId,
            'direction'       => 'out',
            'type'            => 'template',
            'status'          => 'delivered',
            'wa_message_id'   => $waMessageId,
            'created_at'      => '2026-06-01 10:00:00',
            'updated_at'      => '2026-06-01 10:00:00',
        ]);
        return (int) db_connect()->insertID();
    }

    public function testClickAttributedToCampaignViaContext(): void
    {
        $tplId  = $this->seedApprovedTemplate();
        $campId = $this->seedCampaign($tplId);
        $msgId  = $this->seedOutbound('wamid.OUT1', $campId);

        $id = (new ClickTracker())->record([
            'tenant_id'     => 1,
            'contact_id'    => 7,
            'context_wa_id' => 'wamid.OUT1',
            'button_id'     => 'shop_now',
            'button_title'  => 'Shop Now',
            'source'        => 'quick_reply',
            'inbound_wa_id' => 'wamid.IN1',
        ]);

        $this->assertNotNull($id);
        $row = (new ClickEventModel())->setTenant(1)->find($id);
        $this->assertSame($campId, (int) $row['campaign_id']);
        $this->assertSame($msgId, (int) $row['message_id']);
        $this->assertSame('Shop Now', $row['button_title']);
    }

    public function testClickWithoutContextHasNullCampaign(): void
    {
        $id = (new ClickTracker())->record([
            'tenant_id'     => 1,
            'contact_id'    => 7,
            'context_wa_id' => null,
            'button_id'     => 'help',
            'button_title'  => 'Help',
            'inbound_wa_id' => 'wamid.IN2',
        ]);

        $row = (new ClickEventModel())->setTenant(1)->find($id);
        $this->assertNull($row['campaign_id']);
        $this->assertNull($row['message_id']);
    }

    public function testReplayedInboundIsNotDoubleCounted(): void
    {
        $tracker = new ClickTracker();
        $args = [
            'tenant_id'     => 1,
            'context_wa_id' => null,
            'button_id'     => 'x',
            'inbound_wa_id' => 'wamid.DUP',
        ];

        $first  = $tracker->record($args);
        $second = $tracker->record($args);

        $this->assertNotNull($first);
        $this->assertNull($second, 'A replayed webhook must not create a second click');
        $this->assertSame(1, (new ClickEventModel())->setTenant(1)->countAllResults());
    }
}
