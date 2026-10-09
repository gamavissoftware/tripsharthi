<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\AccountModel;
use App\Models\ContactModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;
use App\Controllers\Api\Concerns\EnforcesEditAccess;

/**
 * Accounts (companies) — CRM Phase A.
 */
class AccountsController extends ResourceController
{
    use EnforcesEditAccess;

    protected $format = 'json';

    private function model(): AccountModel
    {
        return (new AccountModel())->setTenant(CurrentUser::tenantId());
    }

    public function index(): ResponseInterface
    {
        $model = $this->model();

        if ($q = trim((string) $this->request->getGet('q'))) {
            $model->like('name', $q);
        }
        if ($owner = (int) $this->request->getGet('owner_id')) {
            $model->where('owner_id', $owner);
        }

        return $this->respond(['success' => true, 'data' => $model->orderBy('name', 'ASC')->findAll(500)]);
    }

    public function show($id = null): ResponseInterface
    {
        $account = $this->model()->find((int) $id);
        if (! $account) {
            return $this->failNotFound("Account #{$id} not found.");
        }

        $tenantId  = CurrentUser::tenantId();
        $contacts  = (new ContactModel())->setTenant($tenantId)
            ->where('account_id', (int) $id)
            ->orderBy('name', 'ASC')
            ->findAll(200);

        $account['contact_count'] = count($contacts);
        $account['contacts']      = $contacts;

        return $this->respond(['success' => true, 'data' => $account]);
    }

    public function create(): ResponseInterface
    {
        $rules = ['name' => 'required|max_length[255]', 'type' => 'permit_empty|in_list[prospect,customer,partner,other]'];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $model = $this->model();
        $id    = $model->insert($this->payload(), true);
        if (! $id) {
            return $this->fail($model->errors() ?: 'Could not create account.', 422);
        }
        $created = $model->find((int) $id);
        \App\Services\Crm\AuditLogger::log('created', 'account', (int) $id, null, $created);
        return $this->respondCreated(['success' => true, 'data' => $created]);
    }

    public function update($id = null): ResponseInterface
    {
        $model  = $this->model();
        $before = $model->find((int) $id);
        if (! $before) {
            return $this->failNotFound("Account #{$id} not found.");
        }
        if ($deny = $this->denyIfReadOnly($model, (int) $id)) return $deny;
        if (! $this->validate(['name' => 'permit_empty|max_length[255]', 'type' => 'permit_empty|in_list[prospect,customer,partner,other]'])) {
            return $this->fail($this->validator->getErrors(), 422);
        }
        $model->update((int) $id, $this->payload());
        $after = $this->model()->find((int) $id);
        \App\Services\Crm\AuditLogger::log('updated', 'account', (int) $id, $before, $after);
        return $this->respond(['success' => true, 'data' => $after]);
    }

    public function delete($id = null): ResponseInterface
    {
        $model  = $this->model();
        $before = $model->find((int) $id);
        if (! $before) {
            return $this->failNotFound("Account #{$id} not found.");
        }
        if ($deny = $this->denyIfReadOnly($model, (int) $id)) return $deny;
        $model->delete((int) $id);
        \App\Services\Crm\AuditLogger::log('deleted', 'account', (int) $id, $before, null);
        return $this->respondDeleted(['success' => true]);
    }

    /** Whitelisted writable fields from the JSON body. */
    private function payload(): array
    {
        $allowed = [
            'name', 'domain', 'industry', 'type', 'owner_id', 'phone', 'website',
            'address_line', 'city', 'state', 'country', 'postal_code',
            'annual_revenue', 'employee_count', 'parent_account_id',
        ];
        $body = $this->request->getJSON(true) ?: [];
        return array_intersect_key($body, array_flip($allowed));
    }
}
