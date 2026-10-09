<?php

declare(strict_types=1);

namespace App\Services\Inbox;

use App\Models\ConversationModel;
use App\Models\RoutingRuleModel;

/**
 * Assigns inbound conversations to agents based on tenant routing rules.
 *
 * Called on every inbound message for a conversation that has no agent yet.
 * Rules are evaluated in sort_order; the first match wins.
 *
 * Two strategies:
 *   - specific     → always the rule's assigned_user_id
 *   - least_loaded → the agent with the fewest OPEN conversations (self-balancing
 *                    round-robin; ties broken by lowest user id for determinism)
 */
class AgentRouter
{
    public function __construct(
        private readonly ?ConversationModel $conversationModel = null,
        private readonly ?RoutingRuleModel  $ruleModel         = null,
    ) {}

    /**
     * Evaluate rules for an unassigned conversation and assign an agent.
     *
     * @param  array $ctx  ['tag_ids' => int[], 'body' => string]
     * @return int|null    Assigned user id, or null if nothing matched / already assigned.
     */
    public function route(int $tenantId, int $conversationId, array $ctx): ?int
    {
        if ($tenantId <= 0 || $conversationId <= 0) {
            return null;
        }

        $convModel = $this->conversationModel ?? new ConversationModel();

        $conv = $convModel->setTenant($tenantId)->find($conversationId);
        if ($conv === null) {
            return null;
        }
        // Never reassign an already-owned conversation.
        if (! empty($conv['assigned_user_id'])) {
            return null;
        }

        $ruleModel = $this->ruleModel ?? new RoutingRuleModel();
        $rules     = $ruleModel->activeOrdered($tenantId);

        foreach ($rules as $rule) {
            if (! self::matches($rule, $ctx)) {
                continue;
            }

            $agentId = $this->resolveAgentForRule($tenantId, $rule);
            if ($agentId > 0) {
                $convModel->setTenant($tenantId)->update($conversationId, [
                    'assigned_user_id' => $agentId,
                ]);
                return $agentId;
            }
        }

        return null;
    }

    /**
     * Pure rule matcher — no DB.
     *
     * @param array $ctx ['tag_ids' => int[], 'body' => string]
     */
    public static function matches(array $rule, array $ctx): bool
    {
        $type  = $rule['match_type'] ?? 'any';
        $value = (string) ($rule['match_value'] ?? '');

        return match ($type) {
            'any'     => true,
            'tag'     => in_array((int) $value, array_map('intval', $ctx['tag_ids'] ?? []), true),
            'keyword' => $value !== ''
                && stripos((string) ($ctx['body'] ?? ''), $value) !== false,
            default   => false,
        };
    }

    /**
     * Resolve the agent for a matched rule.
     */
    private function resolveAgentForRule(int $tenantId, array $rule): int
    {
        if (($rule['strategy'] ?? 'least_loaded') === 'specific') {
            $uid = (int) ($rule['assigned_user_id'] ?? 0);
            return $this->isTenantUser($tenantId, $uid) ? $uid : 0;
        }

        return $this->pickLeastLoaded($tenantId, $this->tenantAgentIds($tenantId)) ?? 0;
    }

    /**
     * Of the candidate agents, return the one with the fewest open conversations.
     *
     * @param  int[] $candidateIds
     */
    public function pickLeastLoaded(int $tenantId, array $candidateIds): ?int
    {
        $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
        if ($candidateIds === []) {
            return null;
        }
        sort($candidateIds); // ascending → deterministic tie-break by lowest id

        $db = db_connect();
        $rows = $db->table('conversations')
            ->select('assigned_user_id')
            ->selectCount('id', 'open_count')
            ->where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->whereIn('assigned_user_id', $candidateIds)
            ->groupBy('assigned_user_id')
            ->get()
            ->getResultArray();

        $load = [];
        foreach ($candidateIds as $id) {
            $load[$id] = 0;
        }
        foreach ($rows as $r) {
            $load[(int) $r['assigned_user_id']] = (int) $r['open_count'];
        }

        // Lowest load wins; deterministic tie-break by lowest user id.
        $bestId   = null;
        $bestLoad = PHP_INT_MAX;
        foreach ($candidateIds as $id) {
            if ($load[$id] < $bestLoad) {
                $bestLoad = $load[$id];
                $bestId   = $id;
            }
        }

        return $bestId;
    }

    /** @return int[] */
    private function tenantAgentIds(int $tenantId): array
    {
        $rows = db_connect()->table('users')
            ->select('id')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();

        return array_map(static fn ($r) => (int) $r['id'], $rows);
    }

    private function isTenantUser(int $tenantId, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        return db_connect()->table('users')
            ->where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->countAllResults() > 0;
    }
}
