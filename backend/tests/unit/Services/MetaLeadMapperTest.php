<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Leads\MetaLeadMapper;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for MetaLeadMapper::map().  No DB required.
 */
class MetaLeadMapperTest extends CIUnitTestCase
{
    private function field(string $name, string $value): array
    {
        return ['name' => $name, 'values' => [$value]];
    }

    // ------------------------------------------------------------------
    // Standard field mapping
    // ------------------------------------------------------------------

    public function testFullNameMapsToName(): void
    {
        $result = MetaLeadMapper::map([$this->field('full_name', 'John Doe')]);
        $this->assertSame('John Doe', $result['name']);
    }

    public function testFirstAndLastNameCombined(): void
    {
        $result = MetaLeadMapper::map([
            $this->field('first_name', 'John'),
            $this->field('last_name',  'Doe'),
        ]);
        $this->assertSame('John Doe', $result['name']);
    }

    public function testFullNameTakesPrecedenceOverFirstLast(): void
    {
        $result = MetaLeadMapper::map([
            $this->field('full_name',  'Full Name'),
            $this->field('first_name', 'First'),
            $this->field('last_name',  'Last'),
        ]);
        $this->assertSame('Full Name', $result['name']);
    }

    public function testEmailMapped(): void
    {
        $result = MetaLeadMapper::map([$this->field('email', 'test@example.com')]);
        $this->assertSame('test@example.com', $result['email']);
    }

    public function testPhoneNumberNormalized(): void
    {
        $result = MetaLeadMapper::map([$this->field('phone_number', '+919999900001')]);
        $this->assertSame('+919999900001', $result['wa_number']);
    }

    public function testPhoneNumberWithDefaultCountryCode(): void
    {
        $result = MetaLeadMapper::map([$this->field('phone_number', '09999900001')], '+91');
        // 09999900001 → strip leading 0 → 9999900001 → +919999900001
        $this->assertSame('+919999900001', $result['wa_number']);
    }

    public function testMissingPhoneNumberResultsInEmptyWaNumber(): void
    {
        $result = MetaLeadMapper::map([$this->field('email', 'x@x.com')]);
        $this->assertArrayHasKey('wa_number', $result);
        $this->assertSame('', $result['wa_number'], 'Missing phone = empty wa_number');
    }

    public function testUnparsablePhoneNumberResultsInEmpty(): void
    {
        $result = MetaLeadMapper::map([$this->field('phone_number', 'notaphone')]);
        $this->assertSame('', $result['wa_number']);
    }

    // ------------------------------------------------------------------
    // Custom fields
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // WhatsApp-number custom questions (how real lead forms are built)
    // ------------------------------------------------------------------

    public function testWhatsappQuestionIsPreferredOverOptionalPhoneNumber(): void
    {
        // The Gamavis form: Meta's phone_number is optional, a required
        // "WhatsApp number" question carries the number we can message.
        $result = MetaLeadMapper::map([
            $this->field('full_name',       'Asha Patel'),
            $this->field('phone_number',    '+911234567890'),
            $this->field('whatsapp_number', '9876543210'),
        ], '+91');

        $this->assertSame('+919876543210', $result['wa_number']);
        $this->assertSame('+911234567890', $result['phone_secondary'], 'Meta phone kept as the secondary line');
        $this->assertArrayNotHasKey('whatsapp_number', $result['custom_fields'], 'consumed, not duplicated');
    }

    public function testPhoneIsAnAliasOfPhoneNumber(): void
    {
        // Seen on the live Gamavis form: Meta delivers the standard field as
        // "phone", not "phone_number".
        $result = MetaLeadMapper::map([
            $this->field('phone',           '+911234567890'),
            $this->field('whatsapp_number', '9876543210'),
        ], '+91');

        $this->assertSame('+919876543210', $result['wa_number']);
        $this->assertSame('+911234567890', $result['phone_secondary']);
        $this->assertArrayNotHasKey('phone', $result['custom_fields']);

        $only = MetaLeadMapper::map([$this->field('phone', '9876543210')], '+91');
        $this->assertSame('+919876543210', $only['wa_number']);
    }

    public function testWhatsappQuestionAloneIsEnough(): void
    {
        $result = MetaLeadMapper::map([$this->field('whatsapp_number', '+919876543210')]);

        $this->assertSame('+919876543210', $result['wa_number']);
        $this->assertArrayNotHasKey('phone_secondary', $result);
    }

    public function testUnparseableWhatsappFallsBackToPhoneNumber(): void
    {
        $result = MetaLeadMapper::map([
            $this->field('whatsapp_number', 'test lead: dummy data for whatsapp_number'),
            $this->field('phone_number',    '+919876543210'),
        ]);

        $this->assertSame('+919876543210', $result['wa_number']);
    }

    public function testOtherPhoneLikeQuestionsAreLastResort(): void
    {
        $result = MetaLeadMapper::map([$this->field('mobile_no', '09876543210')], '+91');

        $this->assertSame('+919876543210', $result['wa_number']);
        $this->assertArrayNotHasKey('mobile_no', $result['custom_fields']);
    }

    public function testCompanyAndJobTitleAreFirstClass(): void
    {
        $result = MetaLeadMapper::map([
            $this->field('company_name', 'Gamavis Softech LLP'),
            $this->field('job_title',    'Director'),
        ]);

        $this->assertSame('Gamavis Softech LLP', $result['company']);
        $this->assertSame('Director', $result['job_title']);
        $this->assertSame([], $result['custom_fields']);
    }

    public function testLongQuestionKeysAreClippedToTheColumnLength(): void
    {
        // "What are you currently using to manage your business?" is 53 chars
        // snake_cased; custom_fields.field_key is VARCHAR(50). MySQL truncated
        // the stored key, the lookup used the full one, and the second lead
        // with that question died on a duplicate-key insert.
        $q      = 'What are you currently using to manage your business?';
        $result = MetaLeadMapper::map([$this->field($q, 'Excel')]);
        $keys   = array_keys($result['custom_fields']);

        $this->assertCount(1, $keys);
        $this->assertLessThanOrEqual(50, strlen($keys[0]));
        $this->assertSame('what_are_you_currently_using_to_manage_your_busine', $keys[0]);
        $this->assertSame($keys[0], MetaLeadMapper::fieldKey($q), 'handler and mapper derive the same key');
    }

    public function testCustomFieldMappedWithSnakeCaseKey(): void
    {
        $result = MetaLeadMapper::map([$this->field('Company Name', 'Acme Corp')]);
        $this->assertArrayHasKey('company_name', $result['custom_fields']);
        $this->assertSame('Acme Corp', $result['custom_fields']['company_name']);
    }

    public function testMultipleCustomFields(): void
    {
        $result = MetaLeadMapper::map([
            $this->field('city',    'Mumbai'),
            $this->field('product', 'Widget'),
        ]);
        $this->assertSame('Mumbai', $result['custom_fields']['city']);
        $this->assertSame('Widget', $result['custom_fields']['product']);
    }

    // ------------------------------------------------------------------
    // Source always meta_lead_ads
    // ------------------------------------------------------------------

    public function testSourceIsAlwaysMetaLeadAds(): void
    {
        $result = MetaLeadMapper::map([]);
        $this->assertSame('meta_lead_ads', $result['source']);
    }

    // ------------------------------------------------------------------
    // Complete lead
    // ------------------------------------------------------------------

    public function testCompleteLeadMapping(): void
    {
        $result = MetaLeadMapper::map([
            $this->field('full_name',    'Alice Smith'),
            $this->field('email',        'alice@example.com'),
            $this->field('phone_number', '+919999900001'),
            $this->field('city',         'Delhi'),
        ], '+91');

        $this->assertSame('Alice Smith',        $result['name']);
        $this->assertSame('alice@example.com',  $result['email']);
        $this->assertSame('+919999900001',       $result['wa_number']);
        $this->assertSame('meta_lead_ads',       $result['source']);
        $this->assertSame('Delhi',               $result['custom_fields']['city']);
    }
}
