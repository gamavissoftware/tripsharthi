<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Leads\ColumnMapper;
use CodeIgniter\Test\CIUnitTestCase;

class ColumnMapperTest extends CIUnitTestCase
{
    // ------------------------------------------------------------------
    // validate()
    // ------------------------------------------------------------------

    public function testValidatePasses(): void
    {
        $result = ColumnMapper::validate([
            'Mobile'   => 'wa_number',
            'FullName' => 'name',
        ]);
        $this->assertTrue($result['ok']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateFailsWithoutWaNumber(): void
    {
        $result = ColumnMapper::validate(['FullName' => 'name']);
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testValidateFailsOnEmptyMapping(): void
    {
        $result = ColumnMapper::validate([]);
        $this->assertFalse($result['ok']);
    }

    // ------------------------------------------------------------------
    // mapRow()
    // ------------------------------------------------------------------

    private function makeHeaders(): array
    {
        return ['Full Name', 'WhatsApp', 'Email', 'City'];
    }

    private function makeMapping(): array
    {
        return [
            'Full Name' => 'name',
            'WhatsApp'  => 'wa_number',
            'Email'     => 'email',
            'City'      => 'city_custom',   // custom field
        ];
    }

    public function testMapRowExtractsStandardFields(): void
    {
        $headers = $this->makeHeaders();
        $row     = ['John Doe', '+919999900000', 'john@example.com', 'Mumbai'];
        $result  = ColumnMapper::mapRow($headers, $row, $this->makeMapping());

        $this->assertSame('John Doe',       $result['name']);
        $this->assertSame('+919999900000',  $result['wa_number']);
        $this->assertSame('john@example.com', $result['email']);
    }

    public function testMapRowPutsUnknownFieldsInCustomFields(): void
    {
        $headers = $this->makeHeaders();
        $row     = ['Jane', '+910000000000', '', 'Delhi'];
        $result  = ColumnMapper::mapRow($headers, $row, $this->makeMapping());

        $this->assertArrayHasKey('custom_fields', $result);
        $this->assertSame('Delhi', $result['custom_fields']['city_custom']);
    }

    public function testMapRowTrimsValues(): void
    {
        $headers = ['Phone'];
        $row     = ['  +919999900000  '];
        $result  = ColumnMapper::mapRow($headers, $row, ['Phone' => 'wa_number']);

        $this->assertSame('+919999900000', $result['wa_number']);
    }

    public function testMapRowIgnoresUnmappedHeaders(): void
    {
        $headers = ['Phone', 'Notes'];
        $row     = ['+919999900000', 'some notes'];
        $result  = ColumnMapper::mapRow($headers, $row, ['Phone' => 'wa_number']);

        $this->assertArrayNotHasKey('Notes', $result);
        $this->assertSame('+919999900000', $result['wa_number']);
    }

    public function testMapRowHandlesMissingColumns(): void
    {
        // Row has fewer values than headers (truncated CSV line)
        $headers = ['Phone', 'Name', 'Email'];
        $row     = ['+919999900000'];  // only one value
        $result  = ColumnMapper::mapRow($headers, $row, [
            'Phone' => 'wa_number',
            'Name'  => 'name',
            'Email' => 'email',
        ]);

        $this->assertSame('+919999900000', $result['wa_number']);
        $this->assertSame('', $result['name']);
        $this->assertSame('', $result['email']);
    }
}
