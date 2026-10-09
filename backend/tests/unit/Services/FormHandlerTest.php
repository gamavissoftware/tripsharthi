<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Models\WebFormModel;
use App\Services\Leads\FormHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\BlankSlateSchema;

/**
 * Tests FormHandler submission logic.
 *
 * Reuses the same SQLite in-memory schema pattern as ImporterDedupeTest.
 */
class FormHandlerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use BlankSlateSchema;

    protected $migrate = false;
    protected $refresh = true;

    private FormHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAllTables();
        $this->createTestSchema();

        $this->handler = new FormHandler(
            new WebFormModel(),
            new ContactModel(),
            new ContactFieldValueModel()
        );
    }

    private function createTestSchema(): void
    {
        $db = db_connect();
        $p  = $db->DBPrefix; // 'db_' in the SQLite3 test group

        $db->query("CREATE TABLE IF NOT EXISTS {$p}tenants (
            id INTEGER PRIMARY KEY,
            name TEXT, slug TEXT, plan TEXT DEFAULT 'free',
            status TEXT DEFAULT 'active', mode TEXT DEFAULT 'saas',
            settings TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER NOT NULL, wa_number TEXT NOT NULL,
            name TEXT, email TEXT, status TEXT DEFAULT 'new',
            source TEXT DEFAULT 'manual', opt_in INTEGER DEFAULT 1,
            last_inbound_at TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT,
            UNIQUE(tenant_id, wa_number)
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}custom_fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER, label TEXT, field_key TEXT, type TEXT DEFAULT 'text',
            options TEXT, is_required INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}contact_field_values (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            contact_id INTEGER, custom_field_id INTEGER, value TEXT,
            created_at TEXT, updated_at TEXT,
            UNIQUE(contact_id, custom_field_id)
        )");
        $db->query("CREATE TABLE IF NOT EXISTS {$p}web_forms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tenant_id INTEGER, form_token TEXT, title TEXT,
            fields TEXT, redirect_url TEXT, active INTEGER DEFAULT 1,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )");
        $db->query("INSERT OR IGNORE INTO {$p}tenants (id, name, slug) VALUES (1, 'Test', 'test')");
    }

    private function seedForm(array $overrides = []): string
    {
        $token = 'testtoken' . rand(1000, 9999);
        $data  = array_merge([
            'tenant_id'  => 1,
            'form_token' => $token,
            'title'      => 'Test Form',
            'fields'     => json_encode(WebFormModel::defaultFields()),
            'active'     => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $overrides);
        // CI4 query builder auto-applies DBPrefix, so 'web_forms' → 'db_web_forms'
        db_connect()->table('web_forms')->insert($data);
        return $token;
    }

    // ------------------------------------------------------------------

    public function testValidSubmissionCreatesContact(): void
    {
        $token  = $this->seedForm();
        $result = $this->handler->handle($token, [
            'wa_number' => '+919999900010',
            'name'      => 'Form User',
            'email'     => 'user@example.com',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('inserted', $result['action']);
        $this->assertGreaterThan(0, $result['contact_id']);

        $row = db_connect()->table('contacts')->where('wa_number', '+919999900010')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame('web_form', $row['source']);
    }

    public function testCustomFieldSubmissionMapsToContactFieldValue(): void
    {
        // A tenant custom field + a form that includes it.
        db_connect()->table('custom_fields')->insert(['tenant_id' => 1, 'label' => 'Budget', 'field_key' => 'budget', 'type' => 'text']);
        $cfId  = (int) db_connect()->insertID();
        $token = $this->seedForm(['fields' => json_encode([
            ['field_key' => 'wa_number', 'label' => 'WhatsApp Number', 'required' => true],
            ['field_key' => 'name', 'label' => 'Name', 'required' => false],
            ['field_key' => 'budget', 'label' => 'Budget', 'required' => false],
        ])]);

        $result = $this->handler->handle($token, ['wa_number' => '+919999900099', 'name' => 'CF User', 'budget' => '50000']);
        $this->assertTrue($result['ok']);

        $val = db_connect()->table('contact_field_values')
            ->where('contact_id', $result['contact_id'])->where('custom_field_id', $cfId)
            ->get()->getRowArray();
        $this->assertNotNull($val, 'custom field value stored');
        $this->assertSame('50000', $val['value']);
    }

    public function testDuplicateSubmissionUpdatesContact(): void
    {
        $token = $this->seedForm();
        $this->handler->handle($token, ['wa_number' => '+919999900011', 'name' => 'First']);
        $r2 = $this->handler->handle($token, ['wa_number' => '+919999900011', 'name' => 'Second']);

        $this->assertTrue($r2['ok']);
        $this->assertSame('updated', $r2['action']);

        $count = db_connect()->table('contacts')->where('wa_number', '+919999900011')->countAllResults();
        $this->assertSame(1, $count);
    }

    public function testInactiveFormReturnsError(): void
    {
        $token  = $this->seedForm(['active' => 0]);
        $result = $this->handler->handle($token, [
            'wa_number' => '+919999900012', 'name' => 'X',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('form', $result['errors']);
    }

    public function testInvalidTokenReturnsError(): void
    {
        $result = $this->handler->handle('nonexistent_token_xyz', [
            'wa_number' => '+919999900013',
        ]);
        $this->assertFalse($result['ok']);
    }

    public function testMissingRequiredFieldReturnsError(): void
    {
        $token  = $this->seedForm();
        $result = $this->handler->handle($token, [
            'name' => 'No Phone',  // wa_number is required but missing
        ]);
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('wa_number', $result['errors']);
    }

    public function testInvalidWaNumberReturnsError(): void
    {
        $token  = $this->seedForm();
        $result = $this->handler->handle($token, [
            'wa_number' => 'notanumber',
            'name'      => 'Bad Phone',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('wa_number', $result['errors']);
    }
}
