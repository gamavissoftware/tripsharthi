<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Single source of truth for "does this template's variable mapping satisfy
 * the number of body parameters Meta expects?".
 *
 * Meta rejects a send with error #132000 ("number of parameters does not
 * match the expected number") when the body component carries a different
 * count of {{n}} parameters than the approved template defines. Both the
 * campaign sender and the flow engine funnel every template send through a
 * single TemplateComponentBuilder::forSend() call, so the rule that protects
 * them is identical — it lives here so it can never drift between the two.
 *
 * Used at three layers (defence in depth):
 *   1. Compile time — FlowValidator (block flow activation) and
 *      CampaignsController::create (reject a bad mapping on save).
 *   2. Launch time  — CampaignsController::send / ::schedule.
 *   3. Run time     — FlowEngine::execSendTemplate (block the doomed send,
 *      record a failed message locally, never call Meta).
 */
final class TemplateParamCheck
{
    /**
     * Number of body variables Meta expects for this template.
     *
     * Prefers the stored `variables` definition (authoritative, set at
     * submission), and falls back to counting the distinct {{n}} placeholders
     * in the body so a template with placeholders but an empty/missing
     * `variables` column is still guarded.
     */
    public static function requiredCount(array $template): int
    {
        $vars = json_decode($template['variables'] ?? '[]', true);
        if (is_array($vars) && $vars !== []) {
            return count($vars);
        }

        return self::placeholdersInBody((string) ($template['body'] ?? ''));
    }

    /** Count distinct positional {{n}} placeholders in a template body. */
    public static function placeholdersInBody(string $body): int
    {
        if (! preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $m)) {
            return 0;
        }

        return count(array_unique($m[1]));
    }

    /**
     * Normalise a variable mapping that may arrive as a JSON string (flow node
     * data / campaign column) or an already-decoded array.
     *
     * @param  string|array|null  $mapping
     * @return array<string,mixed>
     */
    public static function normaliseMap($mapping): array
    {
        if (is_array($mapping)) {
            return $mapping;
        }
        if (is_string($mapping) && $mapping !== '') {
            $decoded = json_decode($mapping, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * Validate a mapping against a template.
     *
     * @param  string|array|null  $mapping  variable mapping (index → field key)
     * @return string|null  null when the mapping is complete, otherwise a
     *                      human-readable explanation of the mismatch.
     */
    public static function error(array $template, $mapping): ?string
    {
        $required = self::requiredCount($template);
        if ($required === 0) {
            return null; // template has no variables — nothing to map
        }

        $map = self::normaliseMap($mapping);
        for ($i = 1; $i <= $required; $i++) {
            if (! array_key_exists((string) $i, $map)) {
                $name   = $template['name'] ?? 'template';
                $mapped = count($map);

                return "Template '{$name}' needs {$required} variable(s); {$mapped} mapped. "
                    . "Map all {$required} placeholders ({{1}}…{{" . $required . '}}) — with a default '
                    . 'for any that are not contact fields — before sending. '
                    . '(Prevents WhatsApp error #132000.)';
            }
        }

        return null;
    }
}
