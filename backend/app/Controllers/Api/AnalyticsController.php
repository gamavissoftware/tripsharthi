<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\CampaignModel;
use App\Models\ClickEventModel;
use App\Models\ContactModel;
use App\Models\FlowModel;
use App\Models\FlowRunModel;
use App\Models\MessageModel;
use App\Services\Analytics\CampaignReport;
use App\Services\Analytics\CostEstimator;
use App\Services\Analytics\MetaAdsService;
use App\Services\Analytics\MetaBillingService;
use App\Services\Analytics\MessageFilters;
use App\Services\Analytics\MessageReportQuery;
use App\Services\Analytics\MessageStats;
use App\Services\Analytics\ReportExporter;
use App\Services\Auth\CurrentUser;
use App\Services\Billing\PlanLimitChecker;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

class AnalyticsController extends ResourceController
{
    public function summary(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();

        $contactsTotal = (new ContactModel())->setTenant($tenantId)->countAllResults();
        $messagesSent  = (new MessageModel())->setTenant($tenantId)->where('direction', 'out')->countAllResults();
        $activeFlows   = (new FlowModel())->setTenant($tenantId)->where('status', 'active')->countAllResults();
        $campaignsTotal = (new CampaignModel())->setTenant($tenantId)->countAllResults();

        // Agents + WABA numbers come from PlanLimitChecker so the usage shown on
        // the Billing page matches exactly what plan-limit enforcement counts.
        $usage = (new PlanLimitChecker())->usageCounts($tenantId);

        // Tenant-wide outbound delivery outcomes. The headline Delivery/Read
        // rates must cover every outbound message (flows + inbox + campaigns),
        // not just campaign sends — otherwise they contradict messages_sent.
        $statusRows = db_connect()->table('messages')
            ->select('status, COUNT(*) AS c')
            ->where('tenant_id', $tenantId)
            ->where('direction', 'out')
            ->groupBy('status')
            ->get()
            ->getResultArray();

        $byStatus  = array_column($statusRows, 'c', 'status');
        $delivered = (int) ($byStatus['delivered'] ?? 0) + (int) ($byStatus['read'] ?? 0);
        $read      = (int) ($byStatus['read'] ?? 0);
        $failed    = (int) ($byStatus['failed'] ?? 0);

        // Contacts who have ever written back — drives the funnel's REPLIED row,
        // which reported 0 for everyone while this field was missing.
        $contactsReplied = (int) (db_connect()->table('messages')
            ->select('COUNT(DISTINCT contact_id) AS c', false)
            ->where('tenant_id', $tenantId)
            ->where('direction', 'in')
            ->where('contact_id IS NOT NULL')
            ->get()
            ->getRowArray()['c'] ?? 0);

        // Where the audience came from. Aggregated in SQL because the page used
        // to count the first 200 contacts client-side and label the result
        // "total" — on a 12,000-contact tenant that is not a sample, it is a
        // wrong answer stated confidently.
        $sourceRows = db_connect()->table('contacts')
            ->select('source, COUNT(*) AS c')
            ->where('tenant_id', $tenantId)
            ->where('deleted_at IS NULL', null, false)
            ->groupBy('source')
            ->orderBy('c', 'DESC')
            ->get()
            ->getResultArray();

        $sources = array_map(static fn (array $r): array => [
            'source' => $r['source'] ?: 'manual',
            'count'  => (int) $r['c'],
        ], $sourceRows);

        return $this->respond([
            'success' => true,
            'data'    => [
                'contacts_total'     => $contactsTotal,
                'contacts_replied'   => $contactsReplied,
                'contact_sources'    => $sources,
                'messages_sent'      => $messagesSent,
                'messages_delivered' => $delivered,
                'messages_read'      => $read,
                'messages_failed'    => $failed,
                'active_flows'    => $activeFlows,
                'campaigns_total' => $campaignsTotal,
                'agents_total'    => $usage['agents'],
                'waba_total'      => $usage['waba_numbers'],
            ],
        ]);
    }

    public function campaigns(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();

        $rows = (new CampaignModel())->setTenant($tenantId)->findAll();

        $campaignIds = array_map('intval', array_column($rows, 'id'));

        $clickMap = (new ClickEventModel())->aggregateForCampaigns($tenantId, $campaignIds);

        // Delivery outcomes live on the message rows — campaigns.stats only holds
        // send-time bookkeeping (billable_sends/skipped_opt_out/…), so reading
        // sent/delivered/read out of it silently reports zero for everything.
        $delivery = (new MessageModel())->deliveryCountsByCampaign($tenantId, $campaignIds);

        $data = array_map(static function (array $campaign) use ($clickMap, $delivery): array {
            $clicks = $clickMap[(int) $campaign['id']] ?? ['total_clicks' => 0, 'unique_clickers' => 0];
            $counts = $delivery[(int) $campaign['id']] ?? null;

            // Campaigns sent before messages.campaign_id existed have no message
            // rows to count — fall back to the campaign's own progress columns.
            $sent   = $counts !== null ? $counts['sent']   : (int) ($campaign['sent_count'] ?? 0);
            $failed = $counts !== null ? $counts['failed'] : (int) ($campaign['failed_count'] ?? 0);

            return [
                'id'          => $campaign['id'],
                'name'        => $campaign['name'],
                'status'      => $campaign['status'],
                'template_id' => $campaign['template_id'],
                'stats'       => [
                    'total_count'     => (int) ($campaign['total_contacts'] ?? 0),
                    'sent_count'      => $sent,
                    'delivered_count' => $counts['delivered'] ?? 0,
                    'read_count'      => $counts['read'] ?? 0,
                    'failed_count'    => $failed,
                    'total_clicks'    => $clicks['total_clicks'],
                    'unique_clickers' => $clicks['unique_clickers'],
                ],
                'created_at'  => $campaign['created_at'],
            ];
        }, $rows);

        return $this->respond([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * Per-campaign click funnel: recipients → delivered → read → clicked,
     * plus a per-button breakdown.
     *
     * GET /api/v1/analytics/campaigns/(:num)/clicks
     */
    public function campaignClicks($id = null): ResponseInterface
    {
        $tenantId   = CurrentUser::tenantId();
        $campaignId = (int) $id;

        $campaign = (new CampaignModel())->setTenant($tenantId)->find($campaignId);
        if (! $campaign) {
            return $this->failNotFound("Campaign #{$id} not found.");
        }

        $db = \Config\Database::connect();

        // Funnel from the messages this campaign produced.
        $recipients = $db->table('messages')
            ->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)
            ->where('direction', 'out')->countAllResults();

        $delivered = $db->table('messages')
            ->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)
            ->where('direction', 'out')->whereIn('status', ['delivered', 'read'])
            ->countAllResults();

        $read = $db->table('messages')
            ->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)
            ->where('direction', 'out')->where('status', 'read')
            ->countAllResults();

        $clickModel = new ClickEventModel();
        $agg        = $clickModel->aggregateForCampaigns($tenantId, [$campaignId]);
        $clicks     = $agg[$campaignId] ?? ['total_clicks' => 0, 'unique_clickers' => 0];

        // ── A/B variant breakdown (only present when the campaign used A/B) ──
        $variantRows = $db->table('messages')
            ->select('variant')
            ->selectCount('id', 'sent')
            ->selectSum("CASE WHEN status = 'read' THEN 1 ELSE 0 END", 'read')
            ->where('tenant_id', $tenantId)->where('campaign_id', $campaignId)
            ->where('direction', 'out')->where('variant IS NOT NULL', null, false)
            ->groupBy('variant')->get()->getResultArray();

        $variants = [];
        if (! empty($variantRows)) {
            $clkRows = $db->table('click_events ce')
                ->join('messages m', 'm.id = ce.message_id')
                ->select('m.variant AS variant')->selectCount('ce.id', 'clicks')
                ->where('ce.tenant_id', $tenantId)->where('ce.campaign_id', $campaignId)
                ->where('m.variant IS NOT NULL', null, false)
                ->groupBy('m.variant')->get()->getResultArray();
            $clkMap = [];
            foreach ($clkRows as $r) {
                $clkMap[$r['variant']] = (int) $r['clicks'];
            }
            foreach ($variantRows as $r) {
                $v = $r['variant'];
                $variants[] = [
                    'variant' => $v,
                    'sent'    => (int) $r['sent'],
                    'read'    => (int) $r['read'],
                    'clicks'  => $clkMap[$v] ?? 0,
                ];
            }
        }

        return $this->respond([
            'success' => true,
            'data'    => [
                'funnel' => [
                    'recipients'      => $recipients,
                    'delivered'       => $delivered,
                    'read'            => $read,
                    'total_clicks'    => $clicks['total_clicks'],
                    'unique_clickers' => $clicks['unique_clickers'],
                ],
                'buttons'  => $clickModel->breakdownForCampaign($tenantId, $campaignId),
                'variants' => $variants,
            ],
        ]);
    }

    public function flows(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();

        $flows = (new FlowModel())->setTenant($tenantId)->findAll();

        if (empty($flows)) {
            return $this->respond(['success' => true, 'data' => []]);
        }

        $flowIds = array_column($flows, 'id');

        $db = \Config\Database::connect();

        $runStats = $db->table('flow_runs')
            ->select('flow_id')
            ->selectCount('id', 'total_runs')
            ->selectSum('CASE WHEN status = \'completed\' THEN 1 ELSE 0 END', 'completed_runs')
            ->selectSum('CASE WHEN status = \'stopped\' THEN 1 ELSE 0 END', 'stopped_runs')
            ->where('tenant_id', $tenantId)
            ->whereIn('flow_id', $flowIds)
            ->groupBy('flow_id')
            ->get()
            ->getResultArray();

        $statsMap = [];
        foreach ($runStats as $row) {
            $statsMap[(int) $row['flow_id']] = [
                'total_runs'     => (int) $row['total_runs'],
                'completed_runs' => (int) $row['completed_runs'],
                'stopped_runs'   => (int) $row['stopped_runs'],
            ];
        }

        $data = array_map(static function (array $flow) use ($statsMap): array {
            $id    = (int) $flow['id'];
            $stats = $statsMap[$id] ?? ['total_runs' => 0, 'completed_runs' => 0, 'stopped_runs' => 0];

            return [
                'id'             => $id,
                'name'           => $flow['name'],
                'trigger_type'   => $flow['trigger_type'],
                'status'         => $flow['status'],
                'total_runs'     => $stats['total_runs'],
                'completed_runs' => $stats['completed_runs'],
                'stopped_runs'   => $stats['stopped_runs'],
            ];
        }, $flows);

        return $this->respond([
            'success' => true,
            'data'    => $data,
        ]);
    }

    // ------------------------------------------------------------------
    // Delivery reporting
    //
    // summary() above answers "how big is this account". Everything below
    // answers "what happened to the messages" — and does it over the same
    // filter vocabulary (MessageFilters), so the funnel, the log and the CSV
    // can never describe different sets of messages.
    // ------------------------------------------------------------------

    /**
     * Range-aware delivery overview: funnel, daily trend, cost by category,
     * failure reasons and per-template performance.
     *
     * GET /api/v1/analytics/overview
     */
    public function overview(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $filters  = MessageFilters::normalize($this->request->getGet() ?? []);

        $stats = new MessageStats();

        return $this->respond([
            'success' => true,
            'data'    => [
                'filters'    => $this->echoFilters($filters),
                'funnel'     => $stats->funnel($tenantId, $filters),
                'timeseries' => $stats->timeseries($tenantId, $filters),
                'categories' => $stats->categoryBreakdown($tenantId, $filters),
                'failures'   => $stats->failureBreakdown($tenantId, $filters),
                'templates'  => $stats->templatePerformance($tenantId, $filters),
                'rate_card'  => CostEstimator::rateCard(),
            ],
        ]);
    }

    /**
     * The delivery log — one row per message: who it went to, whether it was
     * delivered, whether they read it, whether they replied, why it failed.
     *
     * GET /api/v1/analytics/messages
     */
    public function messages(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $get      = $this->request->getGet() ?? [];
        $filters  = MessageFilters::normalize($get);

        $result = (new MessageReportQuery())->page(
            $tenantId,
            $filters,
            max(1, (int) ($get['page'] ?? 1)),
            (int) ($get['per_page'] ?? MessageReportQuery::DEFAULT_PER_PAGE)
        );

        $result['filters'] = $this->echoFilters($filters);

        return $this->respond(['success' => true, 'data' => $result]);
    }

    /**
     * The same log as a CSV download, honouring the same filters.
     *
     * GET /api/v1/analytics/messages/export
     */
    public function messagesExport(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $filters  = MessageFilters::normalize($this->request->getGet() ?? []);

        $query = new MessageReportQuery();
        $total = $query->count($tenantId, $filters);
        $rows  = $query->exportRows($tenantId, $filters);

        $csv      = ReportExporter::toCsv($rows);
        $filename = ReportExporter::filename($this->exportPrefix($tenantId, $filters), $filters);

        return $this->response
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            // The export is capped so one request cannot exhaust memory; say so
            // in the response rather than handing back a silently short file.
            ->setHeader('X-Export-Rows', (string) count($rows))
            ->setHeader('X-Export-Total', (string) $total)
            ->setHeader('X-Export-Truncated', count($rows) < $total ? '1' : '0')
            ->setBody($csv);
    }

    /**
     * Full report for one broadcast: funnel, trend, A/B split, button clicks,
     * failure reasons and estimated Meta spend.
     *
     * GET /api/v1/analytics/campaigns/(:num)/report
     */
    public function campaignReport($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();

        $campaign = (new CampaignModel())->setTenant($tenantId)->find((int) $id);
        if (! $campaign) {
            return $this->failNotFound("Campaign #{$id} not found.");
        }

        $filters = MessageFilters::normalize($this->request->getGet() ?? []);
        $report  = (new CampaignReport())->build($tenantId, (array) $campaign, $filters);

        return $this->respond(['success' => true, 'data' => $report]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Echo back what the server actually filtered on.
     *
     * A date the server rejected (or clamped, on a range longer than
     * MAX_RANGE_DAYS) must not keep showing in the UI as if it applied — that
     * is how an operator ends up reading last month's numbers as this week's.
     *
     * @return array<string, mixed>
     */
    private function echoFilters(array $filters): array
    {
        return [
            'from'        => $filters['from'] ?? null,
            'to'          => $filters['to'] ?? null,
            'direction'   => $filters['direction'] ?? 'all',
            'statuses'    => $filters['statuses'] ?? [],
            'categories'  => $filters['categories'] ?? [],
            'types'       => $filters['types'] ?? [],
            'campaign_id' => $filters['campaign_id'] ?? null,
            'replied'     => $filters['replied'] ?? null,
            'q'           => $filters['q'] ?? null,
        ];
    }

    /** Name the CSV after the campaign when the slice is a single campaign. */
    private function exportPrefix(int $tenantId, array $filters): string
    {
        $campaignId = $filters['campaign_id'] ?? null;

        if (is_int($campaignId)) {
            $campaign = (new CampaignModel())->setTenant($tenantId)->find($campaignId);
            if ($campaign) {
                $name = is_array($campaign) ? ($campaign['name'] ?? '') : ($campaign->name ?? '');
                if ($name !== '') {
                    return 'campaign-' . $name;
                }
            }
        }

        return 'whatsapp-delivery-report';
    }

    // GET /api/v1/analytics/billing?months=12 — Meta's real WhatsApp billing, month on month
    public function billing(): ResponseInterface
    {
        $months = (int) ($this->request->getGet('months') ?? 12);

        return $this->respond([
            'success' => true,
            'data'    => (new MetaBillingService())->report(CurrentUser::tenantId(), $months),
        ]);
    }

    // POST /api/v1/analytics/billing/sync — pull fresh numbers from Meta now
    public function billingSync(): ResponseInterface
    {
        $months = (int) ($this->request->getJsonVar('months') ?? 12);
        try {
            $sync = (new MetaBillingService())->sync(CurrentUser::tenantId(), $months);
        } catch (\Throwable $e) {
            return $this->fail(['error' => $e->getMessage()], 422);
        }

        return $this->respond([
            'success' => true,
            'sync'    => $sync,
            'data'    => (new MetaBillingService())->report(CurrentUser::tenantId(), $months),
        ]);
    }

    // GET /api/v1/analytics/ad-spend?months=12 — Meta ad spend, month on month
    public function adSpend(): ResponseInterface
    {
        $months = (int) ($this->request->getGet('months') ?? 12);

        return $this->respond(['success' => true, 'data' => (new MetaAdsService())->report(CurrentUser::tenantId(), $months)]);
    }
}
