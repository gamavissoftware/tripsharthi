<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\AuditLogModel;
use App\Models\UserModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Read-only, filterable, paginated audit trail (Phase K1). Owner/admin only.
 *
 * GET /crm/audit-logs?entity_type=&entity_id=&actor_user_id=&action=&page=&per_page=
 */
class AuditLogsController extends ResourceController
{
    protected $format = 'json';

    public function index(): ResponseInterface
    {
        $role = (string) (CurrentUser::get()['role'] ?? 'agent');
        if (! in_array($role, ['owner', 'admin'], true)) {
            return $this->failForbidden('Only owners and admins can view the audit log.');
        }

        $tenantId = CurrentUser::tenantId();
        $m        = (new AuditLogModel())->setTenant($tenantId);

        // TripSarthi support visits (and changes made during them) are internal: the workspace never sees them here. The admin app's Access log keeps the record.
        $m->where('impersonated_by', null)->whereNotIn('action', ['support.access.start', 'support.access.end']);

        foreach (['entity_type' => 'entity_type', 'action' => 'action', 'entity_id' => 'entity_id', 'actor_user_id' => 'actor_user_id'] as $param => $col) {
            $v = $this->request->getGet($param);
            if ($v !== null && $v !== '') {
                $m->where($col, $v);
            }
        }

        $perPage = max(1, min(100, (int) ($this->request->getGet('per_page') ?: 30)));
        $rows    = $m->orderBy('id', 'DESC')->paginate($perPage);
        $pager   = $m->pager?->getDetails() ?? ['total' => count($rows), 'currentPage' => 1, 'pageCount' => 1];

        // Attach actor names.
        $names = [];
        foreach ((new UserModel())->setTenant($tenantId)->findAll() as $u) {
            $names[(int) $u['id']] = $u['name'];
        }

        $data = array_map(static function ($r) use ($names) {
            return [
                'id'          => (int) $r['id'],
                'action'      => $r['action'],
                'entity_type' => $r['entity_type'],
                'entity_id'   => $r['entity_id'] !== null ? (int) $r['entity_id'] : null,
                // A change made by a TripSarthi team member inside this workspace is shown as such (never as the owner, never with the person's name).
                'actor'       => ($r['impersonated_by'] ?? null) !== null ? 'TripSarthi support' : ($r['actor_user_id'] !== null ? ($names[(int) $r['actor_user_id']] ?? ('User #' . $r['actor_user_id'])) : 'System'),
                'support'     => ($r['impersonated_by'] ?? null) !== null,
                'before'      => json_decode($r['before'] ?? 'null', true),
                'after'       => json_decode($r['after'] ?? 'null', true),
                'ip'          => ($r['impersonated_by'] ?? null) !== null ? null : $r['ip'],
                'created_at'  => $r['created_at'],
            ];
        }, $rows);

        return $this->respond([
            'success' => true,
            'data'    => $data,
            'pager'   => ['total' => $pager['total'] ?? count($data), 'page' => $pager['currentPage'] ?? 1, 'pageCount' => $pager['pageCount'] ?? 1],
        ]);
    }
}
