<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\EmailCampaignModel;
use App\Models\EmailTemplateModel;
use App\Services\Email\Marketing\EmailCampaignSender;
use App\Services\Email\Marketing\EmailComposer;
use App\Services\Email\Marketing\NurtureSequenceService;
use App\Services\Email\Marketing\SuppressionService;
use App\Services\Leads\ScheduledCampaignDispatcher;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Schedule a nurture series for one tag in a single step: the first email
 * ("<prefix> 1…" template) at --start, then follow-ups from templates
 * "<prefix> 2…", "<prefix> 3…" … on the --followups days, each to the people
 * the first email reached who have not clicked, skipping Qualified/Won/Lost.
 * Optionally records --daily-limit and --copy-to in the tenant's SMTP settings.
 *
 *   php spark email:schedule-nurture --tag=12 --start="2026-09-26 10:30" \
 *       --reply-to=manglesh@gamavis.com --daily-limit=450 --copy-to=me@example.com --dry-run
 *
 * Refuses to create a second campaign with the same name, so re-running it
 * after a success does nothing. Everything it creates is ordinary campaigns,
 * visible and cancellable under Email Marketing.
 */
class EmailScheduleNurture extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'email:schedule-nurture';
    protected $description = 'Schedule a nurture email series (first email + follow-ups) for a tag.';
    protected $usage       = 'email:schedule-nurture --tag=<id|name> --start="YYYY-MM-DD HH:MM" [options]';
    protected $options     = [
        '--tenant'      => 'Tenant id (default 1)',
        '--tag'         => 'Tag id or exact tag name — the audience',
        '--start'       => 'First email, local time "YYYY-MM-DD HH:MM"',
        '--tz'          => 'Time zone (default Asia/Kolkata)',
        '--prefix'      => 'Template name prefix (default "Gamavis Nurture")',
        '--followups'   => 'Follow-up days after the first email (default 3,7,12,18)',
        '--name'        => 'Campaign name (default "<tag> — <prefix> (<Mon YYYY>)")',
        '--reply-to'    => 'Reply-to address for every email in the series',
        '--from-name'   => 'Sender display name (default: the SMTP settings)',
        '--daily-limit' => 'Also save this daily sending limit in the SMTP settings',
        '--copy-to'     => 'Also save this owner-copy address in the SMTP settings ("off" clears it)',
        '--dry-run'     => 'Show what would happen; change nothing',
    ];

    public function run(array $params): void
    {
        $tenantId = (int) ($this->opt('tenant') ?: 1);
        $dryRun   = $this->opt('dry-run') !== null;
        $tz       = (string) ($this->opt('tz') ?: 'Asia/Kolkata');
        $prefix   = (string) ($this->opt('prefix') ?: 'Gamavis Nurture');
        $start    = (string) $this->opt('start');
        $replyTo  = trim((string) $this->opt('reply-to'));
        $fromName = trim((string) $this->opt('from-name'));
        $days     = array_values(array_filter(array_map('intval', explode(',', (string) ($this->opt('followups') ?: '3,7,12,18')))));

        if ($replyTo !== '' && ! filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $this->fail("--reply-to is not a valid email: {$replyTo}");

            return;
        }

        // ── Sending settings ──────────────────────────────────────────
        $db  = db_connect();
        $row = $db->table('integrations')->where('tenant_id', $tenantId)->where('type', 'email_smtp')
            ->where('status', 'active')->get()->getRowArray();
        if ($row === null) {
            $this->fail('No SMTP settings for this tenant — save them under Email Marketing → Sending settings first.');

            return;
        }
        $cfg     = json_decode((string) $row['config'], true) ?: [];
        $changes = [];
        if (($limit = $this->opt('daily-limit')) !== null) {
            $changes['daily_limit'] = max(0, (int) $limit);
        }
        if (($copy = $this->opt('copy-to')) !== null) {
            $copy = trim((string) $copy);
            if ($copy !== 'off' && ! filter_var($copy, FILTER_VALIDATE_EMAIL)) {
                $this->fail("--copy-to is not a valid email: {$copy}");

                return;
            }
            $changes['copy_to'] = $copy === 'off' ? '' : $copy;
        }

        // ── Audience ──────────────────────────────────────────────────
        $tagArg = (string) $this->opt('tag');
        $tag    = $db->table('tags')->where('tenant_id', $tenantId)->where('deleted_at', null)
            ->groupStart()->where('id', ctype_digit($tagArg) ? (int) $tagArg : 0)->orWhere('name', $tagArg)->groupEnd()
            ->get()->getRowArray();
        if ($tag === null) {
            $this->fail("Tag not found: \"{$tagArg}\"");

            return;
        }
        $segment = ['tag_ids' => [(int) $tag['id']]];
        $sender  = new EmailCampaignSender(EmailComposer::defaultTransport());
        $emails  = array_column($sender->audience($tenantId, $segment, 'contacts.email')->findAll(50000), 'email');
        $unique  = array_unique(array_map([SuppressionService::class, 'normalize'], $emails));
        $blocked = count((new SuppressionService())->suppressedAmong($tenantId, $unique));
        $reach   = count($unique) - $blocked;

        // ── Templates ─────────────────────────────────────────────────
        $templates = (new EmailTemplateModel())->setTenant($tenantId);
        $first     = $templates->like('name', "{$prefix} 1", 'after')->first();
        if ($first === null) {
            $this->fail("No template named \"{$prefix} 1…\" — run: php spark db:seed GamavisEmailNurtureSeeder");

            return;
        }
        $steps = [];
        foreach ($days as $i => $d) {
            $tpl = (new EmailTemplateModel())->setTenant($tenantId)->like('name', "{$prefix} " . ($i + 2), 'after')->first();
            if ($tpl === null) {
                $this->fail("No template named \"{$prefix} " . ($i + 2) . "…\" for follow-up " . ($i + 1) . '.');

                return;
            }
            $steps[] = ['email_template_id' => (int) $tpl['id'], 'days_after' => $d, 'name' => $tpl['name']];
        }

        // ── Timing ────────────────────────────────────────────────────
        try {
            $startUtc = ScheduledCampaignDispatcher::toUtc($start, $tz);
        } catch (\InvalidArgumentException $e) {
            $this->fail('--start: ' . $e->getMessage() . ' (use "YYYY-MM-DD HH:MM")');

            return;
        }
        if (strtotime($startUtc . ' UTC') <= time()) {
            $this->fail("--start {$start} ({$tz}) is in the past.");

            return;
        }

        $name = trim((string) $this->opt('name'))
            ?: "{$tag['name']} — {$prefix} (" . date('M Y', strtotime($start)) . ')';
        $existing = (new EmailCampaignModel())->setTenant($tenantId)->where('name', $name)->first();
        if ($existing !== null) {
            $this->fail("A campaign named \"{$name}\" already exists (#{$existing['id']}, {$existing['status']}). Nothing changed.");

            return;
        }

        // Same audience + same first email already queued under ANY name:
        // a second series would email every contact twice.
        foreach ((new EmailCampaignModel())->setTenant($tenantId)
            ->where('email_template_id', (int) $first['id'])
            ->whereIn('status', ['scheduled', 'processing', 'paused', 'done'])->findAll() as $c) {
            $seg = json_decode((string) ($c['segment'] ?? ''), true) ?: [];
            if (array_map('intval', (array) ($seg['tag_ids'] ?? [])) === [(int) $tag['id']]) {
                $this->fail("Campaign #{$c['id']} \"{$c['name']}\" ({$c['status']}) already sends {$first['name']} to this tag. Nothing changed.");

                return;
            }
        }

        // ── Summary ───────────────────────────────────────────────────
        $newCfg   = $changes + $cfg;
        $limitNow = (int) ($newCfg['daily_limit'] ?? 0);
        $copyNow  = (string) ($newCfg['copy_to'] ?? '');
        $perDay   = $limitNow > 0 ? intdiv($limitNow, $copyNow !== '' ? 2 : 1) : null;

        CLI::write($dryRun ? '── DRY RUN — nothing will be changed ──' : '── Scheduling ──', 'yellow');
        CLI::write("Audience:      tag \"{$tag['name']}\" (#{$tag['id']}) — {$reach} will receive it"
            . " ({$blocked} unsubscribed, " . (count($emails) - count($unique)) . ' duplicate addresses)');
        CLI::write("Sending via:   {$cfg['host']} as {$cfg['from_email']}");
        CLI::write('Daily limit:   ' . ($limitNow ?: 'none') . ($perDay ? "  → about {$perDay} contacts a day" : '')
            . (isset($changes['daily_limit']) ? '  (will be saved)' : ''));
        CLI::write('Owner copy:    ' . ($copyNow ?: 'off') . (isset($changes['copy_to']) ? '  (will be saved)' : ''));
        CLI::write('Reply-to:      ' . ($replyTo ?: '(none — replies go to ' . $cfg['from_email'] . ')'));
        CLI::write("First email:   {$start} {$tz} — {$first['name']}");
        foreach ($steps as $i => $s) {
            $local = date('Y-m-d', strtotime(substr($start, 0, 10) . " +{$s['days_after']} days")) . substr($start, 10);
            CLI::write('Follow-up ' . ($i + 1) . ":   {$local} {$tz} — {$s['name']}  (day {$s['days_after']}, only non-clickers)");
        }
        if ($perDay !== null && $reach > $perDay) {
            CLI::write('Note: the first email needs about ' . (int) ceil($reach / $perDay)
                . ' days at this limit; each follow-up waits for the one before it to finish.', 'yellow');
        }

        if ($dryRun) {
            return;
        }

        // ── Write ─────────────────────────────────────────────────────
        if ($changes !== []) {
            $db->table('integrations')->where('id', $row['id'])
                ->update(['config' => json_encode($newCfg), 'updated_at' => date('Y-m-d H:i:s')]);
        }

        $settings = (new EmailComposer())->settings($tenantId);
        if (! $settings['ready']) {
            $this->fail((string) $settings['reason']);

            return;
        }

        $campaigns = (new EmailCampaignModel())->setTenant($tenantId);
        $id        = (int) $campaigns->insert([
            'email_template_id' => (int) $first['id'],
            'name'              => mb_substr($name, 0, 150),
            'subject'           => $first['subject'],
            'preheader'         => $first['preheader'],
            'from_name'         => $fromName ?: null,
            'reply_to'          => $replyTo ?: null,
            'html_body'         => $first['html_body'],
            'segment'           => json_encode($segment + ['name' => $tag['name']]),
            'status'            => 'scheduled',
            'scheduled_at'      => $startUtc,
            'schedule_timezone' => $tz,
            'stats'             => json_encode(EmailCampaignModel::emptyStats()),
        ], true);

        $source = $campaigns->find($id);
        try {
            $created = (new NurtureSequenceService())->create(
                $tenantId,
                $source,
                array_map(static fn ($s) => ['email_template_id' => $s['email_template_id'], 'days_after' => $s['days_after']], $steps),
                'not_clicked',
                ['qualified', 'won', 'lost'],
                substr($start, 11, 5),
                $tz,
            );
        } catch (\InvalidArgumentException $e) {
            // Never leave a first email scheduled without the series it belongs to.
            $campaigns->delete($id);
            $this->fail('Follow-ups: ' . $e->getMessage() . ' Nothing was scheduled.');

            return;
        }

        CLI::write("Scheduled campaign #{$id} + " . count($created) . ' follow-ups (#'
            . implode(', #', array_column($created, 'id')) . '). See Email Marketing → Campaigns.', 'green');
    }

    /**
     * Read an option given as `--name value` OR `--name=value`. CodeIgniter's
     * parser only understands the first form and files `--name=value` under
     * the key "name=value". A bare flag (`--dry-run`) returns ''.
     */
    private function opt(string $name): ?string
    {
        $options = CLI::getOptions();
        if (isset($options[$name])) {
            return (string) $options[$name];
        }
        foreach ($options as $key => $value) {
            if (str_starts_with((string) $key, $name . '=')) {
                return substr((string) $key, strlen($name) + 1);
            }
        }

        return array_key_exists($name, $options) ? '' : null;
    }

    private function fail(string $message): void
    {
        CLI::error($message);
    }
}
