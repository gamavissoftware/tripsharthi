<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Models\LeadImportModel;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Chunked import processor.
 *
 * The import runs in client-driven continuation:
 *   POST /imports/{id}/start     → processes first BATCH_SIZE rows
 *   POST /imports/{id}/continue  → processes next BATCH_SIZE rows
 *   …until status = 'done'
 *
 * The cursor stores a byte offset into the CSV file so each continuation
 * jump is O(1) — no row-skipping needed.  For Excel files the sheet is
 * loaded fully (PhpSpreadsheet has no streaming row-skip), so the cursor
 * is a row index instead (detected by 'cursor_type' stored in the import).
 *
 * To port to the Sprint 4 job queue: replace the controller call to
 * processBatch() with a job dispatch. The method signature is unchanged.
 */
class Importer
{
    public const BATCH_SIZE  = 500;
    public const MAX_ERRORS  = 500;

    private const UPLOAD_PATH = WRITEPATH . 'uploads/imports/';

    public function __construct(
        private LeadImportModel        $importModel,
        private ContactModel           $contactModel,
        private ContactFieldValueModel $cfvModel,
    ) {}

    // ------------------------------------------------------------------
    // File upload & preview
    // ------------------------------------------------------------------

    /**
     * Store the uploaded file, count rows, return preview.
     *
     * @return array{import_id:int, headers:string[], preview:array[], total:int}
     */
    public function storeAndPreview(
        \CodeIgniter\HTTP\Files\UploadedFile $file,
        int $tenantId,
        string $defaultCountryCode = ''
    ): array {
        $stored = $this->storeFile($file, $tenantId);

        [$headers, $preview, $total] = $this->readPreview($stored['path'], $stored['ext']);

        $importId = (int) $this->importModel
            ->withoutTenantScope()
            ->insert([
                'tenant_id'            => $tenantId,
                'original_filename'    => $file->getClientName(),
                'stored_filename'      => $stored['filename'],
                'default_country_code' => $defaultCountryCode ?: null,
                'headers'              => json_encode($headers),
                'total'                => $total,
                'cursor'               => 0,
                'status'               => 'pending',
            ], true);

        return [
            'import_id' => $importId,
            'headers'   => $headers,
            'preview'   => $preview,
            'total'     => $total,
        ];
    }

    /**
     * Save the column mapping on the import record.
     *
     * @return array{ok:bool, errors:string[]}
     */
    public function saveMapping(int $importId, int $tenantId, array $mapping): array
    {
        $validation = ColumnMapper::validate($mapping);
        if (! $validation['ok']) {
            return $validation;
        }

        $this->importModel->setTenant($tenantId)->update($importId, [
            'mapping' => json_encode($mapping),
            'status'  => 'mapped',
        ]);

        return ['ok' => true, 'errors' => []];
    }

    // ------------------------------------------------------------------
    // Batch processing (identical logic for /start and /continue)
    // ------------------------------------------------------------------

    /**
     * Process up to BATCH_SIZE rows from where the cursor left off.
     *
     * @return array{status:string, imported:int, updated:int, failed:int, total:int, cursor:int}
     */
    public function processBatch(int $importId, int $tenantId): array
    {
        $import = $this->importModel->setTenant($tenantId)->find($importId);
        if ($import === null) {
            throw new \RuntimeException("Import #{$importId} not found for tenant {$tenantId}.");
        }

        if (! in_array($import['status'], ['mapped', 'processing'], true)) {
            throw new \RuntimeException("Import #{$importId} is not in a processable state (status: {$import['status']}).");
        }

        $mapping     = json_decode($import['mapping'] ?? '{}', true) ?: [];
        $headers     = json_decode($import['headers']  ?? '[]', true) ?: [];
        $countryCode = $import['default_country_code'] ?? '';
        $cursor      = (int) $import['cursor'];
        $imported    = (int) $import['imported'];
        $updated     = (int) $import['updated_count'];
        $failed      = (int) $import['failed'];
        $total       = (int) $import['total'];
        $errors      = json_decode($import['errors'] ?? '[]', true) ?: [];

        $filePath = self::UPLOAD_PATH . $import['stored_filename'];
        $ext      = strtolower(pathinfo($import['stored_filename'], PATHINFO_EXTENSION));

        $dedupeService = new ContactDedupeService($this->contactModel, $this->cfvModel);

        if ($ext === 'csv') {
            [$imported, $updated, $failed, $errors, $cursor] = $this->processCsvBatch(
                $filePath, $headers, $mapping, $countryCode,
                $cursor, $imported, $updated, $failed, $errors, $tenantId, $dedupeService
            );
        } else {
            [$imported, $updated, $failed, $errors, $cursor] = $this->processXlsBatch(
                $filePath, $headers, $mapping, $countryCode,
                $cursor, $imported, $updated, $failed, $errors, $tenantId, $dedupeService
            );
        }

        $isDone = ($imported + $updated + $failed >= $total) || ($cursor >= $total);
        $status = $isDone ? 'done' : 'processing';

        $this->importModel->setTenant($tenantId)->update($importId, [
            'imported'      => $imported,
            'updated_count' => $updated,
            'failed'        => $failed,
            'errors'        => json_encode($errors),
            'cursor'        => $cursor,
            'status'        => $status,
        ]);

        return [
            'status'   => $status,
            'imported' => $imported,
            'updated'  => $updated,
            'failed'   => $failed,
            'total'    => $total,
            'cursor'   => $cursor,
        ];
    }

    // ------------------------------------------------------------------
    // CSV batch (byte-cursor; O(1) seek per continuation)
    // ------------------------------------------------------------------

    private function processCsvBatch(
        string $filePath,
        array  $headers,
        array  $mapping,
        string $countryCode,
        int    $cursor,        // byte offset
        int    $imported,
        int    $updated,
        int    $failed,
        array  $errors,
        int    $tenantId,
        ContactDedupeService $dedupe
    ): array {
        $handle = fopen($filePath, 'r');

        if ($cursor === 0) {
            // First batch: skip the header line, record byte position after it
            fgetcsv($handle);
            $cursor = (int) ftell($handle);
        } else {
            fseek($handle, $cursor);
        }

        $rowsThisBatch = 0;
        $rowNum        = $imported + $updated + $failed + 1;

        while ($rowsThisBatch < self::BATCH_SIZE) {
            $row = fgetcsv($handle);
            if ($row === false) {
                break;
            }
            // Skip entirely blank rows
            if (count(array_filter($row, static fn ($v) => trim($v) !== '')) === 0) {
                continue;
            }

            $data     = ColumnMapper::mapRow($headers, $row, $mapping);
            $waRaw    = $data['wa_number'] ?? '';
            $waNumber = WaNumberNormalizer::normalize($waRaw, $countryCode);

            if ($waNumber === '') {
                $failed++;
                if (count($errors) < self::MAX_ERRORS) {
                    $errors[] = [
                        'row'       => $rowNum,
                        'wa_number' => $waRaw,
                        'reason'    => 'Invalid or missing WhatsApp number.',
                    ];
                }
                $rowNum++;
                $rowsThisBatch++;
                continue;
            }

            $data['wa_number'] = $waNumber;
            // The mapping rarely includes a source column — reading the key
            // unconditionally raises "Undefined array key", which CI4 turns into a
            // thrown ErrorException and fails the whole import job.
            $data['source']    = ($data['source'] ?? '') ?: 'csv_import';

            try {
                $result = $dedupe->upsert($tenantId, $data);
                if ($result['action'] === 'updated') {
                    $updated++;
                } else {
                    $imported++;
                }
            } catch (\Throwable $e) {
                $failed++;
                if (count($errors) < self::MAX_ERRORS) {
                    $errors[] = [
                        'row'       => $rowNum,
                        'wa_number' => $waNumber,
                        'reason'    => $e->getMessage(),
                    ];
                }
            }

            $rowNum++;
            $rowsThisBatch++;
        }

        $cursor = (int) ftell($handle);
        fclose($handle);

        return [$imported, $updated, $failed, $errors, $cursor];
    }

    // ------------------------------------------------------------------
    // Excel batch (row-index cursor; PhpSpreadsheet has no streaming seek)
    // ------------------------------------------------------------------

    private function processXlsBatch(
        string $filePath,
        array  $headers,
        array  $mapping,
        string $countryCode,
        int    $cursor,        // data row index (0 = first data row after header)
        int    $imported,
        int    $updated,
        int    $failed,
        array  $errors,
        int    $tenantId,
        ContactDedupeService $dedupe
    ): array {
        $spreadsheet = IOFactory::load($filePath);
        $sheet       = $spreadsheet->getActiveSheet();
        $allRows     = $sheet->toArray(null, true, true, false);

        // Row 0 = headers; data starts at row 1
        $dataRows = array_slice($allRows, 1);
        $slice    = array_slice($dataRows, $cursor, self::BATCH_SIZE);
        $rowNum   = $cursor + 1;

        foreach ($slice as $row) {
            $rowArr   = array_values(array_map(static fn ($v) => $v === null ? '' : (string) $v, $row));
            $data     = ColumnMapper::mapRow($headers, $rowArr, $mapping);
            $waRaw    = $data['wa_number'] ?? '';
            $waNumber = WaNumberNormalizer::normalize($waRaw, $countryCode);

            if ($waNumber === '') {
                $failed++;
                if (count($errors) < self::MAX_ERRORS) {
                    $errors[] = ['row' => $rowNum, 'wa_number' => $waRaw, 'reason' => 'Invalid or missing WhatsApp number.'];
                }
                $rowNum++;
                continue;
            }

            $data['wa_number'] = $waNumber;
            // The mapping rarely includes a source column — reading the key
            // unconditionally raises "Undefined array key", which CI4 turns into a
            // thrown ErrorException and fails the whole import job.
            $data['source']    = ($data['source'] ?? '') ?: 'csv_import';

            try {
                $result = $dedupe->upsert($tenantId, $data);
                if ($result['action'] === 'updated') {
                    $updated++;
                } else {
                    $imported++;
                }
            } catch (\Throwable $e) {
                $failed++;
                if (count($errors) < self::MAX_ERRORS) {
                    $errors[] = ['row' => $rowNum, 'wa_number' => $waNumber, 'reason' => $e->getMessage()];
                }
            }

            $rowNum++;
        }

        $newCursor = $cursor + count($slice);
        return [$imported, $updated, $failed, $errors, $newCursor];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function storeFile(\CodeIgniter\HTTP\Files\UploadedFile $file, int $tenantId): array
    {
        $dir = self::UPLOAD_PATH . $tenantId . '/';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $file->getClientExtension();
        $file->move($dir, $filename);

        return [
            'path'     => $dir . $filename,
            'filename' => $tenantId . '/' . $filename,
            'ext'      => $file->getClientExtension(),
        ];
    }

    private function readPreview(string $path, string $ext): array
    {
        if ($ext === 'csv') {
            return $this->readCsvPreview($path);
        }
        return $this->readXlsPreview($path);
    }

    private function readCsvPreview(string $path): array
    {
        $handle  = fopen($path, 'r');
        $headers = fgetcsv($handle) ?: [];
        $headers = array_map('trim', $headers);

        $preview = [];
        $total   = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, static fn ($v) => trim($v) !== '')) === 0) {
                continue;
            }
            $total++;
            if (count($preview) < 10) {
                $keyed = [];
                foreach ($headers as $i => $h) {
                    $keyed[$h] = trim($row[$i] ?? '');
                }
                $preview[] = $keyed;
            }
        }

        fclose($handle);
        return [$headers, $preview, $total];
    }

    private function readXlsPreview(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet       = $spreadsheet->getActiveSheet();
        $allRows     = $sheet->toArray(null, true, true, false);

        $headers = array_map(static fn ($v) => trim((string) ($v ?? '')), array_shift($allRows) ?: []);
        $total   = 0;
        $preview = [];

        foreach ($allRows as $row) {
            $rowArr = array_values(array_map(static fn ($v) => $v === null ? '' : (string) $v, $row));
            if (count(array_filter($rowArr, static fn ($v) => $v !== '')) === 0) {
                continue;
            }
            $total++;
            if (count($preview) < 10) {
                $keyed = [];
                foreach ($headers as $i => $h) {
                    $keyed[$h] = trim($rowArr[$i] ?? '');
                }
                $preview[] = $keyed;
            }
        }

        return [$headers, $preview, $total];
    }
}
