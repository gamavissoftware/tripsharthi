<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Analytics\CostEstimator;
use App\Services\Analytics\MessageFilters;
use App\Services\Analytics\MessageReportQuery;
use App\Services\Analytics\MessageStats;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CampaignTestSchema;

/**
 * The delivery report: who we messaged, whether it landed, whether they read it.
 *
 * The scenario below is deliberately the awkward one a real broadcast produces
 * — one read, one delivered-but-unread, one refused by Meta, plus a flow send
 * that has nothing to do with the campaign and a late reply that must NOT be
 * attributed to it. Every number an operator reads off this screen is a claim
 * about someone's phone, so each one is pinned here.
 */
class DeliveryReportTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CampaignTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    private const TENANT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCampaignSchema();
        $this->createClickEvents();
        $this->seedScenario();
    }

    // ── The log ───────────────────────────────────────────────────────

    public function testTheLogNamesEveryRecipientOfABroadcast(): void
    {
        $page = (new MessageReportQuery())->page(self::TENANT, $this->campaignFilters());

        $this->assertSame(3, $page['total']);
        $this->assertSame(
            ['Chetan Rao', 'Bhanu Iyer', 'Asha Verma'],
            array_column($page['rows'], 'contact_name'),
            'newest first — the most recent send is what an operator checks'
        );

        $asha = $this->rowFor($page['rows'], 'Asha Verma');
        $this->assertSame('+919000000001', $asha['wa_number']);
        $this->assertSame('Diwali Offer', $asha['campaign_name']);
        $this->assertSame('diwali_2026', $asha['template_name']);
        $this->assertSame('read', $asha['status']);
        $this->assertSame('2026-09-10 10:42:00', $asha['read_at']);
    }

    public function testAFailedSendCarriesTheReasonTheOperatorMustActOn(): void
    {
        $filters = MessageFilters::normalize(['campaign_id' => '1', 'status' => 'failed']);
        $rows    = (new MessageReportQuery())->page(self::TENANT, $filters)['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('Chetan Rao', $rows[0]['contact_name']);
        $this->assertStringContainsString('#131049', (string) $rows[0]['error']);
        $this->assertNull($rows[0]['read_at']);
    }

    public function testRepliesAreAttributedOnlyInsideTheTwentyFourHourWindow(): void
    {
        $rows = (new MessageReportQuery())->page(self::TENANT, $this->campaignFilters())['rows'];

        // Asha answered two hours later; Bhanu answered two days later, which is
        // a different conversation entirely. Without the horizon, an old
        // campaign keeps collecting "replies" forever.
        $this->assertSame('2026-09-10 12:00:00', $this->rowFor($rows, 'Asha Verma')['replied_at']);
        $this->assertNull($this->rowFor($rows, 'Bhanu Iyer')['replied_at']);
    }

    public function testAMessageThatNeverArrivedCannotHaveBeenRepliedTo(): void
    {
        $rows = (new MessageReportQuery())->page(self::TENANT, $this->campaignFilters())['rows'];

        // Chetan did write in an hour later — but to a send WhatsApp refused to
        // deliver. Badging that row "Replied" tells the operator a message
        // landed when it never left.
        $this->assertNull($this->rowFor($rows, 'Chetan Rao')['replied_at']);
        $this->assertSame(1, (new MessageStats())->funnel(self::TENANT, $this->campaignFilters())['replied']);
    }

    public function testFilteringToRepliedKeepsThePagerHonest(): void
    {
        $query = new MessageReportQuery();

        $yes = $query->page(self::TENANT, MessageFilters::normalize(['campaign_id' => '1', 'replied' => 'yes']));
        $no  = $query->page(self::TENANT, MessageFilters::normalize(['campaign_id' => '1', 'replied' => 'no']));

        $this->assertSame(1, $yes['total']);
        $this->assertSame('Asha Verma', $yes['rows'][0]['contact_name']);
        // The filter is applied in SQL, so the count matches the rows — a
        // post-filter would have reported 3 here and shown 1.
        $this->assertSame(2, $no['total']);
        $this->assertCount(2, $no['rows']);
    }

    public function testSearchFindsAContactByNumber(): void
    {
        $filters = MessageFilters::normalize(['q' => '919000000002']);
        $rows    = (new MessageReportQuery())->page(self::TENANT, $filters)['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame('Bhanu Iyer', $rows[0]['contact_name']);
    }

    public function testFlowSendsAreVisibleButSeparableFromBroadcasts(): void
    {
        $query = new MessageReportQuery();

        $all      = $query->count(self::TENANT, MessageFilters::normalize([]));
        $nonCampaign = $query->page(self::TENANT, MessageFilters::normalize(['campaign_id' => 'none']));

        $this->assertSame(4, $all, 'three campaign sends plus one flow send');
        $this->assertSame(1, $nonCampaign['total']);
        $this->assertNull($nonCampaign['rows'][0]['campaign_id']);
    }

    public function testAPageBeyondTheEndShowsTheLastPageNotAnEmptyTable(): void
    {
        $page = (new MessageReportQuery())->page(self::TENANT, $this->campaignFilters(), 9, 2);

        $this->assertSame(2, $page['pages']);
        $this->assertSame(2, $page['page']);
        $this->assertCount(1, $page['rows']);
    }

    public function testADayFilterIsReadInTheOperatorsTimezone(): void
    {
        // The flow send is 2026-09-10 19:30 UTC — which is past midnight in IST,
        // so to an operator in Delhi it happened on the 11th.
        $query = new MessageReportQuery();

        $ist = $query->count(self::TENANT, MessageFilters::normalize([
            'from' => '2026-09-11', 'to' => '2026-09-11', 'tz_offset' => 330,
        ]));
        $utc = $query->count(self::TENANT, MessageFilters::normalize([
            'from' => '2026-09-11', 'to' => '2026-09-11',
        ]));

        $this->assertSame(1, $ist);
        $this->assertSame(0, $utc);
    }

    // ── The aggregates ────────────────────────────────────────────────

    public function testTheFunnelRollsTheStatusLadderUp(): void
    {
        $funnel = (new MessageStats())->funnel(self::TENANT, $this->campaignFilters());

        // WhatsApp reports only the furthest rung a message reached, so a read
        // message was also delivered and also sent. Counting the raw status
        // column as exclusive buckets is what turns a 100% delivery rate into 50%.
        $this->assertSame(3, $funnel['total']);
        $this->assertSame(2, $funnel['sent']);
        $this->assertSame(2, $funnel['delivered']);
        $this->assertSame(1, $funnel['read']);
        $this->assertSame(1, $funnel['failed']);
        $this->assertSame(1, $funnel['replied']);
        $this->assertSame(3, $funnel['recipients']);

        $this->assertSame(100, $funnel['delivery_rate'], 'both messages that left us arrived');
        $this->assertSame(50, $funnel['read_rate']);
        $this->assertSame(33, $funnel['failure_rate'], 'one of three recipients was refused');
    }

    public function testClicksAreCountedAgainstTheMessagesInTheSlice(): void
    {
        $funnel = (new MessageStats())->funnel(self::TENANT, $this->campaignFilters());

        $this->assertSame(2, $funnel['clicks']);
        $this->assertSame(1, $funnel['unique_clickers'], 'Asha tapped twice; that is one interested person');
    }

    public function testTheTrendHasNoFalseGaps(): void
    {
        $series = (new MessageStats())->timeseries(self::TENANT, MessageFilters::normalize([
            'from' => '2026-09-09', 'to' => '2026-09-12',
        ]));

        // A day with no sends is a real zero. Dropping it redraws a quiet week
        // as a straight line between two busy days.
        $this->assertSame(['2026-09-09', '2026-09-10', '2026-09-11', '2026-09-12'], array_column($series, 'date'));

        // The 10th UTC holds the whole broadcast plus the evening flow send.
        $tenth = $series[1];
        $this->assertSame(3, $tenth['sent'], 'two broadcast sends that landed, plus the flow send');
        $this->assertSame(2, $tenth['delivered']);
        $this->assertSame(1, $tenth['read']);
        $this->assertSame(1, $tenth['failed']);
        $this->assertSame(1, $tenth['replied']);
        $this->assertSame(0, $series[0]['sent'], 'a quiet day is a real zero, not a missing point');
        $this->assertSame(0, $series[2]['sent']);
    }

    public function testSpendIsEstimatedFromBillableMessagesOnly(): void
    {
        $rows = (new MessageStats())->categoryBreakdown(self::TENANT, $this->campaignFilters());

        $marketing = $rows[0];
        $this->assertSame('marketing', $marketing['category']);
        $this->assertSame(3, $marketing['messages']);
        // The refused send is not billable — Meta does not charge for a message
        // it declined to deliver, and billing it overstates the customer's cost.
        $this->assertSame(2, $marketing['billable']);
        $this->assertSame(2 * CostEstimator::ratePaise('marketing'), $marketing['est_cost_paise']);
    }

    public function testFailuresAreGroupedByTheCodeThatExplainsThem(): void
    {
        $failures = (new MessageStats())->failureBreakdown(self::TENANT, $this->campaignFilters());

        $this->assertCount(1, $failures);
        $this->assertSame('131049', $failures[0]['code']);
        $this->assertSame(1, $failures[0]['count']);
    }

    public function testTemplatePerformanceCoversBroadcastsOnly(): void
    {
        $rows = (new MessageStats())->templatePerformance(self::TENANT, MessageFilters::normalize([]));

        $this->assertCount(1, $rows, 'the flow send has no template to attribute');
        $this->assertSame('diwali_2026', $rows[0]['template_name']);
        $this->assertSame(2, $rows[0]['sent']);
        $this->assertSame(50, $rows[0]['read_rate']);
    }

    public function testATenantNeverSeesAnotherTenantsSends(): void
    {
        $this->assertSame(0, (new MessageReportQuery())->count(99, MessageFilters::normalize([])));
        $this->assertSame(0, (new MessageStats())->funnel(99, MessageFilters::normalize([]))['total']);
    }

    // ── harness ───────────────────────────────────────────────────────

    /** @param list<array<string,mixed>> $rows */
    private function rowFor(array $rows, string $name): array
    {
        foreach ($rows as $row) {
            if ($row['contact_name'] === $name) {
                return $row;
            }
        }

        $this->fail("No log row for {$name}");
    }

    private function campaignFilters(): array
    {
        return MessageFilters::normalize(['campaign_id' => '1']);
    }

    private function createClickEvents(): void
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

    private function seedScenario(): void
    {
        $db = db_connect();

        $db->table('templates')->insert([
            'id' => 1, 'tenant_id' => 1, 'name' => 'diwali_2026', 'language' => 'en',
            'category' => 'marketing', 'body' => 'Happy Diwali {{1}}', 'meta_status' => 'approved',
            'created_at' => '2026-09-01 09:00:00', 'updated_at' => '2026-09-01 09:00:00',
        ]);

        $db->table('campaigns')->insert([
            'id' => 1, 'tenant_id' => 1, 'template_id' => 1, 'name' => 'Diwali Offer',
            'status' => 'done', 'total_contacts' => 3, 'sent_count' => 2, 'failed_count' => 1,
            'created_at' => '2026-09-10 09:55:00', 'updated_at' => '2026-09-10 10:05:00',
        ]);

        $people = [
            1 => ['Asha Verma',  '+919000000001'],
            2 => ['Bhanu Iyer',  '+919000000002'],
            3 => ['Chetan Rao',  '+919000000003'],
        ];
        foreach ($people as $id => [$name, $number]) {
            $db->table('contacts')->insert([
                'id' => $id, 'tenant_id' => 1, 'wa_number' => $number, 'name' => $name,
                'status' => 'new', 'source' => 'import', 'opt_in' => 1,
                'created_at' => '2026-09-01 09:00:00', 'updated_at' => '2026-09-01 09:00:00',
            ]);
            $db->table('conversations')->insert([
                'id' => $id, 'tenant_id' => 1, 'contact_id' => $id, 'wa_number' => $number,
                'created_at' => '2026-09-01 09:00:00', 'updated_at' => '2026-09-01 09:00:00',
            ]);
        }

        // The broadcast: one read, one delivered-but-unread, one refused.
        $this->message([
            'id' => 1, 'contact_id' => 1, 'conversation_id' => 1, 'campaign_id' => 1,
            'status' => 'read', 'billable' => 1, 'created_at' => '2026-09-10 10:00:00',
            'sent_at' => '2026-09-10 10:00:03', 'delivered_at' => '2026-09-10 10:00:09',
            'read_at' => '2026-09-10 10:42:00', 'wa_message_id' => 'wamid.a1',
        ]);
        $this->message([
            'id' => 2, 'contact_id' => 2, 'conversation_id' => 2, 'campaign_id' => 1,
            'status' => 'delivered', 'billable' => 1, 'created_at' => '2026-09-10 10:00:10',
            'sent_at' => '2026-09-10 10:00:12', 'delivered_at' => '2026-09-10 10:00:20',
            'wa_message_id' => 'wamid.b1',
        ]);
        $this->message([
            'id' => 3, 'contact_id' => 3, 'conversation_id' => 3, 'campaign_id' => 1,
            'status' => 'failed', 'billable' => 0, 'created_at' => '2026-09-10 10:00:30',
            'error' => '#131049 — This message was not delivered to maintain healthy ecosystem engagement.',
            'wa_message_id' => 'wamid.c1',
        ]);

        // A flow send, late on the 10th UTC — past midnight in IST.
        $this->message([
            'id' => 4, 'contact_id' => 1, 'conversation_id' => 1, 'campaign_id' => null,
            'type' => 'text', 'category' => 'free_form', 'status' => 'sent', 'billable' => 0,
            'body' => 'Following up on your enquiry', 'created_at' => '2026-09-10 19:30:00',
            'sent_at' => '2026-09-10 19:30:02', 'wa_message_id' => 'wamid.d1',
        ]);

        // Asha replies two hours later (inside the window → attributed).
        $this->message([
            'id' => 5, 'contact_id' => 1, 'conversation_id' => 1, 'campaign_id' => null,
            'direction' => 'in', 'type' => 'text', 'category' => null, 'status' => 'delivered',
            'billable' => 0, 'body' => 'Interested, send details',
            'created_at' => '2026-09-10 12:00:00', 'wa_message_id' => 'wamid.in1',
        ]);
        // Bhanu replies two days later (outside the window → not attributed).
        $this->message([
            'id' => 6, 'contact_id' => 2, 'conversation_id' => 2, 'campaign_id' => null,
            'direction' => 'in', 'type' => 'text', 'category' => null, 'status' => 'delivered',
            'billable' => 0, 'body' => 'Who is this?',
            'created_at' => '2026-09-12 12:00:00', 'wa_message_id' => 'wamid.in2',
        ]);

        // Chetan writes in an hour after the send Meta refused — he never saw
        // that message, so it must not be credited with his reply.
        $this->message([
            'id' => 7, 'contact_id' => 3, 'conversation_id' => 3, 'campaign_id' => null,
            'direction' => 'in', 'type' => 'text', 'category' => null, 'status' => 'delivered',
            'billable' => 0, 'body' => 'Do you sell software?',
            'created_at' => '2026-09-10 11:00:00', 'wa_message_id' => 'wamid.in3',
        ]);

        foreach ([1, 2] as $n) {
            $db->table('click_events')->insert([
                'tenant_id' => 1, 'campaign_id' => 1, 'message_id' => 1, 'contact_id' => 1,
                'conversation_id' => 1, 'button_id' => 'shop_now', 'button_title' => 'Shop now',
                'clicked_at' => "2026-09-10 10:4{$n}:00", 'created_at' => "2026-09-10 10:4{$n}:00",
            ]);
        }
    }

    /** @param array<string, mixed> $row */
    private function message(array $row): void
    {
        db_connect()->table('messages')->insert($row + [
            'tenant_id' => 1,
            'direction' => 'out',
            'type'      => 'template',
            'category'  => 'marketing',
            'body'      => 'Happy Diwali',
            'updated_at'=> $row['created_at'],
        ]);
    }
}
