<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Leads\MetaLeadsArchiveService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * The leads Meta holds for a month, and importing them into Contacts.
 *
 * GET  /api/v1/meta-leads?month=2026-06
 * POST /api/v1/meta-leads/import  { month, leadgen_ids: [...] | null, start_flows: bool, tag: string }
 */
class MetaLeadsController extends ResourceController
{
    protected $format = 'json';

    public function month(): ResponseInterface
    {
        $month = trim((string) ($this->request->getGet('month') ?? ''));
        try {
            $data = (new MetaLeadsArchiveService())->month(CurrentUser::tenantId(), $month);
        } catch (\Throwable $e) {
            return $this->fail(['error' => $e->getMessage()], 422);
        }
        // field_data and payload are for import(); the page reads `answers`.
        foreach ($data['leads'] as &$l) {
            unset($l['field_data'], $l['payload']);
        }
        unset($l);

        return $this->respond(['success' => true, 'data' => $data]);
    }

    public function import(): ResponseInterface
    {
        $month = trim((string) ($this->request->getJsonVar('month') ?? ''));
        $ids   = $this->request->getJsonVar('leadgen_ids');
        $ids   = is_array($ids) ? array_map('strval', $ids) : null;
        $start = filter_var($this->request->getJsonVar('start_flows') ?? false, FILTER_VALIDATE_BOOLEAN);
        $tag   = (string) ($this->request->getJsonVar('tag') ?? '');
        try {
            $result = (new MetaLeadsArchiveService())->import(CurrentUser::tenantId(), $month, $ids, $start, $tag);
        } catch (\Throwable $e) {
            return $this->fail(['error' => $e->getMessage()], 422);
        }

        return $this->respond(['success' => true, 'data' => $result]);
    }
}
