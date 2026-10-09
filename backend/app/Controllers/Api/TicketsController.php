<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Libraries\OptionalId;

use App\Models\AccountModel;
use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Models\TicketModel;
use App\Services\Auth\CurrentUser;
use App\Services\Flow\FlowTriggerService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;
use App\Controllers\Api\Concerns\EnforcesEditAccess;

/**
 * Support tickets (Service CRM, Phase D). Status moves drive SLA + fire the
 * ticket_created / ticket_resolved flow triggers (e.g. CSAT over WhatsApp).
 */
class TicketsController extends ResourceController
{
    use EnforcesEditAccess;

    protected $format = 'json';

    private function model(): TicketModel
    {
        return (new TicketModel())->setTenant(CurrentUser::tenantId());
    }

    /** GET /tickets  ?status=open|pending|resolved|closed|all  ?owner=me  ?contact_id= */
    public function index(): ResponseInterface
    {
        $model     = $this->model();
        $status    = $this->request->getGet('status');
        $contactId = (int) $this->request->getGet('contact_id');

        if ($contactId > 0) {
            $model->where('contact_id', $contactId);
        }
        if ($status && $status !== 'all') {
            $model->where('status', $status);
        }
        if ($this->request->getGet('owner') === 'me') {
            $model->where('owner_id', CurrentUser::id());
        }

        return $this->respond(['success' => true, 'data' => $model->orderBy('updated_at', 'DESC')->findAll(500)]);
    }

    public function show($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $ticket   = $this->model()->find((int) $id);
        if (! $ticket) {
            return $this->failNotFound("Ticket #{$id} not found.");
        }
        $ticket['contact'] = $ticket['contact_id'] ? (new ContactModel())->setTenant($tenantId)->find((int) $ticket['contact_id']) : null;
        $ticket['account'] = $ticket['account_id'] ? (new AccountModel())->setTenant($tenantId)->find((int) $ticket['account_id']) : null;
        $ticket['sla_breached'] = $this->isBreached($ticket);
        return $this->respond(['success' => true, 'data' => $ticket]);
    }

    public function create(): ResponseInterface
    {
        if (! $this->validate(['subject' => 'required|max_length[255]'])) {
            return $this->fail($this->validator->getErrors(), 422);
        }
        $tenantId = CurrentUser::tenantId();
        $priority = $this->request->getJsonVar('priority') ?: 'medium';

        $source        = $this->request->getJsonVar('source') ?: 'manual';
        // OptionalId, not the raw value: an "Unassigned" <select> posts '',
        // which is not null, so it survived ?? and became owner_id = 0 — a
        // foreign key that cannot exist.
        $explicitOwner = OptionalId::from($this->request->getJsonVar('owner_id'));
        $autoOwner     = $explicitOwner === null
            ? (new \App\Services\Crm\AssignmentService())->assignee('ticket', $tenantId, ['priority' => $priority, 'source' => $source, 'status' => 'open'])
            : null;

        $payload = array_merge($this->payload(), [
            'priority'   => $priority,
            'status'     => 'open',
            'owner_id'   => $explicitOwner ?? $autoOwner ?? CurrentUser::id(),
            'source'     => $source,
            'sla_due_at' => (new \App\Services\Crm\BusinessHoursService())->slaDueAt($tenantId, $priority),
        ]);

        $model = $this->model();
        $id    = $model->insert($payload, true);
        if (! $id) {
            return $this->fail($model->errors() ?: 'Could not create ticket.', 422);
        }
        $ticket = $model->find((int) $id);

        (new ActivityModel())->log($tenantId, 'system', 'ticket', (int) $id, [
            'subject' => 'Ticket opened', 'actor_user_id' => CurrentUser::id(),
        ]);
        if ($autoOwner !== null) {
            (new ActivityModel())->log($tenantId, 'system', 'ticket', (int) $id, ['subject' => "Auto-assigned to user #{$autoOwner}"]);
        }
        FlowTriggerService::fire('ticket_created', $tenantId, (int) ($ticket['contact_id'] ?? 0), ['ticket_id' => (int) $id]);

        return $this->respondCreated(['success' => true, 'data' => $ticket]);
    }

    public function update($id = null): ResponseInterface
    {
        $model = $this->model();
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Ticket #{$id} not found.");
        }
        if ($deny = $this->denyIfReadOnly($model, (int) $id)) return $deny;
        $model->update((int) $id, $this->payload());
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    /** POST /tickets/:id/status  { status } */
    public function setStatus($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $ticket   = $this->model()->find((int) $id);
        if (! $ticket) {
            return $this->failNotFound("Ticket #{$id} not found.");
        }
        if ($deny = $this->denyIfReadOnly($this->model(), (int) $id)) return $deny;
        $status = (string) $this->request->getJsonVar('status');
        if (! in_array($status, ['open', 'pending', 'resolved', 'closed'], true)) {
            return $this->fail(['status' => 'Invalid status.'], 422);
        }

        $now    = date('Y-m-d H:i:s');
        $update = ['status' => $status];
        // Stamp resolution time when entering a closed state; clear it on reopen.
        if (in_array($status, ['resolved', 'closed'], true)) {
            $update['resolved_at'] = $ticket['resolved_at'] ?: $now;
        } else {
            $update['resolved_at'] = null;
        }
        $this->model()->update((int) $id, $update);

        $am = new ActivityModel();
        $am->log($tenantId, 'system', 'ticket', (int) $id, [
            'subject' => "Status → {$status}", 'actor_user_id' => CurrentUser::id(),
            'meta' => ['from' => $ticket['status'], 'to' => $status],
        ]);

        // CSAT-over-WhatsApp hook: fire ticket_resolved when first resolved.
        if ($status === 'resolved' && $ticket['status'] !== 'resolved') {
            FlowTriggerService::fire('ticket_resolved', $tenantId, (int) ($ticket['contact_id'] ?? 0), ['ticket_id' => (int) $id]);
        }

        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        $model = $this->model();
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Ticket #{$id} not found.");
        }
        if ($deny = $this->denyIfReadOnly($model, (int) $id)) return $deny;
        $model->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    private function isBreached(array $ticket): bool
    {
        return TicketModel::isOpenStatus($ticket['status'])
            && ! empty($ticket['sla_due_at'])
            && strtotime($ticket['sla_due_at']) < time();
    }

    private function payload(): array
    {
        $allowed = ['subject', 'description', 'contact_id', 'account_id', 'priority', 'owner_id', 'source', 'category', 'conversation_id'];
        $body = $this->request->getJSON(true) ?: [];
        return array_intersect_key($body, array_flip($allowed));
    }
}
