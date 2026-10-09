<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\UserModel;

/**
 * Detects @mentions of teammates in free text (Phase L). A mention is `@` followed
 * by a tenant member's display name, not immediately followed by another
 * letter/number (so `@John` doesn't match a longer `@Johnny`). Tenant-scoped.
 */
final class MentionService
{
    /**
     * @return int[] distinct user ids mentioned in $text, excluding $excludeUserId.
     */
    public function mentionedUserIds(int $tenantId, string $text, int $excludeUserId = 0): array
    {
        if (! str_contains($text, '@')) {
            return [];
        }
        $users = (new UserModel())->setTenant($tenantId)->findAll();
        // Longest names first so "@Anna Maria" wins over "@Anna".
        usort($users, static fn ($a, $b) => mb_strlen((string) $b['name']) <=> mb_strlen((string) $a['name']));

        $hits = [];
        foreach ($users as $u) {
            $name = trim((string) ($u['name'] ?? ''));
            if ($name === '' || (int) $u['id'] === $excludeUserId) {
                continue;
            }
            $pattern = '/@' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu';
            if (preg_match($pattern, $text)) {
                $hits[(int) $u['id']] = true;
            }
        }
        return array_keys($hits);
    }
}
