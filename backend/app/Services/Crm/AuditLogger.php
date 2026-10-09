<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\AuditLogModel;
use App\Services\Auth\CurrentUser;

/**
 * Append-only audit trail for CRM mutations (Phase K1). Fire-and-forget: an audit
 * failure must never break the mutation it records. Updates store only the
 * CHANGED fields (lean diff); create/delete store the relevant full row.
 */
final class AuditLogger
{
    /**
     * Record a mutation. Reads actor/tenant from CurrentUser and IP from the
     * request unless overridden (services without a request can pass them).
     */
    public static function log(string $action, string $entityType, ?int $entityId = null, ?array $before = null, ?array $after = null, ?int $tenantId = null, ?int $actorId = null): void
    {
        try {
            [$b, $a] = self::diff($action, $before, $after);

            (new AuditLogModel())->insert([
                'tenant_id'     => $tenantId ?? CurrentUser::tenantId(),
                'actor_user_id' => $actorId ?? (CurrentUser::id() ?: null),
                'action'        => $action,
                'entity_type'   => $entityType,
                'entity_id'     => $entityId,
                'before'        => $b !== null ? json_encode($b) : null,
                'after'         => $a !== null ? json_encode($a) : null,
                'ip'            => self::ip(),
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Audit log failed (' . $action . ' ' . $entityType . '): ' . $e->getMessage());
        }
    }

    /**
     * For 'updated' with both sides present, reduce to changed fields only.
     * Otherwise keep the supplied snapshots (create → after, delete → before).
     *
     * @return array{0:?array,1:?array} [before, after]
     */
    private static function diff(string $action, ?array $before, ?array $after): array
    {
        if ($before !== null && $after !== null) {
            $changedBefore = [];
            $changedAfter  = [];
            foreach ($after as $k => $v) {
                if (in_array($k, ['updated_at', 'created_at'], true)) {
                    continue;
                }
                $old = $before[$k] ?? null;
                if ((string) $old !== (string) $v) {
                    $changedBefore[$k] = $old;
                    $changedAfter[$k]  = $v;
                }
            }
            return [$changedBefore ?: null, $changedAfter ?: null];
        }
        return [$before, $after];
    }

    private static function ip(): ?string
    {
        try {
            return service('request')->getIPAddress();
        } catch (\Throwable) {
            return null;
        }
    }
}
