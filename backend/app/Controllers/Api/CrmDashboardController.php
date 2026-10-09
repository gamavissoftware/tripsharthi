<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\PipelineModel;
use App\Services\Auth\CurrentUser;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * CRM dashboard (Phase F) — one aggregation endpoint that turns the whole CRM
 * (deals, pipeline, tickets, tasks, contacts) into headline metrics.
 */
class CrmDashboardController extends ResourceController
{
    protected $format = 'json';

    // GET /crm/dashboard
    public function index(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $db       = db_connect();
        $p        = $db->DBPrefix;
        $now      = date('Y-m-d H:i:s');
        $today    = date('Y-m-d');

        // ── Deals ─────────────────────────────────────────────────────
        $d = $db->query(
            "SELECT
                SUM(status='open')  AS open_count,
                COALESCE(SUM(CASE WHEN status='open' THEN value_amount END),0) AS open_value,
                SUM(status='won')   AS won_count,
                COALESCE(SUM(CASE WHEN status='won'  THEN value_amount END),0) AS won_value,
                SUM(status='lost')  AS lost_count
             FROM {$p}deals WHERE tenant_id=? AND deleted_at IS NULL",
            [$tenantId]
        )->getRowArray() ?: [];
        $won  = (int) ($d['won_count'] ?? 0);
        $lost = (int) ($d['lost_count'] ?? 0);

        $deals = [
            'open_count' => (int) ($d['open_count'] ?? 0),
            'open_value' => (int) ($d['open_value'] ?? 0),
            'won_count'  => $won,
            'won_value'  => (int) ($d['won_value'] ?? 0),
            'lost_count' => $lost,
            'win_rate'   => ($won + $lost) > 0 ? (int) round($won * 100 / ($won + $lost)) : 0,
        ];

        // ── Pipeline funnel (default pipeline, open deals per stage) ───
        $pipeline = (new PipelineModel())->ensureDefault($tenantId);
        $funnel   = $db->query(
            "SELECT s.name,
                    COUNT(CASE WHEN d.status='open' AND d.deleted_at IS NULL THEN d.id END) AS count,
                    COALESCE(SUM(CASE WHEN d.status='open' AND d.deleted_at IS NULL THEN d.value_amount END),0) AS value
             FROM {$p}pipeline_stages s
             LEFT JOIN {$p}deals d ON d.stage_id = s.id AND d.tenant_id = s.tenant_id
             WHERE s.tenant_id=? AND s.pipeline_id=? AND s.deleted_at IS NULL
             GROUP BY s.id, s.name, s.position ORDER BY s.position",
            [$tenantId, (int) $pipeline['id']]
        )->getResultArray();
        $funnel = array_map(static fn ($r) => ['name' => $r['name'], 'count' => (int) $r['count'], 'value' => (int) $r['value']], $funnel);

        // ── Tickets ───────────────────────────────────────────────────
        $tk = $db->query(
            "SELECT
                SUM(status='open')     AS open,
                SUM(status='pending')  AS pending,
                SUM(status='resolved') AS resolved,
                SUM(CASE WHEN status IN ('open','pending') AND sla_due_at IS NOT NULL AND sla_due_at < ? THEN 1 ELSE 0 END) AS sla_breached
             FROM {$p}tickets WHERE tenant_id=? AND deleted_at IS NULL",
            [$now, $tenantId]
        )->getRowArray() ?: [];
        $tickets = ['open' => (int) ($tk['open'] ?? 0), 'pending' => (int) ($tk['pending'] ?? 0), 'resolved' => (int) ($tk['resolved'] ?? 0), 'sla_breached' => (int) ($tk['sla_breached'] ?? 0)];

        // ── Tasks ─────────────────────────────────────────────────────
        $ts = $db->query(
            "SELECT
                SUM(status='open') AS open,
                SUM(CASE WHEN status='open' AND due_at IS NOT NULL AND due_at < ?       THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN status='open' AND due_at IS NOT NULL AND DATE(due_at) = ? THEN 1 ELSE 0 END) AS due_today
             FROM {$p}tasks WHERE tenant_id=? AND deleted_at IS NULL",
            [$now, $today, $tenantId]
        )->getRowArray() ?: [];
        $tasks = ['open' => (int) ($ts['open'] ?? 0), 'overdue' => (int) ($ts['overdue'] ?? 0), 'due_today' => (int) ($ts['due_today'] ?? 0)];

        // ── Contacts by lifecycle stage ───────────────────────────────
        $lc = $db->query(
            "SELECT lifecycle_stage AS stage, COUNT(*) AS count
             FROM {$p}contacts WHERE tenant_id=? AND deleted_at IS NULL
             GROUP BY lifecycle_stage",
            [$tenantId]
        )->getResultArray();
        $lifecycle = array_map(static fn ($r) => ['stage' => $r['stage'] ?: 'other', 'count' => (int) $r['count']], $lc);

        // Recurring revenue (MRR/ARR) from won, recurring deals.
        $recurring = (new \App\Services\Crm\RecurringRevenueService())->summary($tenantId);

        // ── Leads (contacts) headline + new in last 30 days + hot count ─
        $ld = $db->query(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS new_30d,
                    SUM(CASE WHEN score_tier='hot' THEN 1 ELSE 0 END) AS hot
             FROM {$p}contacts WHERE tenant_id=? AND deleted_at IS NULL",
            [date('Y-m-d H:i:s', strtotime('-30 days')), $tenantId]
        )->getRowArray() ?: [];
        $leads = ['total' => (int) ($ld['total'] ?? 0), 'new_30d' => (int) ($ld['new_30d'] ?? 0), 'hot' => (int) ($ld['hot'] ?? 0)];

        // ── Leads by source (top 6) ───────────────────────────────────
        $bySource = array_map(
            static fn ($r) => ['source' => $r['source'] ?: 'unknown', 'count' => (int) $r['count']],
            $db->query(
                "SELECT source, COUNT(*) AS count FROM {$p}contacts
                 WHERE tenant_id=? AND deleted_at IS NULL GROUP BY source ORDER BY count DESC LIMIT 6",
                [$tenantId]
            )->getResultArray()
        );

        // ── Lead score-tier distribution ──────────────────────────────
        $tiers = ['hot' => 0, 'warm' => 0, 'cold' => 0];
        foreach ($db->query(
            "SELECT LOWER(score_tier) AS tier, COUNT(*) AS count FROM {$p}contacts
             WHERE tenant_id=? AND deleted_at IS NULL AND score_tier IS NOT NULL GROUP BY LOWER(score_tier)",
            [$tenantId]
        )->getResultArray() as $r) {
            if (isset($tiers[$r['tier']])) {
                $tiers[$r['tier']] = (int) $r['count'];
            }
        }

        // ── Revenue trend: won-deal value per month, last 6 months ─────
        $byYm = [];
        foreach ($db->query(
            "SELECT DATE_FORMAT(won_at,'%Y-%m') AS ym, COALESCE(SUM(value_amount),0) AS value
             FROM {$p}deals WHERE tenant_id=? AND status='won' AND won_at IS NOT NULL
                   AND won_at >= ? AND deleted_at IS NULL GROUP BY ym",
            [$tenantId, date('Y-m-01', strtotime('-5 months'))]
        )->getResultArray() as $r) {
            $byYm[$r['ym']] = (int) $r['value'];
        }
        $trend = [];
        for ($i = 5; $i >= 0; $i--) {
            $ym = date('Y-m', strtotime("-{$i} months"));
            $trend[] = ['month' => $ym, 'value' => $byYm[$ym] ?? 0];
        }

        // ── Top 5 open deals by value ─────────────────────────────────
        $topDeals = array_map(
            static fn ($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'value' => (int) $r['value_amount']],
            $db->query(
                "SELECT id, title, value_amount FROM {$p}deals
                 WHERE tenant_id=? AND status='open' AND deleted_at IS NULL
                 ORDER BY value_amount DESC LIMIT 5",
                [$tenantId]
            )->getResultArray()
        );

        // ── WhatsApp: the 24h window is the product's core state ───────
        $cw = $db->query(
            "SELECT
                SUM(CASE WHEN window_expires_at > ? THEN 1 ELSE 0 END) AS open_windows,
                SUM(CASE WHEN window_expires_at > ? AND window_expires_at <= ? THEN 1 ELSE 0 END) AS expiring_soon
             FROM {$p}conversations WHERE tenant_id=?",
            [$now, $now, date('Y-m-d H:i:s', strtotime('+6 hours')), $tenantId]
        )->getRowArray() ?: [];

        $inToday = (int) (($db->query(
            "SELECT COUNT(*) AS c FROM {$p}messages WHERE tenant_id=? AND direction='in' AND DATE(created_at)=?",
            [$tenantId, $today]
        )->getRowArray() ?: [])['c'] ?? 0);

        // Outbound delivery funnel over the last 7 days (read ⊆ delivered ⊆ sent).
        $since7 = date('Y-m-d H:i:s', strtotime('-7 days'));
        $om = $db->query(
            "SELECT
                SUM(CASE WHEN status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN status IN ('delivered','read')        THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN status = 'read'                       THEN 1 ELSE 0 END) AS read_count,
                SUM(CASE WHEN status = 'failed'                     THEN 1 ELSE 0 END) AS failed
             FROM {$p}messages WHERE tenant_id=? AND direction='out' AND created_at >= ?",
            [$tenantId, $since7]
        )->getRowArray() ?: [];

        $whatsapp = [
            'open_windows'  => (int) ($cw['open_windows'] ?? 0),
            'expiring_soon' => (int) ($cw['expiring_soon'] ?? 0),
            'inbound_today' => $inToday,
            'sent_7d'       => (int) ($om['sent'] ?? 0),
            'delivered_7d'  => (int) ($om['delivered'] ?? 0),
            'read_7d'       => (int) ($om['read_count'] ?? 0),
            'failed_7d'     => (int) ($om['failed'] ?? 0),
        ];

        // Open 24h windows, soonest-closing first — the "reach them now" list.
        $closing = array_map(
            static fn ($r) => [
                'contact_id' => (int) $r['contact_id'],
                'name'       => $r['contact_name'] ?: $r['wa_number'],
                'wa_number'  => $r['wa_number'],
                'expires_at' => $r['window_expires_at'],
            ],
            $db->query(
                "SELECT contact_id, contact_name, wa_number, window_expires_at FROM {$p}conversations
                 WHERE tenant_id=? AND window_expires_at > ? ORDER BY window_expires_at ASC LIMIT 8",
                [$tenantId, $now]
            )->getResultArray()
        );

        // Template approval status (gates what can be sent on WhatsApp).
        $tpl = ['approved' => 0, 'pending' => 0, 'rejected' => 0];
        foreach ($db->query(
            "SELECT meta_status AS s, COUNT(*) AS c FROM {$p}templates
             WHERE tenant_id=? AND deleted_at IS NULL GROUP BY meta_status",
            [$tenantId]
        )->getResultArray() as $r) {
            if (isset($tpl[$r['s']])) {
                $tpl[$r['s']] = (int) $r['c'];
            }
        }

        // Recent broadcast campaigns with their send progress.
        $campaigns = array_map(
            static fn ($r) => [
                'id'     => (int) $r['id'],
                'name'   => $r['name'] ?: ('Campaign #' . $r['id']),
                'status' => $r['status'],
                'sent'   => (int) $r['sent_count'],
                'failed' => (int) $r['failed_count'],
                'total'  => (int) $r['total_contacts'],
            ],
            $db->query(
                "SELECT id, name, status, sent_count, failed_count, total_contacts FROM {$p}campaigns
                 WHERE tenant_id=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 5",
                [$tenantId]
            )->getResultArray()
        );

        // Daily message volume (in vs out) for the last 14 days — the pulse.
        $byDay = [];
        foreach ($db->query(
            "SELECT DATE(created_at) AS d,
                    SUM(CASE WHEN direction='out' THEN 1 ELSE 0 END) AS o,
                    SUM(CASE WHEN direction='in'  THEN 1 ELSE 0 END) AS i
             FROM {$p}messages WHERE tenant_id=? AND created_at >= ? GROUP BY DATE(created_at)",
            [$tenantId, date('Y-m-d', strtotime('-13 days')) . ' 00:00:00']
        )->getResultArray() as $r) {
            $byDay[$r['d']] = ['out' => (int) $r['o'], 'in' => (int) $r['i']];
        }
        $volume = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $volume[] = ['date' => $day, 'out' => $byDay[$day]['out'] ?? 0, 'in' => $byDay[$day]['in'] ?? 0];
        }

        // Top agents this month (reuses the leaderboard's composite ranking).
        $topAgents = array_map(
            static fn ($r) => ['name' => $r['name'], 'won_revenue' => $r['won_revenue'], 'conversions' => $r['conversions'], 'score' => $r['score']],
            array_slice((new \App\Services\Crm\LeaderboardService())->ranking($tenantId, date('Y-m-01'), $today), 0, 4)
        );

        return $this->respond(['success' => true, 'data' => [
            'deals'           => $deals,
            'funnel'          => $funnel,
            'tickets'         => $tickets,
            'tasks'           => $tasks,
            'lifecycle'       => $lifecycle,
            'recurring'       => $recurring,
            'leads'           => $leads,
            'leads_by_source' => $bySource,
            'score_tiers'     => $tiers,
            'revenue_trend'   => $trend,
            'top_deals'       => $topDeals,
            'whatsapp'        => $whatsapp,
            'closing_windows' => $closing,
            'templates'       => $tpl,
            'campaigns'       => $campaigns,
            'message_volume'  => $volume,
            'top_agents'      => $topAgents,
        ]]);
    }
}
