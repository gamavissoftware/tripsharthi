<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Crm\DedupeMergeService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Duplicate detection + merge for contacts and accounts (Phase G5).
 *
 * GET  /crm/duplicates/:entity        groups of likely duplicates
 * POST /crm/merge/:entity             { primary_id, loser_ids:[…] }  (owner/admin)
 */
class DedupeController extends ResourceController
{
    protected $format = 'json';

    public function duplicates($entity = null): ResponseInterface
    {
        if (! DedupeMergeService::supports((string) $entity)) {
            return $this->fail("Merge is not supported for '{$entity}'.", 422);
        }
        $groups = (new DedupeMergeService())->findDuplicates((string) $entity, CurrentUser::tenantId());
        return $this->respond(['success' => true, 'data' => $groups, 'total' => count($groups)]);
    }

    public function merge($entity = null): ResponseInterface
    {
        if (! DedupeMergeService::supports((string) $entity)) {
            return $this->fail("Merge is not supported for '{$entity}'.", 422);
        }
        $role = (string) (CurrentUser::get()['role'] ?? 'agent');
        if (! in_array($role, ['owner', 'admin'], true)) {
            return $this->failForbidden('Only owners and admins can merge records.');
        }

        $primaryId = (int) $this->request->getJsonVar('primary_id');
        $losers    = $this->request->getJsonVar('loser_ids', true) ?? [];
        if ($primaryId <= 0 || ! is_array($losers) || empty($losers)) {
            return $this->fail(['merge' => 'primary_id and at least one loser_id are required.'], 422);
        }

        try {
            $res = (new DedupeMergeService())->merge((string) $entity, CurrentUser::tenantId(), $primaryId, $losers);
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->respond(['success' => true, ...$res]);
    }
}
