<?php

declare(strict_types=1);

namespace App\Services\Email\Marketing;

/**
 * Merge tags for email subjects and bodies.
 *
 *   {{contact.first_name}}           the first word of the contact's name
 *   {{contact.name|there}}           a fallback after the pipe when the value is empty
 *   {{custom.birthday}}              a custom field, by field_key
 *
 * Same vocabulary as the flow engine's WhatsApp tokens, so a user learns one
 * syntax. Only whitelisted contact columns are reachable — an arbitrary column
 * name must never let a template print, say, an owner-only field. Values are
 * HTML-escaped when rendering a body; subjects are plain text.
 */
final class EmailPersonalizer
{
    public const CONTACT_FIELDS = [
        'name', 'first_name', 'last_name', 'email', 'wa_number', 'city', 'state',
        'country', 'job_title', 'business_type', 'status', 'source',
    ];

    private const TOKEN = '/\{\{\s*(contact|custom)\.([a-z0-9_]+)\s*(?:\|([^}]*))?\}\}/i';

    /**
     * @param array<string,mixed>  $contact
     * @param array<string,string> $custom  field_key => value (restricted fields already excluded)
     */
    public function render(string $text, array $contact, array $custom = [], bool $html = true): string
    {
        return (string) preg_replace_callback(self::TOKEN, function (array $m) use ($contact, $custom, $html): string {
            $scope    = strtolower($m[1]);
            $key      = strtolower($m[2]);
            $fallback = isset($m[3]) ? trim($m[3]) : '';

            $value = $scope === 'custom'
                ? (string) ($custom[$key] ?? '')
                : $this->contactValue($contact, $key);

            $value = trim($value) === '' ? $fallback : $value;

            return $html ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
        }, $text);
    }

    /** @return list<string> tokens a template uses, for the editor's "missing data" hint */
    public function tokens(string $text): array
    {
        preg_match_all(self::TOKEN, $text, $m);

        return array_values(array_unique(array_map(
            static fn (string $s, string $k) => strtolower($s) . '.' . strtolower($k),
            $m[1],
            $m[2],
        )));
    }

    private function contactValue(array $contact, string $key): string
    {
        if (! in_array($key, self::CONTACT_FIELDS, true)) {
            return '';
        }

        $name  = trim((string) ($contact['name'] ?? ''));
        $parts = $name === '' ? [] : preg_split('/\s+/', $name);

        return match ($key) {
            'first_name' => (string) ($parts[0] ?? ''),
            'last_name'  => count($parts) > 1 ? (string) end($parts) : '',
            default      => (string) ($contact[$key] ?? ''),
        };
    }
}
