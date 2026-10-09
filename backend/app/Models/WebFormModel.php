<?php

declare(strict_types=1);

namespace App\Models;

class WebFormModel extends BaseModel
{
    protected $table      = 'web_forms';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'form_token', 'title', 'fields',
        'redirect_url', 'active',
    ];

    public static function defaultFields(): array
    {
        return [
            ['field_key' => 'name',      'label' => 'Name',             'required' => true],
            ['field_key' => 'wa_number', 'label' => 'WhatsApp Number',  'required' => true],
            ['field_key' => 'email',     'label' => 'Email',            'required' => false],
        ];
    }

    /**
     * Coerce a stored `fields` value into full field definitions.
     *
     * The admin UI saves a plain list of keys (["name","wa_number"]) while the
     * public form view and FormHandler need {field_key, label, required} — so
     * every form built in the UI used to fatal on the public page. Accepts a
     * JSON string, a list of keys, or already-shaped definitions.
     *
     * @return array<int, array{field_key:string, label:string, required:bool}>
     */
    public static function normalizeFields(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw) || $raw === []) {
            return self::defaultFields();
        }

        $labels = array_column(self::defaultFields(), 'label', 'field_key');
        $out    = [];

        foreach ($raw as $field) {
            if (is_string($field)) {
                $key = $field;
                $out[] = [
                    'field_key' => $key,
                    'label'     => $labels[$key] ?? ucwords(str_replace('_', ' ', $key)),
                    // wa_number is the contact's identity — always required.
                    'required'  => $key === 'wa_number',
                ];
                continue;
            }

            if (is_array($field) && isset($field['field_key'])) {
                $key   = (string) $field['field_key'];
                $out[] = [
                    'field_key' => $key,
                    'label'     => (string) ($field['label'] ?? $labels[$key] ?? ucwords(str_replace('_', ' ', $key))),
                    'required'  => (bool) ($field['required'] ?? $key === 'wa_number'),
                ];
            }
        }

        return $out !== [] ? $out : self::defaultFields();
    }

    public function findByToken(string $token): array|object|null
    {
        return $this->withoutTenantScope()->where('form_token', $token)->first();
    }
}
