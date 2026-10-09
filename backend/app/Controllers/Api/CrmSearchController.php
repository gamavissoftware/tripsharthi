<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Crm\SearchService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Global quick search across CRM entities (Phase G3). GET /crm/search?q=…
 */
class CrmSearchController extends ResourceController
{
    protected $format = 'json';

    public function index(): ResponseInterface
    {
        $q   = (string) $this->request->getGet('q');
        $res = (new SearchService())->search(CurrentUser::tenantId(), $q);

        return $this->respond(['success' => true, 'data' => $res['groups'], 'total' => $res['total']]);
    }
}
