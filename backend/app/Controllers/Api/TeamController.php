<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\UserModel;
use App\Services\Auth\CurrentUser;
use App\Services\Billing\PlanLimitChecker;
use App\Services\Email\EmailService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Team member management — owner/admin only.
 *
 * GET    /api/v1/team              — list all users in this tenant
 * POST   /api/v1/team              — invite (create) a new team member
 * PATCH  /api/v1/team/:id/role     — change a member's role
 * DELETE /api/v1/team/:id          — remove a member (can't remove yourself)
 */
class TeamController extends ResourceController
{
    protected $format = 'json';

    private function model(): UserModel
    {
        return (new UserModel())->setTenant(CurrentUser::tenantId());
    }

    // GET /api/v1/team
    public function index(): ResponseInterface
    {
        $members = $this->model()->orderBy('role', 'ASC')->orderBy('name', 'ASC')->findAll();

        // Strip sensitive fields
        $safe = array_map(fn($u) => [
            'id'         => $u['id'],
            'name'       => $u['name'],
            'email'      => $u['email'],
            'role'       => $u['role'],
            'created_at' => $u['created_at'],
        ], $members);

        return $this->respond(['success' => true, 'data' => $safe]);
    }

    // POST /api/v1/team — invite a new member
    public function create(): ResponseInterface
    {
        $rules = [
            'name'     => 'required|max_length[255]',
            'email'    => 'required|valid_email|max_length[255]',
            'role'     => 'required|in_list[admin,agent]',  // owners can't be invited — only 1 owner per tenant
            'password' => 'required|min_length[8]',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $tenantId  = CurrentUser::tenantId();
        $email     = $this->request->getJsonVar('email');
        $name      = $this->request->getJsonVar('name');
        $userModel = new UserModel();

        // Enforce the plan's agent-seat limit before creating the user.
        $checker = new PlanLimitChecker();
        $seat    = $checker->check($tenantId, 'agents');
        if (! $seat['allowed']) {
            return $this->fail(
                $checker->limitExceededResponse('agents', $seat['current'], $seat['limit']),
                422
            );
        }

        // Check global email uniqueness
        if ($userModel->findByEmail($email) !== null) {
            return $this->fail(['email' => 'A user with this email already exists.'], 409);
        }

        $userId = $userModel->withoutTenantScope()->insert([
            'tenant_id'     => $tenantId,
            'name'          => $name,
            'email'         => $email,
            'password_hash' => password_hash($this->request->getJsonVar('password'), PASSWORD_DEFAULT),
            'role'          => $this->request->getJsonVar('role'),
        ], true);

        // Notify the new member
        $appUrl = EmailService::appUrl();
        EmailService::sendWelcome($email, $name, $appUrl);

        return $this->respondCreated([
            'success' => true,
            'data'    => [
                'id'    => $userId,
                'name'  => $name,
                'email' => $email,
                'role'  => $this->request->getJsonVar('role'),
            ],
        ]);
    }

    // PATCH /api/v1/team/:id/role
    public function updateRole(int $id): ResponseInterface
    {
        $rules = ['role' => 'required|in_list[admin,agent]'];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        // Can't change your own role
        if ($id === CurrentUser::id()) {
            return $this->fail('You cannot change your own role.', 422);
        }

        $member = $this->model()->find($id);
        if (! $member) return $this->failNotFound("Team member #{$id} not found.");

        // Can't change the owner's role
        if (($member['role'] ?? '') === 'owner') {
            return $this->fail('The owner role cannot be changed.', 422);
        }

        $this->model()->update($id, ['role' => $this->request->getJsonVar('role')]);

        return $this->respond(['success' => true, 'message' => 'Role updated.']);
    }

    // DELETE /api/v1/team/:id
    public function delete($id = null): ResponseInterface
    {
        $id = (int) $id;

        if ($id === CurrentUser::id()) {
            return $this->fail('You cannot remove yourself from the team.', 422);
        }

        $member = $this->model()->find($id);
        if (! $member) return $this->failNotFound("Team member #{$id} not found.");

        if (($member['role'] ?? '') === 'owner') {
            return $this->fail('The account owner cannot be removed.', 422);
        }

        $this->model()->delete($id);
        return $this->respondDeleted(['success' => true, 'message' => 'Team member removed.']);
    }
}
