<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Leads\MetaTestLeadService;
use App\Services\Social\GraphClient;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Real test leads through Graph's /{form}/test_leads — the only way to push a
 * real phone number through Meta's lead pipeline without buying an ad.
 */
class MetaTestLeadServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $p  = $db->DBPrefix;
        $db->query("DROP TABLE IF EXISTS {$p}integrations");
        $db->query("CREATE TABLE {$p}integrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
            type TEXT DEFAULT 'meta_lead_ads', page_id TEXT, verify_token TEXT,
            config TEXT, status TEXT DEFAULT 'active',
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->table('integrations')->insert([
            'tenant_id' => 1, 'type' => 'meta_lead_ads', 'page_id' => '109730130934404',
            'verify_token' => 'vt', 'status' => 'active',
            'config' => json_encode(['page_access_token_enc' => TokenCipher::encrypt('page-token')]),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testFormsListsThePagesLeadForms(): void
    {
        $graph = new FakeFormGraph();
        $forms = (new MetaTestLeadService($graph))->forms(1);

        $this->assertSame('109730130934404/leadgen_forms', $graph->calls[0]['path']);
        $this->assertSame('page-token', $graph->calls[0]['params']['access_token']);
        $this->assertSame('GAMAVIS — Custom Software That Drives Growth', $forms[0]['name']);
        $this->assertSame(89, $forms[1]['leads_count']);
    }

    public function testCreateFillsEveryQuestionAndPutsTheRealNumberInPhoneLikeOnes(): void
    {
        $graph  = new FakeFormGraph();
        $result = (new MetaTestLeadService($graph))->create(1, '1065491639694344', '+919718991797', 'Manglesh', 'm@example.com');

        $this->assertSame('1638149481251972', $result['leadgen_id']);
        $sent = array_column($result['field_data'], 'values', 'name');
        $this->assertSame(['+919718991797'], $sent['whatsapp_number']);
        $this->assertSame(['+919718991797'], $sent['phone_number']);
        $this->assertSame(['m@example.com'], $sent['email']);
        $this->assertSame(['Manglesh'], $sent['full_name']);
        $this->assertSame(['Manufacturing'], $sent['what_type_of_business_do_you_operate?'], 'choice questions get their first option');
        $this->assertSame(['TravelPilot Test Co'], $sent['company_name']);

        // Previous test lead cleared, then the new one posted with JSON field_data.
        $paths = array_map(static fn (array $c): string => $c['method'] . ' ' . $c['path'], $graph->calls);
        $this->assertContains('DELETE 1065491639694344/test_leads', $paths);
        $post = $graph->calls[array_key_last($graph->calls)];
        $this->assertSame('POST 1065491639694344/test_leads', $post['method'] . ' ' . $post['path']);
        $this->assertSame($result['field_data'], json_decode($post['params']['field_data'], true));
    }

    public function testDeleteFailureDoesNotStopTheCreate(): void
    {
        $graph               = new FakeFormGraph();
        $graph->deleteThrows = true;

        $result = (new MetaTestLeadService($graph))->create(1, '1065491639694344', '+919718991797');

        $this->assertSame('1638149481251972', $result['leadgen_id']);
    }

    public function testRefusesWhenNoPageIsLinked(): void
    {
        db_connect()->table('integrations')->truncate();

        $this->expectExceptionMessageMatches('/No Facebook Page is linked/');
        (new MetaTestLeadService(new FakeFormGraph()))->forms(1);
    }

    public function testIsTenantScoped(): void
    {
        $this->expectExceptionMessageMatches('/No Facebook Page is linked/');
        (new MetaTestLeadService(new FakeFormGraph()))->forms(2);
    }
}

class FakeFormGraph extends GraphClient
{
    /** @var array<int,array{method:string,path:string,params:array<string,mixed>}> */
    public array $calls = [];

    public bool $deleteThrows = false;

    public function __construct()
    {
    }

    public function get(string $path, array $params = []): array
    {
        $this->calls[] = ['method' => 'GET', 'path' => $path, 'params' => $params];
        if (str_ends_with($path, '/leadgen_forms')) {
            return ['data' => [
                ['id' => '1065491639694344', 'name' => 'GAMAVIS — Custom Software That Drives Growth', 'status' => 'ACTIVE', 'leads_count' => 1],
                ['id' => '1296712285272018', 'name' => 'Gamavis Custom Software Lead Form', 'status' => 'ACTIVE', 'leads_count' => 89],
            ]];
        }

        return ['id' => $path, 'questions' => [
            ['key' => 'what_type_of_business_do_you_operate?', 'type' => 'CUSTOM', 'label' => 'What type of business do you operate?', 'options' => [['key' => 'manufacturing', 'value' => 'Manufacturing'], ['key' => 'other', 'value' => 'Other']]],
            ['key' => 'email', 'type' => 'EMAIL', 'label' => 'Email'],
            ['key' => 'full_name', 'type' => 'FULL_NAME', 'label' => 'Full name'],
            ['key' => 'phone_number', 'type' => 'PHONE', 'label' => 'Phone number'],
            ['key' => 'company_name', 'type' => 'COMPANY_NAME', 'label' => 'Company name'],
            ['key' => 'job_title', 'type' => 'JOB_TITLE', 'label' => 'Job title'],
            ['key' => 'whatsapp_number', 'type' => 'CUSTOM', 'label' => 'WhatsApp number'],
        ]];
    }

    public function post(string $path, array $params = []): array
    {
        $this->calls[] = ['method' => 'POST', 'path' => $path, 'params' => $params];

        return ['id' => '1638149481251972', 'success' => true];
    }

    public function delete(string $path, array $params = []): array
    {
        $this->calls[] = ['method' => 'DELETE', 'path' => $path, 'params' => $params];
        if ($this->deleteThrows) {
            throw new \RuntimeException('Graph API error 100: no test lead to delete');
        }

        return ['success' => true];
    }
}
