<?php

declare(strict_types=1);

namespace App\Models;

class FlowRunModel extends BaseModel
{
    protected $table      = 'flow_runs';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'flow_id', 'contact_id', 'current_node_id',
        'status', 'is_test', 'state', 'graph_snapshot', 'next_run_at',
        'steps_executed', 'entered_at', 'completed_at',
    ];

    /**
     * Check for a live (non-terminal) run for a (flow, contact) pair.
     * Used for reentry_policy='once'.
     */
    public function findActiveRun(int $flowId, int $contactId): array|object|null
    {
        return $this->withoutTenantScope()
                    ->where('flow_id', $flowId)
                    ->where('contact_id', $contactId)
                    ->whereIn('status', ['running', 'waiting'])
                    ->first();
    }
}
