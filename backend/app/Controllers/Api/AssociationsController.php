<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\AssociationModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Polymorphic associations (CRM Phase E) — link any record to any record.
 */
class AssociationsController extends ResourceController
{
    protected $format = 'json';

    /** GET /associations?type=contact&id=5 — links for a record, other side resolved. */
    public function index(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $type     = trim((string) $this->request->getGet('type'));
        $id       = (int) $this->request->getGet('id');
        if ($type === '' || $id <= 0) {
            return $this->fail('type and id are required.', 422);
        }

        $rows = (new AssociationModel())->forRecord($tenantId, $type, $id);
        $out  = [];
        foreach ($rows as $r) {
            // The "other" endpoint relative to the queried record.
            $isFrom    = ($r['from_type'] === $type && (int) $r['from_id'] === $id);
            $otherType = $isFrom ? $r['to_type'] : $r['from_type'];
            $otherId   = (int) ($isFrom ? $r['to_id'] : $r['from_id']);
            $out[] = [
                'id'         => (int) $r['id'],
                'label'      => $r['label'],
                'other_type' => $otherType,
                'other_id'   => $otherId,
                'other_name' => $this->resolveName($tenantId, $otherType, $otherId),
            ];
        }
        return $this->respond(['success' => true, 'data' => $out]);
    }

    /** POST /associations { from_type, from_id, to_type, to_id, label? } */
    public function create(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $fromType = trim((string) $this->request->getJsonVar('from_type'));
        $fromId   = (int) $this->request->getJsonVar('from_id');
        $toType   = trim((string) $this->request->getJsonVar('to_type'));
        $toId     = (int) $this->request->getJsonVar('to_id');
        if ($fromType === '' || $toType === '' || $fromId <= 0 || $toId <= 0) {
            return $this->fail('from_type, from_id, to_type, to_id are required.', 422);
        }

        (new AssociationModel())->link($tenantId, $fromType, $fromId, $toType, $toId, $this->request->getJsonVar('label'));
        return $this->respondCreated(['success' => true]);
    }

    /** DELETE /associations/:id */
    public function delete($id = null): ResponseInterface
    {
        $model = (new AssociationModel())->setTenant(CurrentUser::tenantId());
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Association #{$id} not found.");
        }
        $model->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    /** Display name for any record type (best-effort, tenant-scoped). */
    private function resolveName(int $tenantId, string $type, int $id): string
    {
        $db = db_connect();
        $p  = $db->DBPrefix;
        [$table, $col] = match ($type) {
            'contact'              => ['contacts', 'name'],
            'account'              => ['accounts', 'name'],
            'deal'                 => ['deals', 'title'],
            'ticket'               => ['tickets', 'subject'],
            'custom_object_record' => ['custom_object_records', 'name'],
            default                => [null, null],
        };
        if ($table === null) {
            return "#{$id}";
        }
        $row = $db->query("SELECT {$col} AS name FROM {$p}{$table} WHERE id=? AND tenant_id=? LIMIT 1", [$id, $tenantId])->getRowArray();
        return $row['name'] ?? "#{$id}";
    }
}
