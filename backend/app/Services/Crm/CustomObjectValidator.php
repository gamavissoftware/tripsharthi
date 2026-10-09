<?php

declare(strict_types=1);

namespace App\Services\Crm;

/**
 * Validates a custom-object record's data map against its field schema.
 *
 * Beyond "required", each field's declared type is enforced — an `email` field
 * must hold a valid email, a `number` must be numeric, a `select` must be one of
 * the configured options, etc. Pure (takes the field definitions + the data
 * map), so it is unit-testable without the database.
 */
final class CustomObjectValidator
{
    /**
     * @param array $fields Field rows (field_key, label, type, options(JSON), required).
     * @param array $data   field_key => value map.
     * @return array<string,string> field_key => error message. Empty = valid.
     */
    public function validate(array $fields, array $data): array
    {
        $errors = [];

        foreach ($fields as $f) {
            $key   = (string) ($f['field_key'] ?? '');
            $label = (string) ($f['label'] ?? $key);
            if ($key === '') {
                continue;
            }

            $raw   = $data[$key] ?? null;
            $val   = is_string($raw) ? trim($raw) : $raw;
            $empty = $val === null || $val === '' || $val === [];

            if ((int) ($f['required'] ?? 0) === 1 && $empty) {
                $errors[$key] = "{$label} is required.";
                continue;
            }
            if ($empty) {
                continue; // optional + empty → nothing to type-check
            }

            switch ((string) ($f['type'] ?? 'text')) {
                case 'email':
                    if (! filter_var((string) $val, FILTER_VALIDATE_EMAIL)) {
                        $errors[$key] = "{$label} must be a valid email address.";
                    }
                    break;

                case 'number':
                    if (! is_numeric($val)) {
                        $errors[$key] = "{$label} must be a number.";
                    }
                    break;

                case 'phone':
                    if (! preg_match('/^\+?[0-9 ()\-]{6,20}$/', (string) $val)) {
                        $errors[$key] = "{$label} must be a valid phone number.";
                    }
                    break;

                case 'date':
                    if (! $this->isValidDate((string) $val)) {
                        $errors[$key] = "{$label} must be a valid date (YYYY-MM-DD).";
                    }
                    break;

                case 'boolean':
                    if (! $this->isBooleanish($val)) {
                        $errors[$key] = "{$label} must be true or false.";
                    }
                    break;

                case 'select':
                    $opts = $this->optionValues($f['options'] ?? null);
                    if ($opts !== [] && ! in_array((string) $val, $opts, true)) {
                        $errors[$key] = "{$label} must be one of: " . implode(', ', $opts) . '.';
                    }
                    break;

                // text / textarea: free-form, no type constraint.
            }
        }

        return $errors;
    }

    /** Accepts a real Y-m-d (rejects "2026-13-40" and non-dates). */
    private function isValidDate(string $val): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $val);
        return $d !== false && $d->format('Y-m-d') === $val;
    }

    private function isBooleanish(mixed $val): bool
    {
        if (is_bool($val)) {
            return true;
        }
        return in_array(
            is_string($val) ? strtolower(trim($val)) : $val,
            [0, 1, '0', '1', 'true', 'false', 'yes', 'no'],
            true
        );
    }

    /**
     * Normalize a select field's options (JSON string or array) into a flat list
     * of allowed string values. Supports both ["a","b"] and [{value,label}] shapes.
     *
     * @return string[]
     */
    private function optionValues(mixed $options): array
    {
        if (is_string($options)) {
            $options = json_decode($options, true);
        }
        if (! is_array($options)) {
            return [];
        }

        $values = [];
        foreach ($options as $opt) {
            if (is_array($opt)) {
                $values[] = (string) ($opt['value'] ?? $opt['label'] ?? reset($opt));
            } else {
                $values[] = (string) $opt;
            }
        }
        return array_values(array_filter($values, static fn ($v) => $v !== ''));
    }
}
