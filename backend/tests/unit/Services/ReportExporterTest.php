<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Analytics\ReportExporter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The CSV is the artefact that leaves the product — mailed to a client, opened
 * in Excel, used to argue about a bill. Its shape is a contract.
 */
class ReportExporterTest extends CIUnitTestCase
{
    public function testHeaderRowNamesEveryColumnInOrder(): void
    {
        $lines = self::lines(ReportExporter::toCsv([]));

        $this->assertCount(1, $lines, 'an empty report is still a valid CSV with headers');
        $this->assertSame(array_values(ReportExporter::COLUMNS), str_getcsv($lines[0]));
    }

    public function testARowCarriesTheRecipientAndTheOutcome(): void
    {
        $csv = ReportExporter::toCsv([[
            'created_at'   => '2026-09-10 10:00:00',
            'sent_at'      => '2026-09-10 10:00:03',
            'contact_name' => 'Asha Verma',
            'wa_number'    => '+919000000001',
            'campaign_name'=> 'Diwali Offer',
            'template_name'=> 'diwali_2026',
            'status'       => 'read',
            'read_at'      => '2026-09-10 10:42:00',
            'replied_at'   => '2026-09-10 12:00:00',
            'billable'     => true,
            'preview'      => 'Happy Diwali from Gamavis',
        ]]);

        $row = str_getcsv(self::lines($csv)[1]);
        $map = array_combine(array_keys(ReportExporter::COLUMNS), $row);

        $this->assertSame('Asha Verma', $map['contact_name']);
        $this->assertSame('+919000000001', $map['wa_number']);
        $this->assertSame('Read', $map['status']);
        $this->assertSame('2026-09-10 10:42:00', $map['read_at']);
        $this->assertSame('2026-09-10 12:00:00', $map['replied_at']);
        $this->assertSame('yes', $map['billable']);
    }

    public function testSentButNotDeliveredIsSpelledOutRatherThanLeftAsSent(): void
    {
        // In a spreadsheet, a bare "sent" reads as success. It is not: the
        // message left us and WhatsApp never confirmed it reached the handset.
        $csv = ReportExporter::toCsv([['status' => 'sent'], ['status' => 'failed']]);
        $rows = self::lines($csv);

        $this->assertContains('Sent (not delivered)', str_getcsv($rows[1]));
        $this->assertContains('Failed', str_getcsv($rows[2]));
    }

    public function testMissingFieldsBecomeEmptyCellsNotMissingColumns(): void
    {
        $row = str_getcsv(self::lines(ReportExporter::toCsv([['status' => 'queued']]))[1]);

        $this->assertCount(count(ReportExporter::COLUMNS), $row);
        $this->assertSame('', $row[array_search('contact_name', array_keys(ReportExporter::COLUMNS), true)]);
    }

    public function testFilenameSaysWhatTheFileHolds(): void
    {
        $name = ReportExporter::filename('campaign-Diwali Offer!', ['from' => '2026-09-01', 'to' => '2026-09-11']);

        $this->assertStringStartsWith('campaign-Diwali-Offer-2026-09-01_to_2026-09-11-', $name);
        $this->assertStringEndsWith('.csv', $name);
        $this->assertDoesNotMatchRegularExpression('/[^A-Za-z0-9._-]/', $name, 'filenames travel through HTTP headers');
    }

    /** @return list<string> */
    private static function lines(string $csv): array
    {
        return array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $csv)), static fn ($l) => $l !== ''));
    }
}
