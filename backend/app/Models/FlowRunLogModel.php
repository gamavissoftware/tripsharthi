<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Immutable execution audit log.
 * Extends CI4's base Model — no tenant scope (access is always through flow_run_id).
 */
class FlowRunLogModel extends Model
{
    protected $table      = 'flow_run_logs';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;
    protected $useTimestamps  = false; // only created_at, no updated_at

    protected $allowedFields = ['flow_run_id', 'node_id', 'node_type', 'result', 'detail', 'created_at'];

    public function logsForRun(int $runId): array
    {
        return $this->where('flow_run_id', $runId)
                    ->orderBy('id', 'ASC')
                    ->findAll();
    }
}
