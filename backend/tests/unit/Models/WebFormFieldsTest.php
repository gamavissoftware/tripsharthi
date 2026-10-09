<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\WebFormModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The admin UI stores a web form's fields as a plain list of keys
 * (["name","wa_number"]), while the public form view and FormHandler need
 * {field_key, label, required} definitions. Before normalizeFields() existed,
 * every form built in the UI fatalled on its own public page
 * ("Cannot access offset of type string on string"), taking the whole
 * lead-capture path down. No DB required.
 */
class WebFormFieldsTest extends CIUnitTestCase
{
    public function testPlainKeyListIsExpandedToDefinitions(): void
    {
        $fields = WebFormModel::normalizeFields(['name', 'wa_number', 'email']);

        $this->assertCount(3, $fields);
        $this->assertSame('name', $fields[0]['field_key']);
        $this->assertSame('Name', $fields[0]['label']);
        $this->assertSame('WhatsApp Number', $fields[1]['label']);
        $this->assertSame('Email', $fields[2]['label']);
    }

    public function testJsonStringIsDecodedBeforeNormalising(): void
    {
        $fields = WebFormModel::normalizeFields('["name", "wa_number", "email"]');

        $this->assertCount(3, $fields);
        $this->assertSame('wa_number', $fields[1]['field_key']);
    }

    public function testWaNumberIsAlwaysRequired(): void
    {
        $fields   = WebFormModel::normalizeFields(['name', 'wa_number']);
        $required = array_column($fields, 'required', 'field_key');

        $this->assertTrue($required['wa_number'], 'wa_number identifies the contact');
        $this->assertFalse($required['name']);
    }

    public function testAlreadyShapedDefinitionsArePreserved(): void
    {
        $fields = WebFormModel::normalizeFields([
            ['field_key' => 'city', 'label' => 'Your City', 'required' => true],
        ]);

        $this->assertSame([['field_key' => 'city', 'label' => 'Your City', 'required' => true]], $fields);
    }

    public function testUnknownKeyGetsHumanisedLabel(): void
    {
        $fields = WebFormModel::normalizeFields(['company_name']);

        $this->assertSame('Company Name', $fields[0]['label']);
        $this->assertFalse($fields[0]['required']);
    }

    public function testEmptyOrNullFallsBackToDefaults(): void
    {
        $this->assertSame(WebFormModel::defaultFields(), WebFormModel::normalizeFields(null));
        $this->assertSame(WebFormModel::defaultFields(), WebFormModel::normalizeFields([]));
        $this->assertSame(WebFormModel::defaultFields(), WebFormModel::normalizeFields('not json'));
    }

    public function testEveryDefinitionCarriesTheKeysConsumersRead(): void
    {
        // FormController's view and FormHandler both index these three keys —
        // a missing one is a fatal, not a soft failure.
        foreach (WebFormModel::normalizeFields(['name', 'wa_number', 'email']) as $field) {
            $this->assertArrayHasKey('field_key', $field);
            $this->assertArrayHasKey('label', $field);
            $this->assertArrayHasKey('required', $field);
        }
    }
}
