<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Tenancy\IndustryTemplateService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Industry templates (CRM Phase E) — one-click vertical setup.
 */
class IndustryTemplatesController extends ResourceController
{
    protected $format = 'json';

    // GET /industry-templates
    public function index(): ResponseInterface
    {
        return $this->respond(['success' => true, 'data' => (new IndustryTemplateService())->list()]);
    }

    // POST /industry-templates/:key/apply
    public function apply($key = null): ResponseInterface
    {
        try {
            $object = (new IndustryTemplateService())->apply(CurrentUser::tenantId(), (string) $key);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->respond(['success' => true, 'data' => $object, 'message' => "Applied — '{$object['label_plural']}' is ready."]);
    }
}
