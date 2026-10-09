<?php

declare(strict_types=1);

namespace App\Services\Crm;

/**
 * Global, tenant-scoped quick search across CRM entities (Phase G3).
 *
 * Searches the registry entities (contacts, accounts, deals, tickets) using each
 * entity's whitelisted `searchable` fields, plus a READ-ONLY scan of message
 * bodies that resolves back to the owning contact. Nothing here ever writes — the
 * messaging tables are touched with plain SELECTs only, honouring the WhatsApp
 * read-only guardrail.
 */
final class SearchService
{
    /** Entities that have a dedicated detail route ({route}/{id}). */
    private const HAS_DETAIL = ['contact', 'deal', 'ticket'];

    private const ENTITIES = ['contact', 'account', 'deal', 'ticket'];

    /**
     * @return array{groups: array<int, array{type:string,label:string,items:array}>, total:int}
     */
    public function search(int $tenantId, string $q, int $perType = 5): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return ['groups' => [], 'total' => 0];
        }

        $groups = [];
        $total  = 0;

        foreach (self::ENTITIES as $entity) {
            $items = $this->searchEntity($entity, $tenantId, $q, $perType);
            if ($items) {
                $cfg      = CrmEntityRegistry::get($entity);
                $groups[] = ['type' => $entity, 'label' => $cfg['label'], 'items' => $items];
                $total += count($items);
            }
        }

        $messages = $this->searchMessages($tenantId, $q, $perType);
        if ($messages) {
            $groups[] = ['type' => 'message', 'label' => 'Messages', 'items' => $messages];
            $total += count($messages);
        }

        return ['groups' => $groups, 'total' => $total];
    }

    private function searchEntity(string $entity, int $tenantId, string $q, int $limit): array
    {
        $cfg   = CrmEntityRegistry::get($entity);
        $model = CrmEntityRegistry::model($entity, $tenantId);

        $model->groupStart();
        foreach (array_values($cfg['searchable']) as $i => $f) {
            $i === 0 ? $model->like($f, $q) : $model->orLike($f, $q);
        }
        $model->groupEnd();

        $cols     = $cfg['columns'];
        $titleCol = $cols[0] ?? 'id';
        $subCol   = $cols[1] ?? null;
        $detail   = in_array($entity, self::HAS_DETAIL, true);

        $rows = $model->orderBy('updated_at', 'DESC')->findAll($limit);
        return array_map(static function ($r) use ($titleCol, $subCol, $detail, $cfg) {
            return [
                'id'       => (int) $r['id'],
                'title'    => (string) ($r[$titleCol] ?? ('#' . $r['id'])) ?: ('#' . $r['id']),
                'subtitle' => $subCol !== null ? (string) ($r[$subCol] ?? '') : '',
                'url'      => $detail ? $cfg['route'] . '/' . $r['id'] : $cfg['route'],
            ];
        }, $rows);
    }

    /**
     * READ-ONLY scan of message bodies → owning contact. Plain SELECT, no writes.
     */
    private function searchMessages(int $tenantId, string $q, int $limit): array
    {
        $rows = db_connect()->table('messages m')
            ->select('m.id, m.contact_id, m.body, c.name AS contact_name')
            ->join('contacts c', 'c.id = m.contact_id', 'left')
            ->where('m.tenant_id', $tenantId)
            ->like('m.body', $q)
            ->orderBy('m.id', 'DESC')
            ->limit($limit)
            ->get()->getResultArray();

        return array_values(array_map(static function ($r) {
            $body = (string) ($r['body'] ?? '');
            return [
                'id'       => (int) $r['contact_id'],
                'title'    => $r['contact_name'] ?: ('Contact #' . $r['contact_id']),
                'subtitle' => mb_strlen($body) > 60 ? mb_substr($body, 0, 60) . '…' : $body,
                'url'      => '/contacts/' . (int) $r['contact_id'],
            ];
        }, array_filter($rows, static fn ($r) => ! empty($r['contact_id']))));
    }
}
