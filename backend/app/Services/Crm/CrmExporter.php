<?php

declare(strict_types=1);

namespace App\Services\Crm;

/**
 * Exports any registry entity's current (filtered) view to CSV (Phase G4).
 * Reuses the same whitelisted filter + search path as the list endpoint, so the
 * export honours exactly what the user sees. Read-only; messaging tables are not
 * in the registry and are never exported here.
 */
final class CrmExporter
{
    private const MAX_ROWS = 10000;

    /**
     * Neutralize CSV/spreadsheet formula injection: a cell starting with =, +, -,
     * @, or a control char is treated as a formula by Excel/Sheets, so prefix it
     * with a single quote. Shared by all CRM CSV exports.
     */
    public static function safeCell(mixed $value): string
    {
        $s = (string) $value;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $s;
        }
        return $s;
    }

    /**
     * @param array $filters FilterEngine spec {match, conditions}
     * @return string CSV body
     */
    public function export(string $entity, int $tenantId, array $filters = [], string $q = '', ?int $customObjectId = null): string
    {
        $cfg   = CrmEntityRegistry::get($entity);
        $model = CrmEntityRegistry::model($entity, $tenantId);

        if (in_array('custom_object_id', $cfg['requires'] ?? [], true) && $customObjectId) {
            $model->where('custom_object_id', $customObjectId);
        }

        $q = trim($q);
        if ($q !== '' && ! empty($cfg['searchable'])) {
            $model->groupStart();
            foreach (array_values($cfg['searchable']) as $i => $f) {
                $i === 0 ? $model->like($f, $q) : $model->orLike($f, $q);
            }
            $model->groupEnd();
        }

        (new FilterEngine())->apply($model, $filters, $cfg['filterable']);

        $columns = $cfg['columns'];
        $rows    = $model->orderBy('id', 'DESC')->findAll(self::MAX_ROWS);

        $out = fopen('php://temp', 'r+');
        fputcsv($out, $columns);
        foreach ($rows as $r) {
            fputcsv($out, array_map(static fn ($c) => self::safeCell($r[$c] ?? ''), $columns));
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
