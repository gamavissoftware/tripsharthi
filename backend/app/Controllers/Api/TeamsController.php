<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\TeamMemberModel;
use App\Models\TeamModel;
use App\Models\UserModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * CRM teams + membership (Phase K3). Owner/admin only for writes.
 *
 * GET    /crm/teams                       teams, each with member user-ids
 * POST   /crm/teams                       { name }
 * PUT    /crm/teams/:id                   { name }
 * DELETE /crm/teams/:id
 * POST   /crm/teams/:id/members           { user_id }
 * DELETE /crm/teams/:id/members/:userId
 */
class TeamsController extends ResourceController
{
    protected $format = 'json';

    private function tm(): TeamModel
    {
        return (new TeamModel())->setTenant(CurrentUser::tenantId());
    }
    private function mm(): TeamMemberModel
    {
        return (new TeamMemberModel())->setTenant(CurrentUser::tenantId());
    }
    private function denyAgent(): ?ResponseInterface
    {
        $role = (string) (CurrentUser::get()['role'] ?? 'agent');
        return in_array($role, ['owner', 'admin'], true) ? null : $this->failForbidden('Only owners and admins can manage teams.');
    }

    public function index(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $teams    = $this->tm()->orderBy('name', 'ASC')->findAll();
        $data = array_map(function ($t) use ($tenantId) {
            $t['member_ids'] = array_map('intval', array_column((new TeamMemberModel())->forTeam($tenantId, (int) $t['id']), 'user_id'));
            return $t;
        }, $teams);
        return $this->respond(['success' => true, 'data' => $data]);
    }

    public function create(): ResponseInterface
    {
        if ($deny = $this->denyAgent()) {
            return $deny;
        }
        $name = trim((string) $this->request->getJsonVar('name'));
        if ($name === '') {
            return $this->fail(['name' => 'A name is required.'], 422);
        }
        $id = $this->tm()->insert(['name' => $name], true);
        return $this->respondCreated(['success' => true, 'data' => $this->tm()->find((int) $id)]);
    }

    public function update($id = null): ResponseInterface
    {
        if ($deny = $this->denyAgent()) {
            return $deny;
        }
        if (! $this->tm()->find((int) $id)) {
            return $this->failNotFound('Team not found.');
        }
        if (($n = $this->request->getJsonVar('name')) !== null) {
            $this->tm()->update((int) $id, ['name' => trim((string) $n)]);
        }
        return $this->respond(['success' => true, 'data' => $this->tm()->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        if ($deny = $this->denyAgent()) {
            return $deny;
        }
        if (! $this->tm()->find((int) $id)) {
            return $this->failNotFound('Team not found.');
        }
        $this->tm()->delete((int) $id);
        $this->mm()->where('team_id', (int) $id)->delete();
        return $this->respondDeleted(['success' => true]);
    }

    public function addMember($teamId = null): ResponseInterface
    {
        if ($deny = $this->denyAgent()) {
            return $deny;
        }
        $tenantId = CurrentUser::tenantId();
        if (! $this->tm()->find((int) $teamId)) {
            return $this->failNotFound('Team not found.');
        }
        $userId = (int) $this->request->getJsonVar('user_id');
        if ($userId <= 0 || ! (new UserModel())->setTenant($tenantId)->find($userId)) {
            return $this->fail(['user_id' => 'A valid user is required.'], 422);
        }
        if (! $this->mm()->where('team_id', (int) $teamId)->where('user_id', $userId)->first()) {
            $this->mm()->insert(['team_id' => (int) $teamId, 'user_id' => $userId]);
        }
        return $this->respond(['success' => true]);
    }

    public function removeMember($teamId = null, $userId = null): ResponseInterface
    {
        if ($deny = $this->denyAgent()) {
            return $deny;
        }
        $this->mm()->where('team_id', (int) $teamId)->where('user_id', (int) $userId)->delete();
        return $this->respondDeleted(['success' => true]);
    }
}
