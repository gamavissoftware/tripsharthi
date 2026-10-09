<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Crm\CrmEntityRegistry;
use App\Services\Crm\RecycleBinService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Recycle bin endpoints (Phase G6).
 *
 * GET  /crm/recycle/:entity           list soft-deleted rows
 * POST /crm/recycle/:entity/restore   { ids:[…] }   restore
 * POST /crm/recycle/:entity/purge     { ids:[…] }   permanent delete (owner/admin)
 */
class RecycleBinController extends ResourceController
{
    protected $format = 'json';

    public function index($entity = null): ResponseInterface
    {
        if (! CrmEntityRegistry::has((string) $entity)) {
            return $this->failNotFound("Unknown entity '{$entity}'.");
        }
        $rows = (new RecycleBinService())->deleted((string) $entity, CurrentUser::tenantId());
        return $this->respond([
            'success' => true,
            'data'    => $rows,
            'columns' => CrmEntityRegistry::get((string) $entity)['columns'],
        ]);
    }

    public function restore($entity = null): ResponseInterface
    {
        return $this->mutate((string) $entity, 'restore', false);
    }

    public function purge($entity = null): ResponseInterface
    {
        return $this->mutate((string) $entity, 'purge', true);
    }

    private function mutate(string $entity, string $action, bool $ownerOnly): ResponseInterface
    {
        if (! CrmEntityRegistry::has($entity)) {
            return $this->failNotFound("Unknown entity '{$entity}'.");
        }
        if ($ownerOnly) {
            $role = (string) (CurrentUser::get()['role'] ?? 'agent');
            if (! in_array($role, ['owner', 'admin'], true)) {
                return $this->failForbidden('Only owners and admins can permanently delete.');
            }
        }
        $ids = $this->request->getJsonVar('ids', true) ?? [];
        if (! is_array($ids) || empty($ids)) {
            return $this->fail(['ids' => 'Select at least one record.'], 422);
        }

        try {
            $result = (new RecycleBinService())->{$action}($entity, CurrentUser::tenantId(), $ids);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->respond(['success' => true, ...$result]);
    }
}
