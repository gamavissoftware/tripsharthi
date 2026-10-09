<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Leads\ContactExporter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit tests for the pure CSV serialiser. No DB required.
 */
class ContactExporterTest extends CIUnitTestCase
{
    public function testEmptyExportStillEmitsHeaderRow(): void
    {
        $csv  = ContactExporter::toCsv([]);
        $rows = $this->parse($csv);

        $this->assertCount(1, $rows, 'Only the header row should be present');
        $this->assertSame(array_values(ContactExporter::COLUMNS), $rows[0]);
    }

    public function testRowIsSerialisedInColumnOrder(): void
    {
        $csv = ContactExporter::toCsv([[
            'wa_number'       => '+919999900001',
            'name'            => 'Asha Rao',
            'email'           => 'asha@example.com',
            'status'          => 'qualified',
            'source'          => 'csv_import',
            'opt_in'          => 1,
            'tags'            => 'VIP, Mumbai',
            'last_inbound_at' => '2026-06-01 10:00:00',
            'created_at'      => '2026-05-20 09:00:00',
        ]]);

        $rows = $this->parse($csv);
        $this->assertCount(2, $rows);
        $this->assertSame(
            ['+919999900001', 'Asha Rao', 'asha@example.com', 'qualified', 'csv_import', 'yes', 'VIP, Mumbai', '2026-06-01 10:00:00', '2026-05-20 09:00:00'],
            $rows[1],
        );
    }

    public function testOptInBooleanMapsToYesNo(): void
    {
        $csv  = ContactExporter::toCsv([
            ['wa_number' => '+1', 'opt_in' => 1],
            ['wa_number' => '+2', 'opt_in' => 0],
        ]);
        $rows = $this->parse($csv);

        $optInIdx = array_search('opt_in', array_keys(ContactExporter::COLUMNS), true);
        $this->assertSame('yes', $rows[1][$optInIdx]);
        $this->assertSame('no',  $rows[2][$optInIdx]);
    }

    public function testMissingAndNullFieldsBecomeEmptyStrings(): void
    {
        $csv  = ContactExporter::toCsv([['wa_number' => '+919999900002', 'name' => null]]);
        $rows = $this->parse($csv);

        // name (index 1) and all unspecified columns must be blank, not "null"
        $this->assertSame('+919999900002', $rows[1][0]);
        $this->assertSame('', $rows[1][1]);
        $this->assertSame('', $rows[1][2]);
    }

    public function testCommasAndQuotesAreEscaped(): void
    {
        $csv  = ContactExporter::toCsv([[
            'wa_number' => '+919999900003',
            'name'      => 'Doe, John "JD"',
        ]]);
        $rows = $this->parse($csv);

        // Round-trips back to the original value after CSV parsing
        $this->assertSame('Doe, John "JD"', $rows[1][1]);
    }

    /** Parse a CSV string into rows of arrays. */
    private function parse(string $csv): array
    {
        $rows = [];
        $fh   = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        while (($line = fgetcsv($fh)) !== false) {
            $rows[] = $line;
        }
        fclose($fh);
        return $rows;
    }
}
