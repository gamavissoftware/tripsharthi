<?php

declare(strict_types=1);

namespace App\Services\Crm;

use RuntimeException;

/**
 * Recycle bin over soft-deleted CRM records (Phase G6). Lists, restores, or
 * permanently purges rows that carry a deleted_at. Every operation re-resolves
 * the target ids through the tenant-scoped, only-deleted query first, so a caller
 * can neither restore nor purge anything outside their tenant. Messaging tables
 * are not in the registry and are never reachable here.
 */
final class RecycleBinService
{
    private const MAX_IDS = 1000;

    /** Soft-deleted rows for an entity (most-recently-deleted first). */
    public function deleted(string $entity, int $tenantId, int $limit = 100): array
    {
        $this->assertEntity($entity);
        return CrmEntityRegistry::model($entity, $tenantId)
            ->onlyDeleted()
            ->orderBy('deleted_at', 'DESC')
            ->findAll($limit);
    }

    /** @param int[] $ids @return array{restored:int} */
    public function restore(string $entity, int $tenantId, array $ids): array
    {
        $valid = $this->resolveDeleted($entity, $tenantId, $ids);
        if (empty($valid)) {
            return ['restored' => 0];
        }
        CrmEntityRegistry::model($entity, $tenantId)->builder()
            ->whereIn('id', $valid)->where('tenant_id', $tenantId)
            ->update(['deleted_at' => null]);

        AuditLogger::log('restored', $entity, null, null, ['ids' => $valid], $tenantId);
        return ['restored' => count($valid)];
    }

    /** Permanently remove the rows (hard delete). @param int[] $ids @return array{purged:int} */
    public function purge(string $entity, int $tenantId, array $ids): array
    {
        $valid = $this->resolveDeleted($entity, $tenantId, $ids);
        if (empty($valid)) {
            return ['purged' => 0];
        }
        CrmEntityRegistry::model($entity, $tenantId)->builder()
            ->whereIn('id', $valid)->where('tenant_id', $tenantId)
            ->delete();

        AuditLogger::log('purged', $entity, null, ['ids' => $valid], null, $tenantId);
        return ['purged' => count($valid)];
    }

    /** Re-resolve the requested ids to ones that are genuinely deleted AND this tenant's. */
    private function resolveDeleted(string $entity, int $tenantId, array $ids): array
    {
        $this->assertEntity($entity);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if (empty($ids)) {
            return [];
        }
        if (count($ids) > self::MAX_IDS) {
            throw new RuntimeException('Too many ids in one request (max ' . self::MAX_IDS . ').');
        }

        return array_map('intval', array_column(
            CrmEntityRegistry::model($entity, $tenantId)->onlyDeleted()->whereIn('id', $ids)->findAll(),
            'id'
        ));
    }

    private function assertEntity(string $entity): void
    {
        if (! CrmEntityRegistry::has($entity)) {
            throw new RuntimeException("Unknown entity '{$entity}'.");
        }
    }
}
