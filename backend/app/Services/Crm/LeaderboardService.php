<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\UserModel;

/**
 * Sales leaderboard (Phase I4): a date-ranged composite ranking of reps from
 * won revenue, conversions (won deals), qualified pipeline contribution, and
 * follow-up discipline (completed tasks). Read-only, tenant-scoped.
 *
 * Composite = ⌊won_rupees / RUPEES_PER_POINT⌋
 *           + conversions × CONVERSION_PTS
 *           + qualified   × QUALIFIED_PTS
 *           + follow_up    × FOLLOWUP_PTS
 */
final class LeaderboardService
{
    private const RUPEES_PER_POINT = 10000;
    private const CONVERSION_PTS   = 20;
    private const QUALIFIED_PTS    = 5;
    private const FOLLOWUP_PTS     = 3;

    /** @return array<int, array> ranked rows */
    public function ranking(int $tenantId, string $from, string $to): array
    {
        $db   = db_connect();
        $f    = $from . ' 00:00:00';
        $t    = $to . ' 23:59:59';
        $rows = [];

        // Won revenue + conversions per owner (by won_at, falling back to updated_at).
        foreach ($db->table('deals')->select('owner_id, SUM(value_amount) AS rev, COUNT(*) AS n')
            ->where('tenant_id', $tenantId)->where('status', 'won')->where('deleted_at', null)
            ->where('COALESCE(won_at, updated_at) >=', $f)->where('COALESCE(won_at, updated_at) <=', $t)
            ->groupBy('owner_id')->get()->getResultArray() as $r) {
            $uid = (int) $r['owner_id'];
            $rows[$uid]['won_revenue'] = (int) $r['rev'];
            $rows[$uid]['conversions'] = (int) $r['n'];
        }

        // Qualified pipeline contribution: deals created in the window.
        foreach ($db->table('deals')->select('owner_id, COUNT(*) AS n')
            ->where('tenant_id', $tenantId)->where('deleted_at', null)
            ->where('created_at >=', $f)->where('created_at <=', $t)
            ->groupBy('owner_id')->get()->getResultArray() as $r) {
            $rows[(int) $r['owner_id']]['qualified'] = (int) $r['n'];
        }

        // Follow-up discipline: tasks completed in the window.
        foreach ($db->table('tasks')->select('assigned_user_id AS uid, COUNT(*) AS n')
            ->where('tenant_id', $tenantId)->where('status', 'done')->where('deleted_at', null)
            ->where('completed_at >=', $f)->where('completed_at <=', $t)
            ->groupBy('assigned_user_id')->get()->getResultArray() as $r) {
            $rows[(int) $r['uid']]['follow_up'] = (int) $r['n'];
        }

        $out = [];
        foreach ($rows as $uid => $m) {
            if ($uid <= 0) {
                continue;
            }
            $wonRupees = (int) (($m['won_revenue'] ?? 0) / 100);
            $score = intdiv($wonRupees, self::RUPEES_PER_POINT)
                + ($m['conversions'] ?? 0) * self::CONVERSION_PTS
                + ($m['qualified'] ?? 0) * self::QUALIFIED_PTS
                + ($m['follow_up'] ?? 0) * self::FOLLOWUP_PTS;

            $out[] = [
                'user_id'     => $uid,
                'name'        => $this->name($tenantId, $uid),
                'won_revenue' => $m['won_revenue'] ?? 0,
                'conversions' => $m['conversions'] ?? 0,
                'qualified'   => $m['qualified'] ?? 0,
                'follow_up'   => $m['follow_up'] ?? 0,
                'score'       => $score,
            ];
        }

        usort($out, static fn ($a, $b) => $b['score'] <=> $a['score']);
        foreach ($out as $i => &$row) {
            $row['rank'] = $i + 1;
        }
        return $out;
    }

    private function name(int $tenantId, int $uid): string
    {
        $u = (new UserModel())->setTenant($tenantId)->find($uid);
        return $u['name'] ?? ('User #' . $uid);
    }
}
