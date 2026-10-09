<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\AssignmentRuleModel;
use App\Models\UserModel;

/**
 * Auto-assignment for deals and tickets (Phase H2). Finds the first matching rule
 * for a record and resolves an assignee by strategy, honouring a per-rep open-item
 * capacity. Generalises the inbox routing pattern to the CRM.
 *
 * Strategies: round_robin (rotate the pool), least_loaded (fewest open items),
 * specific (a fixed user). The pool defaults to all tenant members.
 */
final class AssignmentService
{
    private const OPEN = [
        'deal'   => ['table' => 'deals',   'open' => ['open']],
        'ticket' => ['table' => 'tickets', 'open' => ['open', 'pending']],
    ];

    public function __construct(private AssignmentRuleModel $rules = new AssignmentRuleModel()) {}

    /**
     * Resolve an assignee user id for a record, or null if no rule applies / no
     * eligible rep. On round-robin, advances the rule's cursor.
     */
    public function assignee(string $entity, int $tenantId, array $record): ?int
    {
        if (! isset(self::OPEN[$entity])) {
            return null;
        }
        foreach ($this->rules->activeFor($tenantId, $entity) as $rule) {
            if (! $this->matches($rule, $record)) {
                continue;
            }
            $pool = $this->pool($tenantId, $rule);
            if (empty($pool)) {
                return null;
            }
            return match ($rule['strategy']) {
                'specific'     => $this->specific($rule, $pool),
                'least_loaded' => $this->leastLoaded($entity, $tenantId, $pool, (int) $rule['capacity']),
                default        => $this->roundRobin($entity, $tenantId, $rule, $pool),
            };
        }
        return null;
    }

    private function matches(array $rule, array $record): bool
    {
        if (($rule['match_type'] ?? 'any') !== 'field') {
            return true;
        }
        $field = $rule['match_field'] ?? '';
        return $field !== '' && (string) ($record[$field] ?? '') === (string) ($rule['match_value'] ?? '');
    }

    /** @return int[] candidate user ids (rule pool ∩ tenant members), id-ascending. */
    private function pool(int $tenantId, array $rule): array
    {
        $members = array_map('intval', array_column(
            (new UserModel())->setTenant($tenantId)->orderBy('id', 'ASC')->findAll(),
            'id'
        ));
        $configured = json_decode($rule['pool'] ?? '[]', true);
        if (is_array($configured) && $configured) {
            $set = array_map('intval', $configured);
            return array_values(array_filter($members, static fn ($id) => in_array($id, $set, true)));
        }
        return $members;
    }

    private function specific(array $rule, array $pool): ?int
    {
        $uid = (int) ($rule['assigned_user_id'] ?? 0);
        return in_array($uid, $pool, true) ? $uid : null;
    }

    private function roundRobin(string $entity, int $tenantId, array $rule, array $pool): ?int
    {
        $cursor = (int) ($rule['last_assigned_user_id'] ?? 0);
        // Order the pool starting just after the cursor (wrap around).
        $after  = array_values(array_filter($pool, static fn ($id) => $id > $cursor));
        $before = array_values(array_filter($pool, static fn ($id) => $id <= $cursor));
        $order  = array_merge($after, $before);

        $cap = (int) $rule['capacity'];
        $pick = null;
        foreach ($order as $id) {
            if ($this->openCount($entity, $tenantId, $id) < $cap) {
                $pick = $id;
                break;
            }
        }
        // Everyone at capacity → fall back to the least loaded.
        $pick ??= $this->leastLoaded($entity, $tenantId, $pool, PHP_INT_MAX);
        if ($pick !== null) {
            $this->rules->setTenant($tenantId)->update((int) $rule['id'], ['last_assigned_user_id' => $pick]);
        }
        return $pick;
    }

    private function leastLoaded(string $entity, int $tenantId, array $pool, int $cap): ?int
    {
        $best = null;
        $bestN = PHP_INT_MAX;
        foreach ($pool as $id) {
            $n = $this->openCount($entity, $tenantId, $id);
            if ($n < $cap && $n < $bestN) {
                $bestN = $n;
                $best  = $id;
            }
        }
        return $best;
    }

    private function openCount(string $entity, int $tenantId, int $userId): int
    {
        $cfg = self::OPEN[$entity];
        return (int) db_connect()->table($cfg['table'])
            ->where('tenant_id', $tenantId)->where('owner_id', $userId)
            ->whereIn('status', $cfg['open'])->where('deleted_at', null)
            ->countAllResults();
    }
}
