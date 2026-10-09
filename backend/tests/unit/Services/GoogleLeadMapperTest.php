<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Leads\GoogleLeadMapper;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for GoogleLeadMapper::map(). No DB required.
 *
 * Google delivers each field as {column_id, column_name, string_value}.
 */
class GoogleLeadMapperTest extends CIUnitTestCase
{
    private function col(string $id, string $value, string $name = ''): array
    {
        return ['column_id' => $id, 'column_name' => $name ?: $id, 'string_value' => $value];
    }

    // ------------------------------------------------------------------
    // Standard field mapping (column_id codes are case-insensitive)
    // ------------------------------------------------------------------

    public function testFullNameMapsToName(): void
    {
        $result = GoogleLeadMapper::map([$this->col('FULL_NAME', 'John Doe')]);
        $this->assertSame('John Doe', $result['name']);
    }

    public function testLowercaseColumnIdStillMaps(): void
    {
        $result = GoogleLeadMapper::map([$this->col('full_name', 'John Doe')]);
        $this->assertSame('John Doe', $result['name']);
    }

    public function testFirstAndLastNameCombined(): void
    {
        $result = GoogleLeadMapper::map([
            $this->col('FIRST_NAME', 'John'),
            $this->col('LAST_NAME',  'Doe'),
        ]);
        $this->assertSame('John Doe', $result['name']);
    }

    public function testFullNameTakesPrecedenceOverFirstLast(): void
    {
        $result = GoogleLeadMapper::map([
            $this->col('FULL_NAME',  'Full Name'),
            $this->col('FIRST_NAME', 'First'),
            $this->col('LAST_NAME',  'Last'),
        ]);
        $this->assertSame('Full Name', $result['name']);
    }

    public function testEmailMapped(): void
    {
        $result = GoogleLeadMapper::map([$this->col('EMAIL', 'test@example.com')]);
        $this->assertSame('test@example.com', $result['email']);
    }

    public function testPrimaryEmailPreferredOverWorkEmail(): void
    {
        $result = GoogleLeadMapper::map([
            $this->col('WORK_EMAIL', 'work@example.com'),
            $this->col('EMAIL',      'primary@example.com'),
        ]);
        $this->assertSame('primary@example.com', $result['email']);
    }

    public function testPhoneNumberNormalized(): void
    {
        $result = GoogleLeadMapper::map([$this->col('PHONE_NUMBER', '+919999900001')]);
        $this->assertSame('+919999900001', $result['wa_number']);
    }

    public function testPhoneNumberWithDefaultCountryCode(): void
    {
        $result = GoogleLeadMapper::map([$this->col('PHONE_NUMBER', '09999900001')], '+91');
        $this->assertSame('+919999900001', $result['wa_number']);
    }

    public function testMissingPhoneNumberResultsInEmptyWaNumber(): void
    {
        $result = GoogleLeadMapper::map([$this->col('EMAIL', 'x@x.com')]);
        $this->assertArrayHasKey('wa_number', $result);
        $this->assertSame('', $result['wa_number'], 'Missing phone = empty wa_number');
    }

    public function testUnparsablePhoneNumberResultsInEmpty(): void
    {
        $result = GoogleLeadMapper::map([$this->col('PHONE_NUMBER', 'notaphone')]);
        $this->assertSame('', $result['wa_number']);
    }

    public function testBlankValuesSkipped(): void
    {
        $result = GoogleLeadMapper::map([
            $this->col('FULL_NAME', ''),
            $this->col('EMAIL',     'x@x.com'),
        ]);
        $this->assertArrayNotHasKey('name', $result);
        $this->assertSame('x@x.com', $result['email']);
    }

    // ------------------------------------------------------------------
    // Custom fields (custom questions) → snake_case using the column_name
    // ------------------------------------------------------------------

    public function testCustomFieldMappedWithSnakeCaseKey(): void
    {
        $result = GoogleLeadMapper::map([$this->col('what_is_your_budget', 'Over 1L', 'What is your budget?')]);
        $this->assertArrayHasKey('what_is_your_budget', $result['custom_fields']);
        $this->assertSame('Over 1L', $result['custom_fields']['what_is_your_budget']);
    }

    public function testCustomFieldFallsBackToColumnIdWhenNoName(): void
    {
        $result = GoogleLeadMapper::map([
            ['column_id' => 'CITY', 'string_value' => 'Mumbai'],
        ]);
        $this->assertSame('Mumbai', $result['custom_fields']['city']);
    }

    // ------------------------------------------------------------------
    // Source + complete lead
    // ------------------------------------------------------------------

    public function testSourceIsAlwaysGoogleLeadForms(): void
    {
        $result = GoogleLeadMapper::map([]);
        $this->assertSame('google_lead_forms', $result['source']);
    }

    public function testCompleteLeadMapping(): void
    {
        $result = GoogleLeadMapper::map([
            $this->col('FULL_NAME',    'Alice Smith'),
            $this->col('EMAIL',        'alice@example.com'),
            $this->col('PHONE_NUMBER', '+919999900001'),
            $this->col('city',         'Delhi', 'City'),
        ], '+91');

        $this->assertSame('Alice Smith',       $result['name']);
        $this->assertSame('alice@example.com', $result['email']);
        $this->assertSame('+919999900001',     $result['wa_number']);
        $this->assertSame('google_lead_forms', $result['source']);
        $this->assertSame('Delhi',             $result['custom_fields']['city']);
    }
}
