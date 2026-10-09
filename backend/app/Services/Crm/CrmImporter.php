<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\LeadImportModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use RuntimeException;

/**
 * Generic CSV importer for non-contact CRM entities (Phase G4).
 *
 * Reuses the lead_imports progress table and the upload→preview→map→process
 * shape of the contact importer, but stays entirely separate from it: the
 * contact pipeline (wa_number normalisation, ContactDedupeService) is untouched.
 * Entity specifics come from ImportAdapters; field whitelisting comes from there
 * too, so an import can only ever write the columns an adapter declares.
 */
final class CrmImporter
{
    private const UPLOAD_PATH = WRITEPATH . 'uploads/imports/';
    private const MAX_ROWS    = 5000;
    private const MAX_ERRORS  = 200;

    public function __construct(private LeadImportModel $importModel = new LeadImportModel()) {}

    /**
     * Store the CSV, read a preview, and create a lead_imports row.
     *
     * @return array{import_id:int, headers:string[], preview:array, total:int, fields:array}
     */
    public function storeAndPreview(UploadedFile $file, int $tenantId, string $entity, ?int $customObjectId = null): array
    {
        if (! ImportAdapters::supports($entity)) {
            throw new RuntimeException("Import is not supported for '{$entity}'.");
        }
        if (strtolower($file->getClientExtension()) !== 'csv') {
            throw new RuntimeException('Only CSV files are supported for this importer.');
        }

        $stored = $this->storeFile($file, $tenantId);
        [$headers, $preview, $total] = $this->readCsvPreview($stored['path']);

        $adapter  = ImportAdapters::for($entity, ['tenantId' => $tenantId, 'customObjectId' => $customObjectId]);
        $importId = (int) $this->importModel->withoutTenantScope()->insert([
            'tenant_id'        => $tenantId,
            'entity_type'      => $entity,
            'custom_object_id' => $customObjectId,
            'original_filename'=> $file->getClientName(),
            'stored_filename'  => $stored['filename'],
            'headers'          => json_encode($headers),
            'total'            => $total,
            'cursor'           => 0,
            'status'           => 'pending',
        ], true);

        return ['import_id' => $importId, 'headers' => $headers, 'preview' => $preview, 'total' => $total, 'fields' => $adapter['fields']];
    }

    /** @return array{ok:bool, errors:string[]} */
    public function saveMapping(int $importId, int $tenantId, array $mapping): array
    {
        $import = $this->importModel->setTenant($tenantId)->find($importId);
        if ($import === null) {
            throw new RuntimeException("Import #{$importId} not found.");
        }
        $adapter = ImportAdapters::for($import['entity_type'], ['tenantId' => $tenantId, 'customObjectId' => $import['custom_object_id']]);

        // Every required field must be mapped to some column.
        $mapped  = array_values($mapping);
        $missing = array_diff($adapter['required'], $mapped);
        if ($missing) {
            return ['ok' => false, 'errors' => ['Map a column to: ' . implode(', ', $missing)]];
        }

        $this->importModel->setTenant($tenantId)->update($importId, ['mapping' => json_encode($mapping), 'status' => 'mapped']);
        return ['ok' => true, 'errors' => []];
    }

    /**
     * Process the whole file in one pass (synchronous; bounded by MAX_ROWS).
     *
     * @return array{status:string, imported:int, updated:int, failed:int, total:int, errors:array}
     */
    public function process(int $importId, int $tenantId): array
    {
        $import = $this->importModel->setTenant($tenantId)->find($importId);
        if ($import === null) {
            throw new RuntimeException("Import #{$importId} not found.");
        }
        if (! in_array($import['status'], ['mapped', 'processing'], true)) {
            throw new RuntimeException("Import #{$importId} is not ready to process (status: {$import['status']}).");
        }

        $entity  = (string) $import['entity_type'];
        $mapping = json_decode($import['mapping'] ?? '{}', true) ?: [];
        $headers = json_decode($import['headers'] ?? '[]', true) ?: [];
        $adapter = ImportAdapters::for($entity, ['tenantId' => $tenantId, 'customObjectId' => $import['custom_object_id']]);

        $imported = 0;
        $updated  = 0;
        $failed   = 0;
        $errors   = [];
        $rowNum   = 1;

        $handle = fopen(self::UPLOAD_PATH . $import['stored_filename'], 'r');
        fgetcsv($handle); // skip header

        while (($row = fgetcsv($handle)) !== false) {
            if ($rowNum > self::MAX_ROWS) {
                break;
            }
            if (count(array_filter($row, static fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }

            $vals = $this->mapRow($headers, $row, $mapping, $adapter['fields']);

            // Required-field check.
            $missing = array_filter($adapter['required'], static fn ($k) => trim((string) ($vals[$k] ?? '')) === '');
            if ($missing) {
                $failed++;
                if (count($errors) < self::MAX_ERRORS) {
                    $errors[] = ['row' => $rowNum, 'reason' => 'Missing required: ' . implode(', ', $missing)];
                }
                $rowNum++;
                continue;
            }

            try {
                $data = ($adapter['build'])($vals);
                $this->upsert($entity, $tenantId, $data, $adapter['dedupe']) === 'updated' ? $updated++ : $imported++;
            } catch (\Throwable $e) {
                $failed++;
                if (count($errors) < self::MAX_ERRORS) {
                    $errors[] = ['row' => $rowNum, 'reason' => $e->getMessage()];
                }
            }
            $rowNum++;
        }
        fclose($handle);

        $this->importModel->setTenant($tenantId)->update($importId, [
            'imported' => $imported, 'updated_count' => $updated, 'failed' => $failed,
            'errors' => json_encode($errors), 'status' => 'done',
        ]);

        return ['status' => 'done', 'imported' => $imported, 'updated' => $updated, 'failed' => $failed, 'total' => (int) $import['total'], 'errors' => $errors];
    }

    /** Insert, or update an existing row matched on the first present dedupe key. */
    private function upsert(string $entity, int $tenantId, array $data, array $dedupeKeys): string
    {
        foreach ($dedupeKeys as $key) {
            if (! empty($data[$key])) {
                $q = CrmEntityRegistry::model($entity, $tenantId)->where($key, $data[$key]);
                // Records dedupe WITHIN their object — never match a same-named row
                // belonging to a different custom object.
                if (isset($data['custom_object_id'])) {
                    $q->where('custom_object_id', $data['custom_object_id']);
                }
                $existing = $q->first();
                if ($existing) {
                    CrmEntityRegistry::model($entity, $tenantId)->update((int) $existing['id'], $data);
                    return 'updated';
                }
                break; // only dedupe on the first usable key
            }
        }
        CrmEntityRegistry::model($entity, $tenantId)->insert($data);
        return 'created';
    }

    /** @return array<string,string> field_key => value, restricted to the adapter's fields. */
    private function mapRow(array $headers, array $row, array $mapping, array $fields): array
    {
        $keyed = [];
        foreach ($headers as $i => $h) {
            $keyed[$h] = isset($row[$i]) ? trim((string) $row[$i]) : '';
        }
        $out = [];
        foreach ($mapping as $header => $fieldKey) {
            if (isset($fields[$fieldKey])) {   // whitelist: only known adapter fields
                $out[$fieldKey] = $keyed[$header] ?? '';
            }
        }
        return $out;
    }

    private function storeFile(UploadedFile $file, int $tenantId): array
    {
        $dir = self::UPLOAD_PATH . $tenantId . '/';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $file->getClientExtension();
        $file->move($dir, $filename);

        return ['path' => $dir . $filename, 'filename' => $tenantId . '/' . $filename];
    }

    private function readCsvPreview(string $path): array
    {
        $handle  = fopen($path, 'r');
        $headers = array_map('trim', fgetcsv($handle) ?: []);
        $preview = [];
        $total   = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, static fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $total++;
            if (count($preview) < 10) {
                $keyed = [];
                foreach ($headers as $i => $h) {
                    $keyed[$h] = trim((string) ($row[$i] ?? ''));
                }
                $preview[] = $keyed;
            }
        }
        fclose($handle);
        return [$headers, $preview, $total];
    }
}
