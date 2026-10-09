<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\Api\AnalyticsController;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CampaignTestSchema;

/**
 * Controller-level tests for the delivery-report endpoints — the glue the
 * service tests cannot see: query-string parsing, the JSON envelope the SPA
 * unwraps, tenant scoping from the authenticated user, and the CSV download
 * headers the browser needs to name the file.
 *
 * A contract mismatch between controller and page is this codebase's recurring
 * silent bug: both sides look right, and the screen shows zeros.
 */
class AnalyticsEndpointsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CampaignTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCampaignSchema();
        $this->createClickEvents();
        $this->seed();

        CurrentUser::set(['id' => 1, 'tenant_id' => 1, 'role' => 'owner', 'name' => 'Owner']);
    }

    protected function tearDown(): void
    {
        CurrentUser::reset();
        parent::tearDown();
    }

    public function testOverviewReturnsEveryPanelThePageRenders(): void
    {
        $data = $this->json('overview', ['from' => '2026-09-09', 'to' => '2026-09-12']);

        foreach (['filters', 'funnel', 'timeseries', 'categories', 'failures', 'templates', 'rate_card'] as $key) {
            $this->assertArrayHasKey($key, $data, "the page reads data.{$key}");
        }

        $this->assertSame(2, $data['funnel']['sent']);
        $this->assertSame(1, $data['funnel']['read']);
        $this->assertCount(4, $data['timeseries'], 'one point per day in the range');
        $this->assertSame('2026-09-09', $data['filters']['from'], 'the server echoes what it actually filtered on');
    }

    public function testAClampedFilterIsEchoedBackNotTheOneTheOperatorTyped(): void
    {
        // A date the server rejected must stop showing in the UI as if it applied.
        $data = $this->json('overview', ['from' => 'last-tuesday', 'to' => '2026-09-12']);

        $this->assertNull($data['filters']['from']);
        $this->assertSame('2026-09-12', $data['filters']['to']);
    }

    public function testMessagesReturnsThePagedLogWithRecipientDetail(): void
    {
        $data = $this->json('messages', ['campaign_id' => '1', 'per_page' => '2']);

        $this->assertSame(3, $data['total']);
        $this->assertSame(2, $data['pages']);
        $this->assertCount(2, $data['rows']);

        $row = $data['rows'][0];
        foreach (['contact_name', 'wa_number', 'status', 'sent_at', 'delivered_at', 'read_at', 'replied_at'] as $field) {
            $this->assertArrayHasKey($field, $row, "the log table renders row.{$field}");
        }
    }

    public function testPerPageCannotBeInflatedIntoAFullTableDump(): void
    {
        $data = $this->json('messages', ['per_page' => '100000']);

        $this->assertLessThanOrEqual(200, $data['per_page']);
    }

    public function testExportSendsACsvTheBrowserCanName(): void
    {
        $response = $this->call('messagesExport', ['campaign_id' => '1']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/csv', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('filename="campaign-Diwali-Offer', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame('3', $response->getHeaderLine('X-Export-Rows'));
        $this->assertSame('0', $response->getHeaderLine('X-Export-Truncated'));

        $body = $response->getBody();
        $this->assertStringContainsString('WhatsApp Number', $body);
        $this->assertStringContainsString('+919000000001', $body);
        $this->assertStringContainsString('Read', $body);
    }

    public function testCampaignReportComposesTheWholeBroadcast(): void
    {
        $data = $this->json('campaignReport', [], 1);

        $this->assertSame('Diwali Offer', $data['campaign']['name']);
        $this->assertSame('diwali_2026', $data['campaign']['template']['name']);
        $this->assertSame(1, $data['funnel']['read']);
        $this->assertSame(2, $data['funnel']['clicks']);
        $this->assertNotEmpty($data['failures']);
        $this->assertNotEmpty($data['buttons']);
        $this->assertGreaterThan(0, $data['est_cost_paise'], 'two billable marketing sends cost something');
    }

    public function testAnotherTenantsCampaignIsNotFound(): void
    {
        CurrentUser::set(['id' => 9, 'tenant_id' => 99, 'role' => 'owner', 'name' => 'Other']);

        $this->assertSame(404, $this->call('campaignReport', [], 1)->getStatusCode());
    }

    public function testTheLogNeverLeaksAcrossTenants(): void
    {
        CurrentUser::set(['id' => 9, 'tenant_id' => 99, 'role' => 'owner', 'name' => 'Other']);

        $this->assertSame(0, $this->json('messages')['total']);
    }

    // ── harness ───────────────────────────────────────────────────────

    private function json(string $method, array $get = [], ...$args): array
    {
        $body = json_decode($this->call($method, $get, ...$args)->getBody(), true);

        $this->assertTrue($body['success'] ?? false, 'endpoints answer in the {success, data} envelope');

        return $body['data'];
    }

    private function call(string $method, array $get = [], ...$args)
    {
        $_GET = $get;

        $request = new IncomingRequest(new \Config\App(), new URI('http://localhost/'), null, new UserAgent());
        $request->setGlobal('get', $get);

        $controller = new AnalyticsController();
        $controller->initController($request, \Config\Services::response(null, false), \Config\Services::logger());

        return $controller->{$method}(...$args);
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

    private function seed(): void
    {
        $db = db_connect();

        $db->table('templates')->insert([
            'id' => 1, 'tenant_id' => 1, 'name' => 'diwali_2026', 'language' => 'en',
            'category' => 'marketing', 'body' => 'Happy Diwali', 'meta_status' => 'approved',
            'created_at' => '2026-09-01 09:00:00', 'updated_at' => '2026-09-01 09:00:00',
        ]);
        $db->table('campaigns')->insert([
            'id' => 1, 'tenant_id' => 1, 'template_id' => 1, 'name' => 'Diwali Offer',
            'status' => 'done', 'total_contacts' => 3,
            'stats' => json_encode(['billable_sends' => 2, 'skipped_opt_out' => 4, 'failed' => 1]),
            'created_at' => '2026-09-10 09:55:00', 'updated_at' => '2026-09-10 10:05:00',
        ]);

        foreach ([1 => ['Asha Verma', '+919000000001'],
                  2 => ['Bhanu Iyer', '+919000000002'],
                  3 => ['Chetan Rao', '+919000000003']] as $id => [$name, $number]) {
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

        $rows = [
            ['id' => 1, 'contact_id' => 1, 'conversation_id' => 1, 'status' => 'read', 'billable' => 1,
             'created_at' => '2026-09-10 10:00:00', 'sent_at' => '2026-09-10 10:00:03',
             'delivered_at' => '2026-09-10 10:00:09', 'read_at' => '2026-09-10 10:42:00'],
            ['id' => 2, 'contact_id' => 2, 'conversation_id' => 2, 'status' => 'delivered', 'billable' => 1,
             'created_at' => '2026-09-10 10:00:10', 'sent_at' => '2026-09-10 10:00:12',
             'delivered_at' => '2026-09-10 10:00:20'],
            ['id' => 3, 'contact_id' => 3, 'conversation_id' => 3, 'status' => 'failed', 'billable' => 0,
             'created_at' => '2026-09-10 10:00:30',
             'error' => '#131049 — This message was not delivered to maintain healthy ecosystem engagement.'],
        ];
        foreach ($rows as $row) {
            $db->table('messages')->insert($row + [
                'tenant_id' => 1, 'campaign_id' => 1, 'direction' => 'out', 'type' => 'template',
                'category' => 'marketing', 'body' => 'Happy Diwali', 'updated_at' => $row['created_at'],
            ]);
        }

        foreach ([1, 2] as $n) {
            $db->table('click_events')->insert([
                'tenant_id' => 1, 'campaign_id' => 1, 'message_id' => 1, 'contact_id' => 1,
                'conversation_id' => 1, 'button_id' => 'shop_now', 'button_title' => 'Shop now',
                'clicked_at' => "2026-09-10 10:4{$n}:00", 'created_at' => "2026-09-10 10:4{$n}:00",
            ]);
        }
    }
}
