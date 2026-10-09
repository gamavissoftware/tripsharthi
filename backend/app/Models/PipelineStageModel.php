<?php

declare(strict_types=1);

namespace App\Models;

class PipelineStageModel extends BaseModel
{
    protected $table      = 'pipeline_stages';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'pipeline_id', 'name', 'position', 'probability', 'is_won', 'is_lost', 'rotting_days',
    ];

    /** Ordered stages of a pipeline. */
    public function forPipeline(int $tenantId, int $pipelineId): array
    {
        return $this->setTenant($tenantId)
            ->where('pipeline_id', $pipelineId)
            ->orderBy('position', 'ASC')
            ->findAll();
    }
}
