<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\TemplateModel;
use App\Models\WabaAccountModel;
use App\Services\Auth\CurrentUser;
use App\Services\WhatsApp\TemplateApiClient;
use App\Services\WhatsApp\TemplateComponentBuilder;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Template management.
 *
 * GET    /api/v1/templates              — list (filter: meta_status, category)
 * POST   /api/v1/templates              — create (draft)
 * GET    /api/v1/templates/:id          — show
 * PUT    /api/v1/templates/:id          — update (only draft/rejected)
 * DELETE /api/v1/templates/:id          — soft-delete
 * POST   /api/v1/templates/:id/submit   — submit to Meta for approval
 * POST   /api/v1/templates/:id/sync     — sync approval status from Meta
 */
class TemplatesController extends ResourceController
{
    /**
     * Normalise the `variables` payload to a single-encoded JSON array.
     *
     * The UI posts an already-stringified array, so json_encode()-ing it again
     * stored "[\"customer_name\"]" — a JSON *string*. Consumers that
     * count($decoded) then hit a TypeError (PHP 8) instead of a variable list.
     */
    private static function encodeVariables(mixed $variables): ?string
    {
        if ($variables === null || $variables === '' || $variables === []) {
            return null;
        }

        if (is_string($variables)) {
            $decoded = json_decode($variables, true);
            if (is_array($decoded)) {
                return $decoded === [] ? null : json_encode($decoded);
            }
        }

        return json_encode($variables);
    }

    protected $format = 'json';

    private function model(): TemplateModel
    {
        return (new TemplateModel())->setTenant(CurrentUser::tenantId());
    }

    private function apiClient(): ?TemplateApiClient
    {
        $tenantId  = CurrentUser::tenantId();
        $wabaModel = new WabaAccountModel();
        $account   = $wabaModel->findActive($tenantId);
        if (! $account) return null;

        $rawToken = $wabaModel->getDecryptedToken($account);
        return new TemplateApiClient(
            is_array($account) ? ($account['waba_id'] ?? '') : ($account->waba_id ?? ''),
            $rawToken
        );
    }

    /**
     * Fetch a template from Meta by name and return the local fields to store
     * (meta_template_id, meta_status, rejection_reason), or null if not found.
     */
    private function syncByName(TemplateApiClient $client, string $name): ?array
    {
        $res = $client->listAll();
        if (! ($res['success'] ?? false)) return null;

        foreach ($res['templates'] ?? [] as $t) {
            if (($t['name'] ?? '') === $name) {
                return [
                    'meta_template_id' => (string) ($t['id'] ?? ''),
                    'meta_status'      => strtolower($t['status'] ?? 'pending'),
                    'rejection_reason' => $t['rejected_reason'] ?? null,
                ];
            }
        }
        return null;
    }

    // GET /api/v1/templates
    public function index(): ResponseInterface
    {
        $model = $this->model();

        if ($s = $this->request->getGet('meta_status')) {
            $model->where('meta_status', $s);
        }
        if ($c = $this->request->getGet('category')) {
            $model->where('category', $c);
        }

        return $this->respond([
            'success' => true,
            'data'    => $model->orderBy('display_name', 'ASC')->findAll(),
        ]);
    }

    // GET /api/v1/templates/:id
    public function show($id = null): ResponseInterface
    {
        $tpl = $this->model()->find((int) $id);
        if (! $tpl) return $this->failNotFound("Template #{$id} not found.");
        return $this->respond(['success' => true, 'data' => $tpl]);
    }

    // POST /api/v1/templates
    public function create(): ResponseInterface
    {
        $rules = [
            'name'     => 'required|max_length[512]',
            'body'     => 'required',
            'category' => 'required|in_list[marketing,utility,authentication]',
            'language' => 'required|max_length[10]',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $id = $this->model()->insert([
            'name'           => $this->request->getJsonVar('name'),
            'display_name'   => $this->request->getJsonVar('display_name') ?? $this->request->getJsonVar('name'),
            'language'       => $this->request->getJsonVar('language'),
            'category'       => $this->request->getJsonVar('category'),
            'header_type'    => $this->request->getJsonVar('header_type')    ?? 'none',
            'header_content' => $this->request->getJsonVar('header_content') ?? null,
            'body'           => $this->request->getJsonVar('body'),
            'footer'         => $this->request->getJsonVar('footer')         ?? null,
            'buttons'        => $this->request->getJsonVar('buttons')
                ? json_encode($this->request->getJsonVar('buttons')) : null,
            'cards'          => $this->request->getJsonVar('cards')
                ? json_encode($this->request->getJsonVar('cards')) : null,
            'variables'      => self::encodeVariables($this->request->getJsonVar('variables')),
        ], true);

        return $this->respondCreated(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    // PUT /api/v1/templates/:id
    public function update($id = null): ResponseInterface
    {
        $tpl = $this->model()->find((int) $id);
        if (! $tpl) return $this->failNotFound("Template #{$id} not found.");

        $editableStatuses = ['draft', 'rejected'];
        if (! in_array($tpl['meta_status'], $editableStatuses, true)) {
            return $this->fail("Only draft or rejected templates can be edited (current: {$tpl['meta_status']}).", 422);
        }

        $payload = array_filter([
            'name'           => $this->request->getJsonVar('name'),
            'display_name'   => $this->request->getJsonVar('display_name'),
            'language'       => $this->request->getJsonVar('language'),
            'category'       => $this->request->getJsonVar('category'),
            'header_type'    => $this->request->getJsonVar('header_type'),
            'header_content' => $this->request->getJsonVar('header_content'),
            'body'           => $this->request->getJsonVar('body'),
            'footer'         => $this->request->getJsonVar('footer'),
            'buttons'        => $this->request->getJsonVar('buttons')
                ? json_encode($this->request->getJsonVar('buttons')) : null,
            'cards'          => $this->request->getJsonVar('cards')
                ? json_encode($this->request->getJsonVar('cards')) : null,
            'variables'      => self::encodeVariables($this->request->getJsonVar('variables')),
        ], static fn ($v) => $v !== null);

        $this->model()->update((int) $id, $payload);
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    // DELETE /api/v1/templates/:id
    public function delete($id = null): ResponseInterface
    {
        $tpl = $this->model()->find((int) $id);
        if (! $tpl) return $this->failNotFound("Template #{$id} not found.");

        // Attempt deletion from Meta if it was submitted
        if (! empty($tpl['meta_template_id'])) {
            $client = $this->apiClient();
            if ($client) {
                $client->delete($tpl['name'], $tpl['meta_template_id']);
            }
        }

        $this->model()->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    // POST /api/v1/templates/:id/submit
    public function submit($id = null): ResponseInterface
    {
        $tpl = $this->model()->find((int) $id);
        if (! $tpl) return $this->failNotFound("Template #{$id} not found.");

        $editableStatuses = ['draft', 'rejected'];
        if (! in_array($tpl['meta_status'], $editableStatuses, true)) {
            return $this->fail("Template is already {$tpl['meta_status']} — cannot resubmit.", 422);
        }

        $client = $this->apiClient();
        if (! $client) {
            return $this->fail('No active WABA account configured.', 422);
        }

        // Media headers and carousel cards both need a Meta header_handle from
        // the resumable upload API — a plain URL is rejected at submission.
        $cards      = json_decode($tpl['cards'] ?? '[]', true) ?: [];
        $mediaTypes = ['image', 'video', 'document'];
        $needsUpload = $cards !== []
            || (in_array($tpl['header_type'] ?? 'none', $mediaTypes, true) && ! empty($tpl['header_content']));

        if ($needsUpload) {
            $wabaModel = new WabaAccountModel();
            $account   = $wabaModel->findActive(CurrentUser::tenantId());
            $appId     = is_array($account) ? ($account['app_id'] ?? '') : ($account->app_id ?? '');
            $token     = $wabaModel->getDecryptedToken($account);
            $uploader  = new \App\Services\WhatsApp\TemplateMediaUploader((string) $appId, (string) $token);

            // A media header carries its example the same way a card does. Without
            // this, header_content went to Meta as a raw URL and every image
            // template was rejected at submission.
            if ($cards === []) {
                $h = $uploader->handleForUrl((string) $tpl['header_content']);
                if (! $h['success']) {
                    return $this->fail('Header image: ' . $h['error'], 422);
                }
                $tpl['header_content'] = $h['handle'];
            }

            foreach ($cards as $i => $card) {
                $img = (string) ($card['image_url'] ?? '');
                if ($img === '') {
                    return $this->fail('Carousel card ' . ($i + 1) . ' is missing an image URL.', 422);
                }
                $h = $uploader->handleForUrl($img);
                if (! $h['success']) {
                    return $this->fail('Card ' . ($i + 1) . ': ' . $h['error'], 422);
                }
                $cards[$i]['_handle'] = $h['handle'];
            }
            $tpl['cards'] = json_encode($cards);
        }

        $components = TemplateComponentBuilder::forSubmission($tpl);
        $result     = $client->create([
            'name'       => $tpl['name'],
            'language'   => $tpl['language'],
            'category'   => strtoupper($tpl['category']),
            'components' => $components,
        ]);

        if (! $result['success']) {
            // A slow create() can succeed on Meta but time out our client; the
            // retry then hits "already exists". Recover by syncing the template
            // that Meta actually created, instead of surfacing a confusing error.
            if (stripos((string) ($result['error'] ?? ''), 'already') !== false) {
                $synced = $this->syncByName($client, $tpl['name']);
                if ($synced !== null) {
                    $this->model()->update((int) $id, $synced + ['submitted_at' => date('Y-m-d H:i:s')]);
                    return $this->respond(['success' => true, 'recovered' => true] + $synced);
                }
            }
            return $this->fail($result['error'] ?? 'Meta API submission failed.', 422);
        }

        $this->model()->update((int) $id, [
            'meta_template_id' => $result['meta_template_id'],
            'meta_status'      => $result['meta_status'],
            'submitted_at'     => date('Y-m-d H:i:s'),
            'rejection_reason' => null,
        ]);

        return $this->respond([
            'success'          => true,
            'meta_template_id' => $result['meta_template_id'],
            'meta_status'      => $result['meta_status'],
        ]);
    }

    // POST /api/v1/templates/:id/sync
    public function sync($id = null): ResponseInterface
    {
        $tpl = $this->model()->find((int) $id);
        if (! $tpl) return $this->failNotFound("Template #{$id} not found.");

        if (empty($tpl['meta_template_id'])) {
            return $this->fail('Template has not been submitted to Meta yet.', 422);
        }

        $client = $this->apiClient();
        if (! $client) {
            return $this->fail('No active WABA account configured.', 422);
        }

        $result = $client->syncStatus($tpl['meta_template_id']);
        if (! $result['success']) {
            return $this->fail($result['error'] ?? 'Meta API sync failed.', 422);
        }

        $this->model()->update((int) $id, array_filter([
            'meta_status'      => $result['meta_status'],
            'rejection_reason' => $result['rejection_reason'],
        ], static fn ($v) => $v !== null));

        return $this->respond([
            'success'     => true,
            'meta_status' => $result['meta_status'],
            'data'        => $this->model()->find((int) $id),
        ]);
    }
}
