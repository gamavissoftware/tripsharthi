<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\CustomFieldModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

class CustomFieldsController extends ResourceController
{
    protected $format = 'json';

    public function index(): ResponseInterface
    {
        $model = (new CustomFieldModel())->setTenant(CurrentUser::tenantId());
        return $this->respond(['success' => true, 'data' => $model->allForTenant()]);
    }

    public function create(): ResponseInterface
    {
        $rules = [
            'label'     => 'required|max_length[100]',
            'field_key' => 'required|max_length[50]|regex_match[/^[a-z][a-z0-9_]*$/]',
            'type'      => 'required|in_list[text,number,date,url,select]',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $model   = (new CustomFieldModel())->setTenant(CurrentUser::tenantId());
        $options = $this->request->getJsonVar('options');
        $id      = $model->insert([
            'label'       => $this->request->getJsonVar('label'),
            'field_key'   => $this->request->getJsonVar('field_key'),
            'type'        => $this->request->getJsonVar('type'),
            'options'     => $options ? json_encode($options) : null,
            'is_required' => (bool) $this->request->getJsonVar('is_required'),
            'restricted'  => (bool) $this->request->getJsonVar('restricted'),
        ], true);

        return $this->respondCreated(['success' => true, 'data' => $model->find((int) $id)]);
    }

    public function update($id = null): ResponseInterface
    {
        $model = (new CustomFieldModel())->setTenant(CurrentUser::tenantId());
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Custom field #{$id} not found.");
        }

        $payload = array_filter([
            'label'       => $this->request->getJsonVar('label'),
            'is_required' => $this->request->getJsonVar('is_required'),
            'restricted'  => $this->request->getJsonVar('restricted'),
            'options'     => $this->request->getJsonVar('options')
                ? json_encode($this->request->getJsonVar('options')) : null,
        ], static fn ($v) => $v !== null);

        $model->setTenant(CurrentUser::tenantId())->update((int) $id, $payload);
        return $this->respond(['success' => true, 'data' => $model->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        $model = (new CustomFieldModel())->setTenant(CurrentUser::tenantId());
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Custom field #{$id} not found.");
        }
        $model->setTenant(CurrentUser::tenantId())->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }
}
