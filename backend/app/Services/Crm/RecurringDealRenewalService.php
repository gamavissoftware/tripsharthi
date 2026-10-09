<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\ActivityModel;
use App\Models\DealModel;
use App\Models\PipelineStageModel;

/**
 * Generates renewal deals for won, recurring deals whose next_renewal_at has
 * arrived (Phase L recurring deals, part 2). For each due deal it clones a fresh
 * OPEN renewal deal at the pipeline's first stage and advances the source deal's
 * next_renewal_at by one interval, so exactly one renewal is created per cycle.
 *
 * Opt-in: nothing happens unless the `deals:renewals` command is scheduled.
 * Idempotent: advancing next_renewal_at means a re-run before the next cycle
 * generates nothing.
 */
final class RecurringDealRenewalService
{
    /** @return int number of renewal deals created */
    public function generateDue(int $tenantId, ?string $today = null): int
    {
        $today = $today ?? date('Y-m-d');
        $model = new DealModel();

        $due = $model->setTenant($tenantId)
            ->where('status', 'won')
            ->where('is_recurring', 1)
            ->where('next_renewal_at IS NOT NULL', null, false)
            ->where('next_renewal_at <=', $today)
            ->findAll();

        $created = 0;
        foreach ($due as $deal) {
            $stages = (new PipelineStageModel())->forPipeline($tenantId, (int) $deal['pipeline_id']);
            if (empty($stages)) {
                continue; // can't place a renewal without a stage
            }

            $renewalId = (int) $model->setTenant($tenantId)->insert([
                'title'              => $deal['title'] . ' (renewal)',
                'pipeline_id'        => (int) $deal['pipeline_id'],
                'stage_id'           => (int) $stages[0]['id'],
                'account_id'         => $deal['account_id'] ?? null,
                'primary_contact_id' => $deal['primary_contact_id'] ?? null,
                'owner_id'           => $deal['owner_id'] ?? null,
                'value_amount'       => (int) ($deal['value_amount'] ?? 0),
                'is_recurring'       => 1,
                'recurring_interval' => $deal['recurring_interval'] ?? null,
                'currency'           => $deal['currency'] ?? 'INR',
                'status'             => 'open',
                'source'             => 'renewal',
                'last_activity_at'   => date('Y-m-d H:i:s'),
            ], true);

            // Advance the source deal's renewal date by one interval (idempotency).
            $model->setTenant($tenantId)->update((int) $deal['id'], [
                'next_renewal_at' => DealModel::advanceRenewal((string) $deal['next_renewal_at'], $deal['recurring_interval'] ?? null),
            ]);

            (new ActivityModel())->log($tenantId, 'system', 'deal', $renewalId, [
                'subject' => 'Renewal opened from deal #' . $deal['id'],
            ]);
            $created++;
        }

        return $created;
    }
}
