<?php

declare(strict_types=1);

namespace App\Services\Crm;

/**
 * Resolves the set of user-ids visible to a user via team membership (Phase K3).
 * Used by the opt-in "team" list scope — self plus everyone who shares a team.
 * Always includes the user themself; a user in no team sees only their own.
 */
final class TeamVisibilityService
{
    /** @return int[] distinct user ids visible to $userId (self + teammates). */
    public function visibleUserIds(int $tenantId, int $userId): array
    {
        $db = db_connect();

        // Teams the user belongs to.
        $teamIds = array_column(
            $db->table('team_members')->select('team_id')
                ->where('tenant_id', $tenantId)->where('user_id', $userId)->get()->getResultArray(),
            'team_id'
        );

        $ids = [$userId];
        if ($teamIds) {
            $mates = $db->table('team_members')->select('user_id')
                ->where('tenant_id', $tenantId)->whereIn('team_id', $teamIds)
                ->get()->getResultArray();
            foreach ($mates as $m) {
                $ids[] = (int) $m['user_id'];
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
