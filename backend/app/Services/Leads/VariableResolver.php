<?php

declare(strict_types=1);

namespace App\Services\Leads;

/**
 * Resolves template variable placeholders ({{1}}, {{2}}, …) for a specific contact.
 *
 * Fallback chain for a missing/blank value:
 *   1. Campaign-level per-variable default (from campaign.variable_defaults JSON)
 *   2. Generic safe fallback: 'there'  (non-blank; Meta will reject empty-string params)
 *
 * Every substituted blank increments the missing_count in the return value so
 * campaign stats can surface contacts with incomplete data.
 */
class VariableResolver
{
    private const GENERIC_FALLBACK = 'there';

    private const STANDARD_FIELDS = ['name', 'email', 'wa_number', 'status', 'source'];

    /** Marks a mapping value as coming from flow-run state, not the contact. */
    public const STATE_PREFIX = 'state:';

    /**
     * Resolve variables for one contact.
     *
     * @param  array  $variableMapping   {"1":"name","2":"company_cf"} — index → field_key
     * @param  array  $contact           Contact row from DB
     * @param  array  $customFieldValues { field_key => value } from ContactFieldValueModel
     * @param  array  $variableDefaults  {"1":"there","2":"Acme"} — campaign-level fallbacks
     * @param  array  $stateValues       Flow-run state, reachable as "state:key"
     *
     * @return array{
     *   body_params:  array<array{type:string, text:string}>,
     *   missing_count: int,
     * }
     */
    public function resolve(
        array $variableMapping,
        array $contact,
        array $customFieldValues,
        array $variableDefaults = [],
        array $stateValues = [],
    ): array {
        $bodyParams   = [];
        $missingCount = 0;

        foreach ($variableMapping as $index => $fieldKey) {
            $value = $this->extractValue($fieldKey, $contact, $customFieldValues, $stateValues);

            if ($value === '' || $value === null) {
                $missingCount++;
                $value = $variableDefaults[(string) $index] ?? self::GENERIC_FALLBACK;
            }

            $bodyParams[] = ['type' => 'text', 'text' => (string) $value];
        }

        return [
            'body_params'   => $bodyParams,
            'missing_count' => $missingCount,
        ];
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    private function extractValue(
        string $fieldKey,
        array $contact,
        array $customFieldValues,
        array $stateValues = [],
    ): string {
        // "state:key" reads from the flow run rather than the contact. It is how
        // a value computed at trigger time — the category-specific hook in a
        // no-reply follow-up, say — reaches a template variable at all, since
        // nothing about it belongs on the contact record.
        //
        // The prefix is safe to introduce: a field key may name a standard
        // column or a custom field, and neither can contain a colon.
        if (str_starts_with($fieldKey, self::STATE_PREFIX)) {
            $key = substr($fieldKey, strlen(self::STATE_PREFIX));

            return trim((string) ($stateValues[$key] ?? ''));
        }

        if (in_array($fieldKey, self::STANDARD_FIELDS, true)) {
            return trim((string) ($contact[$fieldKey] ?? ''));
        }

        // Custom field
        return trim((string) ($customFieldValues[$fieldKey] ?? ''));
    }
}
