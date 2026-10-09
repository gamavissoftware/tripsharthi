<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Services\Leads\ColumnMapper;
use App\Services\Leads\ContactDedupeService;
use App\Services\Leads\WaNumberNormalizer;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Bulk contact import from a CSV, for files too large to push through the
 * browser's upload → map → chunked-continue cycle.
 *
 * It deliberately reuses the same ColumnMapper + ContactDedupeService the web
 * importer uses, so CLI and UI imports dedupe, tag and link companies
 * identically — there is no second set of import rules to keep in sync.
 *
 *   php spark contacts:import-csv \
 *       --file=/tmp/contacts.csv \
 *       --mapping=/tmp/mapping.json \
 *       --tenant=1 --country=+91 [--dry-run] [--limit=100]
 *
 * mapping.json maps CSV headers to targets, e.g.
 *   {"Contact No":"wa_number","Company Category":"tags","Company Name":"company"}
 * Valid targets are ColumnMapper::allowedTargets() plus any custom field key.
 */
class ContactsImportCsv extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'contacts:import-csv';
    protected $description = 'Bulk-import contacts from a CSV using a JSON column mapping.';
    protected $usage       = 'contacts:import-csv --file=FILE --mapping=FILE [--tenant=1] [--country=+91] [--dry-run] [--limit=N]';
    protected $options     = [
        '--file'    => 'Path to the CSV file (required).',
        '--mapping' => 'Path to a JSON file mapping CSV headers to contact fields (required).',
        '--tenant'  => 'Tenant id to import into. Default: 1.',
        '--country' => 'Default country code for bare numbers. Default: +91.',
        '--dry-run' => 'Parse and validate without writing anything.',
        '--limit'   => 'Stop after N data rows (useful for a trial run).',
    ];

    /** Progress heartbeat, in rows. */
    private const REPORT_EVERY = 500;

    /**
     * Read an option value, accepting both `--opt value` and `--opt=value`.
     *
     * CI4's CLI parser only understands the space-separated form; the `=` form
     * arrives as one unsplit argv entry and would otherwise be read as an empty
     * option, failing with a confusing "required" error despite being supplied.
     */
    private function opt(array $params, string $name, ?string $default = null): ?string
    {
        if (isset($params[$name]) && ! is_bool($params[$name])) {
            return (string) $params[$name];
        }

        $value = CLI::getOption($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $prefix = '--' . $name . '=';
        foreach (($_SERVER['argv'] ?? []) as $arg) {
            if (is_string($arg) && str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
        }

        return $default;
    }

    /** Presence-only flag, tolerant of `--flag`, `--flag=1` and `--flag true`. */
    private function flag(array $params, string $name): bool
    {
        if (array_key_exists($name, $params)) {
            return true;
        }
        if (CLI::getOption($name) !== null) {
            return true;
        }

        foreach (($_SERVER['argv'] ?? []) as $arg) {
            if ($arg === '--' . $name || (is_string($arg) && str_starts_with($arg, '--' . $name . '='))) {
                return true;
            }
        }

        return false;
    }

    public function run(array $params): void
    {
        $file    = (string) ($this->opt($params, 'file')          ?? '');
        $mapFile = (string) ($this->opt($params, 'mapping')       ?? '');
        $tenant  = (int)    ($this->opt($params, 'tenant', '1')   ?? 1);
        $country = (string) ($this->opt($params, 'country', '+91') ?? '+91');
        $limit   = (int)    ($this->opt($params, 'limit', '0')    ?? 0);
        $dryRun  = $this->flag($params, 'dry-run');

        if ($file === '' || ! is_readable($file)) {
            CLI::error("--file is required and must be readable: {$file}");
            return;
        }
        if ($mapFile === '' || ! is_readable($mapFile)) {
            CLI::error("--mapping is required and must be readable: {$mapFile}");
            return;
        }

        $mapping = json_decode((string) file_get_contents($mapFile), true);
        if (! is_array($mapping) || $mapping === []) {
            CLI::error('--mapping must contain a non-empty JSON object.');
            return;
        }

        $check = ColumnMapper::validate($mapping);
        if (! $check['ok']) {
            foreach ($check['errors'] as $err) {
                CLI::error($err);
            }
            return;
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            CLI::error("Could not open {$file}");
            return;
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            CLI::error('The CSV appears to be empty.');
            fclose($handle);
            return;
        }

        $missing = array_diff(array_keys($mapping), $headers);
        if ($missing !== []) {
            CLI::error('Mapping references headers absent from the CSV: ' . implode(', ', $missing));
            fclose($handle);
            return;
        }

        $dedupe = new ContactDedupeService(new ContactModel(), new ContactFieldValueModel());

        $inserted = $updated = $failed = $rows = 0;
        $errors   = [];
        $started  = microtime(true);

        CLI::write(sprintf(
            '[contacts:import-csv] %s → tenant %d%s',
            basename($file), $tenant, $dryRun ? '  (DRY RUN — nothing will be written)' : ''
        ), 'cyan');

        while (($row = fgetcsv($handle)) !== false) {
            if ($limit > 0 && $rows >= $limit) {
                break;
            }
            if (count(array_filter($row, static fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $rows++;
            $data  = ColumnMapper::mapRow($headers, $row, $mapping);
            $raw   = (string) ($data['wa_number'] ?? '');
            $waNum = WaNumberNormalizer::normalize($raw, $country);

            if ($waNum === '') {
                $failed++;
                if (count($errors) < 20) {
                    $errors[] = "row {$rows}: unusable number " . var_export($raw, true);
                }
                continue;
            }

            $data['wa_number'] = $waNum;
            $data['source']    = ($data['source'] ?? '') ?: 'csv_import';

            if ($dryRun) {
                $inserted++;
            } else {
                try {
                    $result = $dedupe->upsert($tenant, $data);
                    $result['action'] === 'updated' ? $updated++ : $inserted++;
                } catch (\Throwable $e) {
                    $failed++;
                    if (count($errors) < 20) {
                        $errors[] = "row {$rows} ({$waNum}): " . $e->getMessage();
                    }
                }
            }

            if ($rows % self::REPORT_EVERY === 0) {
                CLI::write("  … {$rows} rows  (+{$inserted} new, ~{$updated} updated, {$failed} skipped)", 'dark_gray');
            }
        }

        fclose($handle);

        CLI::write(sprintf(
            '[contacts:import-csv] done in %.1fs — %d rows: %d new, %d updated, %d skipped.',
            microtime(true) - $started, $rows, $inserted, $updated, $failed
        ), $failed > 0 ? 'yellow' : 'green');

        foreach ($errors as $err) {
            CLI::write('  ! ' . $err, 'yellow');
        }
        if ($failed > count($errors)) {
            CLI::write('  … and ' . ($failed - count($errors)) . ' more skipped rows.', 'yellow');
        }
    }
}
