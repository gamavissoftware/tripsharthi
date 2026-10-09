<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ActivityModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Unified record timeline — CRM Phase A.
 *
 * GET /timeline?related_type=contact&related_id=123
 *   Merges the immutable activities log with the live WhatsApp thread (for
 *   contacts) into one newest-first feed, so the record page shows everything
 *   that ever happened in one place.
 *
 * POST /activities  — log a manual entry (call, meeting, note, email).
 */
class ActivitiesController extends ResourceController
{
    protected $format = 'json';

    private function model(): ActivityModel
    {
        return (new ActivityModel())->setTenant(CurrentUser::tenantId());
    }

    public function timeline(): ResponseInterface
    {
        $tenantId    = CurrentUser::tenantId();
        $relatedType = trim((string) $this->request->getGet('related_type'));
        $relatedId   = (int) $this->request->getGet('related_id');
        if ($relatedType === '' || $relatedId <= 0) {
            return $this->fail('related_type and related_id are required.', 422);
        }

        // 1. Logged activities (notes, calls, meetings, system, stage changes…)
        $feed = array_map(static function (array $a): array {
            return [
                'source'        => 'activity',
                'id'            => (int) $a['id'],
                'type'          => $a['type'],
                'subject'       => $a['subject'],
                'body'          => $a['body'],
                'actor_user_id' => $a['actor_user_id'] !== null ? (int) $a['actor_user_id'] : null,
                'meta'          => $a['meta'] ? json_decode($a['meta'], true) : null,
                'occurred_at'   => $a['occurred_at'],
            ];
        }, $this->model()->forRecord($tenantId, $relatedType, $relatedId, 200));

        // 2. The WhatsApp thread (contacts only) — joined via conversations.
        if ($relatedType === 'contact') {
            foreach ($this->contactMessages($tenantId, $relatedId) as $m) {
                $feed[] = [
                    'source'      => 'message',
                    'id'          => (int) $m['id'],
                    'type'        => ($m['direction'] === 'in') ? 'whatsapp_in' : 'whatsapp_out',
                    'subject'     => null,
                    'body'        => $m['body'],
                    'meta'        => ['message_type' => $m['type'], 'status' => $m['status']],
                    'occurred_at' => $m['sent_at'] ?: $m['created_at'],
                ];
            }
        }

        // 3. Merge newest-first.
        usort($feed, static fn ($a, $b) => strcmp((string) $b['occurred_at'], (string) $a['occurred_at']));

        return $this->respond(['success' => true, 'data' => $feed]);
    }

    public function create(): ResponseInterface
    {
        $rules = [
            'type'         => 'required|in_list[note,call,meeting,email,system]',
            'related_type' => 'required|max_length[40]',
            'related_id'   => 'required|is_natural_no_zero',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $id = $this->model()->log(
            CurrentUser::tenantId(),
            $this->request->getJsonVar('type'),
            $this->request->getJsonVar('related_type'),
            (int) $this->request->getJsonVar('related_id'),
            [
                'subject'       => $this->request->getJsonVar('subject'),
                'body'          => $this->request->getJsonVar('body'),
                'actor_user_id' => CurrentUser::id(),
                'occurred_at'   => $this->request->getJsonVar('occurred_at') ?: date('Y-m-d H:i:s'),
            ]
        );

        return $this->respondCreated(['success' => true, 'data' => $this->model()->find($id)]);
    }

    /** A contact's WhatsApp messages, via its conversations. */
    private function contactMessages(int $tenantId, int $contactId): array
    {
        return db_connect()->table('messages m')
            ->select('m.id, m.direction, m.type, m.body, m.status, m.created_at, m.sent_at')
            ->join('conversations c', 'c.id = m.conversation_id')
            ->where('c.tenant_id', $tenantId)
            ->where('c.contact_id', $contactId)
            ->orderBy('m.id', 'DESC')
            ->limit(200)
            ->get()->getResultArray();
    }
}
