<?php

declare(strict_types=1);

namespace App\Services\Leads;

/**
 * Maps Meta Lead Ad field_data arrays to TravelPilot contact arrays.
 *
 * Meta lead form standard fields: full_name, first_name, last_name,
 * email, phone_number (and arbitrary custom fields).
 *
 * Priority: full_name > combined first_name + last_name.
 * The WhatsApp number comes from a "WhatsApp number" custom question when the
 * form has one, else Meta's phone_number, else any phone-like question; every
 * candidate goes through WaNumberNormalizer and an empty result overall means
 * the lead cannot be created (wa_number is required).
 */
final class MetaLeadMapper
{
    /** custom_fields.field_key is VARCHAR(50); a longer key is truncated by MySQL on insert and never matches again. */
    public const FIELD_KEY_MAX = 50;

    /**
     * The custom-field key for a form question, as stored in custom_fields.
     * Snake_case of the question text, clipped to the column length so the
     * key we look values up by is byte-identical to the key MySQL kept.
     */
    public static function fieldKey(string $questionName): string
    {
        $key = strtolower(trim($questionName));
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', $key), '_');

        return rtrim(mb_substr($key, 0, self::FIELD_KEY_MAX), '_');
    }

    /**
     * Map Meta field_data array to a contact payload for ContactDedupeService.
     *
     * @param  array  $fieldData          Meta API field_data: [{'name':..,'values':[..]},..]
     * @param  string $defaultCountryCode E.g. '+91' — applied to bare local numbers.
     * @return array  Contact data + 'custom_fields' key for non-standard fields.
     *                wa_number will be '' if no phone_number field was present or unparseable.
     */
    /**
     * Custom-question names that carry a WhatsApp/mobile number, in order of
     * preference. Meta snake_cases the question label ("WhatsApp number" →
     * whatsapp_number), so these are matched as substrings of the key.
     */
    private const PHONE_LIKE = ['whatsapp', 'mobile', 'phone', 'contact_number', 'cell'];

    /**
     * Map Meta field_data array to a contact payload for ContactDedupeService.
     *
     * The number that becomes wa_number is chosen in this order: a custom
     * question mentioning WhatsApp, then the standard phone_number field, then
     * any other phone-like custom question — whichever is the first that
     * normalises. Lead forms routinely make Meta's phone_number optional and
     * add a required "WhatsApp number" question instead, and that is the one
     * this product can actually message.
     *
     * @param  array  $fieldData          Meta API field_data: [{'name':..,'values':[..]},..]
     * @param  string $defaultCountryCode E.g. '+91' — applied to bare local numbers.
     * @return array  Contact data + 'custom_fields' key for non-standard fields.
     *                wa_number will be '' if no usable phone number was present.
     */
    public static function map(array $fieldData, string $defaultCountryCode = ''): array
    {
        $contact = [
            'source'        => 'meta_lead_ads',
            'custom_fields' => [],
        ];
        $firstName  = '';
        $lastName   = '';
        $phoneRaw   = '';          // Meta's standard phone_number field
        $candidates = [];          // key => raw value, phone-like custom questions

        foreach ($fieldData as $field) {
            $name  = strtolower(trim($field['name'] ?? ''));
            $value = trim($field['values'][0] ?? '');

            switch ($name) {
                case 'full_name':
                    $contact['name'] = $value;
                    break;

                case 'first_name':
                    $firstName = $value;
                    break;

                case 'last_name':
                    $lastName = $value;
                    break;

                case 'email':
                    $contact['email'] = $value;
                    break;

                case 'phone_number':
                case 'phone': // what current forms actually send for the standard field
                    $phoneRaw = $value;
                    break;

                case 'company_name':
                case 'company':
                    $contact['company'] = $value;
                    break;

                case 'job_title':
                    $contact['job_title'] = $value;
                    break;

                default:
                    // Non-standard fields → custom_fields (snake_case key)
                    $key = self::fieldKey($name);
                    if ($key === '') {
                        break;
                    }
                    $contact['custom_fields'][$key] = $value;
                    foreach (self::PHONE_LIKE as $hint) {
                        if (str_contains($key, $hint)) {
                            $candidates[$key] = $value;
                            break;
                        }
                    }
            }
        }

        // Combine first/last if full_name was not present
        if (empty($contact['name']) && ($firstName !== '' || $lastName !== '')) {
            $contact['name'] = trim("{$firstName} {$lastName}");
        }

        // --- Pick the WhatsApp number -----------------------------------
        $ordered = [];
        foreach ($candidates as $key => $raw) {
            if (str_contains($key, 'whatsapp')) {
                $ordered[$key] = $raw;
            }
        }
        $ordered['phone_number'] = $phoneRaw;
        foreach ($candidates as $key => $raw) {
            $ordered[$key] ??= $raw;
        }

        $contact['wa_number'] = '';
        foreach ($ordered as $key => $raw) {
            $normalized = WaNumberNormalizer::normalize($raw, $defaultCountryCode);
            if ($normalized === '') {
                continue;
            }
            $contact['wa_number'] = $normalized;
            if ($key !== 'phone_number') {
                // The custom question became the primary number; don't also
                // store it as a custom field. Meta's own phone_number, when it
                // parses too, is kept as the secondary line.
                unset($contact['custom_fields'][$key]);
                $secondary = WaNumberNormalizer::normalize($phoneRaw, $defaultCountryCode);
                if ($secondary !== '' && $secondary !== $normalized) {
                    $contact['phone_secondary'] = $secondary;
                }
            }
            break;
        }

        return $contact;
    }
}
