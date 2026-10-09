<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ActivityModel;
use App\Models\AssociationModel;
use App\Models\ContactModel;
use App\Models\CustomObjectFieldModel;
use App\Models\CustomObjectModel;
use App\Models\CustomObjectRecordModel;
use App\Services\Auth\CurrentUser;
use App\Services\Crm\CustomObjectValidator;
use App\Services\Flow\FlowTriggerService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;
use App\Controllers\Api\Concerns\EnforcesEditAccess;

/**
 * Records of a custom object (CRM Phase E). Field values are stored as JSON,
 * validated against the object's field schema — required fields plus per-type
 * checks (email/number/phone/date/boolean/select) via CustomObjectValidator.
 */
class CustomObjectRecordsController extends ResourceController
{
    use EnforcesEditAccess;

    protected $format = 'json';

    private function model(): CustomObjectRecordModel
    {
        return (new CustomObjectRecordModel())->setTenant(CurrentUser::tenantId());
    }

    /** GET /custom-objects/:id/records */
    public function listForObject($objectId = null): ResponseInterface
    {
        return $this->respond(['success' => true, 'data' => $this->model()->forObject(CurrentUser::tenantId(), (int) $objectId)]);
    }

    /** GET /custom-object-records/:id — record + its object + fields (for rendering). */
    public function show($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $record   = $this->model()->find((int) $id);
        if (! $record) {
            return $this->failNotFound("Record #{$id} not found.");
        }
        $record['data']   = json_decode($record['data'] ?? '{}', true) ?: [];
        $record['object'] = (new CustomObjectModel())->setTenant($tenantId)->find((int) $record['custom_object_id']);
        $record['fields'] = (new CustomObjectFieldModel())->forObject($tenantId, (int) $record['custom_object_id']);
        return $this->respond(['success' => true, 'data' => $record]);
    }

    /** POST /custom-objects/:id/records */
    public function create($objectId = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $object   = (new CustomObjectModel())->setTenant($tenantId)->find((int) $objectId);
        if (! $object) {
            return $this->failNotFound("Custom object #{$objectId} not found.");
        }

        $name = trim((string) $this->request->getJsonVar('name'));
        $data = $this->request->getJsonVar('data', true); // assoc array, not stdClass
        $data = is_array($data) ? $data : [];

        if ($name === '') {
            return $this->fail(['name' => 'A title/name is required.'], 422);
        }
        if ($fieldErrors = $this->validateFields($tenantId, (int) $objectId, $data)) {
            return $this->fail($fieldErrors, 422);
        }

        $model = $this->model();
        $id    = $model->insert([
            'custom_object_id' => (int) $objectId,
            'name'             => $name,
            'owner_id'         => CurrentUser::id(),
            'data'             => json_encode($data),
        ], true);
        if (! $id) {
            return $this->fail($model->errors() ?: 'Could not create record.', 422);
        }

        (new ActivityModel())->log($tenantId, 'system', 'custom_object_record', (int) $id, [
            'subject' => "{$object['label_singular']} created", 'actor_user_id' => CurrentUser::id(),
        ]);

        // Optionally link the record to a contact and fire the record_created
        // flow trigger against them. The flow engine is contact-centric, so a
        // record with no linked contact fires nothing (logged no-op).
        $contactId = (int) ($this->request->getJsonVar('contact_id') ?: 0);
        if ($contactId > 0 && (new ContactModel())->setTenant($tenantId)->find($contactId)) {
            (new AssociationModel())->link(
                $tenantId, 'custom_object_record', (int) $id, 'contact', $contactId, $object['label_singular'] ?? null
            );
            FlowTriggerService::fire('record_created', $tenantId, $contactId, [
                'custom_object_id' => (int) $objectId,
                'record_id'        => (int) $id,
                'object_api_name'  => $object['api_name'] ?? null,
            ]);
        }

        return $this->respondCreated(['success' => true, 'data' => $model->find((int) $id)]);
    }

    /** PUT /custom-object-records/:id */
    public function update($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $record   = $this->model()->find((int) $id);
        if (! $record) {
            return $this->failNotFound("Record #{$id} not found.");
        }
        if ($deny = $this->denyIfReadOnly($this->model(), (int) $id)) return $deny;

        $payload = [];
        if (($name = $this->request->getJsonVar('name')) !== null) {
            $payload['name'] = trim((string) $name);
        }
        $data = $this->request->getJsonVar('data', true);
        if (is_array($data)) {
            if ($fieldErrors = $this->validateFields($tenantId, (int) $record['custom_object_id'], $data)) {
                return $this->fail($fieldErrors, 422);
            }
            $payload['data'] = json_encode($data);
        }
        $this->model()->update((int) $id, $payload);
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        $model = $this->model();
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Record #{$id} not found.");
        }
        if ($deny = $this->denyIfReadOnly($model, (int) $id)) return $deny;
        $model->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    /**
     * Validate $data against the object's field schema (required + per-type).
     *
     * @return array<string,string> field_key => error message (empty = valid)
     */
    private function validateFields(int $tenantId, int $objectId, array $data): array
    {
        $fields = (new CustomObjectFieldModel())->forObject($tenantId, $objectId);
        return (new CustomObjectValidator())->validate($fields, $data);
    }
}
