<?php

declare(strict_types=1);

namespace App\Services\Email\Marketing;

use App\Models\EmailCampaignModel;
use App\Services\Flow\JobDispatcher;

/**
 * Promotes due scheduled email campaigns into the send queue. Runs inside the
 * existing `campaigns:dispatch-scheduled` minute cron, so no new cron line.
 *
 * The conditional UPDATE scheduled → processing is the idempotency guard: a
 * second pass in the same minute no longer sees status='scheduled'.
 *
 * Follow-ups wait their turn. A step whose predecessor (after_campaign_id, or
 * the source campaign) is still sending, paused or not yet sent is pushed back
 * an hour instead of starting — otherwise a big first email throttled by the
 * daily limit would be overtaken by its own follow-up, and the follow-up would
 * only reach the people the first email had got to so far.
 */
final class EmailCampaignScheduler
{
    /** @return array{dispatched:int, ids:int[]} */
    public function dispatchDue(int $nowTs): array
    {
        $due = (new EmailCampaignModel())
            ->withoutTenantScope()
            ->where('status', 'scheduled')
            ->where('scheduled_at <=', gmdate('Y-m-d H:i:s', $nowTs))
            ->orderBy('scheduled_at', 'ASC')
            ->findAll(100);

        $db  = db_connect();
        $ids = [];
        foreach ($due as $c) {
            $id       = (int) $c['id'];
            $tenantId = (int) $c['tenant_id'];

            $waitingFor = $this->unfinishedPredecessor($c);
            if ($waitingFor !== null) {
                $db->table('email_campaigns')->where('id', $id)->where('status', 'scheduled')->update([
                    'scheduled_at' => gmdate('Y-m-d H:i:s', $nowTs + 3600),
                    'last_error'   => "Waiting for \"{$waitingFor['name']}\" to finish sending — checked again every hour.",
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
                continue;
            }

            $db->table('email_campaigns')
                ->where('id', $id)->where('tenant_id', $tenantId)->where('status', 'scheduled')
                ->update(['status' => 'processing', 'last_error' => null, 'updated_at' => date('Y-m-d H:i:s')]);
            if ($db->affectedRows() < 1) {
                continue;
            }

            self::enqueue($tenantId, $id);
            $ids[] = $id;
        }

        return ['dispatched' => count($ids), 'ids' => $ids];
    }

    /** The campaign this follow-up must wait for, if it has not finished. */
    private function unfinishedPredecessor(array $campaign): ?array
    {
        $segment = json_decode((string) ($campaign['segment'] ?? ''), true) ?: [];
        $prevId  = (int) ($segment['after_campaign_id'] ?? $segment['followup_of'] ?? 0);
        if ($prevId <= 0) {
            return null;
        }

        $prev = db_connect()->table('email_campaigns')
            ->select('id, name, status')
            ->where('id', $prevId)
            ->where('tenant_id', (int) $campaign['tenant_id'])
            ->where('deleted_at', null)
            ->get()->getRowArray();

        // A deleted predecessor cannot finish; do not hold the step forever.
        if ($prev === null) {
            return null;
        }

        return in_array($prev['status'], ['draft', 'scheduled', 'processing', 'paused'], true) ? $prev : null;
    }

    public static function enqueue(int $tenantId, int $campaignId, ?int $runAt = null): int
    {
        return JobDispatcher::dispatch(
            tenantId:   $tenantId,
            type:       'email_campaign_send',
            payload:    ['email_campaign_id' => $campaignId],
            runAt:      $runAt,
            sourceType: 'email_campaigns',
            sourceId:   $campaignId,
        );
    }
}
