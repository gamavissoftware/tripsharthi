<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\NoteModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Notes — editable, pinnable annotations on any CRM record. CRM Phase A.
 */
class NotesController extends ResourceController
{
    protected $format = 'json';

    private function model(): NoteModel
    {
        return (new NoteModel())->setTenant(CurrentUser::tenantId());
    }

    /** Frontend route for a record a note hangs off (for notification deep-links). */
    private static function recordLink(string $relatedType, int $relatedId): ?string
    {
        return match ($relatedType) {
            'contact' => '/contacts/' . $relatedId,
            'deal'    => '/deals/' . $relatedId,
            'ticket'  => '/tickets/' . $relatedId,
            'account' => '/accounts',
            default   => null,
        };
    }

    /** GET /notes?related_type=contact&related_id=123 */
    public function index(): ResponseInterface
    {
        $relatedType = trim((string) $this->request->getGet('related_type'));
        $relatedId   = (int) $this->request->getGet('related_id');
        if ($relatedType === '' || $relatedId <= 0) {
            return $this->fail('related_type and related_id are required.', 422);
        }
        $data = $this->model()->forRecord(CurrentUser::tenantId(), $relatedType, $relatedId);
        return $this->respond(['success' => true, 'data' => $data]);
    }

    public function create(): ResponseInterface
    {
        $rules = [
            'body'         => 'required',
            'related_type' => 'required|max_length[40]',
            'related_id'   => 'required|is_natural_no_zero',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $tenantId    = CurrentUser::tenantId();
        $body        = (string) $this->request->getJsonVar('body');
        $relatedType = (string) $this->request->getJsonVar('related_type');
        $relatedId   = (int) $this->request->getJsonVar('related_id');

        $model = $this->model();
        $id    = $model->insert([
            'body'         => $body,
            'related_type' => $relatedType,
            'related_id'   => $relatedId,
            'is_pinned'    => (int) ($this->request->getJsonVar('is_pinned') ?? 0),
            'created_by'   => CurrentUser::id(),
        ], true);

        // Phase L: notify any teammates @mentioned in the note (never the author).
        $mentioned = (new \App\Services\Crm\MentionService())->mentionedUserIds($tenantId, $body, CurrentUser::id());
        if ($mentioned) {
            $author = (string) (CurrentUser::get()['name'] ?? 'Someone');
            $link   = self::recordLink($relatedType, $relatedId);
            foreach ($mentioned as $uid) {
                \App\Services\Crm\NotificationService::notify($tenantId, $uid, 'mention', "{$author} mentioned you in a note", $link);
            }
        }

        return $this->respondCreated(['success' => true, 'data' => $model->find((int) $id)]);
    }

    public function update($id = null): ResponseInterface
    {
        $model = $this->model();
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Note #{$id} not found.");
        }
        $payload = array_filter([
            'body'      => $this->request->getJsonVar('body'),
            'is_pinned' => $this->request->getJsonVar('is_pinned'),
        ], static fn ($v) => $v !== null);
        $model->update((int) $id, $payload);
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        $model = $this->model();
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Note #{$id} not found.");
        }
        $model->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }
}
