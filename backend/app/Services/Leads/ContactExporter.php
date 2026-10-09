<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\ContactModel;

/**
 * Builds a CSV export of contacts, honouring the same filters as the
 * contacts list view (status / source / search / tag).
 *
 * The DB read lives in ContactModel::exportRows(); the CSV serialisation
 * (ContactExporter::toCsv) is pure so it can be unit-tested without a DB.
 *
 * Synchronous export is capped at MAX_ROWS to keep memory bounded on the
 * 8GB dev box / Hostinger Cloud. Larger tenants can be moved to a queued
 * export later without changing the wire format.
 */
class ContactExporter
{
    /** Hard ceiling on a single synchronous export. */
    public const MAX_ROWS = 50000;

    /**
     * Ordered map of contact field key => CSV column header.
     */
    public const COLUMNS = [
        'wa_number'       => 'WhatsApp Number',
        'name'            => 'Name',
        'email'           => 'Email',
        'status'          => 'Status',
        'source'          => 'Source',
        'opt_in'          => 'Opted In',
        'tags'            => 'Tags',
        'last_inbound_at' => 'Last Inbound At',
        'created_at'      => 'Created At',
    ];

    public function __construct(
        private readonly ContactModel $contacts,
    ) {}

    /**
     * Resolve the matching rows for a tenant and serialise them to CSV.
     */
    public function export(int $tenantId, array $filters): string
    {
        $rows = $this->contacts
            ->setTenant($tenantId)
            ->exportRows($filters, self::MAX_ROWS);

        return self::toCsv($rows);
    }

    /**
     * Pure CSV serialiser — no DB access. Given a list of contact rows
     * (associative arrays), returns an RFC-4180 CSV string with a header line.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function toCsv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');

        // Header
        fputcsv($out, array_values(self::COLUMNS));

        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys(self::COLUMNS) as $key) {
                $line[] = self::cell($key, $row[$key] ?? '');
            }
            fputcsv($out, $line);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv === false ? '' : $csv;
    }

    /**
     * Normalise a single cell value for CSV output.
     */
    private static function cell(string $key, mixed $value): string
    {
        if ($key === 'opt_in') {
            return ((int) $value) === 1 ? 'yes' : 'no';
        }

        return $value === null ? '' : (string) $value;
    }
}
