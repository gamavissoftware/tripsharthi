<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\CampaignModel;
use App\Services\Flow\JobDispatcher;

/**
 * Promotes due scheduled campaigns into the send pipeline.
 *
 * A campaign scheduled by the user is stored as:
 *   status        = 'scheduled'
 *   scheduled_at  = the send instant in UTC ('Y-m-d H:i:s')
 *   schedule_timezone = the IANA zone the user picked (display only)
 *
 * Every minute the cron command `campaigns:dispatch-scheduled` calls
 * dispatchDue(). For each campaign whose scheduled_at has arrived it:
 *   1. Flips status 'scheduled' → 'processing' (the state processBatch accepts).
 *   2. Enqueues the existing 'campaign_send' job.
 *
 * The flip happens BEFORE the enqueue and is the idempotency guard: a second
 * dispatcher pass in the same minute will no longer see status='scheduled',
 * so a campaign can never be double-enqueued.
 *
 * Clock rule: callers pass PHP time() — never MySQL NOW() — matching the rest
 * of the queue stack.
 */
class ScheduledCampaignDispatcher
{
    public function __construct(
        private readonly ?CampaignModel $campaignModel = null,
    ) {}

    /**
     * Convert a wall-clock datetime in a given IANA timezone to a UTC string.
     *
     * Pure (no DB) — unit-tested directly.
     *
     * @throws \InvalidArgumentException on an unknown timezone or unparseable date.
     */
    public static function toUtc(string $localDateTime, string $timezone): string
    {
        // Accept every zone PHP can resolve, including the backward-compatible
        // aliases browsers still report — many Indian machines give
        // Intl "Asia/Calcutta", which timezone_identifiers_list() omits.
        // Offsets and abbreviations ("+05:30", "IST") are not zone names.
        if (! in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new \InvalidArgumentException("Unknown timezone: {$timezone}");
        }

        try {
            $dt = new \DateTimeImmutable($localDateTime, new \DateTimeZone($timezone));
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Unparseable datetime: {$localDateTime}");
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Dispatch every scheduled campaign whose time has come.
     *
     * @param  int $nowTs  Unix timestamp (PHP time()).
     * @return array{dispatched:int, ids:int[]}
     */
    public function dispatchDue(int $nowTs): array
    {
        $nowUtc = gmdate('Y-m-d H:i:s', $nowTs);

        // Cross-tenant read — the worker runs globally. Each row carries tenant_id.
        $due = (new CampaignModel())
            ->withoutTenantScope()
            ->where('status', 'scheduled')
            ->where('scheduled_at <=', $nowUtc)
            ->orderBy('scheduled_at', 'ASC')
            ->findAll();

        $db  = db_connect();
        $ids = [];
        foreach ($due as $campaign) {
            $tenantId   = (int) $campaign['tenant_id'];
            $campaignId = (int) $campaign['id'];
            if ($tenantId <= 0 || $campaignId <= 0) {
                continue;
            }

            // Atomic claim: flip 'scheduled' → 'processing' in a single conditional
            // UPDATE. If another pass already claimed it, affectedRows() is 0 and we
            // skip — this is the real idempotency guard against double-enqueue.
            $db->table('campaigns')
                ->where('id', $campaignId)
                ->where('tenant_id', $tenantId)
                ->where('status', 'scheduled')
                ->update(['status' => 'processing', 'updated_at' => date('Y-m-d H:i:s')]);

            if ($db->affectedRows() < 1) {
                continue;
            }

            JobDispatcher::dispatch(
                tenantId:   $tenantId,
                type:       'campaign_send',
                payload:    ['campaign_id' => $campaignId],
                sourceType: 'campaigns',
                sourceId:   $campaignId,
            );

            $ids[] = $campaignId;
        }

        return ['dispatched' => count($ids), 'ids' => $ids];
    }
}
