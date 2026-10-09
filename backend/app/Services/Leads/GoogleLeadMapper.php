<?php

declare(strict_types=1);

namespace App\Services\Leads;

/**
 * Maps Google Ads Lead Form `user_column_data` arrays to TravelPilot contact arrays.
 *
 * Google delivers each answered field as an object:
 *   { "column_id": "FULL_NAME", "column_name": "Full name", "string_value": "Jane Doe" }
 *
 * Standard column_id codes (uppercased): FULL_NAME, FIRST_NAME, LAST_NAME, EMAIL,
 * WORK_EMAIL, PHONE_NUMBER, WORK_PHONE, plus arbitrary custom-question columns
 * (whose column_id is the question slug and column_name is the question text).
 *
 * Priority: full_name > combined first_name + last_name.
 * Phone is run through WaNumberNormalizer — an empty result means the lead cannot
 * be created (wa_number is required by ContactDedupeService).
 */
final class GoogleLeadMapper
{
    /**
     * Map Google user_column_data to a contact payload for ContactDedupeService.
     *
     * @param  array  $columns            [{'column_id':..,'column_name':..,'string_value':..}, ..]
     * @param  string $defaultCountryCode E.g. '+91' — applied to bare local numbers.
     * @return array  Contact data + 'custom_fields' key for non-standard fields.
     *                wa_number will be '' if no phone column was present or unparseable.
     */
    public static function map(array $columns, string $defaultCountryCode = ''): array
    {
        $contact = [
            'source'        => 'google_lead_forms',
            'custom_fields' => [],
        ];
        $firstName = '';
        $lastName  = '';

        foreach ($columns as $col) {
            $id    = strtoupper(trim((string) ($col['column_id'] ?? '')));
            $value = trim((string) ($col['string_value'] ?? ''));
            if ($value === '') {
                continue;
            }

            switch ($id) {
                case 'FULL_NAME':
                    $contact['name'] = $value;
                    break;

                case 'FIRST_NAME':
                    $firstName = $value;
                    break;

                case 'LAST_NAME':
                    $lastName = $value;
                    break;

                case 'EMAIL':
                case 'WORK_EMAIL':
                case 'USER_EMAIL':
                    // Prefer the primary EMAIL if multiple email-like columns arrive.
                    if (empty($contact['email']) || $id === 'EMAIL') {
                        $contact['email'] = $value;
                    }
                    break;

                case 'PHONE_NUMBER':
                case 'WORK_PHONE':
                case 'USER_PHONE':
                    // Prefer the primary PHONE_NUMBER; normalize (empty = unparseable).
                    if (empty($contact['wa_number']) || $id === 'PHONE_NUMBER') {
                        $contact['wa_number'] = WaNumberNormalizer::normalize($value, $defaultCountryCode);
                    }
                    break;

                default:
                    // Non-standard fields → custom_fields (snake_case key).
                    // Prefer the human column_name for a readable key, fall back to id.
                    $label = (string) ($col['column_name'] ?? '') ?: $id;
                    $key   = preg_replace('/[^a-z0-9]+/', '_', strtolower($label));
                    $key   = trim($key, '_');
                    if ($key !== '') {
                        $contact['custom_fields'][$key] = $value;
                    }
            }
        }

        // Combine first/last if full_name was not present.
        if (empty($contact['name']) && ($firstName !== '' || $lastName !== '')) {
            $contact['name'] = trim("{$firstName} {$lastName}");
        }

        // Ensure wa_number key exists (empty string = no phone → caller must check).
        if (! array_key_exists('wa_number', $contact)) {
            $contact['wa_number'] = '';
        }

        return $contact;
    }
}
