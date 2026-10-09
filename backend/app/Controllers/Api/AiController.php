<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\DealModel;
use App\Models\IntegrationModel;
use App\Services\AI\AiReplyService;
use App\Services\AI\AiScriptService;
use App\Services\AI\AiUsageService;
use App\Services\Auth\CurrentUser;
use App\Services\Billing\PlanLimitChecker;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * AI assistance: suggested replies (metered per plan) + per-tenant key config.
 */
class AiController extends ResourceController
{
    protected $format = 'json';

    // POST /api/v1/ai/suggest  { conversation_id, system_prompt? }
    public function suggest(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $convId   = (int) $this->request->getJsonVar('conversation_id');

        $conv = (new ConversationModel())->setTenant($tenantId)->find($convId);
        if (! $conv) {
            return $this->failNotFound('Conversation not found.');
        }

        // ── Plan metering ─────────────────────────────────────────────
        $usage = new AiUsageService();
        if (! $usage->canUse($tenantId)) {
            $snap = $usage->snapshot($tenantId);
            return $this->fail((new PlanLimitChecker())
                ->limitExceededResponse('ai_replies', $snap['used'], $snap['limit']), 422);
        }

        $prompt = (string) ($this->request->getJsonVar('system_prompt')
            ?? 'You are a helpful customer support agent replying on WhatsApp. Be concise, warm, and solve the customer\'s issue.');

        $result = (new AiReplyService($tenantId))->generateForConversation($tenantId, $convId, $prompt);

        if (! ($result['success'] ?? false)) {
            return $this->fail(['ai' => $result['error'] ?? 'AI unavailable.'], 422);
        }

        $usage->record($tenantId); // only charge successful generations

        return $this->respond(['success' => true, 'data' => ['suggestion' => $result['text']]]);
    }

    // POST /api/v1/ai/script  { type: call_script|qualification, contact_id?, deal_id? }
    public function script(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $type     = (string) ($this->request->getJsonVar('type') ?? 'call_script');

        if (! in_array($type, AiScriptService::TYPES, true)) {
            return $this->fail(['type' => 'Must be one of: ' . implode(', ', AiScriptService::TYPES)], 422);
        }

        // ── Plan metering (shared ai_replies allowance) ──────────────────
        $usage = new AiUsageService();
        if (! $usage->canUse($tenantId)) {
            $snap = $usage->snapshot($tenantId);
            return $this->fail((new PlanLimitChecker())
                ->limitExceededResponse('ai_replies', $snap['used'], $snap['limit']), 422);
        }

        $context = $this->buildLeadContext($tenantId);

        $result = (new AiScriptService($tenantId))->generate($type, $context);
        if (! ($result['success'] ?? false)) {
            return $this->fail(['ai' => $result['error'] ?? 'AI unavailable.'], 422);
        }

        $usage->record($tenantId); // only charge successful generations

        return $this->respond(['success' => true, 'data' => ['type' => $type, 'script' => $result['text']]]);
    }

    /** Gather a flat lead-context brief from the requested contact/deal (tenant-scoped). */
    private function buildLeadContext(int $tenantId): array
    {
        $context   = [];
        $contactId = (int) $this->request->getJsonVar('contact_id');
        $dealId    = (int) $this->request->getJsonVar('deal_id');

        if ($contactId > 0) {
            $contact = (new ContactModel())->setTenant($tenantId)->find($contactId);
            if ($contact) {
                $context['name']            = $contact['name'] ?? '';
                $context['job_title']       = $contact['job_title'] ?? '';
                $context['lifecycle_stage'] = $contact['lifecycle_stage'] ?? '';
                $context['source']          = $contact['source'] ?? '';

                // Company from the contact's linked account.
                if (! empty($contact['account_id'])) {
                    $account = (new \App\Models\AccountModel())->setTenant($tenantId)->find((int) $contact['account_id']);
                    $context['company'] = $account['name'] ?? '';
                }

                // Most relevant note (pinned first, else most recent) as free-text context.
                $notes = (new \App\Models\NoteModel())->forRecord($tenantId, 'contact', $contactId);
                if (! empty($notes)) {
                    $context['notes'] = mb_substr((string) ($notes[0]['body'] ?? ''), 0, 500);
                }
            }
        }

        if ($dealId > 0) {
            $deal = (new DealModel())->setTenant($tenantId)->find($dealId);
            if ($deal) {
                $context['deal_title'] = $deal['title'] ?? '';
                $context['deal_value'] = $deal['value_amount'] ? ($deal['value_amount'] / 100) . ' ' . ($deal['currency'] ?? '') : '';

                // Deal stage name for pipeline context.
                if (! empty($deal['stage_id'])) {
                    $stage = (new \App\Models\PipelineStageModel())->setTenant($tenantId)->find((int) $deal['stage_id']);
                    $context['deal_stage'] = $stage['name'] ?? '';
                }
            }
        }

        return $context;
    }

    // GET /api/v1/ai/usage — current period usage snapshot
    public function usage(): ResponseInterface
    {
        return $this->respond([
            'success' => true,
            'data'    => (new AiUsageService())->snapshot(CurrentUser::tenantId()),
        ]);
    }

    // GET /api/v1/ai/config — connection status (owner/admin)
    public function getConfig(): ResponseInterface
    {
        $integration = (new IntegrationModel())->findActiveByType(CurrentUser::tenantId(), 'ai_assistant');
        $config      = $integration ? (json_decode($integration['config'] ?? '{}', true) ?: []) : [];

        return $this->respond(['success' => true, 'data' => [
            'has_tenant_key'        => ! empty($config['api_key_enc']),
            'model'                 => $config['model'] ?? AiReplyService::DEFAULT_MODEL,
            'platform_key_available'=> env('ANTHROPIC_API_KEY', '') !== '',
            'usage'                 => (new AiUsageService())->snapshot(CurrentUser::tenantId()),
        ]]);
    }

    // POST /api/v1/ai/config — store tenant Anthropic key (owner/admin)
    public function saveConfig(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $rawKey   = trim((string) ($this->request->getJsonVar('api_key') ?? ''));

        // The UI renders a stored key as "•••••• (unchanged)" and posts no key
        // when it wasn't retyped, so requiring one unconditionally made a
        // model-only change fail with "api_key is required". Keep the stored key
        // when none is supplied; still require one on first save.
        $existing    = (new IntegrationModel())->findActiveByType($tenantId, 'ai_assistant');
        $storedCfg   = $existing ? (json_decode($existing['config'] ?? '{}', true) ?: []) : [];
        $storedKeyEnc = $storedCfg['api_key_enc'] ?? null;

        if ($rawKey === '' && $storedKeyEnc === null) {
            return $this->fail(['api_key' => 'The api_key field is required.'], 422);
        }

        $config = [
            'api_key_enc' => $rawKey !== '' ? TokenCipher::encrypt($rawKey) : $storedKeyEnc,
            'model'       => trim((string) ($this->request->getJsonVar('model') ?? AiReplyService::DEFAULT_MODEL)),
        ];

        // Upsert that also restores a soft-deleted row. findActiveByType() can't
        // see a soft-deleted integration, but UNIQUE(tenant_id, type) still counts
        // it, so a plain insert would throw "Duplicate entry '..-ai_assistant'".
        (new IntegrationModel())->saveConfig($tenantId, 'ai_assistant', [
            'config' => json_encode($config),
        ]);

        return $this->respond(['success' => true, 'message' => 'AI key saved.']);
    }

    // DELETE /api/v1/ai/config — remove tenant key (fall back to platform)
    public function deleteConfig(): ResponseInterface
    {
        $model       = new IntegrationModel();
        $integration = $model->findActiveByType(CurrentUser::tenantId(), 'ai_assistant');
        if ($integration) {
            $model->setTenant(CurrentUser::tenantId())->delete((int) $integration['id']);
        }
        return $this->respondDeleted(['success' => true]);
    }
}
