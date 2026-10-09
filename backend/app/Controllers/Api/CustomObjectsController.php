<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\CustomObjectFieldModel;
use App\Models\CustomObjectModel;
use App\Models\CustomObjectRecordModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Custom objects + their fields (CRM Phase E). The multi-industry layer.
 */
class CustomObjectsController extends ResourceController
{
    protected $format = 'json';

    private function model(): CustomObjectModel
    {
        return (new CustomObjectModel())->setTenant(CurrentUser::tenantId());
    }

    // GET /custom-objects — list objects with field + record counts.
    public function index(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $objects  = $this->model()->orderBy('label_plural', 'ASC')->findAll();
        foreach ($objects as &$o) {
            $o['field_count']  = count((new CustomObjectFieldModel())->forObject($tenantId, (int) $o['id']));
            $o['record_count'] = (new CustomObjectRecordModel())->setTenant($tenantId)->where('custom_object_id', (int) $o['id'])->countAllResults();
        }
        return $this->respond(['success' => true, 'data' => $objects]);
    }

    // GET /custom-objects/:id — object + ordered fields.
    public function show($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $object   = $this->model()->find((int) $id);
        if (! $object) {
            return $this->failNotFound("Custom object #{$id} not found.");
        }
        $object['fields'] = (new CustomObjectFieldModel())->forObject($tenantId, (int) $id);
        return $this->respond(['success' => true, 'data' => $object]);
    }

    public function create(): ResponseInterface
    {
        $singular = trim((string) $this->request->getJsonVar('label_singular'));
        $plural   = trim((string) $this->request->getJsonVar('label_plural')) ?: ($singular ? $singular . 's' : '');
        $apiName  = trim((string) $this->request->getJsonVar('api_name')) ?: $this->slug($singular);

        if (! $this->validate(['label_singular' => 'required|max_length[80]'])) {
            return $this->fail($this->validator->getErrors(), 422);
        }
        // Phase K2: gate on the plan's custom-object limit (SaaS only).
        if (\App\Services\Tenancy\FeatureGate::isSaas()) {
            $chk = (new \App\Services\Billing\PlanLimitChecker())->check(CurrentUser::tenantId(), 'custom_objects');
            if (! $chk['allowed']) {
                return $this->fail((new \App\Services\Billing\PlanLimitChecker())->limitExceededResponse('custom_objects', $chk['current'], $chk['limit']), 422);
            }
        }
        if ($this->model()->findByApiName(CurrentUser::tenantId(), $apiName)) {
            return $this->fail(['api_name' => "An object named '{$apiName}' already exists."], 422);
        }

        $model = $this->model();
        $id    = $model->insert([
            'label_singular' => $singular,
            'label_plural'   => $plural,
            'api_name'       => $apiName,
            'icon'           => $this->request->getJsonVar('icon') ?: '📦',
            'color'          => $this->request->getJsonVar('color') ?: '#6366f1',
        ], true);
        if (! $id) {
            return $this->fail($model->errors() ?: 'Could not create object.', 422);
        }
        return $this->respondCreated(['success' => true, 'data' => $model->find((int) $id)]);
    }

    public function update($id = null): ResponseInterface
    {
        $model = $this->model();
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Custom object #{$id} not found.");
        }
        $payload = array_filter([
            'label_singular' => $this->request->getJsonVar('label_singular'),
            'label_plural'   => $this->request->getJsonVar('label_plural'),
            'icon'           => $this->request->getJsonVar('icon'),
            'color'          => $this->request->getJsonVar('color'),
        ], static fn ($v) => $v !== null);
        $model->update((int) $id, $payload);
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        $model = $this->model();
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Custom object #{$id} not found.");
        }
        $model->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    // ── Fields ──────────────────────────────────────────────────────────

    /** POST /custom-objects/:id/fields */
    public function addField($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        if (! $this->model()->find((int) $id)) {
            return $this->failNotFound("Custom object #{$id} not found.");
        }
        $label = trim((string) $this->request->getJsonVar('label'));
        if ($label === '') {
            return $this->fail(['label' => 'Field label is required.'], 422);
        }
        $key   = trim((string) $this->request->getJsonVar('field_key')) ?: $this->slug($label);
        $opts  = $this->request->getJsonVar('options', true);
        $fm    = new CustomObjectFieldModel();
        $count = count($fm->forObject($tenantId, (int) $id));

        $fieldId = $fm->setTenant($tenantId)->insert([
            'custom_object_id' => (int) $id,
            'field_key'        => $key,
            'label'            => $label,
            'type'             => $this->request->getJsonVar('type') ?: 'text',
            'options'          => is_array($opts) ? json_encode($opts) : null,
            'required'         => (int) ($this->request->getJsonVar('required') ?? 0),
            'position'         => $count,
        ], true);
        if (! $fieldId) {
            return $this->fail($fm->errors() ?: 'Could not add field.', 422);
        }
        return $this->respondCreated(['success' => true, 'data' => $fm->find((int) $fieldId)]);
    }

    /** DELETE /custom-object-fields/:id */
    public function deleteField($fieldId = null): ResponseInterface
    {
        $fm = (new CustomObjectFieldModel())->setTenant(CurrentUser::tenantId());
        if (! $fm->find((int) $fieldId)) {
            return $this->failNotFound("Field #{$fieldId} not found.");
        }
        $fm->delete((int) $fieldId);
        return $this->respondDeleted(['success' => true]);
    }

    private function slug(string $s): string
    {
        $s = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($s)));
        return trim((string) $s, '_') ?: 'object';
    }
}
