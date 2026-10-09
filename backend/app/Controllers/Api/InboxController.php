<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Exceptions\WindowClosedException;
use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\PhoneNumberModel;
use App\Models\TemplateModel;
use App\Models\WabaAccountModel;
use App\Services\Auth\CurrentUser;
use App\Services\WhatsApp\BillableComputer;
use App\Services\WhatsApp\ProviderAdapter;
use App\Services\WhatsApp\TemplateComponentBuilder;
use App\Services\WhatsApp\WindowService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Shared team inbox.
 *
 * GET    /api/v1/inbox                       — paginated conversation list
 * GET    /api/v1/inbox/{id}                  — conversation detail + window state
 * GET    /api/v1/inbox/{id}/messages         — message history (?since= for polling)
 * POST   /api/v1/inbox/{id}/messages         — send reply (window-gated)
 * POST   /api/v1/inbox/{id}/upload-media     — upload media file, get back media_id
 * PATCH  /api/v1/inbox/{id}/assign           — assign to agent
 * PATCH  /api/v1/inbox/{id}/resolve          — resolve conversation
 * PATCH  /api/v1/inbox/{id}/reopen           — reopen conversation
 */
class InboxController extends ResourceController
{
    protected $format = 'json';

    private function convModel(): ConversationModel
    {
        return (new ConversationModel())->setTenant(CurrentUser::tenantId());
    }

    private function windowService(): WindowService
    {
        return new WindowService(new ConversationModel());
    }

    /**
     * Build a ProviderAdapter for the current tenant.
     * Returns the adapter or a ResponseInterface on error (check with is_array vs instanceof).
     *
     * @return ProviderAdapter|ResponseInterface
     */
    /**
     * Human-readable body to store for an outbound template message.
     *
     * The inbox composer posts template_id + variables (never body text), so the
     * message row needs the template body with {{n}} placeholders filled in —
     * otherwise agents see a blank bubble in the conversation history.
     */
    private function renderTemplateBody(?array $tpl, mixed $vars, string $templateName): string
    {
        $body = trim((string) ($tpl['body'] ?? ''));

        if ($body === '') {
            $name = $templateName !== '' ? $templateName : (string) ($tpl['name'] ?? 'template');
            return "[Template: {$name}]";
        }

        $vars = is_array($vars) ? array_values($vars) : [];
        foreach ($vars as $i => $value) {
            $body = str_replace('{{' . ($i + 1) . '}}', (string) $value, $body);
        }

        return $body;
    }

    private function buildClient(int $tenantId)
    {
        $wabaModel = new WabaAccountModel();
        $account   = $wabaModel->findActive($tenantId);

        if (! $account) {
            return $this->fail('No active WABA account configured.', 422);
        }

        $pn = (new PhoneNumberModel())->defaultForTenant($tenantId);
        if (! $pn) {
            return $this->fail('No phone number configured.', 422);
        }

        $client = $wabaModel->buildAdapter($account);
        if (! $client) {
            return $this->fail('Access token could not be decrypted.', 500);
        }

        return $client;
    }

    // ------------------------------------------------------------------

    // GET /api/v1/inbox
    public function index(): ResponseInterface
    {
        $filters  = [
            'status'           => $this->request->getGet('status'),
            'unread_only'      => $this->request->getGet('unread_only') === '1',
            'assigned_user_id' => $this->request->getGet('assigned_user_id'),
            'q'                => $this->request->getGet('q'),
            'category'         => $this->request->getGet('category'),
        ];
        $perPage       = (int) ($this->request->getGet('per_page') ?? 25);
        // Hold ONE model instance so the pager we read below is the one that
        // actually paginated (convModel() returns a fresh instance each call,
        // whose ->pager would be null → pagination metadata always missing).
        $model         = $this->convModel();
        $conversations = $model->listForInbox(CurrentUser::tenantId(), array_filter($filters), $perPage);
        $ws            = $this->windowService();

        // Enrich with window state (cheap — uses already-fetched data)
        foreach ($conversations as &$conv) {
            $conv['seconds_remaining'] = $ws->secondsRemainingForConversation($conv);
            $conv['send_mode']         = $ws->isOpenForConversation($conv) ? 'free_form' : 'template_only';
        }

        return $this->respond([
            'success' => true,
            'data'    => $conversations,
            'pager'   => $model->pager?->getDetails(),
        ]);
    }

    // GET /api/v1/inbox/{id}
    public function show($id = null): ResponseInterface
    {
        // findForInbox, not find(): the header shows the contact's company and
        // category, and a bare find() would return the conversation row alone —
        // blanking those fields the moment a thread is opened.
        $conv = $this->convModel()->findForInbox(CurrentUser::tenantId(), (int) $id);
        if (! $conv) {
            return $this->failNotFound("Conversation #{$id} not found.");
        }

        $ws   = $this->windowService();

        $conv['seconds_remaining'] = $ws->secondsRemainingForConversation($conv);
        $conv['send_mode']         = $ws->isOpenForConversation($conv) ? 'free_form' : 'template_only';

        return $this->respond(['success' => true, 'data' => $conv]);
    }

    // GET /api/v1/inbox/{id}/messages
    public function messages(int $id): ResponseInterface
    {
        $conv = $this->convModel()->find($id);
        if (! $conv) {
            return $this->failNotFound("Conversation #{$id} not found.");
        }

        $since    = $this->request->getGet('since') ?: null;
        $limit    = (int) ($this->request->getGet('limit') ?? 50);
        $messages = (new MessageModel())->forConversation(CurrentUser::tenantId(), $id, $since, $limit);

        return $this->respond(['success' => true, 'data' => MessageModel::withTemplateCards($messages)]);
    }

    // POST /api/v1/inbox/{id}/messages
    public function sendMessage(int $id): ResponseInterface
    {
        $conv = $this->convModel()->find($id);
        if (! $conv) {
            return $this->failNotFound("Conversation #{$id} not found.");
        }
        $conv = is_array($conv) ? $conv : (array) $conv;

        $msgType = $this->request->getJsonVar('type') ?? 'text';
        $body    = $this->request->getJsonVar('body') ?? $this->request->getJsonVar('content') ?? '';

        // ── Window gate ───────────────────────────────────────────────
        $messageModel = new MessageModel();
        $ws           = $this->windowService();

        if ($msgType !== 'template') {
            try {
                $ws->assertFreeFormAllowedForConversation($conv);
            } catch (WindowClosedException $e) {
                // Log the blocked attempt — every block must be auditable
                $messageModel->logBlocked(
                    CurrentUser::tenantId(),
                    $id,
                    (int) ($conv['contact_id'] ?? 0) ?: null,
                    $body,
                    'Policy block: 24-hour window closed. Use a template message.'
                );

                return $this->respond([
                    'success'          => false,
                    'code'             => 'WINDOW_CLOSED',
                    'message'          => $e->getMessage(),
                    'window_expires_at'=> $e->getWindowExpiresAt(),
                    'seconds_remaining'=> 0,
                ], 422);
            }
        }

        // ── Get WABA + phone number ───────────────────────────────────
        $tenantId = CurrentUser::tenantId();
        $client   = $this->buildClient($tenantId);

        if ($client instanceof ResponseInterface) {
            return $client;
        }

        // ── Send via Meta ─────────────────────────────────────────────
        $mediaTypes = ['image', 'document', 'video', 'audio'];

        if ($msgType === 'template') {
            $templateName = $this->request->getJsonVar('template_name') ?? '';
            $language     = $this->request->getJsonVar('language') ?? 'en';
            $components   = $this->request->getJsonVar('components');

            // Build Meta components from the supplied variable values when the
            // client didn't pass raw components. A template with body variables
            // MUST receive a matching number of params or Meta rejects the send
            // with "(#132000) Number of parameters does not match".
            if (empty($components)) {
                $templateId = (int) ($this->request->getJsonVar('template_id') ?? 0);
                $tpl        = $templateId ? (new TemplateModel())->setTenant($tenantId)->find($templateId) : null;

                if ($tpl !== null) {
                    // Guard the shape: a legacy double-encoded `variables`
                    // decodes to a string, and count() on that is a TypeError.
                    $expected = json_decode($tpl['variables'] ?? '[]', true);
                    $expected = is_array($expected) ? $expected : [];
                    $vars     = $this->request->getJsonVar('variables') ?? [];
                    $vars     = is_array($vars) ? array_values($vars) : [];

                    if (count($expected) > 0) {
                        $filled = array_filter($vars, static fn ($v) => trim((string) $v) !== '');
                        if (count($filled) < count($expected)) {
                            return $this->fail([
                                'variables' => 'This template has ' . count($expected)
                                    . ' variable(s) — fill them all before sending.',
                            ], 422);
                        }
                    }

                    $bodyParams = array_map(
                        static fn ($v) => ['type' => 'text', 'text' => (string) $v],
                        array_slice($vars, 0, count($expected))
                    );
                    $components = TemplateComponentBuilder::forSend($tpl, $bodyParams);
                } else {
                    $components = [];
                }
            }

            $sentTemplateId = (int) ($this->request->getJsonVar('template_id') ?? 0) ?: null;
            $result = $client->sendTemplate($conv['wa_number'], $templateName, $language, $components);
        } elseif (in_array($msgType, $mediaTypes, true)) {
            $mediaId  = $this->request->getJsonVar('media_id') ?? '';
            $caption  = $this->request->getJsonVar('caption') ?? '';
            $filename = $this->request->getJsonVar('filename') ?? '';

            if (empty($mediaId)) {
                return $this->fail(['media_id' => 'media_id is required for media messages.'], 422);
            }

            $result = $client->sendMedia($conv['wa_number'], $msgType, $mediaId, $caption, $filename);
        } else {
            if (empty($body)) {
                return $this->fail(['body' => 'Message body is required.'], 422);
            }
            $result = $client->sendText($conv['wa_number'], $body);
        }

        $status = $result['success'] ? 'sent' : 'failed';

        // ── Compute billable (Sprint 3 — BillableComputer wired here) ─
        $windowOpen = $ws->isOpenForConversation($conv);

        if ($msgType === 'template') {
            // Look up the template to get its category (required for billing)
            $templateId = (int) ($this->request->getJsonVar('template_id') ?? 0);
            $tplRow     = $templateId
                ? (new TemplateModel())->setTenant($tenantId)->find($templateId)
                : null;
            $category   = $tplRow['category'] ?? 'utility'; // safe default

            // The composer sends template_id/variables, not body text, so without
            // this the message row stores an empty body and the conversation
            // shows a blank bubble for every (paid) template send.
            if (trim((string) $body) === '') {
                $body = $this->renderTemplateBody(
                    $tplRow,
                    $this->request->getJsonVar('variables'),
                    (string) ($this->request->getJsonVar('template_name') ?? '')
                );
            }
        } else {
            $category = 'free_form';
        }

        // A send Meta rejected was never delivered — it must not be billed.
        $billable = $result['success'] ? BillableComputer::compute($category, $windowOpen) : 0;

        // ── Store message ─────────────────────────────────────────────
        $msgId = $messageModel->withoutTenantScope()->insert([
            'tenant_id'       => $tenantId,
            'contact_id'      => (int) ($conv['contact_id'] ?? 0) ?: null,
            'conversation_id' => $id,
            'direction'       => 'out',
            'type'            => $msgType === 'template' ? 'template' : $msgType,
            'template_id'     => $sentTemplateId ?? null,
            'category'        => $category,
            'body'            => $body,
            'wa_message_id'   => $result['message_id'],
            'status'          => $status,
            'billable'        => $billable,
            'error'           => $result['error'],
            'sent_at'         => $result['success'] ? date('Y-m-d H:i:s') : null,
        ], true);

        // Update conversation last_message_at
        (new ConversationModel())->withoutTenantScope()->update($id, [
            'last_message_at' => date('Y-m-d H:i:s'),
        ]);

        if (! $result['success']) {
            log_message('error', "InboxController::sendMessage failed for conv #{$id}: " . $result['error']);
            return $this->respond([
                'success' => false,
                'error'   => $result['error'],
            ], 422);
        }

        return $this->respond(['success' => true, 'data' => ['id' => $msgId, 'status' => $status]]);
    }

    // POST /api/v1/inbox/{id}/upload-media
    public function uploadMedia(int $id): ResponseInterface
    {
        $conv = $this->convModel()->find($id);
        if (! $conv) {
            return $this->failNotFound("Conversation #{$id} not found.");
        }

        // ── Validate uploaded file ────────────────────────────────────
        $file = $this->request->getFile('file');
        if (! $file || ! $file->isValid()) {
            return $this->fail('No valid file uploaded.', 422);
        }

        // Resolve the MIME type. PHP finfo reads file CONTENT, which mis-detects
        // Office Open XML files (.docx/.xlsx/.pptx are ZIP archives → "application/zip")
        // and legacy Office docs (→ "application/octet-stream" / "application/x-ole-storage").
        // For those known document extensions, trust the extension so WhatsApp-supported
        // documents aren't wrongly rejected.
        // https://developers.facebook.com/docs/whatsapp/cloud-api/reference/media
        $mime = $file->getMimeType();
        $ext  = strtolower($file->getClientExtension() ?: pathinfo((string) $file->getClientName(), PATHINFO_EXTENSION));
        $docMimeByExt = [
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt'  => 'text/plain',
            'csv'  => 'text/csv',
        ];
        if (isset($docMimeByExt[$ext]) && in_array($mime, [
            'application/zip', 'application/octet-stream', 'application/x-ole-storage',
            'text/plain', 'text/csv',
        ], true)) {
            $mime = $docMimeByExt[$ext];
        }

        $isAllowed = str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'video/')
            || str_starts_with($mime, 'audio/')
            || in_array($mime, $docMimeByExt, true);

        if (! $isAllowed) {
            return $this->fail(
                "Unsupported file type ({$mime}). Allowed: images, video, audio, PDF, "
                . "Word/Excel/PowerPoint, and text files.",
                422
            );
        }

        // ── Get WABA + phone number ───────────────────────────────────
        $tenantId = CurrentUser::tenantId();
        $client   = $this->buildClient($tenantId);

        if ($client instanceof ResponseInterface) {
            return $client;
        }

        // ── Upload to Meta (with the resolved MIME) ───────────────────
        $result = $client->uploadMedia($file->getTempName(), $mime);

        if (! $result['success']) {
            log_message('error', "InboxController::uploadMedia failed for conv #{$id}: " . ($result['error'] ?? 'unknown'));
            return $this->respond([
                'success' => false,
                'error'   => $result['error'] ?? 'Media upload failed.',
            ], 422);
        }

        return $this->respond([
            'success'   => true,
            'media_id'  => $result['media_id'],
            'mime_type' => $mime,
            'filename'  => $file->getName(),
        ]);
    }

    // PATCH /api/v1/inbox/{id}/assign
    public function assign(int $id): ResponseInterface
    {
        $conv = $this->convModel()->find($id);
        if (! $conv) {
            return $this->failNotFound("Conversation #{$id} not found.");
        }
        $userId = (int) ($this->request->getJsonVar('user_id') ?? 0);
        // Only assign to a user that belongs to this tenant (integrity guard).
        if ($userId > 0 && ! (new \App\Models\UserModel())->setTenant(CurrentUser::tenantId())->find($userId)) {
            return $this->fail(['user_id' => 'User not found in this workspace.'], 422);
        }
        $this->convModel()->update($id, ['assigned_user_id' => $userId ?: null]);
        return $this->respond(['success' => true]);
    }

    // PATCH /api/v1/inbox/{id}/resolve
    public function resolve(int $id): ResponseInterface
    {
        $conv = $this->convModel()->find($id);
        if (! $conv) {
            return $this->failNotFound("Conversation #{$id} not found.");
        }
        $this->convModel()->update($id, ['status' => 'resolved']);
        return $this->respond(['success' => true]);
    }

    // PATCH /api/v1/inbox/{id}/reopen
    public function reopen(int $id): ResponseInterface
    {
        $conv = $this->convModel()->find($id);
        if (! $conv) {
            return $this->failNotFound("Conversation #{$id} not found.");
        }
        $this->convModel()->update($id, ['status' => 'open']);
        return $this->respond(['success' => true]);
    }

    // GET /api/v1/inbox/media/{waMediaId}?token={api_token}
    // Proxies inbound media from Meta. Uses query-param auth because <img> tags
    // can't send Bearer headers. The token is validated the same way as AuthFilter.
    public function proxyMedia(string $waMediaId): void
    {
        // This route is outside the `auth` filter so <img src="...?token="> tags
        // work, so authenticate here. The token is REQUIRED — a tokenless request
        // must be rejected, not fall through to tenant 0.
        $rawToken = $this->request->getGet('token') ?? '';
        $user     = $rawToken !== '' ? (new \App\Models\UserModel())->findByToken($rawToken) : null;
        if ($user === null) {
            http_response_code(401);
            echo 'Unauthorized.';
            return;
        }
        \App\Services\Auth\CurrentUser::set($user);

        $tenantId  = CurrentUser::tenantId();
        $wabaModel = new WabaAccountModel();
        $account   = $wabaModel->findActive($tenantId);

        if (! $account) {
            http_response_code(404);
            echo 'No WABA configured.';
            return;
        }

        $rawToken = $wabaModel->getDecryptedToken($account);
        if (empty($rawToken)) {
            http_response_code(500);
            echo 'Token error.';
            return;
        }

        $graphVersion = env('META_GRAPH_VERSION', 'v22.0');

        // Step 1: Get the media URL from Meta
        $ch = curl_init("https://graph.facebook.com/{$graphVersion}/{$waMediaId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$rawToken}"],
            CURLOPT_TIMEOUT_MS     => 8000,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) {
            http_response_code(404);
            echo 'Media not found.';
            return;
        }

        $meta     = json_decode($resp, true) ?? [];
        $mediaUrl = $meta['url'] ?? null;
        $mimeType = $meta['mime_type'] ?? 'application/octet-stream';

        if (! $mediaUrl) {
            http_response_code(404);
            echo 'No URL returned.';
            return;
        }

        // Step 2: Stream the actual file
        $ch = curl_init($mediaUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$rawToken}"],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT_MS     => 15000,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $fileData = curl_exec($ch);
        $fileCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($fileCode !== 200 || ! $fileData) {
            http_response_code(502);
            echo 'Failed to fetch media.';
            return;
        }

        header("Content-Type: {$mimeType}");
        header('Cache-Control: private, max-age=3600');
        header('Access-Control-Allow-Origin: *');
        // Flush CI4 output buffers so the file streams cleanly
        while (ob_get_level() > 0) ob_end_clean();
        echo $fileData;
    }

    // PATCH /api/v1/inbox/{id}/mark-read
    public function markRead(int $id): ResponseInterface
    {
        $conv = $this->convModel()->find($id);
        if (! $conv) {
            return $this->failNotFound("Conversation #{$id} not found.");
        }
        $this->convModel()->update($id, ['is_read' => 1, 'unread_count' => 0]);
        return $this->respond(['success' => true]);
    }

    // GET /api/v1/inbox/{id}/stream   — Server-Sent Events for real-time messages
    // Streams new messages as they arrive. Client sends ?since=<message_id> on reconnect.
    public function stream(int $id): void
    {
        // Verify conversation belongs to tenant (auth)
        $conv = $this->convModel()->find($id);
        if (! $conv) {
            http_response_code(404);
            echo "data: {\"error\":\"not_found\"}\n\n";
            return;
        }

        $tenantId  = CurrentUser::tenantId();
        $lastId    = (int) ($this->request->getGet('since') ?? 0);

        // SSE headers — bypass CI4's response object entirely
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');   // disable Nginx proxy buffering
        header('Access-Control-Allow-Origin: *');

        // Flush ALL output buffer levels (CI4 uses ob_start() internally)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        set_time_limit(60); // 60 s max per connection — client auto-reconnects

        $msgModel = (new MessageModel())->setTenant($tenantId);
        $wsModel  = new ConversationModel();
        $ws       = $this->windowService();
        $ticks    = 0;

        while (true) {
            // Fetch any messages newer than $lastId for this conversation
            // Re-scope model each iteration (CI4 model state can carry over)
            $rows = (new MessageModel())
                ->setTenant($tenantId)
                ->where('conversation_id', $id)
                ->where('id >', $lastId)
                ->orderBy('id', 'ASC')
                ->findAll(20);

            $rows = MessageModel::withTemplateCards($rows);
            foreach ($rows as $row) {
                $lastId = max($lastId, (int) $row['id']);
                $data   = json_encode($row);
                echo "event: message\n";
                echo "data: {$data}\n\n";
            }

            // Every 5 ticks (~5s) also push window state so the countdown stays live
            if ($ticks % 5 === 0) {
                $freshConv = $wsModel->setTenant($tenantId)->find($id);
                if ($freshConv) {
                    $freshConv = is_array($freshConv) ? $freshConv : (array) $freshConv;
                    $payload   = json_encode([
                        'seconds_remaining' => $ws->secondsRemainingForConversation($freshConv),
                        'send_mode'         => $ws->isOpenForConversation($freshConv) ? 'free_form' : 'template_only',
                        'status'            => $freshConv['status'] ?? 'open',
                    ]);
                    echo "event: window\n";
                    echo "data: {$payload}\n\n";
                }
            }

            // Heartbeat every tick to keep connection alive through proxies
            echo ": heartbeat\n\n";
            flush();

            if (connection_aborted()) break;

            $ticks++;
            sleep(1);
        }
    }
}
