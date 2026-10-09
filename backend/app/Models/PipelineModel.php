<?php

declare(strict_types=1);

namespace App\Models;

class PipelineModel extends BaseModel
{
    protected $table      = 'pipelines';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'name', 'entity_type', 'is_default', 'position',
    ];

    /** Default Generic B2B stages used when provisioning a tenant's first pipeline. */
    private const DEFAULT_STAGES = [
        // [name, probability, is_won, is_lost]
        ['Lead', 10, 0, 0],
        ['Qualified', 25, 0, 0],
        ['Proposal', 50, 0, 0],
        ['Negotiation', 75, 0, 0],
        ['Won', 100, 1, 0],
        ['Lost', 0, 0, 1],
    ];

    /**
     * Return the tenant's default deal pipeline, lazily provisioning a Generic
     * B2B pipeline (with stages) on first access. Multi-tenant safe — every
     * tenant gets a working pipeline the first time they open the deal board.
     */
    public function ensureDefault(int $tenantId): array
    {
        $existing = $this->setTenant($tenantId)
            ->where('entity_type', 'deal')
            ->orderBy('is_default', 'DESC')
            ->orderBy('id', 'ASC')
            ->first();

        return $existing ?: $this->provisionDefault($tenantId);
    }

    public function provisionDefault(int $tenantId): array
    {
        $id = (int) $this->setTenant($tenantId)->insert([
            'name' => 'Sales Pipeline', 'entity_type' => 'deal', 'is_default' => 1, 'position' => 0,
        ], true);

        $stageModel = new PipelineStageModel();
        foreach (self::DEFAULT_STAGES as $i => [$name, $prob, $won, $lost]) {
            $stageModel->setTenant($tenantId)->insert([
                'pipeline_id' => $id, 'name' => $name, 'position' => $i,
                'probability' => $prob, 'is_won' => $won, 'is_lost' => $lost,
            ]);
        }

        return (new PipelineModel())->setTenant($tenantId)->find($id);
    }

    /** All deal pipelines for a tenant. */
    public function dealsPipelines(int $tenantId): array
    {
        return $this->setTenant($tenantId)
            ->where('entity_type', 'deal')
            ->orderBy('position', 'ASC')
            ->findAll();
    }
}
