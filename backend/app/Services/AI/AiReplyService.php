<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\MessageModel;

/**
 * Generates AI replies for WhatsApp conversations using the Anthropic API.
 *
 * Credentials: platform-level ANTHROPIC_API_KEY (TravelPilot offers AI as a
 * feature; self-hosted owners set their own key). AI_MOCK_MODE returns a
 * deterministic canned reply for local dev / tests — no API call.
 *
 * The "history" mapping (DB messages → chat turns) is pure and unit-tested.
 */
class AiReplyService
{
    // Haiku 4.5 — fast + low-cost, ideal for inbox reply suggestions.
    // (claude-3-5-haiku-latest was retired.) Override per-tenant or via ANTHROPIC_MODEL.
    public const DEFAULT_MODEL = 'claude-haiku-4-5-20251001';

    /**
     * @param int|null $tenantId  When set, a tenant's own Anthropic key
     *                            (integrations.type='ai_assistant') is preferred
     *                            over the platform ANTHROPIC_API_KEY.
     */
    private int $timeoutMs = 20_000;

    public function __construct(
        private readonly ?int $tenantId = null,
    ) {}

    /** Long generations (itinerary drafts) need more than the 20s chat default. */
    public function withTimeout(int $ms): static
    {
        $this->timeoutMs = max(1_000, $ms);
        return $this;
    }

    /**
     * Resolve the API key + model: the tenant's own credentials if configured,
     * else the platform env defaults.
     *
     * @return array{key:string, model:string}
     */
    private function resolveCredentials(): array
    {
        $envKey   = (string) env('ANTHROPIC_API_KEY', '');
        $envModel = (string) env('ANTHROPIC_MODEL', self::DEFAULT_MODEL);

        if ($this->tenantId !== null && $this->tenantId > 0) {
            $integration = (new \App\Models\IntegrationModel())
                ->findActiveByType($this->tenantId, 'ai_assistant');
            if ($integration !== null) {
                $config = json_decode($integration['config'] ?? '{}', true) ?: [];
                if (! empty($config['api_key_enc'])) {
                    return [
                        'key'   => \App\Services\WhatsApp\TokenCipher::decrypt($config['api_key_enc']),
                        'model' => $config['model'] ?: $envModel,
                    ];
                }
            }
        }

        return ['key' => $envKey, 'model' => $envModel];
    }

    /**
     * Build Anthropic-format chat turns from stored conversation messages.
     * Outbound (direction='out') → assistant; inbound → user. Empty bodies
     * (media-only) are skipped. Pure — no DB.
     *
     * @param  array $messages Rows from MessageModel::forConversation (ASC)
     * @return array<int, array{role:string, content:string}>
     */
    public static function buildHistory(array $messages): array
    {
        $turns = [];
        foreach ($messages as $m) {
            $body = trim((string) ($m['body'] ?? ''));
            if ($body === '') {
                continue;
            }
            $turns[] = [
                'role'    => (($m['direction'] ?? 'in') === 'out') ? 'assistant' : 'user',
                'content' => $body,
            ];
        }
        return $turns;
    }

    /**
     * Generate a reply given a system prompt and prior turns.
     *
     * @param array $history Anthropic-format turns (see buildHistory)
     * @return array{success:bool, text:string, error:?string}
     */
    public function generate(
        string $systemPrompt,
        array $history,
        int $maxTokens = 400,
        bool $forWhatsApp = true,
    ): array {
        // Anthropic requires the conversation to start with a 'user' turn and
        // end with one. Drop leading assistant turns; bail if nothing to answer.
        while (! empty($history) && $history[0]['role'] === 'assistant') {
            array_shift($history);
        }
        if (empty($history) || end($history)['role'] !== 'user') {
            return ['success' => false, 'text' => '', 'error' => 'No user message to respond to.'];
        }

        if ($this->isMockMode()) {
            $last = end($history)['content'];
            return ['success' => true, 'text' => '[AI] Thanks for your message: "' . mb_substr($last, 0, 60) . '". How can I help further?', 'error' => null];
        }

        $creds  = $this->resolveCredentials();
        $apiKey = $creds['key'];
        if ($apiKey === '') {
            return ['success' => false, 'text' => '', 'error' => 'AI is not configured (no Anthropic key for this tenant or platform).'];
        }

        $model = $creds['model'];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => $this->timeoutMs,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model'      => $model,
                'max_tokens' => $maxTokens,
                'system'     => $this->systemFor($systemPrompt, $forWhatsApp),
                'messages'   => array_map(static fn ($t) => ['role' => $t['role'], 'content' => $t['content']], $history),
            ]),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['success' => false, 'text' => '', 'error' => "AI request failed: {$err}"];
        }
        $parsed = json_decode($resp, true) ?: [];
        if ($code < 200 || $code >= 300) {
            $msg = $parsed['error']['message'] ?? "HTTP {$code}";
            return ['success' => false, 'text' => '', 'error' => "AI error: {$msg}"];
        }

        // Anthropic returns content as an array of blocks; concatenate text blocks.
        $text = '';
        foreach ($parsed['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }
        $text = trim($text);

        // Repair any Markdown that slipped through before it reaches a customer.
        if ($forWhatsApp) {
            $text = \App\Services\WhatsApp\WhatsAppText::normalise($text);
        }

        return $text !== ''
            ? ['success' => true, 'text' => $text, 'error' => null]
            : ['success' => false, 'text' => '', 'error' => 'AI returned an empty reply.'];
    }

    /**
     * Convenience: generate a reply for a conversation using its recent history.
     *
     * @return array{success:bool, text:string, error:?string}
     */
    public function generateForConversation(int $tenantId, int $conversationId, string $systemPrompt, int $historyLimit = 20): array
    {
        $messages = (new MessageModel())->forConversation($tenantId, $conversationId, null, $historyLimit);
        return $this->generate($systemPrompt, self::buildHistory($messages));
    }

    /**
     * The system prompt actually sent, with the channel's formatting rules
     * appended when the reply is going out over WhatsApp.
     */
    private function systemFor(string $systemPrompt, bool $forWhatsApp): string
    {
        $base = $systemPrompt !== ''
            ? $systemPrompt
            : 'You are a helpful WhatsApp assistant. Reply concisely and politely.';

        return $forWhatsApp
            ? $base . "\n\n" . \App\Services\WhatsApp\WhatsAppText::promptRule()
            : $base;
    }

    public function isMockMode(): bool
    {
        return filter_var(env('AI_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
    }
}
