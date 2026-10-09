<?php

declare(strict_types=1);

namespace App\Services\Flow;

use App\Models\JobModel;

/**
 * Static facade for enqueuing durable jobs.
 *
 * Callers never touch the jobs table directly — they call JobDispatcher::dispatch().
 * Phase 5 replaces the direct CampaignSender / Importer controller calls with this.
 *
 * Clock rule: run_at is PHP time() — never MySQL NOW() — matching WindowService.
 *
 * @see App\Models\JobModel
 */
class JobDispatcher
{
    /**
     * Enqueue a job.
     *
     * @param  int         $tenantId
     * @param  string      $type       'flow_start' | 'flow_resume' | 'campaign_send' | 'lead_import' | 'meta_lead_fetch'
     * @param  array       $payload    Arbitrary context, stored as JSON.
     * @param  int|null    $runAt      Unix timestamp for when to run; null = now (immediate).
     * @param  string|null $sourceType Table name of the owning source row (e.g. 'meta_lead_events').
     *                                 Used by OrphanedRowSweeper for indexed orphan detection.
     *                                 Omit for fire-and-forget jobs (flow_start, flow_resume).
     * @param  int|null    $sourceId   Primary key of the owning source row.
     * @return int  The new job's ID.
     */
    public static function dispatch(
        int     $tenantId,
        string  $type,
        array   $payload,
        ?int    $runAt     = null,
        ?string $sourceType = null,
        ?int    $sourceId   = null,
    ): int {
        $row = [
            'tenant_id'    => $tenantId,
            'type'         => $type,
            'payload'      => json_encode($payload),
            'run_at'       => date('Y-m-d H:i:s', $runAt ?? time()),
            'status'       => 'pending',
            'attempts'     => 0,
            'max_attempts' => 5,
        ];

        if ($sourceType !== null) {
            $row['source_type'] = $sourceType;
        }
        if ($sourceId !== null) {
            $row['source_id'] = $sourceId;
        }

        return (int) (new JobModel())->insert($row, true);
    }
}
