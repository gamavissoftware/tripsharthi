<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\PipelineModel;
use App\Models\PipelineStageModel;

/** Provisions the tenant's "Travel Sales" deal pipeline (idempotent). */
final class TravelPipelineService
{
    public const NAME = 'Travel Sales';

    private const STAGES = [
        // [name, probability, is_won, is_lost, rotting_days]
        ['New Enquiry', 10, 0, 0, 1],
        ['Qualified', 25, 0, 0, 3],
        ['Itinerary Sent', 50, 0, 0, 4],
        ['Negotiation', 75, 0, 0, 5],
        ['Booking Confirmed', 100, 1, 0, null],
        ['Lost', 0, 0, 1, null],
    ];

    public function ensure(int $tenantId): array
    {
        $existing = (new PipelineModel())->setTenant($tenantId)
            ->where('entity_type', 'deal')->where('name', self::NAME)->first();
        if ($existing) {
            return $existing;
        }
        $hasDefault = (new PipelineModel())->setTenant($tenantId)->where('entity_type', 'deal')->countAllResults() > 0;
        $id = (int) (new PipelineModel())->setTenant($tenantId)->insert([
            'name' => self::NAME, 'entity_type' => 'deal', 'is_default' => $hasDefault ? 0 : 1, 'position' => 0,
        ], true);
        foreach (self::STAGES as $i => [$name, $prob, $won, $lost, $rot]) {
            (new PipelineStageModel())->setTenant($tenantId)->insert([
                'pipeline_id' => $id, 'name' => $name, 'position' => $i,
                'probability' => $prob, 'is_won' => $won, 'is_lost' => $lost, 'rotting_days' => $rot,
            ]);
        }
        return (new PipelineModel())->setTenant($tenantId)->find($id);
    }

    /** @return array<string,array> stage rows keyed by name */
    public function stages(int $tenantId, int $pipelineId): array
    {
        $out = [];
        foreach ((new PipelineStageModel())->forPipeline($tenantId, $pipelineId) as $s) {
            $out[$s['name']] = $s;
        }
        return $out;
    }
}
