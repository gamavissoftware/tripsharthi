<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\PipelineStageModel;
use App\Models\UserModel;
use RuntimeException;

/**
 * The reporting engine (Phase I2). Runs a whitelisted aggregation — metric over a
 * dimension within a date range — for a CRM entity and returns a label/value
 * series the chart widgets render. Read-only; every metric/dimension is
 * whitelisted so untrusted input can never reach SQL.
 */
final class ReportService
{
    /** Per-entity whitelist: table, metrics, dimensions. */
    private const SPEC = [
        'deal' => [
            'table'      => 'deals',
            'metrics'    => ['count', 'sum_value', 'avg_value', 'win_rate'],
            'dimensions' => ['none', 'stage', 'owner', 'status', 'source', 'day', 'week', 'month'],
        ],
        'ticket' => [
            'table'      => 'tickets',
            'metrics'    => ['count'],
            'dimensions' => ['none', 'status', 'priority', 'owner', 'source', 'day', 'week', 'month'],
        ],
        'contact' => [
            'table'      => 'contacts',
            'metrics'    => ['count'],
            'dimensions' => ['none', 'lifecycle', 'source', 'owner', 'day', 'week', 'month'],
        ],
    ];

    /** Dimensions that bucket by time — sorted chronologically and rendered as a trend. */
    private const TIME_DIMENSIONS = ['day', 'week', 'month'];

    private const DIM_COLUMN = [
        'stage' => 'stage_id', 'owner' => 'owner_id', 'status' => 'status',
        'source' => 'source', 'priority' => 'priority', 'lifecycle' => 'lifecycle_stage',
    ];

    /**
     * @param array $spec {entity, metric, dimension, from, to, preset}
     * @return array{series:array<int,array{label:string,value:int}>, total:int, meta:array}
     */
    public function run(int $tenantId, array $spec, ?int $now = null): array
    {
        $now    = $now ?? time();
        $entity = (string) ($spec['entity'] ?? '');
        if (! isset(self::SPEC[$entity])) {
            throw new RuntimeException("Unknown report entity '{$entity}'.");
        }
        $cfg    = self::SPEC[$entity];
        $metric = in_array($spec['metric'] ?? '', $cfg['metrics'], true) ? $spec['metric'] : $cfg['metrics'][0];
        $dim    = in_array($spec['dimension'] ?? '', $cfg['dimensions'], true) ? $spec['dimension'] : 'none';

        [$from, $to] = $this->resolveRange($spec, $now);

        $db = db_connect();
        $b  = $db->table($cfg['table'])->where('tenant_id', $tenantId)->where('deleted_at', null);
        if ($from) {
            $b->where('created_at >=', $from . ' 00:00:00');
        }
        if ($to) {
            $b->where('created_at <=', $to . ' 23:59:59');
        }

        // Aggregate expression.
        $agg = match ($metric) {
            'sum_value' => 'COALESCE(SUM(value_amount),0)',
            'avg_value' => 'COALESCE(AVG(value_amount),0)',
            'win_rate'  => "ROUND(100.0 * SUM(CASE WHEN status='won' THEN 1 ELSE 0 END) / COUNT(*), 1)",
            default     => 'COUNT(*)',
        };

        if ($dim === 'none') {
            $val = (float) ($b->select("{$agg} AS v")->get()->getRow('v') ?? 0);
            return ['series' => [['label' => 'Total', 'value' => $this->cast($val)]], 'total' => $this->cast($val),
                'meta' => ['entity' => $entity, 'metric' => $metric, 'dimension' => $dim, 'from' => $from, 'to' => $to]];
        }

        $isTime = in_array($dim, self::TIME_DIMENSIONS, true);
        $col    = $isTime ? $this->timeBucketColumn($dim, $db) : self::DIM_COLUMN[$dim];
        // Time series sort chronologically (oldest→newest); categorical sort by value.
        $rows = $b->select("{$col} AS k, {$agg} AS v")
            ->groupBy($col)
            ->orderBy($isTime ? 'k' : 'v', $isTime ? 'ASC' : 'DESC')
            ->get()->getResultArray();

        $series = array_map(fn ($r) => ['label' => $this->label($dim, $r['k'], $tenantId), 'value' => $this->cast((float) $r['v'])], $rows);
        $total  = array_sum(array_column($series, 'value'));

        return ['series' => $series, 'total' => $total, 'meta' => ['entity' => $entity, 'metric' => $metric, 'dimension' => $dim, 'from' => $from, 'to' => $to]];
    }

    private function cast(float $v): int
    {
        return (int) round($v);
    }

    /**
     * DB-portable time-bucket expression on created_at. `day` and `month` use
     * plain string slicing (works everywhere); `week` needs a driver-specific
     * function (ISO year-week on MySQL, strftime on SQLite).
     */
    private function timeBucketColumn(string $dim, \CodeIgniter\Database\BaseConnection $db): string
    {
        if ($dim === 'day') {
            return 'substr(created_at,1,10)';   // YYYY-MM-DD
        }
        if ($dim === 'month') {
            return 'substr(created_at,1,7)';    // YYYY-MM
        }
        // week
        return ($db->DBDriver === 'SQLite3')
            ? "strftime('%Y-%W', created_at)"
            : "DATE_FORMAT(created_at, '%x-%v')";
    }

    /** Human label for a dimension bucket key. */
    private function label(string $dim, $key, int $tenantId): string
    {
        if ($key === null || $key === '') {
            return '—';
        }
        if ($dim === 'week') {
            return 'W' . str_replace('-', ' ', (string) $key); // "2026-24" → "W2026 24"
        }
        if (in_array($dim, self::TIME_DIMENSIONS, true)) {
            return (string) $key; // day/month bucket keys are already readable
        }
        if ($dim === 'owner') {
            $u = (new UserModel())->setTenant($tenantId)->find((int) $key);
            return $u['name'] ?? ('User #' . $key);
        }
        if ($dim === 'stage') {
            $s = (new PipelineStageModel())->setTenant($tenantId)->find((int) $key);
            return $s['name'] ?? ('Stage #' . $key);
        }
        return ucwords(str_replace('_', ' ', (string) $key));
    }

    /** @return array{0:?string,1:?string} [from, to] as Y-m-d or nulls (= all time). */
    private function resolveRange(array $spec, int $now): array
    {
        if (! empty($spec['from']) || ! empty($spec['to'])) {
            return [$spec['from'] ?? null, $spec['to'] ?? null];
        }
        $d = static fn ($ts) => date('Y-m-d', $ts);
        return match ($spec['preset'] ?? 'all') {
            'last_7'     => [$d($now - 7 * 86400), $d($now)],
            'last_30'    => [$d($now - 30 * 86400), $d($now)],
            'this_month' => [date('Y-m-01', $now), $d($now)],
            'ytd'        => [date('Y-01-01', $now), $d($now)],
            default      => [null, null],
        };
    }

    /** Expose the whitelist so the builder UI can offer valid choices. */
    public static function options(): array
    {
        return self::SPEC;
    }

    /** Serialize a run() result to CSV (Phase I5 export). */
    public function toCsv(array $result): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Label', ucfirst((string) ($result['meta']['metric'] ?? 'value'))]);
        foreach ($result['series'] as $row) {
            fputcsv($out, [CrmExporter::safeCell($row['label']), $row['value']]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }
}
