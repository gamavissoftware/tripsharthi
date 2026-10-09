<?php

declare(strict_types=1);

namespace App\Services\Push;

/** Who should hear about an event: the record's owner if they are an active user, otherwise the owners/admins. */
final class PushRecipients
{
    /** @return list<int> */
    public static function staff(int $tenantId): array
    {
        $rows = db_connect()->table('users')->select('id')->where('tenant_id', $tenantId)->whereIn('role', ['owner', 'admin'])->where('deleted_at', null)->get()->getResultArray();
        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    /** @return list<int> */
    public static function ownerOrStaff(int $tenantId, ?int $ownerId): array
    {
        if ($ownerId) {
            $u = db_connect()->table('users')->select('id')->where('id', $ownerId)->where('tenant_id', $tenantId)->where('deleted_at', null)->get()->getRowArray();
            if ($u) { return [(int) $u['id']]; }
        }
        return self::staff($tenantId);
    }
}
