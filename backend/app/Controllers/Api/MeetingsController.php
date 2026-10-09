<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\MeetingModel;
use App\Services\Auth\CurrentUser;
use App\Services\Flow\FlowTriggerService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;
use App\Controllers\Api\Concerns\EnforcesEditAccess;

/**
 * Meetings — Phase L native calendar. Schedule a meeting against a contact/deal;
 * agenda view by date range; booking fires the `meeting_scheduled` flow trigger.
 * Native only — no external calendar sync.
 */
class MeetingsController extends ResourceController
{
    use EnforcesEditAccess;

    protected $format = 'json';

    private function model(): MeetingModel
    {
        return (new MeetingModel())->setTenant(CurrentUser::tenantId());
    }

    /**
     * GET /meetings
     *   ?from=&to=            → agenda window (defaults: today → +30 days)
     *   ?contact_id=          → meetings for one contact
     */
    public function index(): ResponseInterface
    {
        $tenantId  = CurrentUser::tenantId();
        $contactId = (int) $this->request->getGet('contact_id');

        if ($contactId > 0) {
            return $this->respond(['success' => true, 'data' => $this->model()->forContact($tenantId, $contactId)]);
        }

        $from = trim((string) $this->request->getGet('from')) ?: date('Y-m-d 00:00:00');
        $to   = trim((string) $this->request->getGet('to'))   ?: date('Y-m-d 23:59:59', strtotime('+30 days'));

        return $this->respond(['success' => true, 'data' => $this->model()->agenda($tenantId, $from, $to)]);
    }

    public function show($id = null): ResponseInterface
    {
        $meeting = $this->model()->find((int) $id);
        if (! $meeting) {
            return $this->failNotFound("Meeting #{$id} not found.");
        }
        return $this->respond(['success' => true, 'data' => $meeting]);
    }

    public function create(): ResponseInterface
    {
        if (! $this->validate(['title' => 'required|max_length[255]', 'start_at' => 'required'])) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $payload = $this->payload();
        $payload['owner_id'] = $payload['owner_id'] ?? CurrentUser::id();

        $model = $this->model();
        $id    = $model->insert($payload, true);
        if (! $id) {
            return $this->fail($model->errors() ?: 'Could not create meeting.', 422);
        }

        // Fire the meeting_scheduled flow trigger against the linked contact (no-op if none).
        $contactId = (int) ($payload['contact_id'] ?? 0);
        if ($contactId > 0) {
            FlowTriggerService::fire('meeting_scheduled', CurrentUser::tenantId(), $contactId, [
                'meeting_id' => (int) $id,
                'deal_id'    => (int) ($payload['deal_id'] ?? 0),
                'start_at'   => $payload['start_at'] ?? null,
            ]);
        }

        return $this->respondCreated(['success' => true, 'data' => $model->find((int) $id)]);
    }

    public function update($id = null): ResponseInterface
    {
        $model = $this->model();
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Meeting #{$id} not found.");
        }
        if ($deny = $this->denyIfReadOnly($model, (int) $id)) return $deny;
        if (! $model->update((int) $id, $this->payload())) {
            return $this->fail($model->errors() ?: 'Could not update meeting.', 422);
        }
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        $model = $this->model();
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Meeting #{$id} not found.");
        }
        if ($deny = $this->denyIfReadOnly($model, (int) $id)) return $deny;
        $model->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    private function payload(): array
    {
        $allowed = ['title', 'contact_id', 'deal_id', 'owner_id', 'start_at', 'end_at', 'location', 'notes', 'status'];
        $body    = $this->request->getJSON(true) ?: [];
        return array_intersect_key($body, array_flip($allowed));
    }
}
