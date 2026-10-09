<?php

declare(strict_types=1);

namespace App\Services\Flow;

use App\Models\TemplateModel;

/**
 * Validates a flow graph before save and before activation.
 *
 * Returns array{valid:bool, errors:string[], warnings:string[]}
 *   errors   = block activation (hard errors)
 *   warnings = shown in builder but don't prevent save
 */
class FlowValidator
{
    /** Triggers that guarantee an open service window (contact initiated). */
    private const INBOUND_TRIGGERS = ['keyword_reply', 'inbound_message'];

    private const FREEFORM_TYPES  = ['send_freeform', 'send_media', 'send_slots'];
    private const BRANCH_TYPES    = ['condition', 'window_check', 'book_slot'];

    /** All valid action node types (non-trigger, non-branch). */
    private const ACTION_TYPES = [
        'send_template', 'send_freeform', 'send_media', 'send_interactive',
        'add_tag', 'remove_tag', 'update_status', 'assign_agent', 'handoff', 'webhook_call',
        'send_payment', 'send_product', 'ai_reply', 'send_flow', 'delay',
        // Demo booking: offer live availability, then book what was tapped.
        'send_slots', 'book_slot',
        // CRM actions (Phase C / D)
        'create_task', 'update_field', 'create_deal', 'create_ticket',
        // Email marketing
        'send_email',
    ];

    /**
     * @param  array  $flowData  Row data (trigger_type, reentry_policy, etc.)
     * @param  array  $graph     Decoded {nodes, edges} graph.
     * @param  int    $tenantId  For template ownership check.
     */
    public function validate(array $flowData, array $graph, int $tenantId): array
    {
        $errors   = [];
        $warnings = [];

        $nodes = $graph['nodes'] ?? [];
        $edges = $graph['edges'] ?? [];

        // ── Hard errors ───────────────────────────────────────────────

        // Exactly one trigger node
        $triggerNodes = array_filter($nodes, fn ($n) => in_array($n['type'] ?? '', FlowTriggers::all(), true));
        $triggerCount = count($triggerNodes);
        if ($triggerCount === 0) {
            $errors[] = 'Flow must have exactly one trigger node (has 0).';
        } elseif ($triggerCount > 1) {
            $errors[] = "Flow must have exactly one trigger node (has {$triggerCount}).";
        } else {
            // Trigger type must match flows.trigger_type
            $triggerNode = reset($triggerNodes);
            if (($triggerNode['type'] ?? '') !== ($flowData['trigger_type'] ?? '')) {
                $errors[] = sprintf(
                    "Trigger node type '%s' does not match flow trigger_type '%s'.",
                    $triggerNode['type'] ?? '',
                    $flowData['trigger_type'] ?? ''
                );
            }
        }

        // send_template nodes must reference approved templates this tenant owns
        $templateModel = (new TemplateModel())->setTenant($tenantId);
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') !== 'send_template') continue;
            $templateId = (int) ($node['data']['template_id'] ?? 0);
            if ($templateId === 0) {
                $errors[] = "send_template node '{$node['id']}' has no template selected.";
                continue;
            }
            $tpl = $templateModel->find($templateId);
            if ($tpl === null) {
                $errors[] = "send_template node '{$node['id']}': template #{$templateId} not found.";
            } elseif (($tpl['meta_status'] ?? '') !== 'approved') {
                $errors[] = "send_template node '{$node['id']}': template '{$tpl['name']}' is not approved "
                    . "(status: {$tpl['meta_status']}).";
            }
        }

        // ── Warnings ──────────────────────────────────────────────────

        // Orphan nodes (not reachable from the trigger)
        if ($triggerCount === 1) {
            $triggerNode = reset($triggerNodes);
            $reachable   = $this->reachableNodes($graph, $triggerNode['id']);
            $nodeIds     = array_column($nodes, 'id');
            $orphans     = array_diff($nodeIds, $reachable);
            foreach ($orphans as $orphanId) {
                if (! in_array($orphanId, array_column(array_values($triggerNodes), 'id'), true)) {
                    $warnings[] = "Node '{$orphanId}' is not reachable from the trigger (orphan).";
                }
            }
        }

        // Branch nodes with dangling handles
        foreach ($nodes as $node) {
            if (! in_array($node['type'] ?? '', self::BRANCH_TYPES, true)) continue;
            // Each branch type names its own handles; assuming true/false for
            // all of them reports phantom dangling handles on the others.
            $handles = match ($node['type']) {
                'window_check' => ['open', 'closed'],
                'book_slot'    => ['booked', 'failed'],
                default        => ['true', 'false'],
            };
            foreach ($handles as $handle) {
                $edge = $this->findEdge($edges, $node['id'], $handle);
                if ($edge === null) {
                    $warnings[] = "'{$node['type']}' node '{$node['id']}' handle '{$handle}' has no outgoing edge.";
                }
            }
        }

        // ── Free-form window warning (§1) ─────────────────────────────
        $triggerType = $flowData['trigger_type'] ?? '';
        foreach ($nodes as $node) {
            if (! in_array($node['type'] ?? '', self::FREEFORM_TYPES, true)) continue;

            if (! $this->isGuaranteedInOpenWindow($node['id'], $graph, $triggerType)) {
                $warnings[] = sprintf(
                    "'%s' node '%s' may execute when the 24-hour service window is closed. "
                    . "Add a window_check node upstream with a 'window_closed' fallback edge, "
                    . "or use an inbound trigger (keyword_reply / inbound_message).",
                    $node['type'],
                    $node['id']
                );
            }
        }

        return [
            'valid'    => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    // ------------------------------------------------------------------
    // Graph traversal helpers
    // ------------------------------------------------------------------

    /** BFS from startNodeId; returns all reachable node IDs (including start). */
    private function reachableNodes(array $graph, string $startNodeId): array
    {
        $visited = [];
        $queue   = [$startNodeId];

        while (! empty($queue)) {
            $current = array_shift($queue);
            if (in_array($current, $visited, true)) continue;
            $visited[] = $current;

            foreach ($graph['edges'] ?? [] as $edge) {
                if ($edge['source'] === $current
                    && ! in_array($edge['target'], $visited, true)) {
                    $queue[] = $edge['target'];
                }
            }
        }

        return $visited;
    }

    /**
     * Check whether every path from the trigger to $targetNodeId passes through
     * a window-opener (inbound trigger) or a window_check/open edge.
     * Returns true = guaranteed open; false = emit the freeform warning.
     */
    private function isGuaranteedInOpenWindow(
        string $targetNodeId,
        array  $graph,
        string $triggerType
    ): bool {
        // Case 1: inbound trigger opens the window for ALL downstream nodes
        if (in_array($triggerType, self::INBOUND_TRIGGERS, true)) {
            return true;
        }

        // Case 2: DFS from every entry point; each path to targetNodeId must
        // traverse a window_check node's 'open' edge
        $triggerNode = null;
        foreach ($graph['nodes'] ?? [] as $n) {
            if (in_array($n['type'] ?? '', FlowTriggers::all(), true)) {
                $triggerNode = $n;
                break;
            }
        }
        if ($triggerNode === null) return false;

        return $this->allPathsHaveWindowOpener(
            $triggerNode['id'],
            $targetNodeId,
            $graph,
            false, // windowConfirmed on current path
            []     // visited (cycle prevention)
        );
    }

    private function allPathsHaveWindowOpener(
        string $currentId,
        string $targetId,
        array  $graph,
        bool   $windowConfirmed,
        array  $visited
    ): bool {
        if ($currentId === $targetId) {
            return $windowConfirmed;
        }

        if (in_array($currentId, $visited, true)) {
            return false; // Cycle — treat as not confirmed
        }

        $visited[] = $currentId;
        $outEdges  = array_filter($graph['edges'] ?? [],
            fn ($e) => $e['source'] === $currentId
        );

        if (empty($outEdges)) {
            // Dead end — this path never reaches the target, so it places no
            // constraint on whether the window was confirmed. Return true so that
            // sibling paths (e.g. the window_check 'open' branch that DOES reach
            // the freeform node) are not penalised by an unrelated dead end.
            return true;
        }

        foreach ($outEdges as $edge) {
            // A window_check node's 'open' edge confirms the window
            $confirmed = $windowConfirmed;
            if (($edge['sourceHandle'] ?? 'next') === 'open') {
                // Check if source is a window_check node
                foreach ($graph['nodes'] ?? [] as $n) {
                    if ($n['id'] === $currentId && $n['type'] === 'window_check') {
                        $confirmed = true;
                        break;
                    }
                }
            }

            if (! $this->allPathsHaveWindowOpener(
                $edge['target'], $targetId, $graph, $confirmed, $visited
            )) {
                return false; // At least one path is unconfirmed
            }
        }

        return true;
    }

    private function findEdge(array $edges, string $fromNodeId, string $sourceHandle): ?array
    {
        foreach ($edges as $edge) {
            if ($edge['source'] === $fromNodeId
                && ($edge['sourceHandle'] ?? 'next') === $sourceHandle) {
                return $edge;
            }
        }
        return null;
    }
}
