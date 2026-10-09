<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ContactFieldValueModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * Phase K3b: a custom field flagged `restricted` is returned only when the
 * caller is allowed to see it (owner/admin); agents get the field omitted.
 */
class FieldVisibilityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CrmTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCrmSchema();
    }

    private function seed(): int
    {
        $db = db_connect();
        $db->table('contacts')->insert(['id' => 1, 'tenant_id' => 1, 'wa_number' => '111', 'name' => 'C']);
        $db->table('custom_fields')->insert(['id' => 1, 'tenant_id' => 1, 'label' => 'Notes', 'field_key' => 'notes', 'restricted' => 0]);
        $db->table('custom_fields')->insert(['id' => 2, 'tenant_id' => 1, 'label' => 'Salary', 'field_key' => 'salary', 'restricted' => 1]);
        $db->table('contact_field_values')->insert(['contact_id' => 1, 'custom_field_id' => 1, 'value' => 'hello']);
        $db->table('contact_field_values')->insert(['contact_id' => 1, 'custom_field_id' => 2, 'value' => '90000']);
        return 1;
    }

    public function testAdminSeesRestrictedField(): void
    {
        $this->seed();
        $values = (new ContactFieldValueModel())->getForContact(1, true);

        $this->assertSame('hello', $values['notes']);
        $this->assertArrayHasKey('salary', $values, 'admin sees restricted field');
        $this->assertSame('90000', $values['salary']);
    }

    public function testAgentDoesNotSeeRestrictedField(): void
    {
        $this->seed();
        $values = (new ContactFieldValueModel())->getForContact(1, false);

        $this->assertSame('hello', $values['notes'], 'unrestricted field still visible');
        $this->assertArrayNotHasKey('salary', $values, 'restricted field hidden from agent');
    }

    public function testDefaultIncludesRestricted(): void
    {
        // Other callers (timelines, exports run by admins) rely on the default
        // remaining inclusive so behaviour is unchanged where the flag is absent.
        $this->seed();
        $values = (new ContactFieldValueModel())->getForContact(1);
        $this->assertArrayHasKey('salary', $values);
    }
}
