<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Crm\ForecastService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Pipeline forecast + per-rep attainment (Phase I3).
 *
 * GET /crm/forecast                 weighted pipeline forecast (total + by owner)
 * GET /crm/forecast/attainment?from=&to=
 */
class ForecastController extends ResourceController
{
    protected $format = 'json';

    public function index(): ResponseInterface
    {
        return $this->respond(['success' => true, 'data' => (new ForecastService())->weightedForecast(CurrentUser::tenantId())]);
    }

    public function attainment(): ResponseInterface
    {
        $from = $this->request->getGet('from') ?: date('Y-m-01');
        $to   = $this->request->getGet('to') ?: date('Y-m-d');
        $rows = (new ForecastService())->attainment(CurrentUser::tenantId(), $from, $to);
        return $this->respond(['success' => true, 'data' => $rows, 'period' => ['from' => $from, 'to' => $to]]);
    }
}
