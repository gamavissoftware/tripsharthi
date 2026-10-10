<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Crm\ReportService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Runs a saved/ad-hoc report spec and returns a chartable series (Phase I2).
 *
 * POST /crm/reports/run     { entity, metric, dimension, from?, to?, preset? }
 * GET  /crm/reports/options whitelist for the builder UI
 */
class ReportsController extends ResourceController
{
    protected $format = 'json';

    public function run(): ResponseInterface
    {
        $spec = [
            'entity'    => $this->request->getJsonVar('entity'),
            'metric'    => $this->request->getJsonVar('metric'),
            'dimension' => $this->request->getJsonVar('dimension'),
            'from'      => $this->request->getJsonVar('from'),
            'to'        => $this->request->getJsonVar('to'),
            'preset'    => $this->request->getJsonVar('preset'),
        ];
        try {
            $res = (new ReportService())->run(CurrentUser::tenantId(), $spec);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->respond(['success' => true, 'data' => $res]);
    }

    /**
     * POST /crm/reports/run-batch {specs: [spec, ...]} — runs many report specs in one request
     * (a dashboard has a dozen widgets; one call per widget trips the rate limiter).
     * Result order matches the input; a failing spec yields {error} and never fails the others.
     */
    public function runBatch(): ResponseInterface
    {
        $specs = $this->request->getJsonVar('specs', true);
        if (! is_array($specs) || $specs === []) {
            return $this->fail('specs must be a non-empty list', 422);
        }
        if (count($specs) > 40) {
            return $this->fail('At most 40 reports per request', 422);
        }
        $svc     = new ReportService();
        $tenant  = CurrentUser::tenantId();
        $results = [];
        foreach ($specs as $spec) {
            $spec = is_array($spec) ? $spec : [];
            $in   = [];
            foreach (['entity', 'metric', 'dimension', 'from', 'to', 'preset'] as $k) {
                $in[$k] = $spec[$k] ?? null;
            }
            try {
                $results[] = ['data' => $svc->run($tenant, $in)];
            } catch (\Throwable $e) {
                $results[] = ['error' => $e->getMessage()];
            }
        }
        return $this->respond(['success' => true, 'data' => $results]);
    }

    public function options(): ResponseInterface
    {
        return $this->respond(['success' => true, 'data' => ReportService::options()]);
    }

    /** GET /crm/reports/export — a report's series as a CSV download (Phase I5). */
    public function export(): ResponseInterface
    {
        $spec = [
            'entity'    => $this->request->getGet('entity'),
            'metric'    => $this->request->getGet('metric'),
            'dimension' => $this->request->getGet('dimension'),
            'from'      => $this->request->getGet('from'),
            'to'        => $this->request->getGet('to'),
            'preset'    => $this->request->getGet('preset'),
        ];
        try {
            $svc = new ReportService();
            $csv = $svc->toCsv($svc->run(CurrentUser::tenantId(), $spec));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $name = 'report-' . ($spec['entity'] ?? 'crm') . '-' . date('Ymd-His') . '.csv';
        return $this->response->setStatusCode(200)
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->setBody($csv);
    }
}
