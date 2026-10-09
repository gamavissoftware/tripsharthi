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
