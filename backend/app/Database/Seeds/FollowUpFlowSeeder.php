<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * The no-reply follow-up: one marketing template and the flow that sends it.
 *
 * ONE template covers all 28 imported categories, because the category-specific
 * part is a variable rather than a separate approval. {{2}} carries the exact
 * problem we raised with that prospect — resolved by FollowUpHooks from the
 * template they were actually sent, falling back to their industry tag. Nine
 * near-identical templates would have meant nine Meta reviews, nine chances of
 * a rejection leaving a category with no follow-up at all, and a fresh approval
 * cycle every time a line of copy changed.
 *
 * Three things in the copy are deliberate, and all three exist to protect the
 * sending number rather than to sell:
 *
 *  1. It acknowledges the silence instead of ignoring it. Pretending the first
 *     message never happened is what makes a second one feel like spam.
 *  2. It offers the exit before the pitch. People who can opt out politely do
 *     not press Block, and Block — not "no" — is what restricts a number.
 *  3. The three buttons are byte-identical to every other Gamavis template, so
 *     flows #21/#22/#23 already answer a tap. #21 now offers demo slots
 *     immediately, which means a tap here can book a meeting with no human in
 *     the loop and no new automation to build.
 *
 * Seeded as DRAFT, and the flow cannot be activated until Meta approves the
 * template — FlowValidator rejects a send_template node pointing at anything
 * unapproved.
 *
 * Idempotent: keyed on name. SEED_FORCE=1 rewrites the flow graph in place.
 */
class FollowUpFlowSeeder extends Seeder
{
    private const TEMPLATE_NAME       = 'gamavis_followup_contextual';
    private const FINAL_TEMPLATE_NAME = 'gamavis_followup_final';
    private const FLOW_NAME     = 'No-reply follow-up — category aware';

    /** The three labels flows #21/#22/#23 already answer. Do not reword. */
    private const BUTTONS = [
        ['type' => 'QUICK_REPLY', 'text' => 'Show me a demo'],
        ['type' => 'QUICK_REPLY', 'text' => 'What does it cost?'],
        ['type' => 'QUICK_REPLY', 'text' => 'Talk to an expert'],
    ];

    public function run(): void
    {
        $tenantId = (int) env('SEED_TENANT_ID', 1);
        $now      = date('Y-m-d H:i:s');

        $templateId = $this->ensureTemplate($tenantId, $now);
        $finalId    = $this->ensureFinalTemplate($tenantId, $now);
        $this->ensureFlow($tenantId, $now, $templateId, $finalId);
    }

    // ------------------------------------------------------------------

    private function ensureTemplate(int $tenantId, string $now): int
    {
        $existing = $this->db->table('templates')->select('id, meta_status')
            ->where('tenant_id', $tenantId)->where('name', self::TEMPLATE_NAME)
            ->where('deleted_at', null)->get()->getRowArray();

        if ($existing !== null) {
            CLI::write(
                'FollowUpFlowSeeder: template ' . self::TEMPLATE_NAME
                . " already present (#{$existing['id']}, {$existing['meta_status']}).",
                'yellow'
            );
            return (int) $existing['id'];
        }

        $body = "Hi {{1}}, following up on the message I sent a few days ago.\n\n"
            . "No reply is completely fine — you may simply not have had a chance to look. "
            . "I wrote because {{2}} is where most owners we meet lose the most time.\n\n"
            . "If Excel and WhatsApp groups are working well for you, genuinely no problem. "
            . "Reply STOP and I won't write again.\n\n"
            . "If they aren't, I'll show you exactly what we would build for your business. "
            . "Twenty minutes, your own process, no slides.";

        $this->db->table('templates')->insert([
            'tenant_id'    => $tenantId,
            'name'         => self::TEMPLATE_NAME,
            'display_name' => 'No-reply follow-up (category aware)',
            'language'     => 'en',
            'category'     => 'marketing',
            'header_type'  => 'none',
            'body'         => $body,
            'footer'       => 'Reply STOP to opt out',
            'buttons'      => json_encode(self::BUTTONS, JSON_UNESCAPED_UNICODE),
            // Meta requires a sample for every variable. {{2}} must sample as a
            // real hook, not a placeholder word, or the reviewer cannot tell
            // what the finished message says.
            'variables'    => json_encode([
                '1' => 'Rajesh',
                '2' => 'tracking an order from production plan to dispatch',
            ], JSON_UNESCAPED_UNICODE),
            'meta_status'  => 'draft',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $id = (int) $this->db->insertID();
        CLI::write('FollowUpFlowSeeder: template ' . self::TEMPLATE_NAME . " created as DRAFT (#{$id}).", 'green');

        return $id;
    }

    /**
     * The second and final nudge.
     *
     * Deliberately not a rewording of the first. Somebody who ignored two
     * messages is telling us something, and the only honest third message is
     * one that says we are stopping. Naming that is what makes it land: "last
     * note from me" is read, "just circling back" is reported.
     *
     * Same three buttons, byte-identical, so a late change of mind still routes
     * into flows #21/#22/#23 and can book a demo unaided.
     */
    private function ensureFinalTemplate(int $tenantId, string $now): int
    {
        $existing = $this->db->table('templates')->select('id, meta_status')
            ->where('tenant_id', $tenantId)->where('name', self::FINAL_TEMPLATE_NAME)
            ->where('deleted_at', null)->get()->getRowArray();

        if ($existing !== null) {
            CLI::write(
                'FollowUpFlowSeeder: template ' . self::FINAL_TEMPLATE_NAME
                . " already present (#{$existing['id']}, {$existing['meta_status']}).",
                'yellow'
            );
            return (int) $existing['id'];
        }

        $body = "Hi {{1}}, last note from me — I won't write again after this.\n\n"
            . "If {{2}} is something you want to fix this year, I'll keep twenty minutes free "
            . "whenever it suits you. If it isn't a priority, that's a perfectly good answer "
            . "and I'll leave you to it.\n\n"
            . "Either way, thank you for your time.";

        $this->db->table('templates')->insert([
            'tenant_id'    => $tenantId,
            'name'         => self::FINAL_TEMPLATE_NAME,
            'display_name' => 'No-reply follow-up — final note',
            'language'     => 'en',
            'category'     => 'marketing',
            'header_type'  => 'none',
            'body'         => $body,
            'footer'       => 'Reply STOP to opt out',
            'buttons'      => json_encode(self::BUTTONS, JSON_UNESCAPED_UNICODE),
            'variables'    => json_encode([
                '1' => 'Rajesh',
                '2' => 'tracking an order from production plan to dispatch',
            ], JSON_UNESCAPED_UNICODE),
            'meta_status'  => 'draft',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $id = (int) $this->db->insertID();
        CLI::write('FollowUpFlowSeeder: template ' . self::FINAL_TEMPLATE_NAME . " created as DRAFT (#{$id}).", 'green');

        return $id;
    }

    private function ensureFlow(int $tenantId, string $now, int $templateId, int $finalTemplateId): void
    {
        $exists = $this->db->table('flows')
            ->where('tenant_id', $tenantId)->where('name', self::FLOW_NAME)
            ->where('deleted_at', null)->countAllResults() > 0;

        $force = filter_var(env('SEED_FORCE', false), FILTER_VALIDATE_BOOLEAN);

        if ($exists && ! $force) {
            CLI::write(
                "FollowUpFlowSeeder: flow '" . self::FLOW_NAME . "' already present — skipped (SEED_FORCE=1 to rewrite).",
                'yellow'
            );
            return;
        }

        $tagId = $this->tagId($tenantId, 'Followed up') ?? $this->createTag($tenantId, 'Followed up', $now);
        $graph = json_encode(self::buildGraph($templateId, $tagId, $finalTemplateId), JSON_UNESCAPED_UNICODE);

        if ($exists) {
            $this->db->table('flows')
                ->where('tenant_id', $tenantId)->where('name', self::FLOW_NAME)->where('deleted_at', null)
                ->update(['graph' => $graph, 'updated_at' => $now]);

            CLI::write('FollowUpFlowSeeder: flow graph rewritten (status and id unchanged).', 'green');
            return;
        }

        $this->db->table('flows')->insert([
            'tenant_id'      => $tenantId,
            'name'           => self::FLOW_NAME,
            'status'         => 'draft',
            'trigger_type'   => 'no_reply_followup',
            'trigger_config' => json_encode([]),
            // 'always': the scanner already guarantees at most MAX_STEPS nudges
            // per contact, and it is a stronger guard than reentry because it
            // counts across campaigns. Leaving this at 'once' would mean a
            // single stuck run silently cancels a contact's second nudge.
            'reentry_policy' => 'always',
            'graph'          => $graph,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        CLI::write("FollowUpFlowSeeder: flow '" . self::FLOW_NAME . "' created as DRAFT.", 'green');
        CLI::write('  Submit the template to Meta, then dry-run followups:scan before activating.', 'yellow');
    }

    private function tagId(int $tenantId, string $name): ?int
    {
        $row = $this->db->table('tags')->select('id')
            ->where('tenant_id', $tenantId)->where('name', $name)
            ->where('deleted_at', null)->get()->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }

    private function createTag(int $tenantId, string $name, string $now): int
    {
        $this->db->table('tags')->insert([
            'tenant_id' => $tenantId, 'name' => $name,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * no_reply_followup → which nudge is this? → send it → tag them.
     *
     * The branch on step is the whole point. A second nudge that repeats the
     * first word for word is worse than sending nothing: it reads as an
     * unattended machine, and an unattended machine is what people press Block
     * on. Step 1 names the problem; step 2 says we are stopping.
     *
     * There is no window_check, and that is not an oversight. Anyone whose
     * window is open must have messaged us, and anyone who messaged us has by
     * definition replied — so the scanner has already excluded them. The window
     * here is always shut, which is exactly why these have to be templates.
     *
     * Public and static so the graph can be asserted without a database.
     */
    public static function buildGraph(int $templateId, int $tagId, int $finalTemplateId = 0): array
    {
        // {{2}} comes from the run's state, which FollowUpScanner seeded with
        // the hook for this prospect's category. Never a contact field — the
        // hook belongs to the message, not to the person.
        $vars = [
            'variable_mapping'  => ['1' => 'name', '2' => 'state:followup_hook'],
            // Meta rejects an empty body parameter outright.
            'variable_defaults' => [
                '1' => 'there',
                '2' => \App\Services\Leads\FollowUpHooks::GENERIC,
            ],
        ];

        $nodes = [
            ['id' => 'tr', 'type' => 'no_reply_followup', 'position' => ['x' => 0, 'y' => 0],
             'data' => ['label' => 'Template sent, no reply']],

            // followup_step arrives as a string, so compare against "1".
            ['id' => 'c1', 'type' => 'condition', 'position' => ['x' => 260, 'y' => 0],
             'data' => [
                 'label'    => 'First nudge?',
                 'type'     => 'state',
                 'field'    => 'followup_step',
                 'operator' => 'equals',
                 'value'    => '1',
             ]],

            ['id' => 'm1', 'type' => 'send_template', 'position' => ['x' => 540, 'y' => -90],
             'data' => array_merge(['label' => 'Nudge 1 — name the problem', 'template_id' => $templateId], $vars)],

            ['id' => 'm2', 'type' => 'send_template', 'position' => ['x' => 540, 'y' => 100],
             'data' => array_merge(
                 ['label' => 'Nudge 2 — final note', 'template_id' => $finalTemplateId > 0 ? $finalTemplateId : $templateId],
                 $vars
             )],

            ['id' => 'tg', 'type' => 'add_tag', 'position' => ['x' => 820, 'y' => 0],
             'data' => ['label' => 'Tag Followed up', 'tag_id' => $tagId]],
        ];

        $edges = [
            ['id' => 'f1', 'source' => 'tr', 'target' => 'c1', 'sourceHandle' => 'next'],
            ['id' => 'f2', 'source' => 'c1', 'target' => 'm1', 'sourceHandle' => 'true'],
            ['id' => 'f3', 'source' => 'c1', 'target' => 'm2', 'sourceHandle' => 'false'],
            ['id' => 'f4', 'source' => 'm1', 'target' => 'tg', 'sourceHandle' => 'next'],
            ['id' => 'f5', 'source' => 'm2', 'target' => 'tg', 'sourceHandle' => 'next'],
        ];

        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
