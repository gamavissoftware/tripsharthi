<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Gamavis email nurture series — five branded email templates for tenant 1:
 *
 *   1  Introduction          day 0   "Run your business on a system — not on memory"
 *   2  Custom vs generic     day 3   why off-the-shelf ERP ends up back in Excel
 *   3  How we deliver        day 7   four steps, module by module
 *   4  Monday morning        day 12  dashboards, approvals, audit trail
 *   5  Close the file        day 18  plain-letter break-up, reply 1 / 2 / 3
 *
 * Copy rules (same as the WhatsApp openers in CategoryTemplateSeeder): name
 * real operational pains, describe what Gamavis builds in the words of
 * gamavis.com, and never invent prices, timelines, client names or statistics.
 *
 * Markup is table-based with inline styles — the only layout Outlook, Gmail
 * and phone mail apps render alike. Images are the public assets already on
 * www.gamavis.com. {{unsubscribe_url}} and {{footer_text}} are filled per
 * recipient by EmailTracking; {{contact.first_name|there}} by EmailPersonalizer.
 *
 * Idempotent: keyed on template name, and an existing template is left alone
 * so edits made in the app are never overwritten.
 *
 *   php spark db:seed GamavisEmailNurtureSeeder
 */
class GamavisEmailNurtureSeeder extends Seeder
{
    private const TENANT = 1;

    private const LOGO  = 'https://www.gamavis.com/assets/img/gamavis-logo.png';
    private const IMG   = 'https://www.gamavis.com/assets/img/';
    private const SITE  = 'https://www.gamavis.com';
    private const WA    = 'https://wa.me/919718991797?text=Hi%20Manglesh%2C%20I%27d%20like%20a%20free%20demo%20from%20Gamavis.';

    // Brand palette, sampled from the logo.
    private const NAVY   = '#0B2A5B';
    private const BRAND  = '#1B4596';
    private const ACCENT = '#2F6FE4';
    private const SOFT   = '#EEF3FB';
    private const INK    = '#1F2937';
    private const MUTED  = '#5B6474';
    private const LINE   = '#E3E8F0';

    public function run(): void
    {
        $db  = $this->db;
        $now = date('Y-m-d H:i:s');

        foreach ($this->templates() as $t) {
            $exists = $db->table('email_templates')
                ->where('tenant_id', self::TENANT)
                ->where('name', $t['name'])
                ->where('deleted_at', null)
                ->countAllResults();
            if ($exists > 0) {
                echo "  skip (exists): {$t['name']}\n";
                continue;
            }

            $db->table('email_templates')->insert([
                'tenant_id'  => self::TENANT,
                'name'       => $t['name'],
                'subject'    => $t['subject'],
                'preheader'  => $t['preheader'],
                'html_body'  => $t['html'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            echo "  created: {$t['name']}\n";
        }
    }

    /** @return list<array{name:string, subject:string, preheader:string, html:string}> */
    public function templates(): array
    {
        return [
            [
                'name'      => 'Gamavis Nurture 1 — Introduction',
                'subject'   => 'Is your business still running on Excel and WhatsApp groups?',
                'preheader' => 'Custom ERP, CRM & workflow automation — built around how you already work.',
                'html'      => $this->email1(),
            ],
            [
                'name'      => 'Gamavis Nurture 2 — Custom vs generic software',
                'subject'   => 'Why generic ERP so often ends up back in Excel',
                'preheader' => 'The workaround problem — and how software built around your workflow avoids it.',
                'html'      => $this->email2(),
            ],
            [
                'name'      => 'Gamavis Nurture 3 — How we deliver',
                'subject'   => 'No big-bang rollout: how we deliver, one department at a time',
                'preheader' => 'Four steps, live in phases, with your team trained along the way.',
                'html'      => $this->email3(),
            ],
            [
                'name'      => 'Gamavis Nurture 4 — Monday morning visibility',
                'subject'   => 'What your Monday morning could look like',
                'preheader' => 'Live dashboards, approvals that don\'t wait, and a record of who did what.',
                'html'      => $this->email4(),
            ],
            [
                'name'      => 'Gamavis Nurture 5 — Close the file',
                'subject'   => 'Should I close your file, {{contact.first_name|there}}?',
                'preheader' => 'A one-number reply is all I need.',
                'html'      => $this->email5(),
            ],
        ];
    }

    // ── Emails ──────────────────────────────────────────────────────────

    private function email1(): string
    {
        $pains = $this->checklist([
            'Order status needs a phone call to find out',
            'Approvals wait until somebody chases them',
            'Stock is whatever was last counted',
            'Month-end MIS is rebuilt from spreadsheets — again',
        ], '#DC2626', '&#10007;');

        $tiles = $this->tiles([
            ['&#127981;', 'Custom ERP', 'Purchase, store, production, dispatch and finance on one platform, with real-time reports.'],
            ['&#129309;', 'CRM & Lead Management', 'Pipeline stages, follow-ups, reminders and WhatsApp/email automation.'],
            ['&#9989;', 'Workflow & Approvals', 'Approval matrix, escalation rules, attachments and audit logs.'],
            ['&#128202;', 'Dashboards & MIS', 'KPI dashboards, trends and drill-downs built for management decisions.'],
            ['&#128279;', 'Integrations & APIs', 'WhatsApp, email, SMS, payments, barcode/QR and accounting exports.'],
            ['&#128736;', 'Support & AMC', 'Enhancements, training and security updates — so your team keeps moving.'],
        ]);

        $body = $this->para('Hi {{contact.first_name|there}},')
            . $this->para('I\'m Manglesh from <strong>Gamavis Software Solutions</strong>. Most growing businesses we meet run well on diaries, WhatsApp groups and Excel — right up to the point where one person has to remember everything. Does any of this sound familiar?')
            . $pains
            . $this->para('That is exactly the gap we close. We design <strong>custom ERP, CRM and workflow automation</strong> around your real operating flow — with approvals, dashboards and integrations — so your team moves faster and you get numbers you can trust.')
            . $this->heading('What we build')
            . $tiles
            . $this->image('illus-automation.png', 'Workflow automation built by Gamavis')
            . $this->ctaCard(
                'See it on your own process',
                'In 20 minutes we\'ll take one of your workflows and show what it looks like as software. Your process, no slides, no obligation.',
                'Book my free demo',
            );

        return $this->layout(
            'CUSTOM ERP &middot; CRM &middot; WORKFLOW AUTOMATION',
            'Run your business on a system — not on memory.',
            'Software designed around how you already work, delivered module by module.',
            $body,
        );
    }

    private function email2(): string
    {
        $cell = 'padding:14px 16px;font-size:14px;line-height:1.55;vertical-align:top;border-top:1px solid ' . self::LINE . ';';
        $rows = [
            ['Teams keep parallel spreadsheets because the flows don\'t match', 'Workflows are mapped first; software is built around your control points'],
            ['Approvals stay manual and audit trails stay incomplete', 'Approval matrix, escalation and logs live inside the system'],
            ['Reports become an "export and fix" exercise every week', 'Dashboards designed for action, not just for looking at'],
            ['Customisation gets expensive — or impossible — over time', 'Module-wise rollout that grows with your business'],
        ];
        $trs = '';
        foreach ($rows as [$bad, $good]) {
            $trs .= '<tr>'
                . '<td width="50%" style="' . $cell . 'color:' . self::MUTED . ';"><span style="color:#DC2626;font-weight:bold;">&#10007;</span>&nbsp; ' . $bad . '</td>'
                . '<td width="50%" style="' . $cell . 'color:' . self::INK . ';background:' . self::SOFT . ';"><span style="color:#16A34A;font-weight:bold;">&#10003;</span>&nbsp; ' . $good . '</td>'
                . '</tr>';
        }
        $table = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid ' . self::LINE . ';border-radius:10px;border-collapse:separate;overflow:hidden;margin:8px 0 24px;">'
            . '<tr>'
            . '<td width="50%" style="padding:12px 16px;font-size:12px;font-weight:bold;letter-spacing:.06em;color:' . self::MUTED . ';background:#F8FAFC;">GENERIC SOFTWARE</td>'
            . '<td width="50%" style="padding:12px 16px;font-size:12px;font-weight:bold;letter-spacing:.06em;color:#FFFFFF;background:' . self::BRAND . ';">GAMAVIS CUSTOM ERP / CRM</td>'
            . '</tr>' . $trs . '</table>';

        $body = $this->para('Hi {{contact.first_name|there}},')
            . $this->para('A quick follow-up to my last note. A question we hear a lot: <em>"Why not just buy a ready-made ERP?"</em>')
            . $this->para('Sometimes that is the right call. But when your workflow has its own approvals, exceptions and hand-offs, a generic product forces workarounds — and the workarounds quietly move back into Excel and WhatsApp.')
            . $table
            . $this->image('illus-erp-crm-wf.png', 'ERP, CRM and workflow modules connected into one platform')
            . $this->para('We don\'t ask your team to change how it works to suit the software. We map your flow first, then build the screens around it — so people actually use them.')
            . $this->ctaCard(
                'Is your workflow a fit?',
                'Tell me which process hurts most — orders, purchase, production, dispatch or follow-ups — and I\'ll show you how we would build it.',
                'Talk to an expert',
            );

        return $this->layout(
            'CUSTOM VS GENERIC',
            'Your workflow is unique. Your software should be too.',
            'Why off-the-shelf systems so often end in parallel spreadsheets.',
            $body,
        );
    }

    private function email3(): string
    {
        $steps = [
            ['Discovery & workflow mapping', 'We study hand-offs, approvals, exceptions and "who owns what" to define your real operating flow.'],
            ['Role-based UI/UX prototype', 'Screens your team will actually use — minimal clicks, clear actions, controlled visibility.'],
            ['Build, integrations & approvals', 'ERP/CRM and workflows with approval matrix, notifications, audit logs and WhatsApp/email/SMS/API integrations.'],
            ['Testing, training & go-live', 'Validated with your real master data, trained department by department, launched in stable phases.'],
        ];
        $html = '';
        foreach ($steps as $i => [$title, $text]) {
            $n     = $i + 1;
            $last  = $n === count($steps);
            $html .= '<tr>'
                . '<td width="56" valign="top" style="padding:0 0 ' . ($last ? '0' : '18px') . ';">'
                . '<table role="presentation" cellpadding="0" cellspacing="0"><tr><td width="40" height="40" align="center" valign="middle" style="width:40px;height:40px;border-radius:20px;background:' . self::BRAND . ';color:#FFFFFF;font-size:16px;font-weight:bold;font-family:Arial,Helvetica,sans-serif;">' . $n . '</td></tr></table>'
                . '</td>'
                . '<td valign="top" style="padding:2px 0 ' . ($last ? '0' : '18px') . ';">'
                . '<div style="font-size:16px;font-weight:bold;color:' . self::NAVY . ';margin:0 0 4px;">' . $title . '</div>'
                . '<div style="font-size:14px;line-height:1.6;color:' . self::MUTED . ';">' . $text . '</div>'
                . '</td></tr>';
        }
        $timeline = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;">' . $html . '</table>';

        $body = $this->para('Hi {{contact.first_name|there}},')
            . $this->para('The most common reason business software fails isn\'t the code — it\'s the rollout. Everything goes live on one day, nobody is trained, and within a month the team is back on the old register.')
            . $this->para('We don\'t "dump software". We deliver <strong>module by module</strong>, department by department, so each part is in daily use before the next one starts:')
            . $timeline
            . $this->image('illus-unified-platform.png', 'One unified platform for execution, approvals and reporting')
            . $this->ctaCard(
                'Start with one workflow',
                'Pick the process that costs you the most time today. We\'ll map it with you — free — and show what phase one would look like.',
                'Map my workflow',
            );

        return $this->layout(
            'HOW WE DELIVER',
            'Module by module — so your team actually uses it.',
            'Four steps, live in phases, with training along the way.',
            $body,
        );
    }

    private function email4(): string
    {
        $tiles = $this->tiles([
            ['&#9201;', 'Fewer delays', 'Approval and escalation flows cut the dependency on manual follow-ups.'],
            ['&#128065;', 'More visibility', 'Real-time MIS dashboards for weekly management decisions.'],
            ['&#128101;', 'Higher adoption', 'Role-based screens and streamlined actions teams actually use.'],
            ['&#128203;', 'Audit-ready', 'Activity logs, attachments and remarks for accountable execution.'],
        ]);

        $monday = $this->checklist([
            'Pending orders by customer, and where each one is stuck',
            'Approvals waiting — and exactly who they are waiting with',
            'Items below reorder level, before production stops',
            'Collections due this week, party by party',
        ], '#16A34A', '&#10003;');

        $body = $this->para('Hi {{contact.first_name|there}},')
            . $this->para('Picture Monday morning. Instead of calling three people and waiting for a spreadsheet, you open one screen and see, for example:')
            . $monday
            . $this->image('illus-dashboards.png', 'Live dashboards and MIS reports')
            . $this->para('Every number is live, and every approval, change and remark is logged against a name. That is what we mean by running on a system — decisions from today\'s numbers, not last month\'s Excel.')
            . $this->heading('What changes for your team')
            . $tiles
            . $this->ctaCard(
                'Want to see your own dashboard?',
                'Share the three numbers you chase every week and we\'ll show you how they would appear — live — in a Gamavis dashboard.',
                'Show me a demo',
            );

        return $this->layout(
            'DASHBOARDS &middot; APPROVALS &middot; MIS',
            'Decide from live numbers, not last month\'s Excel.',
            'Visibility and accountability built into the way work moves.',
            $body,
        );
    }

    private function email5(): string
    {
        $opt = static fn (string $n, string $text) => '<tr><td width="36" valign="top" style="padding:6px 0;"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td width="26" height="26" align="center" style="width:26px;height:26px;border-radius:13px;background:' . self::SOFT . ';color:' . self::BRAND . ';font-weight:bold;font-size:13px;font-family:Arial,Helvetica,sans-serif;">' . $n . '</td></tr></table></td>'
            . '<td valign="top" style="padding:9px 0 6px;font-size:15px;line-height:1.5;color:' . self::INK . ';">' . $text . '</td></tr>';

        $options = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 20px;">'
            . $opt('1', '<strong>Yes</strong> — show me a 20-minute demo')
            . $opt('2', '<strong>Not now</strong> — check back in three months')
            . $opt('3', '<strong>Not relevant</strong> for us')
            . '</table>';

        $body = $this->para('Hi {{contact.first_name|there}},')
            . $this->para('I\'ve written a few times about moving your operations off Excel and WhatsApp groups and onto a system built around the way you work. I haven\'t heard back, which usually means the timing is wrong — completely fine.')
            . $this->para('I don\'t want to keep filling your inbox, so could you just reply with a number?')
            . $options
            . $this->para('If I don\'t hear from you, this will be my last email on the subject. And if you would rather talk than type, my WhatsApp is open:')
            . $this->buttons('Chat on WhatsApp', self::WA, 'Visit gamavis.com', self::SITE)
            . '<div style="height:8px;line-height:8px;">&nbsp;</div>'
            . $this->para('Thanks for reading all the same.');

        // A plain letter: no hero band — it should read like a personal note.
        return $this->layout(null, null, null, $body);
    }

    // ── Building blocks ─────────────────────────────────────────────────

    private function layout(?string $eyebrow, ?string $title, ?string $subtitle, string $body): string
    {
        $navy   = self::NAVY;
        $brand  = self::BRAND;
        $line   = self::LINE;
        $muted  = self::MUTED;
        $logo   = self::LOGO;
        $site   = self::SITE;

        $hero = '';
        if ($title !== null) {
            $wa   = self::WA;
            $hero = <<<HTML
<tr><td class="px" bgcolor="{$brand}" style="background:{$brand};background-image:linear-gradient(135deg,{$navy} 0%,{$brand} 55%,#2F6FE4 100%);padding:40px 40px 36px;">
  <div style="font-size:11px;letter-spacing:.14em;font-weight:bold;color:#A9C4F5;margin:0 0 12px;">{$eyebrow}</div>
  <div class="h1" style="font-size:30px;line-height:1.2;font-weight:bold;color:#FFFFFF;margin:0 0 12px;">{$title}</div>
  <div style="font-size:16px;line-height:1.55;color:#DCE7FB;margin:0 0 24px;">{$subtitle}</div>
  <table role="presentation" cellpadding="0" cellspacing="0"><tr>
    <td bgcolor="#FFFFFF" style="border-radius:8px;">
      <a href="{$wa}" style="display:inline-block;padding:13px 24px;font-size:15px;font-weight:bold;color:{$brand};text-decoration:none;border-radius:8px;">Book a free demo &rarr;</a>
    </td>
  </tr></table>
</td></tr>
HTML;
        }

        $signature = $this->signature();

        return <<<HTML
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="x-apple-disable-message-reformatting">
<title>Gamavis Software Solutions</title>
<style>
  @media only screen and (max-width:620px){
    .container{width:100%!important}
    .px{padding-left:22px!important;padding-right:22px!important}
    .h1{font-size:25px!important}
    .col{display:block!important;width:100%!important;max-width:100%!important;padding-left:0!important;padding-right:0!important}
    .btn-cell{display:block!important;width:100%!important;text-align:center!important;margin:0 0 10px!important;box-sizing:border-box}
    .btn-gap{display:none!important}
    .hide-sm{display:none!important}
  }
  a{color:{$brand}}
</style>
</head>
<body style="margin:0;padding:0;background:#F2F5FA;-webkit-text-size-adjust:100%;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#F2F5FA" style="background:#F2F5FA;">
<tr><td align="center" style="padding:24px 10px;">
<table role="presentation" class="container" width="640" cellpadding="0" cellspacing="0" style="width:640px;max-width:640px;background:#FFFFFF;border-radius:14px;overflow:hidden;border:1px solid {$line};font-family:Arial,Helvetica,sans-serif;color:#1F2937;">

<tr><td class="px" style="padding:22px 40px;border-bottom:1px solid {$line};">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
    <td valign="middle"><a href="{$site}" style="text-decoration:none;"><img src="{$logo}" width="190" alt="Gamavis Software Solutions" style="display:block;width:190px;max-width:190px;height:auto;border:0;"></a></td>
    <td valign="middle" align="right" class="hide-sm" style="font-size:12px;color:{$muted};line-height:1.5;">Custom ERP &middot; CRM<br>Workflow Automation</td>
  </tr></table>
</td></tr>

{$hero}

<tr><td class="px" style="padding:34px 40px 8px;">
{$body}
</td></tr>

<tr><td class="px" style="padding:8px 40px 36px;">
{$signature}
</td></tr>

<tr><td class="px" bgcolor="{$navy}" style="background:{$navy};padding:26px 40px;font-size:12px;line-height:1.7;color:#A9B8D3;">
  <div style="font-size:14px;font-weight:bold;color:#FFFFFF;margin:0 0 4px;">Gamavis Software Solutions</div>
  <div style="margin:0 0 10px;">Custom ERP &middot; CRM &middot; Workflow Automation &middot; Dashboards &middot; Integrations</div>
  <div style="margin:0 0 10px;">{{footer_text}}</div>
  <div>
    <a href="{$site}" style="color:#FFFFFF;text-decoration:underline;">gamavis.com</a>
    &nbsp;&middot;&nbsp; You are receiving this because we believe our work is relevant to your business.
    <a href="{{unsubscribe_url}}" style="color:#FFFFFF;text-decoration:underline;">Unsubscribe</a>
  </div>
</td></tr>

</table>
</td></tr>
</table>
</body></html>
HTML;
    }

    private function signature(): string
    {
        $brand = self::BRAND;
        $navy  = self::NAVY;
        $muted = self::MUTED;
        $line  = self::LINE;
        $soft  = self::SOFT;
        $logo  = self::LOGO;
        $wa    = self::WA;
        $site  = self::SITE;
        $row   = "font-size:14px;line-height:1.5;color:#1F2937;padding:3px 0;";
        $link  = "color:{$brand};text-decoration:none;";

        return <<<HTML
<div style="font-size:15px;line-height:1.6;color:#1F2937;margin:0 0 14px;">Warm regards,</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid {$line};border-radius:12px;border-collapse:separate;background:{$soft};">
<tr>
  <td width="6" bgcolor="{$brand}" style="background:{$brand};border-radius:12px 0 0 12px;font-size:0;line-height:0;">&nbsp;</td>
  <td style="padding:18px 20px;">
    <div style="font-size:18px;font-weight:bold;color:{$navy};">Manglesh Upadhyay</div>
    <div style="font-size:13px;color:{$muted};margin:2px 0 12px;">Business Development Head &middot; Gamavis Software Solutions</div>
    <table role="presentation" cellpadding="0" cellspacing="0">
      <tr><td style="{$row}">&#128222;&nbsp; <a href="tel:+919718991797" style="{$link}">+91-97189 91797</a> &nbsp;|&nbsp; <a href="tel:+919650609615" style="{$link}">+91-96506 09615</a></td></tr>
      <tr><td style="{$row}">&#9993;&#65039;&nbsp; <a href="mailto:manglesh@gamavis.com" style="{$link}">manglesh@gamavis.com</a> &nbsp;|&nbsp; <a href="mailto:mangleshup@gmail.com" style="{$link}">mangleshup@gmail.com</a></td></tr>
      <tr><td style="{$row}">&#128172;&nbsp; <a href="{$wa}" style="{$link}">WhatsApp me</a> &nbsp;|&nbsp; &#127760;&nbsp; <a href="{$site}" style="{$link}">www.gamavis.com</a></td></tr>
    </table>
  </td>
  <td width="120" align="right" valign="middle" class="hide-sm" style="padding:18px 20px 18px 0;">
    <img src="{$logo}" width="110" alt="Gamavis" style="display:block;width:110px;height:auto;border:0;">
  </td>
</tr>
</table>
HTML;
    }

    private function para(string $html): string
    {
        return '<p style="margin:0 0 16px;font-size:15px;line-height:1.65;color:' . self::INK . ';">' . $html . '</p>';
    }

    private function heading(string $text): string
    {
        return '<div style="font-size:12px;letter-spacing:.12em;font-weight:bold;color:' . self::ACCENT . ';margin:28px 0 12px;text-transform:uppercase;">' . $text . '</div>';
    }

    /** @param list<string> $items */
    private function checklist(array $items, string $color, string $mark): string
    {
        $rows = '';
        foreach ($items as $it) {
            $rows .= '<tr><td width="30" valign="top" style="padding:7px 0;font-size:16px;font-weight:bold;color:' . $color . ';">' . $mark . '</td>'
                . '<td valign="top" style="padding:7px 0;font-size:15px;line-height:1.5;color:' . self::INK . ';">' . $it . '</td></tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;background:' . self::SOFT . ';border-radius:10px;border-collapse:separate;">'
            . '<tr><td style="padding:10px 18px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table></td></tr></table>';
    }

    /**
     * Two-column cards that stack on phones: each card is an inline-block
     * wrapper (Gmail app) inside a table cell with class="col" (media query).
     *
     * @param list<array{0:string,1:string,2:string}> $tiles [icon, title, text]
     */
    private function tiles(array $tiles): string
    {
        $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">';
        foreach (array_chunk($tiles, 2) as $pair) {
            $out .= '<tr>';
            foreach ($pair as $i => [$icon, $title, $text]) {
                $pad  = $i === 0 ? 'padding:0 6px 12px 0;' : 'padding:0 0 12px 6px;';
                $out .= '<td class="col" width="50%" valign="top" height="100%" style="height:100%;' . $pad . '">'
                    . '<table role="presentation" width="100%" height="100%" cellpadding="0" cellspacing="0" style="height:100%;border:1px solid ' . self::LINE . ';border-radius:10px;border-collapse:separate;">'
                    . '<tr><td valign="top" style="padding:16px 16px 14px;">'
                    . '<div style="font-size:22px;line-height:1;margin:0 0 8px;">' . $icon . '</div>'
                    . '<div style="font-size:15px;font-weight:bold;color:' . self::NAVY . ';margin:0 0 4px;">' . $title . '</div>'
                    . '<div style="font-size:13px;line-height:1.55;color:' . self::MUTED . ';">' . $text . '</div>'
                    . '</td></tr></table></td>';
            }
            if (count($pair) === 1) {
                $out .= '<td class="col" width="50%">&nbsp;</td>';
            }
            $out .= '</tr>';
        }

        return $out . '</table>';
    }

    private function image(string $file, string $alt): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 22px;"><tr><td align="center">'
            . '<img src="' . self::IMG . $file . '" width="560" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '" style="display:block;width:100%;max-width:560px;height:auto;border:0;border-radius:10px;">'
            . '</td></tr></table>';
    }

    private function buttons(string $primary, string $primaryUrl, string $secondary, string $secondaryUrl): string
    {
        $brand = self::BRAND;

        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:4px 0 8px;"><tr>'
            . '<td class="btn-cell" bgcolor="' . $brand . '" style="border-radius:8px;"><a href="' . $primaryUrl . '" style="display:inline-block;padding:13px 22px;font-size:15px;font-weight:bold;color:#FFFFFF;text-decoration:none;border-radius:8px;">' . $primary . '</a></td>'
            . '<td class="btn-gap" width="10">&nbsp;</td>'
            . '<td class="btn-cell" style="border-radius:8px;border:2px solid ' . $brand . ';"><a href="' . $secondaryUrl . '" style="display:inline-block;padding:11px 20px;font-size:15px;font-weight:bold;color:' . $brand . ';text-decoration:none;border-radius:8px;">' . $secondary . '</a></td>'
            . '</tr></table>';
    }

    private function ctaCard(string $title, string $text, string $button): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:10px 0 26px;background:' . self::SOFT . ';border:1px solid #D3DEF2;border-radius:12px;border-collapse:separate;">'
            . '<tr><td style="padding:24px 24px 20px;">'
            . '<div style="font-size:19px;font-weight:bold;color:' . self::NAVY . ';margin:0 0 6px;">' . $title . '</div>'
            . '<div style="font-size:14px;line-height:1.6;color:' . self::MUTED . ';margin:0 0 16px;">' . $text . '</div>'
            . $this->buttons('&#128172; ' . $button, self::WA, '&#128222; Call +91-97189 91797', 'tel:+919718991797')
            . '<div style="font-size:12px;color:' . self::MUTED . ';margin:8px 0 0;">Or simply reply to this email — it comes straight to me.</div>'
            . '</td></tr></table>';
    }
}
