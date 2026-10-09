<?php

declare(strict_types=1);

namespace App\Services\Crm;

use RuntimeException;

/**
 * Duplicate detection + merge for contacts and accounts (Phase G5).
 *
 * findDuplicates() groups rows that share a dedupe key (wa_number/email for
 * contacts, domain/name for accounts). merge() keeps a primary record, fills its
 * empty fields from the losers, re-points the CRM references (deals, tickets,
 * activities, notes, tasks, pivots, associations) from the losers to the primary,
 * then soft-deletes the losers.
 *
 * GUARDRAIL: messaging + flow tables (conversations, messages, flow_runs) are
 * NEVER re-pointed — they are read-only to the CRM. A merged contact's WhatsApp
 * history stays attached to the original record; only CRM-owned relations move.
 */
final class DedupeMergeService
{
    /** Only contacts and accounts get dedupe/merge (per spec). */
    private const MERGE = [
        'contact' => [
            'fk'    => [['deals', 'primary_contact_id'], ['tickets', 'contact_id']],
            'poly'  => ['activities', 'notes', 'tasks'],   // related_type = 'contact'
            'pivot' => [
                ['deal_contacts', 'contact_id', 'deal_id', true],
                ['contact_tags', 'contact_id', 'tag_id', false],
                ['contact_field_values', 'contact_id', 'custom_field_id', false],
            ],
        ],
        'account' => [
            'fk'    => [['contacts', 'account_id'], ['deals', 'account_id'], ['tickets', 'account_id'], ['accounts', 'parent_account_id']],
            'poly'  => ['activities', 'notes', 'tasks'],   // related_type = 'account'
            'pivot' => [],
        ],
    ];

    public static function supports(string $entity): bool
    {
        return isset(self::MERGE[$entity]);
    }

    /**
     * @return array<int, array{field:string,value:string,records:array}>
     */
    public function findDuplicates(string $entity, int $tenantId): array
    {
        $this->assertSupported($entity);
        $cfg    = CrmEntityRegistry::get($entity);
        $table  = $this->table($entity, $tenantId);
        $db     = db_connect();

        $seen   = [];   // record id => already in a group
        $groups = [];

        foreach ($cfg['dedupe'] as $field) {
            $dups = $db->table($table)
                ->select($field . ' AS v, COUNT(*) AS n')
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->where("{$field} IS NOT NULL", null, false)
                ->where("{$field} != ''", null, false)
                ->groupBy($field)
                ->having('n > 1')
                ->get()->getResultArray();

            foreach ($dups as $d) {
                $records = CrmEntityRegistry::model($entity, $tenantId)->where($field, $d['v'])->findAll();
                // Skip records already grouped by an earlier key.
                $records = array_values(array_filter($records, static fn ($r) => ! isset($seen[$r['id']])));
                if (count($records) < 2) {
                    continue;
                }
                foreach ($records as $r) {
                    $seen[$r['id']] = true;
                }
                $groups[] = ['field' => $field, 'value' => (string) $d['v'], 'records' => $records];
            }
        }

        return $groups;
    }

    /**
     * @param int[] $loserIds
     * @return array{merged:int, primary_id:int}
     */
    public function merge(string $entity, int $tenantId, int $primaryId, array $loserIds): array
    {
        $this->assertSupported($entity);

        $loserIds = array_values(array_unique(array_filter(array_map('intval', $loserIds), static fn ($i) => $i > 0 && $i !== $primaryId)));
        if (empty($loserIds)) {
            return ['merged' => 0, 'primary_id' => $primaryId];
        }

        $model   = CrmEntityRegistry::model($entity, $tenantId);
        $primary = $model->find($primaryId);
        if (! $primary) {
            throw new RuntimeException('Primary record not found.');
        }
        // Re-resolve losers through the tenant scope (security gate).
        $losers = CrmEntityRegistry::model($entity, $tenantId)->whereIn('id', $loserIds)->findAll();
        $losers = array_values(array_filter($losers, static fn ($r) => (int) $r['id'] !== $primaryId));
        if (empty($losers)) {
            return ['merged' => 0, 'primary_id' => $primaryId];
        }
        $loserIds = array_map(static fn ($r) => (int) $r['id'], $losers);

        $cfg     = self::MERGE[$entity];
        $columns = CrmEntityRegistry::get($entity)['columns'];
        $db      = db_connect();

        $db->transStart();

        // 1. Fill empty primary fields from the losers (first non-empty wins).
        $fill = [];
        foreach ($columns as $col) {
            if (in_array($col, ['id', 'tenant_id'], true)) {
                continue;
            }
            if (($primary[$col] ?? '') === '' || $primary[$col] === null) {
                foreach ($losers as $l) {
                    if (($l[$col] ?? '') !== '' && $l[$col] !== null) {
                        $fill[$col] = $l[$col];
                        break;
                    }
                }
            }
        }
        if ($fill) {
            $model->update($primaryId, $fill);
        }

        // 2. Simple FK re-points (tenant-scoped, no unique constraints).
        foreach ($cfg['fk'] as [$tbl, $col]) {
            $db->table($tbl)->where('tenant_id', $tenantId)->whereIn($col, $loserIds)->update([$col => $primaryId]);
        }

        // 3. Polymorphic re-points (related_type = entity).
        foreach ($cfg['poly'] as $tbl) {
            $db->table($tbl)->where('tenant_id', $tenantId)->where('related_type', $entity)
                ->whereIn('related_id', $loserIds)->update(['related_id' => $primaryId]);
        }

        // 4. Pivot re-points with unique-collision handling.
        foreach ($cfg['pivot'] as [$tbl, $col, $otherCol, $hasTenant]) {
            $this->repointPivot($db, $tbl, $col, $otherCol, $primaryId, $loserIds, $hasTenant ? $tenantId : null);
        }

        // 5. Associations (both directions), per-row to dodge the unique index.
        $this->repointAssociations($db, $entity, $tenantId, $primaryId, $loserIds);

        // 6. Soft-delete the losers.
        $model->delete($loserIds);

        $db->transComplete();
        if ($db->transStatus() === false) {
            throw new RuntimeException('Merge failed; rolled back.');
        }

        AuditLogger::log('merged', $entity, $primaryId, null, ['merged_ids' => $loserIds], $tenantId);

        return ['merged' => count($loserIds), 'primary_id' => $primaryId];
    }

    /**
     * Move pivot rows from losers to the primary, deleting rows that would collide
     * on the (col, otherCol) unique pair (the primary already has that pairing).
     */
    private function repointPivot($db, string $table, string $col, string $otherCol, int $primaryId, array $loserIds, ?int $tenantId): void
    {
        // Values of otherCol already linked to the primary.
        $primaryPairs = $db->table($table)->select($otherCol)->where($col, $primaryId)->get()->getResultArray();
        $taken        = array_column($primaryPairs, $otherCol);

        // Delete colliding loser rows.
        $q = $db->table($table)->whereIn($col, $loserIds);
        if ($tenantId !== null) {
            $q->where('tenant_id', $tenantId);
        }
        if (! empty($taken)) {
            $q->whereIn($otherCol, $taken);
            $q->delete();
        }

        // Re-point the remainder.
        $u = $db->table($table)->whereIn($col, $loserIds);
        if ($tenantId !== null) {
            $u->where('tenant_id', $tenantId);
        }
        $u->update([$col => $primaryId]);
    }

    private function repointAssociations($db, string $entity, int $tenantId, int $primaryId, array $loserIds): void
    {
        foreach ([['from_type', 'from_id'], ['to_type', 'to_id']] as [$typeCol, $idCol]) {
            $rows = $db->table('associations')
                ->where('tenant_id', $tenantId)->where($typeCol, $entity)->whereIn($idCol, $loserIds)
                ->get()->getResultArray();

            foreach ($rows as $r) {
                // Would re-pointing collide with an existing association?
                $exists = $db->table('associations')
                    ->where('tenant_id', $tenantId)
                    ->where('from_type', $typeCol === 'from_type' ? $entity : $r['from_type'])
                    ->where('from_id', $typeCol === 'from_type' ? $primaryId : $r['from_id'])
                    ->where('to_type', $typeCol === 'to_type' ? $entity : $r['to_type'])
                    ->where('to_id', $typeCol === 'to_type' ? $primaryId : $r['to_id'])
                    ->countAllResults() > 0;

                if ($exists) {
                    $db->table('associations')->where('id', $r['id'])->delete();
                } else {
                    $db->table('associations')->where('id', $r['id'])->update([$idCol => $primaryId]);
                }
            }
        }
    }

    private function table(string $entity, int $tenantId): string
    {
        // Only contact/account are mergeable, so a direct map is clearest.
        return ['contact' => 'contacts', 'account' => 'accounts'][$entity];
    }

    private function assertSupported(string $entity): void
    {
        if (! self::supports($entity)) {
            throw new RuntimeException("Merge is not supported for '{$entity}'.");
        }
    }
}
