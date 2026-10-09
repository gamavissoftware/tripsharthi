<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\AccountModel;
use App\Models\ContactModel;
use App\Models\CustomObjectRecordModel;
use App\Models\DealModel;
use App\Models\MeetingModel;
use App\Models\RecordShareModel;
use App\Models\TicketModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Record sharing (Phase M). Grants a user or team access to a single record that
 * record-level visibility would otherwise hide.
 *
 * GET    /crm/{entityType}/{id}/shares
 * POST   /crm/{entityType}/{id}/shares     { grantee_type: user|team, grantee_id, access? }
 * DELETE /crm/{entityType}/{id}/shares/{shareId}
 *
 * Only the record owner or an owner/admin may manage a record's shares.
 */
class SharesController extends ResourceController
{
    protected $format = 'json';

    private const MODELS = [
        'deal'          => DealModel::class,
        'contact'       => ContactModel::class,
        'account'       => AccountModel::class,
        'ticket'        => TicketModel::class,
        'custom_record' => CustomObjectRecordModel::class,
        'meeting'       => MeetingModel::class,
    ];

    private function shares(): RecordShareModel
    {
        return (new RecordShareModel())->setTenant(CurrentUser::tenantId());
    }

    /** Load the record (visibility-scoped) and verify the actor may manage its shares. */
    private function authorizeRecord(string $entityType, int $id): array|ResponseInterface
    {
        if (! isset(self::MODELS[$entityType])) {
            return $this->failValidationErrors('Unknown entity type.');
        }
        $cls    = self::MODELS[$entityType];
        $record = (new $cls())->setTenant(CurrentUser::tenantId())->find($id);
        if ($record === null) {
            return $this->failNotFound('Record not found.');
        }
        $isOwner = (int) ($record['owner_id'] ?? 0) === CurrentUser::id();
        if (! $isOwner && ! CurrentUser::isPrivileged()) {
            return $this->failForbidden('Only the record owner or an admin can manage sharing.');
        }
        return $record;
    }

    public function listShares(string $entityType, int $id): ResponseInterface
    {
        $auth = $this->authorizeRecord($entityType, $id);
        if ($auth instanceof ResponseInterface) {
            return $auth;
        }
        $rows = $this->shares()
            ->where('entity_type', $entityType)->where('entity_id', $id)
            ->orderBy('id', 'ASC')->findAll();
        return $this->respond(['success' => true, 'data' => $rows]);
    }

    public function addShare(string $entityType, int $id): ResponseInterface
    {
        $auth = $this->authorizeRecord($entityType, $id);
        if ($auth instanceof ResponseInterface) {
            return $auth;
        }
        $granteeType = (string) $this->request->getJsonVar('grantee_type');
        $granteeId   = (int) $this->request->getJsonVar('grantee_id');
        $access      = (string) ($this->request->getJsonVar('access') ?: 'edit');

        if (! in_array($granteeType, ['user', 'team'], true) || $granteeId <= 0) {
            return $this->failValidationErrors('grantee_type must be user|team and grantee_id is required.');
        }
        if (! in_array($access, ['read', 'edit'], true)) {
            $access = 'edit';
        }

        // Idempotent: don't duplicate an existing grant — but DO update its access
        // level so re-sharing can upgrade read→edit (or downgrade edit→read).
        $existing = $this->shares()
            ->where('entity_type', $entityType)->where('entity_id', $id)
            ->where('grantee_type', $granteeType)->where('grantee_id', $granteeId)
            ->first();
        if ($existing !== null) {
            if (($existing['access'] ?? 'edit') !== $access) {
                $this->shares()->update((int) $existing['id'], ['access' => $access]);
                $existing['access'] = $access;
            }
            return $this->respond(['success' => true, 'data' => $existing]);
        }

        $shareId = (int) $this->shares()->insert([
            'entity_type'  => $entityType,
            'entity_id'    => $id,
            'grantee_type' => $granteeType,
            'grantee_id'   => $granteeId,
            'access'       => $access,
            'created_by'   => CurrentUser::id(),
        ], true);

        return $this->respondCreated(['success' => true, 'data' => $this->shares()->find($shareId)]);
    }

    public function removeShare(string $entityType, int $id, int $shareId): ResponseInterface
    {
        $auth = $this->authorizeRecord($entityType, $id);
        if ($auth instanceof ResponseInterface) {
            return $auth;
        }
        $share = $this->shares()->find($shareId);
        if ($share === null || $share['entity_type'] !== $entityType || (int) $share['entity_id'] !== $id) {
            return $this->failNotFound('Share not found.');
        }
        $this->shares()->delete($shareId);
        return $this->respondDeleted(['success' => true]);
    }
}
