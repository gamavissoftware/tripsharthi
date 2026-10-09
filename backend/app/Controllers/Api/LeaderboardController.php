<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Crm\LeaderboardService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Date-ranged sales leaderboard (Phase I4). GET /crm/leaderboard?from=&to=
 */
class LeaderboardController extends ResourceController
{
    protected $format = 'json';

    public function index(): ResponseInterface
    {
        $from = $this->request->getGet('from') ?: date('Y-m-01');
        $to   = $this->request->getGet('to') ?: date('Y-m-d');
        $rows = (new LeaderboardService())->ranking(CurrentUser::tenantId(), $from, $to);
        return $this->respond(['success' => true, 'data' => $rows, 'period' => ['from' => $from, 'to' => $to]]);
    }
}
