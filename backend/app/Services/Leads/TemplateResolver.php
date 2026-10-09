<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\TemplateModel;

/**
 * Picks the language-appropriate version of a template for a contact.
 *
 * WhatsApp templates share a NAME across language versions (that's how Meta
 * models multi-language). Given a base template and a contact's preferred
 * language, this returns the approved same-name template in that language,
 * falling back to the base template when there's no match.
 *
 * Results are cached per (name|language) so a batch send issues at most one
 * lookup per distinct language.
 */
class TemplateResolver
{
    /** @var array<string, array|null> */
    private array $cache = [];

    public function __construct(
        private readonly TemplateModel $templates,
    ) {}

    public function localize(int $tenantId, array $base, ?string $language): array
    {
        $language = trim((string) $language);
        if ($language === '' || $language === ($base['language'] ?? '')) {
            return $base;
        }

        $key = ($base['name'] ?? '') . '|' . $language;
        if (! array_key_exists($key, $this->cache)) {
            $match = $this->templates
                ->setTenant($tenantId)
                ->where('name', $base['name'] ?? '')
                ->where('language', $language)
                ->where('meta_status', 'approved')
                ->first();
            $this->cache[$key] = $match ? (is_array($match) ? $match : (array) $match) : null;
        }

        return $this->cache[$key] ?? $base;
    }
}
