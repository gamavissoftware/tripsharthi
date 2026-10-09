<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\ActivityModel;
use App\Models\ContactModel;

/**
 * Rotting deals + stale-lead auto-reset (Phase H3).
 *
 * A deal is "rotting" when it is open and has had no activity for longer than its
 * stage's rotting_days. A lead/MQL contact is "stale" when it has had no inbound
 * activity for N days while still owned — those get re-queued (owner cleared) so
 * they resurface. Both operations are idempotent: rotting is derived (no writes),
 * and a re-queued contact has no owner so a second pass skips it.
 */
final class RottingService
{
    public const STALE_DAYS = 14;

    public function __construct(
        private ContactModel $contacts = new ContactModel(),
        private ActivityModel $activities = new ActivityModel(),
    ) {}

    /** @return int[] ids of open deals idle past their stage's rotting_days. */
    public function rottingDealIds(int $tenantId, ?int $now = null): array
    {
        $now ??= time();
        $rows = db_connect()->table('deals d')
            ->select('d.id, d.last_activity_at, d.created_at, s.rotting_days')
            ->join('pipeline_stages s', 's.id = d.stage_id', 'left')
            ->where('d.tenant_id', $tenantId)->where('d.status', 'open')->where('d.deleted_at', null)
            ->where('s.rotting_days IS NOT NULL', null, false)
            ->where('s.rotting_days >', 0)
            ->get()->getResultArray();

        $rotting = [];
        foreach ($rows as $r) {
            $ref = $r['last_activity_at'] ?: $r['created_at'];
            $ts  = $ref ? strtotime((string) $ref) : false;
            if ($ts !== false && ($now - $ts) > ((int) $r['rotting_days']) * 86400) {
                $rotting[] = (int) $r['id'];
            }
        }
        return $rotting;
    }

    /**
     * Re-queue stale lead/MQL contacts (clear owner + log). Idempotent.
     * @return int number of contacts re-queued
     */
    public function rerouteStaleContacts(int $tenantId, ?int $now = null, int $days = self::STALE_DAYS): int
    {
        $now = $now ?? time();
        $cut = db_connect()->escape(date('Y-m-d H:i:s', $now - $days * 86400));

        $rows = $this->contacts->setTenant($tenantId)
            ->whereIn('lifecycle_stage', ['lead', 'mql'])
            ->where('owner_id IS NOT NULL', null, false)
            ->where("COALESCE(last_inbound_at, updated_at) < {$cut}", null, false)
            ->findAll();

        foreach ($rows as $c) {
            $this->contacts->setTenant($tenantId)->update((int) $c['id'], ['owner_id' => null]);
            $this->activities->log($tenantId, 'system', 'contact', (int) $c['id'], [
                'subject' => 'Re-queued: stale lead with no recent activity',
            ]);
        }
        return count($rows);
    }
}
