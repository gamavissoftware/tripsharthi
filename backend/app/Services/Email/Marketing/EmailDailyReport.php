<?php

declare(strict_types=1);

namespace App\Services\Email\Marketing;

use App\Services\Email\EmailService;
use App\Services\Email\Marketing\Transport\MailTransport;

/**
 * The daily email-marketing report sent to the tenant's notify_to address:
 * what happened in the last 24 hours (sends, first opens, clicks, replies,
 * unsubscribes — with names), each recent campaign's running totals, and the
 * follow-ups coming up next. Everything is counted from the `emails` rows,
 * the same source as the campaign report pages.
 */
class EmailDailyReport
{
    private const IST = 19800;

    public function __construct(private readonly ?MailTransport $transport = null) {}

    /** @return array{sent:int, opened:int, clicked:int, replied:int, unsubscribed:int, openers:list<array>, clickers:list<array>, replies:list<array>, campaigns:list<array>, upcoming:list<array>} */
    public function build(int $tenantId, int $nowTs): array
    {
        $db    = db_connect();
        $from  = date('Y-m-d H:i:s', $nowTs - 86400);
        $to    = date('Y-m-d H:i:s', $nowTs);
        $base  = fn () => $db->table('emails e')
            ->join('contacts c', 'c.id = e.contact_id', 'left')
            ->join('accounts a', 'a.id = c.account_id', 'left')
            ->where('e.tenant_id', $tenantId)->where('e.deleted_at', null);
        $who   = 'c.id AS contact_id, c.name, c.wa_number, a.name AS company';

        $sent = $base()->where('e.direction', 'out')->where('e.status', 'sent')->where('e.email_campaign_id IS NOT NULL')
            ->where('e.sent_at >=', $from)->where('e.sent_at <', $to)->countAllResults();

        $openers = $base()->select("{$who}, MIN(e.to_email) AS email, MIN(e.opened_at) AS at, SUM(e.open_count) AS opens")
            ->where('e.email_campaign_id IS NOT NULL')->where('e.opened_at >=', $from)->where('e.opened_at <', $to)
            ->groupBy('c.id, c.name, c.wa_number, a.name')->orderBy('at', 'ASC')->get()->getResultArray();

        $clickers = $base()->select("{$who}, MIN(e.to_email) AS email, MIN(e.clicked_at) AS at, SUM(e.click_count) AS clicks")
            ->where('e.email_campaign_id IS NOT NULL')->where('e.clicked_at >=', $from)->where('e.clicked_at <', $to)
            ->groupBy('c.id, c.name, c.wa_number, a.name')->orderBy('at', 'ASC')->get()->getResultArray();

        $replies = $base()->select("{$who}, e.from_email AS email, e.created_at AS at, e.subject, e.body")
            ->where('e.direction', 'in')->where('e.is_auto_reply', 0)
            ->where('e.created_at >=', $from)->where('e.created_at <', $to)
            ->orderBy('e.created_at', 'ASC')->get()->getResultArray();

        $unsub = $base()->where('e.unsubscribed_at >=', $from)->where('e.unsubscribed_at <', $to)->countAllResults();

        $report    = new EmailCampaignReport();
        $campaigns = [];
        foreach ($db->table('email_campaigns')->select('id, name, status, started_at, scheduled_at')
            ->where('tenant_id', $tenantId)->where('deleted_at', null)
            ->whereIn('status', ['processing', 'paused', 'done'])
            ->where('started_at >=', date('Y-m-d H:i:s', $nowTs - 45 * 86400))
            ->orderBy('id', 'ASC')->get()->getResultArray() as $c) {
            $f       = $report->funnel($tenantId, [(int) $c['id']]);
            $replied = $db->table('emails')->where('tenant_id', $tenantId)->where('email_campaign_id', (int) $c['id'])
                ->where('replied_at IS NOT NULL')->countAllResults();
            $campaigns[] = $c + ['funnel' => $f, 'replied' => $replied];
        }

        $upcoming = $db->table('email_campaigns')->select('id, name, subject, scheduled_at, last_error')
            ->where('tenant_id', $tenantId)->where('deleted_at', null)->where('status', 'scheduled')
            ->where('scheduled_at <=', gmdate('Y-m-d H:i:s', $nowTs + 7 * 86400))
            ->orderBy('scheduled_at', 'ASC')->get()->getResultArray();

        return [
            'sent' => $sent, 'opened' => count($openers), 'clicked' => count($clickers),
            'replied' => count($replies), 'unsubscribed' => $unsub,
            'openers' => $openers, 'clickers' => $clickers, 'replies' => $replies,
            'campaigns' => $campaigns, 'upcoming' => $upcoming,
        ];
    }

    public function send(int $tenantId, int $nowTs, ?string $to = null): array
    {
        $settings = (new EmailComposer())->settings($tenantId);
        $to     ??= $settings['notify_to'];
        if ($to === '' || ! $settings['ready']) {
            return [false, 'No notify address or SMTP not ready.'];
        }
        $d    = $this->build($tenantId, $nowTs);
        $day  = gmdate('D, d M Y', $nowTs + self::IST);
        $subj = "Email report {$day}: {$d['opened']} opened · {$d['clicked']} clicked · {$d['replied']} replied";

        return ($this->transport ?? EmailComposer::defaultTransport())->send(
            $settings['smtp'], $to, $subj, $this->html($d, $day), $this->text($d), [], null, 'TravelPilot',
        );
    }

    public function html(array $d, string $day): string
    {
        $e    = static fn ($s) => htmlspecialchars(html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $ist  = static fn (?string $utc) => $utc ? gmdate('d M, h:i A', strtotime($utc . ' UTC') + self::IST) : '';
        $app  = EmailService::appUrl();
        $tile = static fn (string $label, int $n, string $color) => '<td width="20%" style="padding:4px;"><div style="border:1px solid #E3E8F0;border-radius:8px;padding:10px;text-align:center;">'
            . '<div style="font-size:22px;font-weight:bold;color:' . $color . ';">' . $n . '</div><div style="font-size:11px;color:#5B6474;">' . $label . '</div></div></td>';
        $person = function (array $r, string $extra = '') use ($e): string {
            $wa = preg_replace('/\D+/', '', (string) ($r['wa_number'] ?? ''));
            return '<tr><td style="padding:6px 8px;border-top:1px solid #EEF1F5;"><strong>' . $e($r['name'] ?: $r['email']) . '</strong>'
                . ($r['company'] ? '<br><span style="color:#5B6474;">' . $e($r['company']) . '</span>' : '') . '</td>'
                . '<td style="padding:6px 8px;border-top:1px solid #EEF1F5;font-size:12px;"><a href="mailto:' . $e($r['email']) . '">' . $e($r['email']) . '</a>'
                . ($wa ? '<br><a href="https://wa.me/' . $wa . '">+' . $wa . '</a>' : '') . '</td>'
                . '<td style="padding:6px 8px;border-top:1px solid #EEF1F5;font-size:12px;color:#5B6474;">' . $extra . '</td></tr>';
        };
        $section = static fn (string $title, string $rows, string $empty) => '<h3 style="font-size:15px;color:#0B2A5B;margin:22px 0 6px;">' . $title . '</h3>'
            . ($rows !== '' ? '<table width="100%" style="border-collapse:collapse;font-size:13px;">' . $rows . '</table>' : '<div style="color:#8A8F98;font-size:13px;">' . $empty . '</div>');

        $replies = '';
        foreach ($d['replies'] as $r) {
            $snippet = mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) $r['body'])), 0, 220);
            $replies .= $person($r, $e($ist($r['at'])) . '<br><em style="color:#1F2937;">“' . $e($snippet) . '”</em>');
        }
        $clickers = '';
        foreach ($d['clickers'] as $r) {
            $clickers .= $person($r, $e($ist($r['at'])) . ' · ' . (int) $r['clicks'] . ' click' . ((int) $r['clicks'] === 1 ? '' : 's'));
        }
        $openers = '';
        foreach (array_slice($d['openers'], 0, 60) as $r) {
            $openers .= $person($r, $e($ist($r['at'])) . ' · opened ' . (int) $r['opens'] . '×');
        }
        if (count($d['openers']) > 60) {
            $openers .= '<tr><td colspan="3" style="padding:6px 8px;color:#5B6474;">…and ' . (count($d['openers']) - 60) . ' more — see the campaign report.</td></tr>';
        }

        $camp = '';
        foreach ($d['campaigns'] as $c) {
            $f     = $c['funnel'];
            $camp .= '<tr><td style="padding:6px 8px;border-top:1px solid #EEF1F5;"><a href="' . $e($app . '/#/email/campaigns/' . $c['id']) . '">' . $e($c['name']) . '</a><br><span style="font-size:11px;color:#5B6474;">' . $e($c['status']) . '</span></td>'
                . '<td style="padding:6px 8px;border-top:1px solid #EEF1F5;text-align:right;">' . $f['sent'] . '</td>'
                . '<td style="padding:6px 8px;border-top:1px solid #EEF1F5;text-align:right;">' . $f['opened'] . ' <span style="color:#5B6474;">(' . $f['open_rate'] . '%)</span></td>'
                . '<td style="padding:6px 8px;border-top:1px solid #EEF1F5;text-align:right;">' . $f['clicked'] . ' <span style="color:#5B6474;">(' . $f['click_rate'] . '%)</span></td>'
                . '<td style="padding:6px 8px;border-top:1px solid #EEF1F5;text-align:right;">' . $c['replied'] . '</td>'
                . '<td style="padding:6px 8px;border-top:1px solid #EEF1F5;text-align:right;">' . $f['unsubscribed'] . '</td></tr>';
        }
        $campTable = $camp !== '' ? '<table width="100%" style="border-collapse:collapse;font-size:13px;"><tr style="color:#5B6474;font-size:11px;text-align:right;"><td style="text-align:left;padding:4px 8px;">CAMPAIGN</td><td style="padding:4px 8px;">SENT</td><td style="padding:4px 8px;">OPENED</td><td style="padding:4px 8px;">CLICKED</td><td style="padding:4px 8px;">REPLIED</td><td style="padding:4px 8px;">UNSUB</td></tr>' . $camp . '</table>' : '';

        $up = '';
        foreach ($d['upcoming'] as $u) {
            $up .= '<tr><td style="padding:6px 8px;border-top:1px solid #EEF1F5;">' . $e($ist($u['scheduled_at'])) . ' IST</td><td style="padding:6px 8px;border-top:1px solid #EEF1F5;">' . $e($u['subject']) . '<br><span style="font-size:11px;color:#5B6474;">' . $e($u['name']) . '</span></td></tr>';
        }

        return '<div style="font-family:Arial,sans-serif;color:#1F2937;max-width:680px;margin:0 auto;">'
            . '<div style="background:#0B2A5B;color:#fff;padding:18px 20px;border-radius:10px 10px 0 0;"><div style="font-size:12px;color:#A9C4F5;letter-spacing:.08em;">DAILY EMAIL REPORT</div><div style="font-size:20px;font-weight:bold;">' . $e($day) . '</div><div style="font-size:12px;color:#DCE7FB;">Last 24 hours</div></div>'
            . '<div style="border:1px solid #E3E8F0;border-top:0;border-radius:0 0 10px 10px;padding:14px 16px 20px;">'
            . '<table width="100%" style="border-collapse:collapse;"><tr>' . $tile('Sent', $d['sent'], '#1B4596') . $tile('Opened', $d['opened'], '#1B4596') . $tile('Clicked', $d['clicked'], '#16A34A') . $tile('Replied', $d['replied'], '#16A34A') . $tile('Unsubscribed', $d['unsubscribed'], '#DC2626') . '</tr></table>'
            . $section('&#8617; Replies — follow up personally', $replies, 'No replies in the last 24 hours.')
            . $section('&#128293; Clicked a link — hottest leads', $clickers, 'No clicks in the last 24 hours.')
            . $section('&#128065; Opened for the first time', $openers, 'No new opens in the last 24 hours.')
            . ($campTable !== '' ? '<h3 style="font-size:15px;color:#0B2A5B;margin:22px 0 6px;">Campaign totals so far</h3>' . $campTable : '')
            . $section('&#128197; Coming up (next 7 days)', $up, 'Nothing scheduled.')
            . '<p style="font-size:12px;color:#5B6474;margin:20px 0 0;">Opens are approximate (Apple Mail pre-loads images; some apps block them). Clicks and replies are exact. <a href="' . $e($app . '/#/email') . '">Open Email Marketing</a></p>'
            . '</div></div>';
    }

    private function text(array $d): string
    {
        return "Last 24 hours: sent {$d['sent']}, opened {$d['opened']}, clicked {$d['clicked']}, replied {$d['replied']}, unsubscribed {$d['unsubscribed']}.\n"
            . 'Open the HTML version of this email for names and details.';
    }
}
