<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Generates context-aware sales artifacts with the Anthropic model:
 *  - call_script   : a talk-track for a rep about to phone/WhatsApp a lead.
 *  - qualification : a short list of qualifying questions tailored to the lead.
 *
 * Pure prompt-building + a thin delegation to AiReplyService (which owns the
 * HTTP call, credential resolution, and AI_MOCK_MODE). No DB access here — the
 * caller passes a flat context array — so prompt construction is unit-testable.
 */
final class AiScriptService
{
    public const TYPES = ['call_script', 'qualification'];

    public function __construct(private readonly ?int $tenantId = null) {}

    /**
     * @param string $type    One of self::TYPES.
     * @param array  $context Flat lead context: name, job_title, company,
     *                        lifecycle_stage, source, deal_title, deal_value,
     *                        deal_stage, notes.
     * @return array{success:bool, text:string, error:?string}
     */
    public function generate(string $type, array $context): array
    {
        if (! in_array($type, self::TYPES, true)) {
            return ['success' => false, 'text' => '', 'error' => "Unknown script type '{$type}'."];
        }

        $system = $this->systemPrompt($type);
        $user   = $this->userPrompt($type, $context);

        // forWhatsApp: false — this is a call script rendered in the UI, where
        // Markdown is correct and WhatsApp's syntax would look wrong.
        return (new AiReplyService($this->tenantId))
            ->generate($system, [['role' => 'user', 'content' => $user]], 600, false);
    }

    private function systemPrompt(string $type): string
    {
        if ($type === 'qualification') {
            return 'You are a B2B sales coach. Produce a short, numbered list of 5–7 sharp '
                . 'qualifying questions a rep should ask this lead to assess fit, budget, '
                . 'authority, need, and timeline. Tailor them to the lead context. Output only '
                . 'the questions — no preamble.';
        }

        return 'You are a B2B sales coach. Write a concise call script a rep can follow when '
            . 'phoning or messaging this lead: a warm opener, 2–3 discovery talking points '
            . 'grounded in the lead context, a value statement, and a clear next-step ask. '
            . 'Keep it under 200 words and conversational.';
    }

    /** Render the lead context into a compact, model-readable brief. */
    public function userPrompt(string $type, array $context): string
    {
        $lines = [];
        $add = static function (string $label, $value) use (&$lines): void {
            $value = trim((string) $value);
            if ($value !== '') {
                $lines[] = "{$label}: {$value}";
            }
        };

        $add('Lead name', $context['name'] ?? '');
        $add('Job title', $context['job_title'] ?? '');
        $add('Company', $context['company'] ?? '');
        $add('Lifecycle stage', $context['lifecycle_stage'] ?? '');
        $add('Lead source', $context['source'] ?? '');
        $add('Open deal', $context['deal_title'] ?? '');
        if (! empty($context['deal_value'])) {
            $add('Deal value', $context['deal_value']);
        }
        $add('Deal stage', $context['deal_stage'] ?? '');
        $add('Notes', $context['notes'] ?? '');

        $brief = $lines === [] ? 'No specific lead details are known.' : implode("\n", $lines);

        $ask = $type === 'qualification'
            ? 'Generate the qualifying questions for this lead.'
            : 'Generate the call script for this lead.';

        return "Lead context:\n{$brief}\n\n{$ask}";
    }
}
