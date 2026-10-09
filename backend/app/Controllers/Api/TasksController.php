<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\TaskModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Tasks — CRM Phase A. Supports record-attached tasks and a personal queue.
 */
class TasksController extends ResourceController
{
    protected $format = 'json';

    private function model(): TaskModel
    {
        return (new TaskModel())->setTenant(CurrentUser::tenantId());
    }

    /**
     * GET /tasks
     *   ?assigned=me               → my open tasks (soonest due first)
     *   ?related_type=&related_id= → tasks on a record
     *   ?status=open|done|all      → filter (default: open for record view)
     */
    public function index(): ResponseInterface
    {
        $tenantId    = CurrentUser::tenantId();
        $relatedType = trim((string) $this->request->getGet('related_type'));
        $relatedId   = (int) $this->request->getGet('related_id');

        if ($relatedType !== '' && $relatedId > 0) {
            $data = $this->model()->forRecord($tenantId, $relatedType, $relatedId);
            return $this->respond(['success' => true, 'data' => $data]);
        }

        if ($this->request->getGet('assigned') === 'me') {
            $data = $this->model()->openForUser($tenantId, CurrentUser::id());
            return $this->respond(['success' => true, 'data' => $data]);
        }

        // Fallback: tenant-wide list with optional status filter
        $model  = $this->model();
        $status = $this->request->getGet('status');
        if ($status && $status !== 'all') {
            $model->where('status', $status);
        }
        return $this->respond(['success' => true, 'data' => $model->orderBy('due_at', 'ASC')->findAll(500)]);
    }

    public function create(): ResponseInterface
    {
        if (! $this->validate(['title' => 'required|max_length[255]'])) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $payload = $this->payload();
        // Default assignee = current user; record creator.
        $payload['assigned_user_id'] = $payload['assigned_user_id'] ?? CurrentUser::id();
        $payload['created_by']       = CurrentUser::id();

        $model = $this->model();
        $id    = $model->insert($payload, true);
        if (! $id) {
            return $this->fail($model->errors() ?: 'Could not create task.', 422);
        }
        return $this->respondCreated(['success' => true, 'data' => $model->find((int) $id)]);
    }

    public function update($id = null): ResponseInterface
    {
        $model = $this->model();
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Task #{$id} not found.");
        }
        $model->update((int) $id, $this->payload());
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    /** POST /tasks/:id/complete */
    public function complete($id = null): ResponseInterface
    {
        $model = $this->model();
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Task #{$id} not found.");
        }
        $this->model()->markDone(CurrentUser::tenantId(), (int) $id);
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        $model = $this->model();
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Task #{$id} not found.");
        }
        $model->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    private function payload(): array
    {
        $allowed = [
            'title', 'description', 'type', 'status', 'priority',
            'due_at', 'reminder_at', 'assigned_user_id', 'related_type', 'related_id',
        ];
        $body = $this->request->getJSON(true) ?: [];
        return array_intersect_key($body, array_flip($allowed));
    }
}
