<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

use App\Services\Crm\NotificationService;

/**
 * Nudges owners/admins when a GST return is due within 3 days or overdue and not marked filed. Once per (tenant, period, return, day-bucket)
 * via the shared claim table, so the hourly cron never repeats itself. Only tenants with a GSTIN on their profile are considered.
 */
final class GstFilingReminder
{
    public function __construct(private readonly ?int $now = null) {}

    /** @return int reminders sent */
    public function run(): int
    {
        $today = date('Y-m-d', $this->now ?? time()); $sent = 0; $db = db_connect();
        foreach ($db->table('business_profiles')->select('tenant_id')->where('gstin IS NOT NULL')->where('gstin !=', '')->get()->getResultArray() as $t) {
            $tid = (int) $t['tenant_id'];
            $svc = new GstExportService($this->now);
            $prev = date('Y-m', strtotime($today . ' -1 month'));                      // the month that just ended is the one being filed now
            $f = $svc->filings($tid, TcsLedger::currentFy($prev . '-01'), $today)['months'];
            $m = array_values(array_filter($f, static fn ($x) => $x['period'] === $prev))[0] ?? null;
            if (! $m) { continue; }
            foreach (['GSTR1' => 'GSTR-1', 'GSTR3B' => 'GSTR-3B'] as $type => $label) {
                $st = $m[$type];
                if (! in_array($st['status'], ['due_soon', 'overdue'], true)) { continue; }
                $key = "{$type}:{$prev}:" . ($st['status'] === 'overdue' ? 'late' : 'soon') . ':' . ($st['status'] === 'overdue' ? $today : $st['due']);
                $db->table('travel_trigger_log')->ignore(true)->insert(['tenant_id' => $tid, 'trigger_type' => 'gst_due', 'entity_type' => 'gst_return', 'entity_id' => (int) str_replace('-', '', $prev), 'claim_key' => $key, 'fired_at' => date('Y-m-d H:i:s', $this->now ?? time())]);
                if ($db->affectedRows() < 1) { continue; }
                $body = $st['status'] === 'overdue' ? "{$label} for " . date('F Y', strtotime($prev . '-01')) . " was due on {$st['due']} and is not marked filed ({$st['days']} day(s) late). Late fee and interest apply." : "{$label} for " . date('F Y', strtotime($prev . '-01')) . " is due on {$st['due']} ({$st['days']} day(s) left).";
                foreach ($db->table('users')->select('id')->where('tenant_id', $tid)->whereIn('role', ['owner', 'admin'])->where('deleted_at', null)->get()->getResultArray() as $u) { NotificationService::notify($tid, (int) $u['id'], 'gst_due', $body, '/settings/gst-exports'); $sent++; }
            }
        }
        return $sent;
    }
}
