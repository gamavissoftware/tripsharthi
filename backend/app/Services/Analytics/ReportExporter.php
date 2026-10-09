<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * Serialises delivery-log rows to CSV.
 *
 * Pure — no DB access — so the wire format is unit-testable without a database
 * (same split as Leads\ContactExporter).
 *
 * The export is the artefact that leaves the product: it gets mailed to a
 * client, opened in Excel, used to argue about a bill. So it carries the raw
 * timestamps and the full failure reason, not the prettified UI labels.
 */
final class ReportExporter
{
    /** Ordered map of row key => CSV column header. */
    public const COLUMNS = [
        'created_at'    => 'Queued At',
        'sent_at'       => 'Sent At',
        'contact_name'  => 'Name',
        'wa_number'     => 'WhatsApp Number',
        'campaign_name' => 'Campaign',
        'template_name' => 'Template',
        'variant'       => 'Variant',
        'direction'     => 'Direction',
        'type'          => 'Type',
        'category'      => 'Category',
        'status'        => 'Status',
        'delivered_at'  => 'Delivered At',
        'read_at'       => 'Read At',
        'replied_at'    => 'Replied At',
        'error'         => 'Failure Reason',
        'billable'      => 'Billable',
        'wa_message_id' => 'WhatsApp Message ID',
        'preview'       => 'Message',
    ];

    /**
     * @param list<array<string, mixed>> $rows Rows as shaped by MessageReportQuery.
     */
    public static function toCsv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');

        fputcsv($out, array_values(self::COLUMNS));

        foreach ($rows as $row) {
            $line = [];
            foreach (array_keys(self::COLUMNS) as $key) {
                $line[] = self::cell($key, $row[$key] ?? null);
            }
            fputcsv($out, $line);
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv === false ? '' : $csv;
    }

    /** A filename that says what the file holds without being opened. */
    public static function filename(string $prefix, array $filters): string
    {
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $prefix . '-' . MessageFilters::rangeLabel($filters));
        $slug = preg_replace('/-{2,}/', '-', (string) $slug);

        return trim((string) $slug, '-') . '-' . date('Ymd-His') . '.csv';
    }

    private static function cell(string $key, mixed $value): string
    {
        if ($key === 'billable') {
            return $value ? 'yes' : 'no';
        }

        if ($key === 'status') {
            // "read" alone reads as an instruction in a spreadsheet column;
            // spell the outcome the way the operator says it out loud.
            return match ((string) $value) {
                'read'      => 'Read',
                'delivered' => 'Delivered',
                'sent'      => 'Sent (not delivered)',
                'queued'    => 'Queued',
                'failed'    => 'Failed',
                default     => (string) $value,
            };
        }

        return $value === null ? '' : (string) $value;
    }
}
