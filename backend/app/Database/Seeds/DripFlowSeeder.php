<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * The engagement drip: what happens after someone taps a button on a campaign.
 *
 * Why it triggers on `tag_added` rather than `keyword_reply`: flows #21/#22/#23
 * already answer the three button labels and tag the contact "Hot Lead". A new
 * flow on the same keyword_reply triggers would answer a second time and the
 * contact would get two replies to one tap. Hanging the drip off the tag chains
 * onto those flows instead of competing with them.
 *
 * Why every message is free-form and every delay is hours, not days: a button
 * tap opens the 24-hour customer-service window, and inside it free-form is
 * both allowed and free. Stretch a step past 24h and the window shuts, the
 * window_check sends the run down the 'closed' branch, and the drip stops
 * rather than silently failing or forcing a paid template.
 *
 * Seeded as DRAFT. Activating a flow that sends real WhatsApp messages to real
 * customers is a human decision, made on the Flows screen after reading it.
 *
 * Idempotent: keyed on flow name.
 */
class DripFlowSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = (int) env('SEED_TENANT_ID', 1);
        $now      = date('Y-m-d H:i:s');
        $name     = 'Engagement drip — after a button tap';

        $exists = $this->db->table('flows')
            ->where('tenant_id', $tenantId)->where('name', $name)
            ->where('deleted_at', null)->countAllResults() > 0;

        // SEED_FORCE rewrites the graph of a flow that already exists, keeping
        // its id and status so flow_runs pointing at it stay valid. Off by
        // default: a re-seed must never silently overwrite a graph someone has
        // edited by hand on the Flows screen.
        $force = filter_var(env('SEED_FORCE', false), FILTER_VALIDATE_BOOLEAN);

        if ($exists && ! $force) {
            CLI::write("DripFlowSeeder: '{$name}' already present — skipped (SEED_FORCE=1 to rewrite).", 'yellow');
            return;
        }

        $hotLeadId = $this->tagId($tenantId, 'Hot Lead');
        if ($hotLeadId === null) {
            CLI::write("DripFlowSeeder: no 'Hot Lead' tag for tenant {$tenantId} — skipped.", 'red');
            return;
        }

        $alertTemplateId = $this->templateId($tenantId, 'gamavis_demo_request_alert');
        $demoBookedId    = $this->tagId($tenantId, 'Demo booked') ?? 0;

        $graph = json_encode(self::buildGraph($hotLeadId, $alertTemplateId, $demoBookedId), JSON_UNESCAPED_UNICODE);

        if ($exists) {
            $this->db->table('flows')
                ->where('tenant_id', $tenantId)->where('name', $name)->where('deleted_at', null)
                ->update(['graph' => $graph, 'updated_at' => $now]);

            CLI::write("DripFlowSeeder: '{$name}' graph rewritten (status and id unchanged).", 'green');
            return;
        }

        $this->db->table('flows')->insert([
            'tenant_id'      => $tenantId,
            'name'           => $name,
            'status'         => 'draft',
            'trigger_type'   => 'tag_added',
            'trigger_config' => json_encode(['tag_id' => $hotLeadId]),
            'graph'          => $graph,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        CLI::write("DripFlowSeeder: '{$name}' created as DRAFT.", 'green');
        CLI::write('  Read it on the Flows screen, then activate when you are happy.', 'yellow');
    }

    private function tagId(int $tenantId, string $name): ?int
    {
        $row = $this->db->table('tags')->select('id')
            ->where('tenant_id', $tenantId)->where('name', $name)
            ->where('deleted_at', null)->get()->getRowArray();

        return $row === null ? null : (int) $row['id'];
    }

    private function templateId(int $tenantId, string $name): int
    {
        $row = $this->db->table('templates')->select('id')
            ->where('tenant_id', $tenantId)->where('name', $name)
            ->where('deleted_at', null)->get()->getRowArray();

        return $row === null ? 0 : (int) $row['id'];
    }

    /**
     * tag_added → wait → (window open?) → ask a qualifying question →
     * wait → (window open?) → offer slots → alert a human.
     */
    public static function buildGraph(int $hotLeadId, int $alertTemplateId, int $demoBookedId = 0): array
    {
        $ask = "One more thing while it's fresh.\n\n"
            . "The part owners usually find useful isn't the software itself — it's seeing their own process on screen. "
            . "So before the demo we ask one question:\n\n"
            . "Which part of the day costs your team the most time right now — order status, stock, or following up with customers?\n\n"
            . "Whatever you answer, that's what we'll build the demo around.";

        $offer = "Happy to keep this simple.\n\n"
            . "Twenty minutes on your own process, not a slide deck. "
            . "Tap below and pick whichever time suits you — I'll send the invite straight away.";

        $nodes = [
            ['id' => 't1', 'type' => 'tag_added',     'position' => ['x' => 0,    'y' => 0],
             'data' => ['label' => 'Tagged Hot Lead', 'tag_id' => $hotLeadId]],

            ['id' => 'd1', 'type' => 'delay',         'position' => ['x' => 260,  'y' => 0],
             'data' => ['label' => 'Wait 2 hours', 'value' => 2, 'unit' => 'hours']],

            ['id' => 'w1', 'type' => 'window_check',  'position' => ['x' => 520,  'y' => 0],
             'data' => ['label' => '24h window still open?']],

            ['id' => 'm1', 'type' => 'send_freeform', 'position' => ['x' => 780,  'y' => 0],
             'data' => ['label' => 'Ask what costs them time', 'content' => $ask]],

            ['id' => 'd2', 'type' => 'delay',         'position' => ['x' => 1040, 'y' => 0],
             'data' => ['label' => 'Wait 5 hours', 'value' => 5, 'unit' => 'hours']],

            ['id' => 'w2', 'type' => 'window_check',  'position' => ['x' => 1300, 'y' => 0],
             'data' => ['label' => '24h window still open?']],

            // A real picker, not a question. "Morning or evening?" reads well and
            // then dead-ends: someone has to read the reply and propose a time.
            // The list books itself.
            // Flow #21 offers slots the moment someone taps "Show me a demo" and
            // tags them on success. Without this check the drip would offer a
            // second list hours later to someone already booked.
            ['id' => 'c1', 'type' => 'condition',     'position' => ['x' => 1560, 'y' => 0],
             'data' => ['label' => 'Not already booked?', 'type' => 'tag', 'tag_id' => $demoBookedId, 'operator' => 'not_has_tag']],

            ['id' => 's1', 'type' => 'send_slots',    'position' => ['x' => 1820, 'y' => 0],
             'data' => [
                 'label'         => 'Offer live slots',
                 'body'          => $offer,
                 'button_text'   => 'See times',
                 'section_title' => 'Available',
                 'count'         => 6,
                 'timezone'      => 'Asia/Kolkata',
             ]],

            ['id' => 'b1', 'type' => 'book_slot',     'position' => ['x' => 2080, 'y' => 0],
             'data' => ['label' => 'Book what they tapped', 'title' => 'Product demo', 'timezone' => 'Asia/Kolkata']],

            ['id' => 'bt', 'type' => 'add_tag',       'position' => ['x' => 2340, 'y' => 0],
             'data' => ['label' => 'Tag Demo booked', 'tag_id' => $demoBookedId]],

            ['id' => 'a1', 'type' => 'notify_number', 'position' => ['x' => 2600, 'y' => 0],
             'data' => [
                 'label'       => 'Alert my WhatsApp',
                 'to'          => '+919718991797',
                 'template_id' => $alertTemplateId,
                 'params'      => ['{{contact.name}}', '{{contact.wa_number}}', 'Demo slot picker — check Meetings for a booking'],
             ]],
        ];

        $edges = [
            ['id' => 'e1', 'source' => 't1', 'target' => 'd1', 'sourceHandle' => 'next'],
            ['id' => 'e2', 'source' => 'd1', 'target' => 'w1', 'sourceHandle' => 'next'],
            // 'closed' is intentionally left unconnected: a shut window ends the
            // run, which is the correct outcome — never a paid template instead.
            ['id' => 'e3', 'source' => 'w1', 'target' => 'm1', 'sourceHandle' => 'open'],
            ['id' => 'e4', 'source' => 'm1', 'target' => 'd2', 'sourceHandle' => 'next'],
            ['id' => 'e5', 'source' => 'd2', 'target' => 'w2', 'sourceHandle' => 'next'],
            ['id' => 'e6',  'source' => 'w2', 'target' => 'c1', 'sourceHandle' => 'open'],
            // 'false' leads nowhere on purpose: already booked means done.
            ['id' => 'e6b', 'source' => 'c1', 'target' => 's1', 'sourceHandle' => 'true'],
            // 'fallback', not 'next'. After a parked send, WebhookService routes
            // the tap to the edge whose sourceHandle EQUALS the tapped button id,
            // and only falls back to 'fallback' when none matches. Slot ids are
            // generated per send, so no edge can ever name one — every tap takes
            // the fallback path, and a 'next' edge here is simply never followed
            // (the run completes silently instead of booking).
            ['id' => 'e7', 'source' => 's1', 'target' => 'b1', 'sourceHandle' => 'fallback'],
            // Both outcomes alert a human: a booking is worth knowing about, and
            // so is a contact who tried to book and could not.
            ['id' => 'e8', 'source' => 'b1', 'target' => 'bt', 'sourceHandle' => 'booked'],
            ['id' => 'e9', 'source' => 'bt', 'target' => 'a1', 'sourceHandle' => 'next'],
            ['id' => 'e10', 'source' => 'b1', 'target' => 'a1', 'sourceHandle' => 'failed'],
        ];

        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
