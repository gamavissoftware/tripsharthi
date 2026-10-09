<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\ActivityModel;
use App\Models\TicketModel;
use App\Models\UserModel;

/**
 * SLA breach escalation for tickets (Phase H5). A still-open ticket past its
 * sla_due_at that has not been escalated is stamped escalated_at and reassigned
 * to the least-loaded other agent (notify). The escalated_at flag makes it
 * once-only.
 */
final class SlaEscalationService
{
    public function __construct(
        private TicketModel $tickets = new TicketModel(),
        private ActivityModel $activities = new ActivityModel(),
    ) {}

    /** @return int[] ids of open/pending tickets breached and not yet escalated. */
    public function breachedTicketIds(int $tenantId, ?int $now = null): array
    {
        $now    = $now ?? time();
        $nowEsc = db_connect()->escape(date('Y-m-d H:i:s', $now));

        return array_map('intval', array_column($this->tickets->setTenant($tenantId)
            ->whereIn('status', ['open', 'pending'])
            ->where('escalated_at IS NULL', null, false)
            ->where('sla_due_at IS NOT NULL', null, false)
            ->where("sla_due_at < {$nowEsc}", null, false)
            ->findAll(), 'id'));
    }

    /** Escalate every breached ticket. @return int count escalated */
    public function run(int $tenantId, ?int $now = null): int
    {
        $now ??= time();
        $ids  = $this->breachedTicketIds($tenantId, $now);
        if (empty($ids)) {
            return 0;
        }
        $stamp = date('Y-m-d H:i:s', $now);

        foreach ($ids as $id) {
            $ticket   = $this->tickets->setTenant($tenantId)->find($id);
            $newOwner = $this->leastLoadedOther($tenantId, (int) ($ticket['owner_id'] ?? 0));

            $update = ['escalated_at' => $stamp];
            if ($newOwner !== null) {
                $update['owner_id'] = $newOwner;
            }
            $this->tickets->setTenant($tenantId)->update($id, $update);

            $this->activities->log($tenantId, 'system', 'ticket', $id, [
                'subject' => 'SLA breached — escalated' . ($newOwner !== null ? " (reassigned to user #{$newOwner})" : ''),
            ]);
        }
        return count($ids);
    }

    /** Pick the agent with the fewest open tickets, excluding the current owner. */
    private function leastLoadedOther(int $tenantId, int $currentOwner): ?int
    {
        $db   = db_connect();
        $best = null;
        $bestN = PHP_INT_MAX;
        foreach ((new UserModel())->setTenant($tenantId)->findAll() as $u) {
            $uid = (int) $u['id'];
            if ($uid === $currentOwner) {
                continue;
            }
            $n = (int) $db->table('tickets')->where('tenant_id', $tenantId)->where('owner_id', $uid)
                ->whereIn('status', ['open', 'pending'])->where('deleted_at', null)->countAllResults();
            if ($n < $bestN) {
                $bestN = $n;
                $best  = $uid;
            }
        }
        return $best;
    }
}
