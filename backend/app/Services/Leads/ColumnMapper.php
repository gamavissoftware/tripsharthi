<?php

declare(strict_types=1);

namespace App\Services\Leads;

/**
 * Maps a CSV/Excel row (keyed by original header) to a contact data array
 * using a saved mapping definition.
 *
 * Mapping format (JSON stored in lead_imports.mapping):
 *   { "CSV Header": "contact_field_or_custom_field_key", … }
 *
 * Standard contact fields: columns written straight onto `contacts` — see
 * STANDARD_FIELDS. Relation fields (`tags`, `company`, `company_industry`) are
 * not columns; they drive related rows (tag get-or-create + contact_tags, and
 * account get-or-create + contacts.account_id) and are handled downstream by
 * ContactDedupeService. Anything else is treated as a custom field key.
 *
 * wa_number is the only required field; its absence blocks the import start.
 */
class ColumnMapper
{
    private const STANDARD_FIELDS = [
        'wa_number', 'name', 'email', 'status', 'source', 'opt_in',
        'job_title', 'phone_secondary', 'business_type',
        'city', 'state', 'country', 'language', 'remarks',
    ];

    /**
     * Mapping targets that are NOT columns on `contacts`. They create or link
     * related rows instead, so they must survive mapRow() without being
     * mistaken for custom fields.
     */
    private const RELATION_FIELDS = ['tags', 'company', 'company_industry'];

    /** Every target a mapping may point a column at. */
    public static function allowedTargets(): array
    {
        return array_merge(self::STANDARD_FIELDS, self::RELATION_FIELDS);
    }

    /**
     * Validate that a proposed mapping covers at least wa_number.
     *
     * @param  array  $mapping  { header => field_key }
     * @return array{ok: bool, errors: string[]}
     */
    public static function validate(array $mapping): array
    {
        $errors = [];

        if (! in_array('wa_number', array_values($mapping), true)) {
            $errors[] = 'Mapping must include a column mapped to "wa_number".';
        }

        return ['ok' => empty($errors), 'errors' => $errors];
    }

    /**
     * Map a single raw CSV row (indexed array) to a contact data array.
     *
     * @param  string[] $headers  CSV header row (positional).
     * @param  string[] $row      CSV data row (positional, same count as headers).
     * @param  array    $mapping  { header => field_key }
     * @return array   Keyed by contact field / custom_field key. 'custom_fields' sub-array
     *                 holds values for non-standard fields.
     */
    public static function mapRow(array $headers, array $row, array $mapping): array
    {
        // Build header => value from positional arrays
        $keyed = [];
        foreach ($headers as $i => $header) {
            $keyed[$header] = isset($row[$i]) ? trim((string) $row[$i]) : '';
        }

        $contact      = [];
        $customFields = [];

        foreach ($mapping as $header => $fieldKey) {
            $value = $keyed[$header] ?? '';

            if (in_array($fieldKey, self::STANDARD_FIELDS, true)
                || in_array($fieldKey, self::RELATION_FIELDS, true)
            ) {
                $contact[$fieldKey] = $value;
            } else {
                // Treat as a custom field key
                $customFields[$fieldKey] = $value;
            }
        }

        $contact['custom_fields'] = $customFields;
        return $contact;
    }
}
