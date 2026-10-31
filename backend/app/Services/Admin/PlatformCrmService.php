<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\ActivityModel;
use App\Models\DealModel;
use App\Models\FlowModel;
use App\Models\PipelineModel;
use App\Models\PipelineStageModel;
use App\Models\TagModel;
use App\Services\WhatsApp\TokenCipher;
use App\Services\Email\PlatformMail;
use App\Services\Flow\FlowTriggerService;

/**
 * TripSarthi's own CRM, run inside the marketing workspace (Admin -> WhatsApp -> Our marketing). Facebook / Instagram lead-form enquiries
 * arrive there through the ordinary Meta Lead Ads webhook (the page is connected in THAT workspace), become contacts, and the "Facebook lead"
 * automation puts each one into the "Subscriber Funnel" pipeline and nurtures it by e-mail + WhatsApp until they sign up and subscribe.
 *
 *  - setup()      idempotent: pipeline + stages + tags + nurture automations (drafts) + (optionally) the platform mail relay for e-mail steps.
 *  - dashboard()  funnel, sources, speed to first touch, who needs a call.
 *  - reconcile()  matches leads to real TripSarthi accounts (e-mail / phone) and moves them: signed up -> "Signed up (free)", paying -> "Subscribed",
 *                 replied -> "Engaged". Tags drive the automations' stop checks, so nobody who joined is nagged. Cron: `php spark crm:reconcile`.
 */
final class PlatformCrmService
{
    public const PIPELINE = 'Subscriber Funnel';

    public function __construct(private readonly ?int $now = null) {}

    private function ts(): int { return $this->now ?? time(); }
    private function stamp(?int $t = null): string { return date('Y-m-d H:i:s', $t ?? $this->ts()); }

    public function tenant(): ?int { return (new PlatformAudienceService())->marketingTenant(); }

    private function need(): int
    {
        $t = $this->tenant();
        if ($t === null) { throw new \DomainException('Choose the marketing workspace first (WhatsApp -> Our marketing).'); }
        return $t;
    }

    // ── setup ───────────────────────────────────────────────────────────────────────────────

    /** @return array{pipeline_id:int,stages:array<string,int>,tags:array<string,int>,flows:array} */
    public function setup(int $adminId, bool $mailRelay = true): array
    {
        $t = $this->need();
        $db = db_connect();
        $db->transStart();

        $pl = (new PipelineModel())->setTenant($t)->where('entity_type', 'deal')->where('name', self::PIPELINE)->first();
        if (! $pl) {
            $db->table('pipelines')->where('tenant_id', $t)->where('entity_type', 'deal')->update(['is_default' => 0]);
            $pid = (int) (new PipelineModel())->setTenant($t)->insert(['name' => self::PIPELINE, 'entity_type' => 'deal', 'is_default' => 1, 'position' => 0], true);
            foreach (PlatformNurtureRecipes::STAGES as $i => [$name, $prob, $won, $lost]) {
                (new PipelineStageModel())->setTenant($t)->insert(['pipeline_id' => $pid, 'name' => $name, 'position' => $i, 'probability' => $prob, 'is_won' => $won, 'is_lost' => $lost]);
            }
        } else { $pid = (int) $pl['id']; }

        $stages = $this->stageIds($t, $pid);
        $tags = $this->ensureTags($t);

        $flows = (new PlatformNurtureRecipes())->install($t, $pid, $stages['New lead'], $tags);
        $db->transComplete();
        if (! $db->transStatus()) { throw new \RuntimeException('Could not set up the CRM.'); }

        $relay = $mailRelay ? $this->useMailRelay() : null;
        $this->audit($t, $adminId, 'crm.setup', ['pipeline_id' => $pid, 'flows' => array_keys(array_filter($flows, static fn ($f) => $f['created']))]);
        return ['pipeline_id' => $pid, 'stages' => $stages, 'tags' => $tags, 'flows' => $flows, 'mail_relay' => $relay];
    }

    /** @return array<string,int> stage name => id */
    private function stageIds(int $t, int $pipelineId): array
    {
        $out = [];
        foreach ((new PipelineStageModel())->forPipeline($t, $pipelineId) as $s) { $out[$s['name']] = (int) $s['id']; }
        return $out;
    }

    /** @return array<string,int> tag key => id (keys of PlatformNurtureRecipes::TAGS plus nurture-done) */
    private function ensureTags(int $t): array
    {
        $want = PlatformNurtureRecipes::TAGS + ['nurture-done' => ['Nurture finished', '#9ca3af']];
        $out = [];
        $m = new TagModel();
        foreach ($want as $key => [$label, $color]) {
            $row = $m->setTenant($t)->where('name', $label)->first();
            $out[$key] = $row ? (int) $row['id'] : (int) (new TagModel())->setTenant($t)->insert(['name' => $label, 'color' => $color], true);
        }
        return $out;
    }

    /**
     * The nurture e-mails go out through the marketing workspace's own SMTP integration. If it has none, copy TripSarthi's platform mail
     * server (Admin -> Mail server) into it so the e-mail steps work without a second setup. Returns null when nothing was needed.
     */
    public function useMailRelay(): ?array
    {
        $t = $this->need();
        $im = new \App\Models\IntegrationModel();
        if ($im->findActiveByType($t, 'email_smtp')) { return ['changed' => false, 'reason' => 'The workspace already has its own mail server.']; }
        $p = PlatformMail::smtp();
        if (trim((string) $p['host']) === '' || in_array(strtolower((string) $p['host']), ['localhost', '127.0.0.1'], true)) {
            return ['changed' => false, 'reason' => 'No real mail server is set up yet (Admin -> Mail server).'];
        }
        $cfg = ['host' => $p['host'], 'port' => (int) $p['port'], 'username' => $p['user'], 'crypto' => $p['crypto'], 'from_email' => $p['from_email'], 'from_name' => $p['from_name'],
            'rate_per_minute' => 30];
        if ((string) $p['pass'] !== '') { $cfg['password_enc'] = TokenCipher::encrypt((string) $p['pass']); }
        $im->saveConfig($t, 'email_smtp', ['config' => json_encode($cfg), 'status' => 'active']);
        return ['changed' => true, 'reason' => 'Nurture e-mails will be sent through the TripSarthi mail server.'];
    }

    // ── status / readiness ──────────────────────────────────────────────────────────────────

    public function status(): array
    {
        $t = $this->tenant();
        $base = ['workspace' => null, 'checks' => [], 'ready' => false, 'setup_done' => false, 'recipes' => []];
        if ($t === null) { return $base; }
        $db = db_connect();
        $ws = $db->table('tenants')->select('id,name')->where('id', $t)->get()->getRowArray();
        $pl = (new PipelineModel())->setTenant($t)->where('entity_type', 'deal')->where('name', self::PIPELINE)->first();
        $fb = $db->table('integrations')->where('tenant_id', $t)->where('type', 'meta_lead_ads')->where('deleted_at', null)->get()->getResultArray();
        $fbActive = array_values(array_filter($fb, static fn ($i) => ($i['status'] ?? '') === 'active'));
        $waba = $db->table('waba_accounts')->where('tenant_id', $t)->where('deleted_at', null)->orderBy('id', 'DESC')->get()->getRowArray();
        $mail = (new \App\Services\Email\Marketing\EmailComposer())->settings($t);
        $recipes = (new PlatformNurtureRecipes())->status($t);
        $live = count(array_filter($recipes, static fn ($r) => $r['flow_status'] === 'active'));
        $tplApproved = (int) $db->table('templates')->where('tenant_id', $t)->like('name', 'ts_', 'after')->where('meta_status', 'approved')->countAllResults();
        $tplTotal = (int) $db->table('templates')->where('tenant_id', $t)->like('name', 'ts_', 'after')->countAllResults();

        $checks = [
            ['key' => 'workspace', 'ok' => true, 'label' => 'Marketing workspace', 'detail' => $ws['name'] ?? ''],
            ['key' => 'pipeline', 'ok' => (bool) $pl, 'label' => 'Subscriber Funnel pipeline & automations installed', 'detail' => $pl ? 'Installed' : 'Press "Set up CRM"'],
            ['key' => 'facebook', 'ok' => count($fbActive) > 0, 'label' => 'Facebook Lead Ads page connected', 'detail' => $fbActive ? count($fbActive) . ' page(s) connected' : 'Connect your Facebook page so new leads flow in'],
            ['key' => 'whatsapp', 'ok' => $waba && ($waba['status'] ?? '') === 'active', 'label' => 'WhatsApp number active', 'detail' => $waba ? 'Status: ' . $waba['status'] : 'Connect WhatsApp (WhatsApp -> Overview)'],
            ['key' => 'templates', 'ok' => $tplTotal > 0 && $tplApproved === $tplTotal, 'label' => 'WhatsApp nurture templates approved by Meta', 'detail' => $tplTotal ? "$tplApproved of $tplTotal approved (submit the drafts on the Templates page)" : 'None yet'],
            ['key' => 'email', 'ok' => (bool) $mail['ready'], 'label' => 'E-mail sending ready', 'detail' => $mail['ready'] ? 'Mail server connected' : (string) $mail['reason']],
            ['key' => 'live', 'ok' => $live > 0, 'label' => 'At least one automation switched on', 'detail' => $live . ' of ' . count($recipes) . ' active'],
        ];
        return ['workspace' => $ws, 'checks' => $checks, 'ready' => ! in_array(false, array_column($checks, 'ok'), true), 'setup_done' => (bool) $pl,
            'pipeline_id' => $pl ? (int) $pl['id'] : null, 'recipes' => $recipes, 'facebook_pages' => count($fbActive), 'webhook_url' => rtrim(base_url('webhooks/meta-leads'), '/')];
    }

    // ── dashboard ───────────────────────────────────────────────────────────────────────────

    public function dashboard(int $days = 30): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $t = $this->need();
        $db = db_connect();
        $since = $this->stamp($this->ts() - $days * 86400);
        $pl = (new PipelineModel())->setTenant($t)->where('entity_type', 'deal')->where('name', self::PIPELINE)->first();

        // Leads = Facebook / Instagram lead-form contacts (and any other contact source that arrives as an enquiry).
        $bySource = $db->query("SELECT source, COUNT(*) n FROM contacts WHERE tenant_id = ? AND deleted_at IS NULL AND created_at >= ? GROUP BY source ORDER BY n DESC", [$t, $since])->getResultArray();
        $newLeads = array_sum(array_map('intval', array_column($bySource, 'n')));
        $fbLeads = (int) ($db->query("SELECT COUNT(*) n FROM contacts WHERE tenant_id = ? AND deleted_at IS NULL AND created_at >= ? AND source = 'meta_lead_ads'", [$t, $since])->getRow()->n ?? 0);

        $daily = $db->query("SELECT DATE(CONVERT_TZ(created_at,'+00:00','+05:30')) d, COUNT(*) n FROM contacts WHERE tenant_id = ? AND deleted_at IS NULL AND created_at >= ? GROUP BY d ORDER BY d", [$t, $since])->getResultArray();

        $funnel = []; $open = 0; $won = 0; $lost = 0; $openValue = 0;
        if ($pl) {
            $rows = $db->query("SELECT s.id, s.name, s.position, s.is_won, s.is_lost, COUNT(d.id) n FROM pipeline_stages s LEFT JOIN deals d ON d.stage_id = s.id AND d.tenant_id = s.tenant_id AND d.deleted_at IS NULL WHERE s.tenant_id = ? AND s.pipeline_id = ? GROUP BY s.id ORDER BY s.position", [$t, (int) $pl['id']])->getResultArray();
            foreach ($rows as $r) { $funnel[] = ['stage' => $r['name'], 'count' => (int) $r['n'], 'won' => (bool) $r['is_won'], 'lost' => (bool) $r['is_lost']]; }
            $win = $db->query("SELECT SUM(status='open') o, SUM(status='won' AND won_at >= ?) w, SUM(status='lost' AND updated_at >= ?) l FROM deals WHERE tenant_id = ? AND pipeline_id = ? AND deleted_at IS NULL", [$since, $since, $t, (int) $pl['id']])->getRowArray();
            $open = (int) ($win['o'] ?? 0); $won = (int) ($win['w'] ?? 0); $lost = (int) ($win['l'] ?? 0);
        }
        $created = $pl ? (int) $db->query("SELECT COUNT(*) n FROM deals WHERE tenant_id = ? AND pipeline_id = ? AND deleted_at IS NULL AND created_at >= ?", [$t, (int) $pl['id'], $since])->getRow()->n : 0;

        // Needs attention: open leads nobody has touched (no outbound e-mail, no completed task) for > 1 hour; replies waiting; overdue tasks.
        $untouched = 0; $replied = 0; $overdue = 0; $hotList = [];
        if ($pl) {
            $stages = $this->stageIds($t, (int) $pl['id']);
            $untouched = (int) $db->query("SELECT COUNT(*) n FROM deals WHERE tenant_id = ? AND pipeline_id = ? AND status = 'open' AND stage_id = ? AND deleted_at IS NULL AND created_at < ?", [$t, (int) $pl['id'], $stages['New lead'] ?? 0, $this->stamp($this->ts() - 3600)])->getRow()->n;
            $hot = $db->query("SELECT c.id, c.name, c.wa_number, c.email, c.last_inbound_at FROM deals d JOIN contacts c ON c.id = d.primary_contact_id WHERE d.tenant_id = ? AND d.pipeline_id = ? AND d.status = 'open' AND d.deleted_at IS NULL AND c.last_inbound_at >= ? ORDER BY c.last_inbound_at DESC LIMIT 8", [$t, (int) $pl['id'], $this->stamp($this->ts() - 3 * 86400)])->getResultArray();
            $replied = count($hot);
            foreach ($hot as $h) { $hotList[] = ['id' => (int) $h['id'], 'name' => $h['name'], 'phone' => $this->mask((string) $h['wa_number']), 'last_inbound_at' => $h['last_inbound_at']]; }
        }
        $overdue = (int) $db->query("SELECT COUNT(*) n FROM tasks WHERE tenant_id = ? AND status = 'open' AND deleted_at IS NULL AND due_at IS NOT NULL AND due_at < ?", [$t, $this->stamp()])->getRow()->n;

        // Campaign view: Meta lead attribution by campaign (leads and how many became signed-up / subscribers).
        $camp = $db->query("SELECT COALESCE(NULLIF(a.campaign_name,''), a.campaign_id, '(no campaign)') campaign, COUNT(DISTINCT a.contact_id) leads FROM lead_attributions a WHERE a.tenant_id = ? AND a.touch = 'first' AND a.touched_at >= ? GROUP BY campaign ORDER BY leads DESC LIMIT 10", [$t, $since])->getResultArray();
        $tags = $this->ensureTagsReadOnly($t);
        foreach ($camp as &$c) {
            $c['leads'] = (int) $c['leads'];
            $c['signed_up'] = $this->campaignTagged($t, (string) $c['campaign'], $since, $tags['signed-up'] ?? 0);
            $c['subscribed'] = $this->campaignTagged($t, (string) $c['campaign'], $since, $tags['subscriber'] ?? 0);
        }
        unset($c);

        // Speed to lead: minutes from lead creation to the first outbound e-mail logged on the contact.
        $speed = $db->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, c.created_at, f.first_out)) m, COUNT(*) n FROM contacts c JOIN (SELECT contact_id, MIN(sent_at) first_out FROM emails WHERE tenant_id = ? AND direction = 'out' AND status = 'sent' GROUP BY contact_id) f ON f.contact_id = c.id WHERE c.tenant_id = ? AND c.created_at >= ? AND c.source = 'meta_lead_ads'", [$t, $t, $since])->getRowArray();

        $signed = $tags['signed-up'] ? $this->taggedSince($t, $tags['signed-up'], $since) : 0;
        $subs = ($tags['subscriber'] ?? 0) ? $this->taggedSince($t, $tags['subscriber'], $since) : 0;
        $conv = $newLeads > 0 ? round(100 * $subs / $newLeads, 1) : 0.0;

        // Revenue brought by subscribers who came through the funnel: price before GST of their active paid periods.
        $rev = (int) ($db->query("SELECT COALESCE(SUM(COALESCE(s.taxable_paise, s.amount_paise)),0) r FROM subscriptions s JOIN users u ON u.tenant_id = s.tenant_id AND u.role = 'owner' JOIN contacts c ON c.tenant_id = ? AND LOWER(c.email) = LOWER(u.email) AND c.deleted_at IS NULL WHERE s.status IN ('active','cancelled','downgraded') AND s.amount_paise > 0 AND s.razorpay_sub_id NOT LIKE ?", [$t, PlatformAdminService::MANUAL_PREFIX . '%'])->getRow()->r ?? 0);

        return ['days' => $days, 'kpis' => ['new_leads' => $newLeads, 'facebook_leads' => $fbLeads, 'deals_created' => $created, 'open' => $open, 'signed_up' => $signed, 'subscribed' => $subs,
                'conversion_pct' => $conv, 'lost' => $lost, 'revenue_paise' => $rev, 'median_first_touch_min' => ($speed['n'] ?? 0) ? (int) round((float) $speed['m']) : null],
            'funnel' => $funnel, 'sources' => array_map(static fn ($r) => ['source' => $r['source'] ?: 'unknown', 'count' => (int) $r['n']], $bySource),
            'daily' => array_map(static fn ($r) => ['date' => $r['d'], 'count' => (int) $r['n']], $daily), 'campaigns' => $camp,
            'attention' => ['untouched' => $untouched, 'replied' => $replied, 'overdue_tasks' => $overdue, 'hot' => $hotList]];
    }

    private function mask(string $n): string { return strlen($n) > 4 ? str_repeat('•', max(0, strlen($n) - 4)) . substr($n, -4) : $n; }

    private function ensureTagsReadOnly(int $t): array
    {
        $out = [];
        foreach (PlatformNurtureRecipes::TAGS as $key => [$label]) {
            $r = (new TagModel())->setTenant($t)->where('name', $label)->first();
            $out[$key] = $r ? (int) $r['id'] : 0;
        }
        return $out;
    }

    private function taggedSince(int $t, int $tagId, string $since): int
    {
        return (int) db_connect()->query('SELECT COUNT(*) n FROM contact_tags ct JOIN contacts c ON c.id = ct.contact_id WHERE c.tenant_id = ? AND ct.tag_id = ? AND ct.created_at >= ?', [$t, $tagId, $since])->getRow()->n;
    }

    private function campaignTagged(int $t, string $campaign, string $since, int $tagId): int
    {
        if ($tagId <= 0) { return 0; }
        return (int) db_connect()->query("SELECT COUNT(DISTINCT a.contact_id) n FROM lead_attributions a JOIN contact_tags ct ON ct.contact_id = a.contact_id AND ct.tag_id = ? WHERE a.tenant_id = ? AND a.touch = 'first' AND a.touched_at >= ? AND COALESCE(NULLIF(a.campaign_name,''), a.campaign_id, '(no campaign)') = ?", [$tagId, $t, $since, $campaign])->getRow()->n;
    }

    // ── intake ──────────────────────────────────────────────────────────────────────────────

    /**
     * Puts one Facebook lead into the Subscriber Funnel ("New lead") the moment it exists, whether or not any automation is switched on.
     * Idempotent: a contact that already has a deal in the funnel (open, won or lost) gets no second one. No flow trigger is fired here.
     * Returns the deal id, or null when nothing was created (CRM not set up, not this workspace, already in the funnel).
     */
    public function adoptLead(int $tenantId, int $contactId): ?int
    {
        if ($tenantId !== $this->tenant()) { return null; }
        $pl = (new PipelineModel())->setTenant($tenantId)->where('entity_type', 'deal')->where('name', self::PIPELINE)->first();
        if (! $pl) { return null; }
        $db = db_connect();
        if ($db->query('SELECT 1 FROM deals WHERE tenant_id = ? AND pipeline_id = ? AND primary_contact_id = ? AND deleted_at IS NULL LIMIT 1', [$tenantId, (int) $pl['id'], $contactId])->getRow()) { return null; }
        $stage = (new PipelineStageModel())->setTenant($tenantId)->where('pipeline_id', (int) $pl['id'])->where('name', 'New lead')->first();
        $contact = $db->table('contacts')->select('id,name')->where('id', $contactId)->where('tenant_id', $tenantId)->where('deleted_at', null)->get()->getRowArray();
        if (! $stage || ! $contact) { return null; }
        return (int) (new DealModel())->setTenant($tenantId)->insert([
            'title' => 'Facebook Ads lead', 'pipeline_id' => (int) $pl['id'], 'stage_id' => (int) $stage['id'], 'primary_contact_id' => $contactId,
            'value_amount' => 0, 'status' => 'open', 'source' => 'facebook_ads', 'last_activity_at' => $this->stamp(),
        ], true) ?: null;
    }

    /** Catch-up for leads that arrived before the CRM was set up, or while something failed. @return int deals created */
    public function adoptMissing(): int
    {
        $t = $this->tenant();
        if ($t === null) { return 0; }
        $ids = array_column(db_connect()->query("SELECT id FROM contacts WHERE tenant_id = ? AND source = 'meta_lead_ads' AND deleted_at IS NULL ORDER BY id", [$t])->getResultArray(), 'id');
        $n = 0;
        foreach ($ids as $id) { if ($this->adoptLead($t, (int) $id) !== null) { $n++; } }
        return $n;
    }

    // ── reconcile ───────────────────────────────────────────────────────────────────────────

    /**
     * Moves every open Subscriber Funnel deal to the stage that matches reality. Safe to run any time and repeatedly; a deal never moves BACKWARDS
     * (except nothing: lost / won deals are left alone).
     * @return array{adopted:int,checked:int,signed_up:int,subscribed:int,engaged:int,contacted:int}
     */
    public function reconcile(): array
    {
        $out = ['adopted' => 0, 'checked' => 0, 'signed_up' => 0, 'subscribed' => 0, 'engaged' => 0, 'contacted' => 0];
        $t = $this->tenant();
        if ($t === null) { return $out; }
        $pl = (new PipelineModel())->setTenant($t)->where('entity_type', 'deal')->where('name', self::PIPELINE)->first();
        if (! $pl) { return $out; }
        $out['adopted'] = $this->adoptMissing();
        $db = db_connect();
        $stages = $this->stageIds($t, (int) $pl['id']);
        $pos = array_flip(array_keys($stages));            // stage name => position in the funnel (creation order)
        $tags = $this->ensureTags($t);
        $accounts = $this->accountsByContactKey();

        $deals = $db->query("SELECT d.id, d.stage_id, d.created_at, d.primary_contact_id, c.email, c.wa_number, c.last_inbound_at FROM deals d JOIN contacts c ON c.id = d.primary_contact_id AND c.tenant_id = d.tenant_id WHERE d.tenant_id = ? AND d.pipeline_id = ? AND d.status = 'open' AND d.deleted_at IS NULL", [$t, (int) $pl['id']])->getResultArray();
        $nameOf = array_flip($stages);
        foreach ($deals as $d) {
            $out['checked']++;
            $cid = (int) $d['primary_contact_id'];
            $cur = $pos[$nameOf[(int) $d['stage_id']] ?? ''] ?? 0;
            $acct = $accounts['e:' . strtolower(trim((string) $d['email']))] ?? $accounts['p:' . $this->last10((string) $d['wa_number'])] ?? null;

            if ($acct) {
                $this->tag($t, $cid, $tags['signed-up']);
                if ($acct['paying']) {
                    $this->tag($t, $cid, $tags['subscriber']);
                    $db->table('contacts')->where('id', $cid)->update(['lifecycle_stage' => 'customer', 'status' => 'won']);
                    $this->move($t, (int) $d['id'], $stages['Subscribed'], $cid, 'Subscribed to a paid plan');
                    $out['subscribed']++;
                } elseif ($cur < ($pos['Signed up (free)'] ?? 3)) {
                    $db->table('contacts')->where('id', $cid)->update(['lifecycle_stage' => 'opportunity']);
                    $this->move($t, (int) $d['id'], $stages['Signed up (free)'], $cid, 'Created a TripSarthi workspace');
                    $out['signed_up']++;
                }
                continue;
            }
            if ($d['last_inbound_at'] && strtotime((string) $d['last_inbound_at']) >= strtotime((string) $d['created_at']) && $cur < ($pos['Engaged'] ?? 2)) {
                $this->tag($t, $cid, $tags['hot-lead']);
                $this->move($t, (int) $d['id'], $stages['Engaged'], $cid, 'Replied');
                $out['engaged']++;
            } elseif ($cur < ($pos['Contacted'] ?? 1) && $this->touched($t, $cid, (string) $d['created_at'])) {
                $this->move($t, (int) $d['id'], $stages['Contacted'], $cid, 'First message sent');
                $out['contacted']++;
            }
        }
        return $out;
    }

    /** True once we have actually messaged the lead: an e-mail went out, a WhatsApp message went out, or a call task was completed. */
    private function touched(int $t, int $contactId, string $since): bool
    {
        $db = db_connect();
        if ($db->query("SELECT 1 FROM emails WHERE tenant_id = ? AND contact_id = ? AND direction = 'out' AND status = 'sent' LIMIT 1", [$t, $contactId])->getRow()) { return true; }
        if ($db->query("SELECT 1 FROM messages WHERE tenant_id = ? AND contact_id = ? AND direction = 'out' AND created_at >= ? LIMIT 1", [$t, $contactId, $since])->getRow()) { return true; }
        return (bool) $db->query("SELECT 1 FROM tasks WHERE tenant_id = ? AND related_type = 'contact' AND related_id = ? AND status = 'done' LIMIT 1", [$t, $contactId])->getRow();
    }

    /** @return array<string,array{tenant_id:int,paying:bool}> keyed "e:<email>" and "p:<last 10 digits>" for every account owner / user */
    private function accountsByContactKey(): array
    {
        $db = db_connect();
        $paying = array_flip(array_map('intval', array_column($db->query("SELECT DISTINCT tenant_id FROM subscriptions WHERE status = 'active' AND amount_paise > 0 AND (current_period_end IS NULL OR current_period_end >= ?)", [$this->stamp()])->getResultArray(), 'tenant_id')));
        $market = (int) $this->tenant();
        $out = [];
        foreach ($db->query("SELECT tenant_id, email, phone FROM users WHERE deleted_at IS NULL AND tenant_id <> ?", [$market])->getResultArray() as $u) {
            $row = ['tenant_id' => (int) $u['tenant_id'], 'paying' => isset($paying[(int) $u['tenant_id']])];
            $e = strtolower(trim((string) $u['email']));
            if ($e !== '') { $out['e:' . $e] = ($out['e:' . $e]['paying'] ?? false) ? $out['e:' . $e] : $row; }
            $p = $this->last10((string) $u['phone']);
            if ($p !== '') { $out['p:' . $p] = ($out['p:' . $p]['paying'] ?? false) ? $out['p:' . $p] : $row; }
        }
        return $out;
    }

    private function last10(string $n): string
    {
        $d = preg_replace('/\D+/', '', $n) ?? '';
        return strlen($d) >= 10 ? substr($d, -10) : '';
    }

    private function tag(int $t, int $contactId, int $tagId): void
    {
        if ($tagId <= 0) { return; }
        $db = db_connect();
        if ($db->query('SELECT 1 FROM contact_tags WHERE contact_id = ? AND tag_id = ? LIMIT 1', [$contactId, $tagId])->getRow()) { return; }
        $db->query('INSERT INTO contact_tags (contact_id, tag_id, created_at) VALUES (?,?,?)', [$contactId, $tagId, $this->stamp()]);
        FlowTriggerService::fire('tag_added', $t, $contactId, ['tag_id' => $tagId]);
    }

    /** Mirrors DealsController::move (stage, status, timeline, flow triggers) for a system-made change. */
    private function move(int $t, int $dealId, int $stageId, int $contactId, string $why): void
    {
        $deal = (new DealModel())->setTenant($t)->find($dealId);
        $stage = (new PipelineStageModel())->setTenant($t)->find($stageId);
        if (! $deal || ! $stage || (int) $deal['stage_id'] === $stageId) { return; }
        $from = (new PipelineStageModel())->setTenant($t)->find((int) $deal['stage_id']);
        $now = $this->stamp();
        $upd = ['stage_id' => $stageId, 'last_activity_at' => $now];
        if ((int) $stage['is_won'] === 1) { $upd['status'] = 'won'; $upd['won_at'] = $now; }
        elseif ((int) $stage['is_lost'] === 1) { $upd['status'] = 'lost'; $upd['lost_reason'] = $why; }
        else { $upd['status'] = 'open'; }
        (new DealModel())->setTenant($t)->update($dealId, $upd);
        (new ActivityModel())->log($t, 'stage_change', 'deal', $dealId, ['subject' => 'Stage: ' . ($from['name'] ?? '?') . ' → ' . $stage['name'], 'body' => $why, 'meta' => ['from_stage' => $from['name'] ?? null, 'to_stage' => $stage['name'], 'by' => 'crm-reconcile']]);
        $ctx = ['deal_id' => $dealId, 'stage_id' => $stageId, 'stage_name' => $stage['name'], 'value_amount' => (int) $deal['value_amount']];
        FlowTriggerService::fire('deal_stage_changed', $t, $contactId, $ctx);
        if (($upd['status'] ?? '') === 'won') { FlowTriggerService::fire('deal_won', $t, $contactId, $ctx); }
    }

    private function audit(int $t, int $adminId, string $action, array $detail): void
    {
        try {
            db_connect()->table('audit_logs')->insert(['tenant_id' => $t, 'actor_user_id' => null, 'impersonated_by' => $adminId, 'action' => 'admin.' . $action, 'entity_type' => 'crm', 'entity_id' => 0,
                'after' => json_encode($detail), 'ip' => null, 'created_at' => $this->stamp()]);
        } catch (\Throwable $e) { log_message('warning', '[crm] audit failed: ' . $e->getMessage()); }
    }

    /** Switch an installed automation on or off by recipe key (activation is validated by the flow validator: templates must be approved). */
    public function activate(string $recipe, bool $on, int $adminId): array
    {
        $t = $this->need();
        $r = PlatformNurtureRecipes::all()[$recipe] ?? throw new \OutOfBoundsException('Unknown automation.');
        $f = (new FlowModel())->setTenant($t)->where('name', $r['name'])->first();
        if (! $f) { throw new \DomainException('Press "Set up CRM" first.'); }
        if ($on) {
            $graph = json_decode((string) $f['graph'], true) ?: [];
            $v = (new \App\Services\Flow\FlowValidator())->validate($f, $graph, $t);
            if (! empty($v['errors'])) { throw new \DomainException('Cannot switch on yet: ' . implode(' ', array_slice(array_map('strval', $v['errors']), 0, 3))); }
        }
        (new FlowModel())->setTenant($t)->update((int) $f['id'], ['status' => $on ? 'active' : 'paused']);
        $this->audit($t, $adminId, $on ? 'crm.automation.on' : 'crm.automation.off', ['recipe' => $recipe]);
        return ['flow_id' => (int) $f['id'], 'status' => $on ? 'active' : 'paused'];
    }
}
