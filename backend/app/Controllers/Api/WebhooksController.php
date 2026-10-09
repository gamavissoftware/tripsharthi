<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\WebhookSubscriptionModel;
use App\Services\Auth\CurrentUser;
use App\Services\Flow\JobDispatcher;
use App\Services\Webhooks\OutboundWebhookService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Manage outbound webhook subscriptions (Zapier / Make / custom CRM).
 */
class WebhooksController extends ResourceController
{
    protected $format = 'json';

    private function model(): WebhookSubscriptionModel
    {
        return (new WebhookSubscriptionModel())->setTenant(CurrentUser::tenantId());
    }

    public function index(): ResponseInterface
    {
        return $this->respond([
            'success'          => true,
            'data'             => $this->model()->orderBy('created_at', 'DESC')->findAll(100),
            'available_events' => OutboundWebhookService::EVENTS,
        ]);
    }

    public function create(): ResponseInterface
    {
        if (! $this->validate(['url' => 'required|valid_url_strict'])) {
            return $this->fail($this->validator->getErrors(), 422);
        }
        if (! \App\Services\Security\UrlGuard::isSafePublicUrl((string) $this->request->getJsonVar('url'))) {
            return $this->fail(['url' => 'URL must be a public http(s) endpoint (internal/loopback addresses are blocked).'], 422);
        }

        $events = $this->cleanEvents($this->request->getJsonVar('events'));

        $id = $this->model()->insert([
            'url'       => trim((string) $this->request->getJsonVar('url')),
            'secret'    => OutboundWebhookService::newSecret(),
            'events'    => json_encode($events),
            'is_active' => 1,
        ], true);

        return $this->respondCreated(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    public function update($id = null): ResponseInterface
    {
        $sub = $this->model()->find((int) $id);
        if (! $sub) {
            return $this->failNotFound("Webhook #{$id} not found.");
        }

        $payload = [];
        if ($this->request->getJsonVar('url') !== null) {
            if (! $this->validate(['url' => 'valid_url_strict'])) {
                return $this->fail($this->validator->getErrors(), 422);
            }
            if (! \App\Services\Security\UrlGuard::isSafePublicUrl((string) $this->request->getJsonVar('url'))) {
                return $this->fail(['url' => 'URL must be a public http(s) endpoint (internal/loopback addresses are blocked).'], 422);
            }
            $payload['url'] = trim((string) $this->request->getJsonVar('url'));
        }
        if ($this->request->getJsonVar('events') !== null) {
            $payload['events'] = json_encode($this->cleanEvents($this->request->getJsonVar('events')));
        }
        if ($this->request->getJsonVar('is_active') !== null) {
            $payload['is_active'] = $this->request->getJsonVar('is_active') ? 1 : 0;
        }

        if ($payload) {
            $this->model()->update((int) $id, $payload);
        }
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        if (! $this->model()->find((int) $id)) {
            return $this->failNotFound("Webhook #{$id} not found.");
        }
        $this->model()->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    // POST /api/v1/webhooks/:id/test — enqueue a ping delivery
    public function test($id = null): ResponseInterface
    {
        $sub = $this->model()->find((int) $id);
        if (! $sub) {
            return $this->failNotFound("Webhook #{$id} not found.");
        }

        JobDispatcher::dispatch(CurrentUser::tenantId(), 'webhook_deliver', [
            'subscription_id' => (int) $id,
            'event'           => 'ping',
            'data'            => ['message' => 'This is a test event from TravelPilot.'],
        ]);

        return $this->respond(['success' => true, 'message' => 'Test ping queued — check your endpoint shortly.']);
    }

    /** Keep only valid event names; default to all if none/invalid. */
    private function cleanEvents(mixed $events): array
    {
        if (! is_array($events)) {
            return ['*'];
        }
        $valid = array_values(array_intersect($events, array_merge(['*'], OutboundWebhookService::EVENTS)));
        return $valid ?: ['*'];
    }
}
