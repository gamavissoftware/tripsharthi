<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Leads\MetaLeadsArchiveService;
use App\Services\Social\GraphClient;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\MetaLeadTestSchema;

/**
 * A month's leads from Meta, and importing them into Contacts.
 *
 * The rules that matter: only that month's leads (Meta is asked with a
 * time filter and pages are followed), each is matched to the CRM by
 * number, an import goes through the same dedupe as the webhook (no
 * duplicates, answers become custom fields, the idempotency record is
 * written), and a bulk import starts no flow unless asked.
 */
class MetaLeadsArchiveServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use MetaLeadTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['WHATSAPP_MOCK_MODE'] = 'true';
        $this->now = gmmktime(12, 0, 0, 9, 25, 2026);
        $this->createMetaLeadSchema();
        $db = db_connect();
        $p  = $db->DBPrefix;
        $db->query("CREATE TABLE IF NOT EXISTS {$p}tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, name TEXT, color TEXT,
            created_at TEXT, updated_at TEXT, deleted_at TEXT)");
        $db->query("DELETE FROM {$p}tags");
        $db->table('integrations')->insert([
            'tenant_id' => 1, 'type' => 'meta_lead_ads', 'page_id' => '109730130934404', 'verify_token' => 'vt',
            'config' => json_encode(['page_access_token_enc' => TokenCipher::encrypt('page-token'), 'default_country_code' => '+91']),
            'status' => 'active', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        unset($_ENV['WHATSAPP_MOCK_MODE']);
        parent::tearDown();
    }

    private function service(?FakeArchiveGraph $g = null): MetaLeadsArchiveService
    {
        return new MetaLeadsArchiveService($g ?? new FakeArchiveGraph(), $this->now);
    }

    public function testBoundsAndLabel(): void
    {
        [$from, $to] = MetaLeadsArchiveService::bounds('2026-06');
        $this->assertSame('2026-06-01', gmdate('Y-m-d', $from));
        $this->assertSame('2026-07-01', gmdate('Y-m-d', $to));
        $this->assertSame('Jun 2026', MetaLeadsArchiveService::label('2026-06'));
        $this->expectException(\RuntimeException::class);
        MetaLeadsArchiveService::bounds('June');
    }

    public function testMonthAsksMetaWithATimeFilterAndFollowsPages(): void
    {
        $g    = new FakeArchiveGraph();
        $data = $this->service($g)->month(1, '2026-06');

        $leadCalls = array_values(array_filter($g->calls, static fn ($c) => str_ends_with($c['path'], '/leads')));
        $this->assertCount(2, $leadCalls, 'two pages for the form');
        $filter = json_decode($leadCalls[0]['params']['filtering'], true);
        $this->assertSame('time_created', $filter[0]['field']);
        $this->assertSame(gmmktime(0, 0, 0, 6, 1, 2026) - 1, $filter[0]['value']);
        $this->assertSame('LESS_THAN', $filter[1]['operator']);
        $this->assertSame('cursor-2', $leadCalls[1]['params']['after']);
        $this->assertSame('page-token', $leadCalls[0]['params']['access_token']);

        $this->assertSame(3, $data['counts']['total']);
        $this->assertSame('2026-06-01', $data['from']);
        $this->assertSame('2026-06-30', $data['to']);
        // newest first
        $this->assertSame('L3', $data['leads'][0]['leadgen_id']);
        $lead = $data['leads'][2];
        $this->assertSame('Asha Patel', $lead['name']);
        $this->assertSame('+919876543210', $lead['wa_number'], 'WhatsApp question wins over Meta phone');
        $this->assertSame('Sharma Textiles', $lead['company']);
        $this->assertSame('GAMAVIS — Custom Software That Drives Growth', $lead['form_name']);
        $this->assertSame('Lead campaign', $lead['campaign_name']);
        $this->assertSame('What type of business do you operate?', $lead['answers'][0]['question']);
        $this->assertSame('manufacturing', $lead['answers'][0]['answer']);
    }

    public function testMonthMarksLeadsAlreadyInTheCrmWithTheirStatus(): void
    {
        db_connect()->table('contacts')->insert([
            'tenant_id' => 1, 'wa_number' => '+919876543210', 'name' => 'Asha', 'status' => 'won', 'source' => 'csv_import',
            'opt_in' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $data = $this->service()->month(1, '2026-06');

        $byId = array_column($data['leads'], null, 'leadgen_id');
        $this->assertSame('won', $byId['L1']['contact']['status']);
        $this->assertNull($byId['L2']['contact']);
        $this->assertSame(['total' => 3, 'in_crm' => 1, 'won' => 1, 'not_in_crm' => 2, 'no_phone' => 1], $data['counts']);
    }

    public function testImportCreatesContactsWithTagAndAnswersWithoutStartingFlows(): void
    {
        $this->seedFlow('meta_lead_received');
        $this->seedFlow('lead_created');

        $r = $this->service()->import(1, '2026-06', null, false, '');

        $this->assertSame(2, $r['inserted']);
        $this->assertSame(0, $r['updated']);
        $this->assertSame(1, $r['skipped'], 'the lead with neither phone nor email');
        $this->assertSame('Meta Ads · Jun 2026', $r['tag']);

        $c = db_connect()->table('contacts')->where('wa_number', '+919876543210')->get()->getRowArray();
        $this->assertSame('meta_lead_ads', $c['source']);
        $tag = db_connect()->table('tags')->where('name', 'Meta Ads · Jun 2026')->get()->getRowArray();
        $this->assertNotNull($tag);
        $this->assertSame(1, db_connect()->table('contact_tags')->where('contact_id', $c['id'])->where('tag_id', $tag['id'])->countAllResults());
        $cf = db_connect()->table('custom_fields')->where('field_key', 'what_type_of_business_do_you_operate')->get()->getRowArray();
        $this->assertNotNull($cf);
        $this->assertSame('manufacturing', db_connect()->table('contact_field_values')->where('contact_id', $c['id'])->where('custom_field_id', $cf['id'])->get()->getRowArray()['value']);
        $this->assertSame(0, db_connect()->table('jobs')->where('type', 'flow_start')->countAllResults(), 'a bulk import must not fire welcome flows');
        $this->assertSame(2, db_connect()->table('meta_lead_events')->where('status', 'processed')->countAllResults(), 'idempotency record written');
    }

    public function testImportCanStartFlowsAndNeverDuplicates(): void
    {
        $this->seedFlow('meta_lead_received');
        $s = $this->service();
        $s->import(1, '2026-06', ['L1'], true, 'Nurture');
        $s->import(1, '2026-06', ['L1'], true, 'Nurture');

        $this->assertSame(1, db_connect()->table('contacts')->where('wa_number', '+919876543210')->countAllResults());
        $this->assertGreaterThanOrEqual(1, db_connect()->table('jobs')->where('type', 'flow_start')->countAllResults());
        $this->assertSame(1, db_connect()->table('meta_lead_events')->where('leadgen_id', 'L1')->countAllResults());
    }

    public function testRefusesWithoutALinkedPage(): void
    {
        $this->expectExceptionMessageMatches('/No Facebook Page is linked/');
        $this->service()->month(2, '2026-06');
    }

    private function seedFlow(string $trigger): void
    {
        db_connect()->table('flows')->insert([
            'tenant_id' => 1, 'name' => "T {$trigger}", 'status' => 'active', 'trigger_type' => $trigger,
            'graph' => json_encode(['nodes' => [], 'edges' => []]), 'reentry_policy' => 'always', 'version' => 1,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

class FakeArchiveGraph extends GraphClient
{
    /** @var array<int,array{path:string,params:array<string,mixed>}> */
    public array $calls = [];

    public function __construct()
    {
    }

    public function get(string $path, array $params = []): array
    {
        $this->calls[] = ['path' => $path, 'params' => $params];
        if (str_ends_with($path, '/leadgen_forms')) {
            return ['data' => [['id' => '1065491639694344', 'name' => 'GAMAVIS — Custom Software That Drives Growth', 'status' => 'ACTIVE', 'leads_count' => 3]]];
        }
        if (str_ends_with($path, '/leads')) {
            if (! isset($params['after'])) {
                return ['data' => [
                    ['id' => 'L1', 'created_time' => '2026-06-03T10:00:00+0000', 'campaign_name' => 'Lead campaign', 'ad_name' => 'ERP ad', 'platform' => 'ig', 'field_data' => [
                        ['name' => 'What type of business do you operate?', 'values' => ['manufacturing']],
                        ['name' => 'full_name', 'values' => ['Asha Patel']],
                        ['name' => 'phone', 'values' => ['+911234567890']],
                        ['name' => 'whatsapp_number', 'values' => ['+919876543210']],
                        ['name' => 'company_name', 'values' => ['Sharma Textiles']],
                        ['name' => 'email', 'values' => ['asha@example.com']],
                    ]],
                    ['id' => 'L2', 'created_time' => '2026-06-15T10:00:00+0000', 'field_data' => [
                        ['name' => 'full_name', 'values' => ['Ravi']],
                        ['name' => 'phone', 'values' => ['9000000001']],
                    ]],
                ], 'paging' => ['cursors' => ['after' => 'cursor-2'], 'next' => 'https://graph/next']];
            }

            return ['data' => [
                ['id' => 'L3', 'created_time' => '2026-06-28T10:00:00+0000', 'field_data' => [
                    ['name' => 'full_name', 'values' => ['No Phone']],
                ]],
            ], 'paging' => ['cursors' => ['after' => 'cursor-3']]];
        }

        return [];
    }
}
