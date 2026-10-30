<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\FlowModel;
use App\Models\TemplateModel;

/**
 * TripSarthi's OWN lead-nurture automations (the Subscriber Funnel), installed into the marketing workspace as DRAFT flows + DRAFT WhatsApp templates.
 * Installing sends nothing: a flow only goes live when the team activates it, and a WhatsApp step needs its template approved by Meta first
 * (the flow validator blocks activation until then). The e-mail steps carry their copy inline, so they work as soon as the flow is active.
 *
 * Every drip step is:  wait -> "already signed up / subscribed?" check -> send.  The check reads the `signed-up` tag that
 * PlatformCrmService::reconcile() puts on a lead the moment they register (a subscriber carries it too), so nobody who already joined is nagged.
 * Every WhatsApp step is  window_check -> free-form when the 24-hour window is open, else the approved MARKETING template (WhatsApp policy, spec §1).
 * Copy only says things that are true of the product (free plan, limits from FeatureGate) and promises no discount that does not exist.
 */
final class PlatformNurtureRecipes
{
    public const TAGS = [
        'fb-lead'      => ['Facebook lead', '#1877f2'],
        'nurture'      => ['In nurture', '#12a89e'],
        'hot-lead'     => ['Hot lead', '#f98f26'],
        'signed-up'    => ['Signed up', '#0a6cc4'],
        'subscriber'   => ['Subscriber', '#16a34a'],
        'not-interested' => ['Not interested', '#6b7280'],
    ];

    /** Pipeline stages: [name, probability, is_won, is_lost]. Order matters (PlatformCrmService relies on the names). */
    public const STAGES = [
        ['New lead', 10, 0, 0], ['Contacted', 20, 0, 0], ['Engaged', 35, 0, 0], ['Signed up (free)', 60, 0, 0], ['Subscribed', 100, 1, 0], ['Not interested', 0, 0, 1],
    ];

    private static function appUrl(string $campaign, string $hash = '/register'): string
    {
        $base = rtrim((string) (env('APP_URL') ?: 'https://app.tripsarthi.com'), '/');
        return $base . '/?utm_source=crm&utm_medium=nurture&utm_campaign=' . $campaign . '#' . $hash;
    }

    /** A simple, inline-styled e-mail body (the sender adds the unsubscribe footer). */
    private static function mail(string $title, array $paras, ?array $cta = null, ?array $bullets = null): string
    {
        $h = '<div style="font-family:Arial,Helvetica,sans-serif;color:#1f2937;font-size:15px;line-height:1.6;max-width:560px">'
           . '<h2 style="color:#0a1f44;font-size:20px;margin:0 0 14px">' . htmlspecialchars($title, ENT_QUOTES) . '</h2>';
        foreach ($paras as $p) { $h .= '<p style="margin:0 0 12px">' . $p . '</p>'; }
        if ($bullets) {
            $h .= '<ul style="margin:0 0 14px;padding-left:20px">';
            foreach ($bullets as $b) { $h .= '<li style="margin-bottom:6px">' . $b . '</li>'; }
            $h .= '</ul>';
        }
        if ($cta) {
            $h .= '<p style="margin:18px 0"><a href="' . htmlspecialchars($cta[1], ENT_QUOTES) . '" style="background:#0a6cc4;color:#ffffff;text-decoration:none;padding:11px 20px;border-radius:8px;display:inline-block;font-weight:bold">'
                . htmlspecialchars($cta[0], ENT_QUOTES) . '</a></p>';
        }
        return $h . '<p style="margin:14px 0 0">— Team TripSarthi<br><span style="color:#6b7280;font-size:13px">Reply to this e-mail or message us on WhatsApp any time.</span></p></div>';
    }

    /**
     * Recipes: key => name, trigger, config (tag keys are resolved to ids at install), summary, steps.
     * A step is ['wait'=>[n,unit]?, 'type'=>'email'|'whatsapp'|'task', ...].
     */
    public static function all(): array
    {
        return [
            'fb_welcome_nurture' => [
                'name' => 'Facebook lead — welcome & 14-day nurture', 'trigger' => 'meta_lead_received', 'config' => [], 'reentry' => 'once', 'guard' => 'signed-up',
                'summary' => 'The moment a Facebook / Instagram lead-form enquiry arrives: put it in the Subscriber Funnel, task a call, welcome by e-mail and WhatsApp, then follow up over 14 days until they sign up.',
                'steps' => [
                    ['type' => 'setup'],
                    ['type' => 'task', 'title' => 'Call this new Facebook lead (first 30 minutes win)', 'priority' => 'high', 'task_type' => 'call', 'due_in_days' => 1],
                    ['type' => 'email', 'key' => 'welcome', 'subject' => 'Welcome to TripSarthi, {{contact.first_name|there}} — here is your next step',
                        'html' => self::mail('Thanks for your interest, {{contact.first_name|there}}!', [
                            'You asked about TripSarthi — the CRM, WhatsApp and quoting tool built for Indian travel agencies.',
                            'The fastest way to see if it fits is to try it. Create a free workspace (no card needed) and you can capture an enquiry, build a quote and send it on WhatsApp in one sitting:',
                        ], ['Create my free workspace', self::appUrl('fb_welcome')], [
                            'Every enquiry from Facebook, your website and WhatsApp in one place',
                            'Itineraries and quotes with GST and TCS worked out for you',
                            'Payment reminders and documents that go out automatically',
                        ])],
                    ['type' => 'whatsapp', 'key' => 'wa_welcome', 'category' => 'marketing', 'name' => 'ts_fb_welcome',
                        'body' => 'Hi {{1}}, thanks for your interest in TripSarthi — the CRM + WhatsApp tool for travel agencies. You can try it free at app.tripsarthi.com. Reply here if you would like a quick walkthrough.',
                        'map' => ['1' => 'name'], 'sample' => ['Rohit'],
                        'free' => "Hi {{contact.name}}, thanks for your interest in TripSarthi! You can try it free here: " . self::appUrl('fb_welcome_wa') . "\nWant a quick walkthrough? Just reply here."],
                    ['wait' => [1, 'days'], 'type' => 'whatsapp', 'key' => 'wa_help', 'category' => 'marketing', 'name' => 'ts_fb_day1_help',
                        'body' => 'Hi {{1}}, did you get a chance to look at TripSarthi? If you tell us how many enquiries you handle a month, we will show you the best way to set it up. Just reply here.',
                        'map' => ['1' => 'name'], 'sample' => ['Rohit'],
                        'free' => "Hi {{contact.name}}, did you get a chance to look at TripSarthi? Tell us roughly how many enquiries you handle a month and we'll show you the best way to set it up."],
                    ['wait' => [2, 'days'], 'type' => 'email', 'key' => 'value', 'subject' => 'How travel agencies stop losing enquiries',
                        'html' => self::mail('Enquiries slip away when follow-up is manual', [
                            'Most agencies lose leads not because the quote was wrong, but because nobody followed up at the right time.',
                            'With TripSarthi each enquiry becomes a trip with a next step, and quotes, payment reminders and WhatsApp follow-ups run on their own:',
                        ], ['See it in action — start free', self::appUrl('fb_value')], [
                            'Quote follow-ups when a customer opened but did not accept',
                            'Instalment reminders on WhatsApp before and after the due date',
                            'A dashboard that shows who needs a call today',
                        ])],
                    ['wait' => [2, 'days'], 'type' => 'whatsapp', 'key' => 'wa_demo', 'category' => 'marketing', 'name' => 'ts_fb_day4_demo',
                        'body' => 'Hi {{1}}, travel agencies use TripSarthi to turn WhatsApp and Facebook enquiries into quotes in minutes. Want us to set up a sample trip for you? Reply YES and we will do it.',
                        'map' => ['1' => 'name'], 'sample' => ['Rohit'],
                        'free' => "Hi {{contact.name}}, agencies use TripSarthi to turn WhatsApp and Facebook enquiries into quotes in minutes. Want us to set up a sample trip for you? Reply YES and we will."],
                    ['wait' => [2, 'days'], 'type' => 'email', 'key' => 'features', 'subject' => 'Your first quote in 10 minutes',
                        'html' => self::mail('Set up once, then it runs', [
                            'Here is the shortest path to value on a free workspace:',
                        ], ['Open TripSarthi', self::appUrl('fb_features', '/login')], [
                            '<b>1.</b> Add your business profile (GSTIN, logo, bank details)',
                            '<b>2.</b> Add a supplier and a rate card or two',
                            '<b>3.</b> Log an enquiry and build the itinerary',
                            '<b>4.</b> Share the quote link on WhatsApp — it tracks when the customer opens it',
                        ])],
                    ['wait' => [3, 'days'], 'type' => 'whatsapp', 'key' => 'wa_last', 'category' => 'marketing', 'name' => 'ts_fb_day9_check',
                        'body' => 'Hi {{1}}, a quick check-in from TripSarthi. Is there anything stopping you from trying it? Happy to help on a call or right here on WhatsApp.',
                        'map' => ['1' => 'name'], 'sample' => ['Rohit'],
                        'free' => "Hi {{contact.name}}, a quick check-in from TripSarthi. Is there anything stopping you from trying it? Happy to help on a call or right here."],
                    ['type' => 'task_after', 'title' => 'Final follow-up call for Facebook lead (9 days, not signed up)', 'priority' => 'medium', 'task_type' => 'call', 'due_in_days' => 1],
                    ['wait' => [5, 'days'], 'type' => 'email', 'key' => 'breakup', 'subject' => 'Should we close your file?',
                        'html' => self::mail('Is TripSarthi still on your list?', [
                            'We have not heard back, so we will stop sending reminders after this. If your plans change, your free workspace is one click away.',
                            'If something put you off, just reply and tell us — we read every answer.',
                        ], ['Start free whenever you are ready', self::appUrl('fb_breakup')])],
                    ['type' => 'tag', 'tag' => 'nurture-done'],
                ],
            ],
            'free_to_paid' => [
                'name' => 'Signed up free — activate & upgrade', 'trigger' => 'tag_added', 'config' => ['tag' => 'signed-up'], 'reentry' => 'once', 'guard' => 'subscriber',
                'summary' => 'When a lead creates a workspace: help them reach their first quote, then show what a paid plan adds. Stops once they subscribe.',
                'steps' => [
                    ['wait' => [1, 'days'], 'type' => 'email', 'key' => 'activate', 'subject' => 'Your TripSarthi workspace is ready — do these 3 things', 'stop' => 'subscriber',
                        'html' => self::mail('Welcome aboard!', ['Three things that make the biggest difference in your first week:'], ['Open my workspace', self::appUrl('activate', '/login')], [
                            'Connect WhatsApp so every chat lands in the inbox',
                            'Add your business profile so quotes and invoices carry your GSTIN',
                            'Log your first enquiry and build a quote',
                        ])],
                    ['wait' => [2, 'days'], 'type' => 'whatsapp', 'key' => 'wa_stuck', 'category' => 'marketing', 'name' => 'ts_activate_stuck', 'stop' => 'subscriber',
                        'body' => 'Hi {{1}}, how is your TripSarthi workspace going? If you are stuck on WhatsApp setup, quotes or GST details, reply here and we will walk you through it.',
                        'map' => ['1' => 'name'], 'sample' => ['Rohit'],
                        'free' => "Hi {{contact.name}}, how is your TripSarthi workspace going? If you're stuck on WhatsApp setup, quotes or GST details, reply here and we'll walk you through it."],
                    ['wait' => [4, 'days'], 'type' => 'email', 'key' => 'upgrade', 'subject' => 'When a free workspace is not enough', 'stop' => 'subscriber',
                        'html' => self::mail('Room to grow', [
                            'The free plan is generous for getting started — 200 contacts, 1 team member, 1 automation and 1 WhatsApp number.',
                            'When you outgrow it, Starter lifts that to 1,000 contacts, 3 team members, 5 automations; Growth to 5,000 contacts, 10 team members and 20 automations. Prices are on the Billing page, GST extra.',
                        ], ['See plans', self::appUrl('upgrade', '/settings/billing')])],
                    ['wait' => [5, 'days'], 'type' => 'whatsapp', 'key' => 'wa_upgrade', 'category' => 'marketing', 'name' => 'ts_upgrade_nudge', 'stop' => 'subscriber',
                        'body' => 'Hi {{1}}, if TripSarthi is working for you and you would like more contacts, team members or automations, you can upgrade any time from Billing. Questions? Reply here.',
                        'map' => ['1' => 'name'], 'sample' => ['Rohit'],
                        'free' => "Hi {{contact.name}}, if TripSarthi is working for you and you'd like more contacts, team members or automations, you can upgrade any time from Billing. Questions? Reply here."],
                    ['type' => 'task', 'title' => 'Free workspace, not subscribed after 12 days — offer a call', 'priority' => 'medium', 'task_type' => 'call', 'due_in_days' => 1, 'stop' => 'subscriber'],
                ],
            ],
            'new_subscriber' => [
                'name' => 'New subscriber — thank you & onboarding', 'trigger' => 'tag_added', 'config' => ['tag' => 'subscriber'], 'reentry' => 'once',
                'summary' => 'When a lead becomes a paying subscriber: thank them, tell them how to get help, and check in after a week.',
                'steps' => [
                    ['type' => 'email', 'key' => 'thanks', 'subject' => 'Thank you for subscribing to TripSarthi',
                        'html' => self::mail('You are all set — thank you!', [
                            'Your GST invoice is in your inbox and on the Billing page. If you would like us to help migrate your contacts or set up your first automations, just reply to this e-mail.',
                        ], ['Open my workspace', self::appUrl('thanks', '/login')])],
                    ['type' => 'whatsapp', 'key' => 'wa_thanks', 'category' => 'marketing', 'name' => 'ts_subscriber_thanks',
                        'body' => 'Hi {{1}}, thank you for subscribing to TripSarthi! If you need help setting anything up, message us here any time.',
                        'map' => ['1' => 'name'], 'sample' => ['Rohit'],
                        'free' => "Hi {{contact.name}}, thank you for subscribing to TripSarthi! If you need help setting anything up, message us here any time."],
                    ['wait' => [7, 'days'], 'type' => 'task', 'title' => 'Onboarding check-in call with new subscriber', 'priority' => 'medium', 'task_type' => 'call', 'due_in_days' => 1],
                ],
            ],
        ];
    }

    /**
     * Install (idempotent by flow / template name). Returns recipe key => [flow_id, created].
     * @param array<string,int> $tagIds tag key => id (all of self::TAGS plus 'nurture-done')
     */
    public function install(int $tenantId, int $pipelineId, int $newStageId, array $tagIds, ?array $only = null): array
    {
        $out = [];
        foreach (self::all() as $key => $r) {
            if ($only !== null && ! in_array($key, $only, true)) { continue; }
            $existing = (new FlowModel())->setTenant($tenantId)->where('name', $r['name'])->first();
            if ($existing) { $out[$key] = ['flow_id' => (int) $existing['id'], 'created' => false]; continue; }

            $tplIds = [];
            foreach ($r['steps'] as $s) {
                if (($s['type'] ?? '') !== 'whatsapp') { continue; }
                $tpl = (new TemplateModel())->setTenant($tenantId)->where('name', $s['name'])->first();
                $tplIds[$s['key']] = $tpl ? (int) $tpl['id'] : (int) (new TemplateModel())->setTenant($tenantId)->insert([
                    'name' => $s['name'], 'display_name' => $r['name'] . ' — ' . $s['key'], 'language' => 'en', 'category' => $s['category'], 'header_type' => 'none',
                    'body' => $s['body'], 'variables' => json_encode($s['sample'], JSON_UNESCAPED_UNICODE), 'meta_status' => 'draft',
                ], true);
            }
            $config = $r['config'];
            if (isset($config['tag'])) { $config = ['tag_id' => (int) ($tagIds[$config['tag']] ?? 0)]; }
            $flowId = (int) (new FlowModel())->setTenant($tenantId)->insert([
                'name' => $r['name'], 'trigger_type' => $r['trigger'], 'trigger_config' => $config ? json_encode($config) : null,
                'graph' => json_encode(self::graph($r, $pipelineId, $newStageId, $tagIds, $tplIds), JSON_UNESCAPED_UNICODE),
                'reentry_policy' => $r['reentry'], 'status' => 'draft',
            ], true);
            $out[$key] = ['flow_id' => $flowId, 'created' => true];
        }
        return $out;
    }

    /** @return list<array> recipes with their install state, for the UI */
    public function status(int $tenantId): array
    {
        $rows = [];
        foreach (self::all() as $key => $r) {
            $f = (new FlowModel())->setTenant($tenantId)->where('name', $r['name'])->first();
            $steps = array_values(array_filter($r['steps'], static fn ($s) => in_array($s['type'], ['email', 'whatsapp', 'task', 'task_after'], true)));
            $rows[] = ['key' => $key, 'name' => $r['name'], 'summary' => $r['summary'], 'trigger' => $r['trigger'], 'installed' => (bool) $f,
                'flow_id' => $f ? (int) $f['id'] : null, 'flow_status' => $f['status'] ?? null,
                'emails' => count(array_filter($steps, static fn ($s) => $s['type'] === 'email')),
                'whatsapps' => count(array_filter($steps, static fn ($s) => $s['type'] === 'whatsapp')),
                'steps' => array_map(static fn ($s) => ['type' => $s['type'] === 'task_after' ? 'task' : $s['type'], 'label' => $s['subject'] ?? $s['title'] ?? ($s['key'] ?? ''), 'wait' => $s['wait'] ?? null], $steps)];
        }
        return $rows;
    }

    /** Builds the node graph: a straight line of steps; each step that follows a wait is guarded by a "signed up / subscribed?" check. */
    public static function graph(array $r, int $pipelineId, int $newStageId, array $tagIds, array $tplIds): array
    {
        $nodes = [['id' => 't', 'type' => $r['trigger'], 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => $r['name']]]];
        $edges = []; $n = 0; $x = 260;
        $pending = [['t', 'next']];          // [source node, handle] pairs the next node must be linked from
        $add = static function (string $id, string $type, array $data, string $handle = 'next') use (&$nodes, &$edges, &$pending, &$x, &$n): void {
            $nodes[] = ['id' => $id, 'type' => $type, 'position' => ['x' => $x, 'y' => 0], 'data' => $data];
            foreach ($pending as [$src, $h]) { $edges[] = ['id' => 'e' . (++$n), 'source' => $src, 'target' => $id, 'sourceHandle' => $h]; }
            $pending = [[$id, $handle]]; $x += 260;
        };
        foreach ($r['steps'] as $i => $s) {
            $id = 's' . $i;
            if (! empty($s['wait'])) {
                $add($id . 'd', 'delay', ['label' => 'Wait ' . $s['wait'][0] . ' ' . $s['wait'][1], 'value' => $s['wait'][0], 'unit' => $s['wait'][1]]);
            }
            $guard = $s['stop'] ?? (! empty($s['wait']) ? ($r['guard'] ?? null) : null);
            if ($guard !== null && isset($tagIds[$guard])) {
                // true = already signed up / subscribed: no edge, so the run ends there. false carries on.
                $add($id . 'c', 'condition', ['label' => $guard === 'subscriber' ? 'Already subscribed?' : 'Already signed up?', 'type' => 'tag', 'operator' => 'has_tag', 'tag_id' => $tagIds[$guard]], 'false');
            }
            switch ($s['type']) {
                case 'setup':
                    $add($id . 'a', 'create_deal', ['label' => 'Add to Subscriber Funnel', 'title' => 'Facebook Ads lead', 'pipeline_id' => $pipelineId, 'stage_id' => $newStageId, 'skip_if_open' => 1, 'source' => 'facebook_ads']);
                    foreach (['fb-lead', 'nurture'] as $k) {
                        if (isset($tagIds[$k])) { $add($id . 't' . $k, 'add_tag', ['label' => 'Tag: ' . self::TAGS[$k][0], 'tag_id' => $tagIds[$k]]); }
                    }
                    $add($id . 'u', 'update_field', ['label' => 'Lifecycle: lead', 'field' => 'lifecycle_stage', 'value' => 'lead']);
                    break;
                case 'task':
                case 'task_after':
                    $add($id, 'create_task', ['label' => 'Task: ' . $s['title'], 'title' => $s['title'], 'type' => $s['task_type'] ?? 'todo', 'priority' => $s['priority'] ?? 'medium', 'due_in_days' => $s['due_in_days'] ?? 1]);
                    break;
                case 'tag':
                    if (! empty($tagIds[$s['tag']])) { $add($id, 'add_tag', ['label' => 'Tag: ' . $s['tag'], 'tag_id' => $tagIds[$s['tag']]]); }
                    break;
                case 'email':
                    $add($id, 'send_email', ['label' => 'E-mail: ' . $s['subject'], 'subject' => $s['subject'], 'html_body' => $s['html'], 'from_name' => 'TripSarthi']);
                    break;
                case 'whatsapp':
                    $add($id . 'w', 'window_check', ['label' => '24h window open?'], 'open');
                    $window = $id . 'w';
                    $nodes[] = ['id' => $id . 'f', 'type' => 'send_freeform', 'position' => ['x' => $x, 'y' => -90], 'data' => ['label' => 'WhatsApp (window open)', 'content' => $s['free']]];
                    $nodes[] = ['id' => $id . 'm', 'type' => 'send_template', 'position' => ['x' => $x, 'y' => 90], 'data' => [
                        'label' => 'WhatsApp template (window closed)', 'template_id' => $tplIds[$s['key']] ?? 0, 'template_name' => $s['name'],
                        'variable_mapping' => json_encode($s['map']), 'variable_defaults' => json_encode(new \stdClass())]];
                    $edges[] = ['id' => 'e' . (++$n), 'source' => $window, 'target' => $id . 'f', 'sourceHandle' => 'open'];
                    $edges[] = ['id' => 'e' . (++$n), 'source' => $window, 'target' => $id . 'm', 'sourceHandle' => 'closed'];
                    $pending = [[$id . 'f', 'next'], [$id . 'm', 'next']];     // the next step is linked from BOTH branches
                    $x += 260;
                    break;
            }
        }
        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
