<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\ContactModel;
use RuntimeException;

/**
 * Applies a bulk action to a set of CRM records (Phase G2). Every field and
 * entity is validated against CrmEntityRegistry, and the target ids are first
 * re-resolved through the tenant-scoped model so a caller can never touch a row
 * outside their tenant. Messaging tables are never reachable (not in the registry).
 *
 * Actions: set (whitelisted field), delete (soft), add_tag, remove_tag.
 */
final class BulkActionService
{
    public const ACTIONS = ['set', 'delete', 'add_tag', 'remove_tag'];

    private const MAX_IDS = 1000;

    /**
     * @param int[] $ids
     * @param array{field?:string,value?:mixed,tag_id?:int} $opts
     * @return array{affected:int}
     */
    public function run(string $entity, int $tenantId, array $ids, string $action, array $opts = []): array
    {
        if (! CrmEntityRegistry::has($entity)) {
            throw new RuntimeException("Unknown entity '{$entity}'.");
        }
        if (! in_array($action, self::ACTIONS, true)) {
            throw new RuntimeException("Unknown bulk action '{$action}'.");
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if (empty($ids)) {
            return ['affected' => 0];
        }
        if (count($ids) > self::MAX_IDS) {
            throw new RuntimeException('Too many ids in one request (max ' . self::MAX_IDS . ').');
        }

        $cfg = CrmEntityRegistry::get($entity);

        // Re-resolve the ids through the tenant scope — this is the security gate.
        $valid = array_map('intval', array_column(
            CrmEntityRegistry::model($entity, $tenantId)->whereIn('id', $ids)->findAll(),
            'id'
        ));
        if (empty($valid)) {
            return ['affected' => 0];
        }

        $result = match ($action) {
            'set'        => $this->set($entity, $tenantId, $valid, $cfg, $opts),
            'delete'     => $this->delete($entity, $tenantId, $valid),
            'add_tag',
            'remove_tag' => $this->tag($entity, $tenantId, $valid, $cfg, $action, $opts),
        };

        AuditLogger::log('bulk_' . $action, $entity, null, null, [
            'ids'   => $valid,
            'field' => $opts['field'] ?? null,
            'value' => $opts['value'] ?? null,
            'tag_id'=> $opts['tag_id'] ?? null,
        ], $tenantId);

        return $result;
    }

    private function set(string $entity, int $tenantId, array $ids, array $cfg, array $opts): array
    {
        $field = (string) ($opts['field'] ?? '');
        if (! in_array($field, $cfg['bulk_set'] ?? [], true)) {
            throw new RuntimeException("Field '{$field}' cannot be bulk-set on {$entity}.");
        }
        $model = CrmEntityRegistry::model($entity, $tenantId);
        $model->whereIn('id', $ids)->where('tenant_id', $tenantId)
            ->set($field, $opts['value'] ?? null)
            ->set('updated_at', date('Y-m-d H:i:s'))
            ->update();

        return ['affected' => count($ids)];
    }

    private function delete(string $entity, int $tenantId, array $ids): array
    {
        // Model::delete() with an id array applies the configured soft delete.
        CrmEntityRegistry::model($entity, $tenantId)->delete($ids);
        return ['affected' => count($ids)];
    }

    private function tag(string $entity, int $tenantId, array $ids, array $cfg, string $action, array $opts): array
    {
        if (empty($cfg['supports_tags'])) {
            throw new RuntimeException("{$entity} does not support tags.");
        }
        $tagId = (int) ($opts['tag_id'] ?? 0);
        if ($tagId <= 0) {
            throw new RuntimeException('A tag_id is required.');
        }

        // Tag must belong to this tenant.
        $owns = db_connect()->table('tags')
            ->where('id', $tagId)->where('tenant_id', $tenantId)->where('deleted_at', null)
            ->countAllResults() > 0;
        if (! $owns) {
            throw new RuntimeException('Tag not found.');
        }

        $model = new ContactModel();   // tags only exist for contacts
        foreach ($ids as $id) {
            $action === 'add_tag' ? $model->attachTag($id, $tagId) : $model->detachTag($id, $tagId);
        }
        return ['affected' => count($ids)];
    }
}
