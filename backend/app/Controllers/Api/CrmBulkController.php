<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Crm\BulkActionService;
use App\Services\Crm\CrmEntityRegistry;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Bulk actions over a selection of CRM records (Phase G2).
 *
 * POST /crm/bulk/:entity  { ids:[…], action:'set'|'delete'|'add_tag'|'remove_tag', field?, value?, tag_id? }
 *
 * `delete` is restricted to owner/admin; all other actions are open to any agent.
 */
class CrmBulkController extends ResourceController
{
    protected $format = 'json';

    public function apply($entity = null): ResponseInterface
    {
        if (! CrmEntityRegistry::has((string) $entity)) {
            return $this->failNotFound("Unknown entity '{$entity}'.");
        }

        $ids    = $this->request->getJsonVar('ids', true) ?? [];
        $action = (string) $this->request->getJsonVar('action');
        if (! is_array($ids) || empty($ids)) {
            return $this->fail(['ids' => 'Select at least one record.'], 422);
        }

        if ($action === 'delete') {
            $role = (string) (CurrentUser::get()['role'] ?? 'agent');
            if (! in_array($role, ['owner', 'admin'], true)) {
                return $this->failForbidden('Only owners and admins can bulk delete.');
            }
        }

        try {
            $result = (new BulkActionService())->run(
                (string) $entity,
                CurrentUser::tenantId(),
                $ids,
                $action,
                [
                    'field'  => $this->request->getJsonVar('field'),
                    'value'  => $this->request->getJsonVar('value'),
                    'tag_id' => $this->request->getJsonVar('tag_id'),
                ],
            );
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->respond(['success' => true, 'affected' => $result['affected']]);
    }
}
