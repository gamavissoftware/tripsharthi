<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * The 30-minutes-before reminder: one UTILITY template and the flow that sends it.
 *
 * Both halves live here because neither works alone — the flow cannot be
 * activated until the template it points at is approved by Meta.
 *
 * Why the flow branches on the window instead of just sending text: a demo
 * booked on Monday for Thursday means the 24-hour service window opened when
 * the contact tapped the slot on Monday and shut long before the reminder is
 * due. Free-form would be blocked at execution time and the contact would
 * simply never hear from us. So:
 *
 *   open   → free-form. Free, and it can name the exact time from run state.
 *   closed → an approved UTILITY template. Paid, but it actually arrives.
 *
 * UTILITY, not MARKETING, is the correct category for an appointment reminder:
 * it is cheaper, it is not subject to the marketing throttle that produces
 * error #131049, and Meta approves it readily because it follows an action the
 * customer themselves took.
 *
 * The template carries no buttons on purpose. A tapped quick-reply that no flow
 * answers is worse than no button at all, and nothing here listens for one —
 * so the copy asks for a reply instead, which opens the window and lands the
 * message in the shared inbox where a human picks it up.
 *
 * Seeded as DRAFT. Activating a flow that messages real customers stays a human
 * decision made on the Flows screen.
 *
 * Idempotent: template keyed on name, flow keyed on name. SEED_FORCE=1 rewrites
 * the flow graph in place, keeping its id and status.
 */
class MeetingReminderSeeder extends Seeder
{
    private const TEMPLATE_NAME    = 'gamavis_meeting_reminder';
    /** Same copy plus a "Join the call" URL button. A button cannot be added to
     *  an already-approved template, so the link version is a separate one. */
    private const TEMPLATE_V2_NAME = 'gamavis_meeting_reminder_v2';
    private const OWNER_ALERT_NAME = 'gamavis_owner_alert';
    private const FLOW_NAME        = 'Meeting reminder — 30 minutes before';

    public function run(): void
    {
        $tenantId = (int) env('SEED_TENANT_ID', 1);
        $now      = date('Y-m-d H:i:s');

        $templateId = $this->ensureTemplate($tenantId, $now);
        $v2Id       = $this->ensureReminderV2($tenantId, $now);
        $ownerId    = $this->ensureOwnerAlert($tenantId, $now);

        // Prefer the button version, but only once Meta has actually approved
        // it — send_template refuses anything else, and a flow pointing at a
        // pending template would fail every run until review finished.
        $best = $this->isApproved($tenantId, self::TEMPLATE_V2_NAME) ? $v2Id : $templateId;

        // Same rule for the owner alert, and it matters more here: the two
        // templates are reviewed independently, so the button version can land
        // while this one is still pending. Wiring an unapproved template into
        // the graph would fail validation and break the reminder for the
        // customer too — the alert is the least important part of this flow and
        // must never be what takes it down. Zero means "leave the node out".
        if (! $this->isApproved($tenantId, self::OWNER_ALERT_NAME)) {
            if ($ownerId > 0) {
                CLI::write('MeetingReminderSeeder: ' . self::OWNER_ALERT_NAME
                    . ' is not approved yet — the owner alert node is left out for now.', 'yellow');
            }
            $ownerId = 0;
        }

        $this->ensureFlow($tenantId, $now, $best, $ownerId);
    }

    // ------------------------------------------------------------------

    private function ensureTemplate(int $tenantId, string $now): int
    {
        $existing = $this->db->table('templates')->select('id, meta_status')
            ->where('tenant_id', $tenantId)->where('name', self::TEMPLATE_NAME)
            ->where('deleted_at', null)->get()->getRowArray();

        if ($existing !== null) {
            CLI::write(
                'MeetingReminderSeeder: template ' . self::TEMPLATE_NAME
                . " already present (#{$existing['id']}, {$existing['meta_status']}).",
                'yellow'
            );
            return (int) $existing['id'];
        }

        $body = "Hi {{1}}, quick reminder — your demo with Gamavis starts in about 30 minutes.\n\n"
            . "We'll look at your own process on screen, not a slide deck. "
            . "If the timing no longer suits, just reply here and we'll move it.";

        $this->db->table('templates')->insert([
            'tenant_id'    => $tenantId,
            'name'         => self::TEMPLATE_NAME,
            'display_name' => 'Meeting reminder (30 min before)',
            'language'     => 'en',
            // Utility: follows an action the customer took, so it is cheaper
            // than marketing and exempt from the marketing throttle.
            'category'     => 'utility',
            'header_type'  => 'none',
            'body'         => $body,
            // {{1}} is the contact's first name. Meta requires a sample value.
            'variables'    => json_encode(['1' => 'Rajesh'], JSON_UNESCAPED_UNICODE),
            'meta_status'  => 'draft',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $id = (int) $this->db->insertID();
        CLI::write("MeetingReminderSeeder: template " . self::TEMPLATE_NAME . " created as DRAFT (#{$id}).", 'green');
        CLI::write('  Submit it to Meta before activating the flow — send_template refuses anything unapproved.', 'yellow');

        return $id;
    }

    private function ensureFlow(int $tenantId, string $now, int $templateId, int $ownerAlertId = 0): void
    {
        $exists = $this->db->table('flows')
            ->where('tenant_id', $tenantId)->where('name', self::FLOW_NAME)
            ->where('deleted_at', null)->countAllResults() > 0;

        $force = filter_var(env('SEED_FORCE', false), FILTER_VALIDATE_BOOLEAN);

        if ($exists && ! $force) {
            CLI::write(
                "MeetingReminderSeeder: flow '" . self::FLOW_NAME . "' already present — skipped (SEED_FORCE=1 to rewrite).",
                'yellow'
            );
            return;
        }

        $alertTo = (new \App\Services\Crm\OwnerAlertService())->alertNumber($tenantId) ?? '';
        $graph   = json_encode(
            self::buildGraph($templateId, $ownerAlertId, $alertTo),
            JSON_UNESCAPED_UNICODE
        );

        if ($exists) {
            $this->db->table('flows')
                ->where('tenant_id', $tenantId)->where('name', self::FLOW_NAME)->where('deleted_at', null)
                ->update(['graph' => $graph, 'updated_at' => $now]);

            CLI::write("MeetingReminderSeeder: flow graph rewritten (status and id unchanged).", 'green');
            return;
        }

        $this->db->table('flows')->insert([
            'tenant_id'      => $tenantId,
            'name'           => self::FLOW_NAME,
            'status'         => 'draft',
            'trigger_type'   => 'meeting_reminder',
            'trigger_config' => json_encode([]),
            // 'always', not the default 'once'. Once-per-meeting is already
            // guaranteed by meetings.reminder_sent, which is the stronger guard
            // because it is per meeting rather than per contact. Leaving this at
            // 'once' would mean a single run stuck in 'waiting' silently
            // suppresses every future reminder that contact should ever get.
            'reentry_policy' => 'always',
            'graph'          => $graph,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        CLI::write("MeetingReminderSeeder: flow '" . self::FLOW_NAME . "' created as DRAFT.", 'green');
    }

    private function isApproved(int $tenantId, string $name): bool
    {
        $row = $this->db->table('templates')->select('meta_status')
            ->where('tenant_id', $tenantId)->where('name', $name)
            ->where('deleted_at', null)->get()->getRowArray();

        return ($row['meta_status'] ?? '') === 'approved';
    }

    /**
     * The reminder again, with a "Join the call" button.
     *
     * A button cannot be bolted onto a template Meta has already approved, so
     * this is a second template rather than an edit. The button is STATIC —
     * a dynamic URL would need a per-send button parameter, which the send path
     * only builds for carousels — which is exactly why it points at /meet
     * rather than at Google: one fixed URL that resolves to wherever demos
     * currently happen.
     */
    private function ensureReminderV2(int $tenantId, string $now): int
    {
        $existing = $this->db->table('templates')->select('id, meta_status')
            ->where('tenant_id', $tenantId)->where('name', self::TEMPLATE_V2_NAME)
            ->where('deleted_at', null)->get()->getRowArray();

        if ($existing !== null) {
            CLI::write('MeetingReminderSeeder: ' . self::TEMPLATE_V2_NAME
                . " already present (#{$existing['id']}, {$existing['meta_status']}).", 'yellow');

            return (int) $existing['id'];
        }

        $base = rtrim((string) (config('App')->baseURL ?? ''), '/');
        if ($base === '') {
            CLI::write('MeetingReminderSeeder: app.baseURL is empty — skipping the button template.', 'red');

            return 0;
        }

        $this->db->table('templates')->insert([
            'tenant_id'    => $tenantId,
            'name'         => self::TEMPLATE_V2_NAME,
            'display_name' => 'Meeting reminder (30 min before, with join button)',
            'language'     => 'en',
            'category'     => 'utility',
            'header_type'  => 'none',
            'body'         => "Hi {{1}}, quick reminder — your demo with Gamavis starts in about 30 minutes.\n\n"
                . "We'll look at your own process on screen, not a slide deck. "
                . "Tap below to join, or reply here if the timing no longer suits.",
            'buttons'      => json_encode(
                [['type' => 'URL', 'text' => 'Join the call', 'url' => $base . '/meet']],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            'variables'    => json_encode(['1' => 'Rajesh'], JSON_UNESCAPED_UNICODE),
            'meta_status'  => 'draft',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $id = (int) $this->db->insertID();
        CLI::write('MeetingReminderSeeder: ' . self::TEMPLATE_V2_NAME . " created as DRAFT (#{$id}).", 'green');

        return $id;
    }

    /** One utility template for every heads-up the owner gets about their own pipeline. */
    private function ensureOwnerAlert(int $tenantId, string $now): int
    {
        $existing = $this->db->table('templates')->select('id, meta_status')
            ->where('tenant_id', $tenantId)->where('name', self::OWNER_ALERT_NAME)
            ->where('deleted_at', null)->get()->getRowArray();

        if ($existing !== null) {
            CLI::write('MeetingReminderSeeder: ' . self::OWNER_ALERT_NAME
                . " already present (#{$existing['id']}, {$existing['meta_status']}).", 'yellow');

            return (int) $existing['id'];
        }

        $this->db->table('templates')->insert([
            'tenant_id'    => $tenantId,
            'name'         => self::OWNER_ALERT_NAME,
            'display_name' => 'Owner alert',
            'language'     => 'en',
            'category'     => 'utility',
            'header_type'  => 'none',
            // {{2}} completes the sentence, so one approval covers both "is
            // about to start" and "just tapped join".
            'body'         => "Heads up — {{1}} {{2}}\n\nNumber: {{3}}\n\nOpen TravelPilot for the full history.",
            'variables'    => json_encode([
                '1' => 'Rajesh Kumar',
                '2' => 'has a demo with you in about 30 minutes',
                '3' => '+919812345678',
            ], JSON_UNESCAPED_UNICODE),
            'meta_status'  => 'draft',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $id = (int) $this->db->insertID();
        CLI::write('MeetingReminderSeeder: ' . self::OWNER_ALERT_NAME . " created as DRAFT (#{$id}).", 'green');

        return $id;
    }

    /**
     * meeting_reminder → (window open?) → free-form, else approved template.
     *
     * Public and static so the graph can be asserted in tests without a database.
     */
    public static function buildGraph(int $templateId, int $ownerAlertId = 0, string $alertTo = ''): array
    {
        // {{meeting.time}} / {{meeting.minutes}} are filled from the run's state,
        // which MeetingReminderService seeds with the time already converted to
        // the business timezone. Never print start_at directly — it is UTC.
        // {{meeting.link}} resolves only when a meeting link is configured
        // (tenants.settings.meeting_link, or MEETING_VIDEO_LINK in .env). With
        // none set the token is left visible rather than silently blanked, so
        // the gap is obvious on the Flows screen instead of shipping a message
        // with a dangling "Join here:" and nothing after it.
        $text = "Hi {{contact.name}} — quick reminder.\n\n"
            . "Your demo is at {{meeting.time}}, about {{meeting.minutes}} minutes from now.\n\n"
            . "Join here: {{meeting.link}}\n\n"
            . "We'll look at your own process on screen, not a slide deck. "
            . "If the timing no longer suits, just reply here and I'll move it.";

        $nodes = [
            ['id' => 'tr', 'type' => 'meeting_reminder', 'position' => ['x' => 0, 'y' => 0],
             'data' => ['label' => '30 min before a meeting']],

            ['id' => 'w1', 'type' => 'window_check', 'position' => ['x' => 260, 'y' => 0],
             'data' => ['label' => '24h window still open?']],

            ['id' => 'm1', 'type' => 'send_freeform', 'position' => ['x' => 520, 'y' => -80],
             'data' => ['label' => 'Remind (free)', 'content' => $text]],

            ['id' => 'm2', 'type' => 'send_template', 'position' => ['x' => 520, 'y' => 90],
             'data' => [
                 'label'            => 'Remind (approved template)',
                 'template_id'      => $templateId,
                 // {{1}} → the contact's first name.
                 'variable_mapping' => ['1' => 'name'],
             ]],
        ];

        // Tell the owner too. With a free Google account nobody can enter the
        // call until the host admits them, so a reminder that only reaches the
        // customer leaves them knocking on a door with nobody behind it.
        // Both branches converge here — whether the customer got free-form or a
        // template, the person hosting still needs to know.
        if ($ownerAlertId > 0 && $alertTo !== '') {
            $nodes[] = ['id' => 'a1', 'type' => 'notify_number', 'position' => ['x' => 800, 'y' => 0],
                        'data' => [
                            'label'       => 'Tell me it is starting',
                            'to'          => $alertTo,
                            'template_id' => $ownerAlertId,
                            'params'      => [
                                '{{contact.name}}',
                                'has a demo with you in about 30 minutes',
                                '{{contact.wa_number}}',
                            ],
                        ]];
        }

        $edges = [
            ['id' => 'r1', 'source' => 'tr', 'target' => 'w1', 'sourceHandle' => 'next'],
            ['id' => 'r2', 'source' => 'w1', 'target' => 'm1', 'sourceHandle' => 'open'],
            // Unlike the drip, 'closed' is wired up here. The drip stops at a shut
            // window because a nurture message is optional; a reminder for a
            // meeting that is 30 minutes away is not, and a template is the only
            // thing WhatsApp will deliver outside the window.
            ['id' => 'r3', 'source' => 'w1', 'target' => 'm2', 'sourceHandle' => 'closed'],
        ];

        if ($ownerAlertId > 0 && $alertTo !== '') {
            $edges[] = ['id' => 'r4', 'source' => 'm1', 'target' => 'a1', 'sourceHandle' => 'next'];
            $edges[] = ['id' => 'r5', 'source' => 'm2', 'target' => 'a1', 'sourceHandle' => 'next'];
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
