<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Category-wise WhatsApp opener templates, plus one follow-up nudge.
 *
 * Each opener names the specific thing that goes wrong in that industry when
 * the business runs on diaries, WhatsApp groups and Excel — the problem to
 * extract — and offers automation as the fix.
 *
 * Three design rules, each learned the hard way on this account:
 *
 *  1. QUICK_REPLY buttons only. URL and PHONE_NUMBER buttons send nothing back
 *     to WhatsApp, so a tap is invisible to the flow engine — which is why
 *     `gamavis_custom_software_promo` can never trigger automation.
 *  2. The three button labels are byte-identical to `gamavis_erp_manufacturing`,
 *     because flows #21/#22/#23 already listen for exactly those strings. New
 *     templates inherit that automation with no new flows.
 *  3. Every body ends with a question, and every footer offers an exit. People
 *     who can opt out politely do not press "Block", and Block is what destroys
 *     a number's quality rating.
 *
 * Seeded as `draft`. Submission to Meta is a deliberate human action from the
 * Templates screen — this seeder never pushes to a live WABA.
 *
 * Idempotent: templates are keyed by name and skipped if they already exist.
 */
class CategoryTemplateSeeder extends Seeder
{
    /** The three labels flows #21/#22/#23 already answer. Do not reword. */
    private const BUTTONS = [
        ['type' => 'QUICK_REPLY', 'text' => 'Show me a demo'],
        ['type' => 'QUICK_REPLY', 'text' => 'What does it cost?'],
        ['type' => 'QUICK_REPLY', 'text' => 'Talk to an expert'],
    ];

    private const FOOTER = 'Reply STOP to opt out';

    public function run(): void
    {
        $tenantId = (int) env('SEED_TENANT_ID', 1);
        $now      = date('Y-m-d H:i:s');
        $created  = 0;
        $skipped  = 0;

        foreach ($this->templates() as $tpl) {
            $exists = $this->db->table('templates')
                ->where('tenant_id', $tenantId)
                ->where('name', $tpl['name'])
                ->where('deleted_at', null)
                ->countAllResults() > 0;

            if ($exists) {
                $skipped++;
                continue;
            }

            $this->db->table('templates')->insert([
                'tenant_id'    => $tenantId,
                'name'         => $tpl['name'],
                'display_name' => $tpl['display_name'],
                'language'     => 'en',
                'category'     => 'marketing',
                'header_type'  => 'none',
                'body'         => $tpl['body'],
                'footer'       => self::FOOTER,
                'buttons'      => json_encode(self::BUTTONS, JSON_UNESCAPED_UNICODE),
                // {{1}} is the contact's first name. Meta requires a sample.
                'variables'    => json_encode(['1' => 'Rajesh'], JSON_UNESCAPED_UNICODE),
                'meta_status'  => 'draft',
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            $created++;
        }

        CLI::write(
            "CategoryTemplateSeeder: {$created} created, {$skipped} already present (tenant {$tenantId}).",
            'green'
        );
        if ($created > 0) {
            CLI::write('  They are DRAFTS — review the copy, then submit to Meta from the Templates screen.', 'yellow');
        }
    }

    /**
     * One opener per pain cluster. The clusters group the 28 imported company
     * categories by the software problem they actually share, so the copy can
     * be specific without needing 28 separate Meta approvals.
     *
     * @return list<array{name:string, display_name:string, body:string}>
     */
    private function templates(): array
    {
        return [
            [
                'name'         => 'gamavis_manufacturing_no_software',
                'display_name' => 'Manufacturing & Industrial — running without software',
                'body' => "Hi {{1}} 👋\n\n"
                    . "Most manufacturing units we meet in NCR still run production on diaries, WhatsApp groups and Excel.\n\n"
                    . "It holds up — until a buyer asks where his order has reached, material runs short mid-run, or month-end costing has to be rebuilt from memory.\n\n"
                    . "We build software that ties sales order → production plan → material issue → dispatch → invoice into one screen, so any of that is answerable in seconds.\n\n"
                    . "Worth a 20-minute look at how it would fit your unit?",
            ],
            [
                'name'         => 'gamavis_apparel_no_software',
                'display_name' => 'Apparel & Textiles — running without software',
                'body' => "Hi {{1}} 👋\n\n"
                    . "In apparel and textiles, the detail that breaks Excel is the matrix — every style in several sizes and colours, half of it out with fabricators on job work.\n\n"
                    . "So fabric consumption is an estimate, rejections are argued from memory, and a buyer's delivery date depends on someone remembering to chase.\n\n"
                    . "We build software that tracks style-wise stock, job work sent and received, and order status against buyer dates.\n\n"
                    . "Shall I show you what that looks like for a unit your size?",
            ],
            [
                'name'         => 'gamavis_trading_no_software',
                'display_name' => 'Trading & Distribution — running without software',
                'body' => "Hi {{1}} 👋\n\n"
                    . "Most trading and distribution businesses we meet know their stock and their outstanding — but only by asking two or three people first.\n\n"
                    . "Stock sits across godowns, party rates live in one person's head, and credit outstanding is only really known at month end, when it is already late to act.\n\n"
                    . "We build software that shows live stock, party-wise rates and outstanding, and pending orders on one screen.\n\n"
                    . "Worth 20 minutes to see it against your own numbers?",
            ],
            [
                'name'         => 'gamavis_realestate_no_software',
                'display_name' => 'Real Estate & Construction — running without software',
                'body' => "Hi {{1}} 👋\n\n"
                    . "In property, the money is usually lost in the gap between an enquiry and a site visit.\n\n"
                    . "Leads arrive across WhatsApp, calls and portals, follow-ups depend on memory, and by the time someone circles back the client has seen three other options.\n\n"
                    . "We build software that keeps every enquiry with its source, next follow-up and site-visit status, and reminds your team before it goes cold.\n\n"
                    . "Shall I show you how it would handle your current enquiries?",
            ],
            [
                'name'         => 'gamavis_services_it_no_software',
                'display_name' => 'Services, IT & Professional — running without software',
                'body' => "Hi {{1}} 👋\n\n"
                    . "In a services business the work is visible but the margin is not.\n\n"
                    . "Project status lives in chats, who spent how long on what is a guess, and renewals or retainers slip simply because nobody was reminded.\n\n"
                    . "We build software that tracks projects, effort and billing together, so you can see which clients actually make you money and what is due to be invoiced.\n\n"
                    . "Worth a 20-minute walkthrough?",
            ],
            [
                'name'         => 'gamavis_pharma_no_software',
                'display_name' => 'Pharma & Healthcare — running without software',
                'body' => "Hi {{1}} 👋\n\n"
                    . "In pharma and healthcare distribution, batch and expiry are the whole game — and they are the hardest things to keep straight in Excel.\n\n"
                    . "Near-expiry stock gets noticed too late, returns are reconciled by hand, and pulling records for a compliance check means digging through files.\n\n"
                    . "We build software that tracks batch, expiry and returns properly, and can produce the record you need on demand.\n\n"
                    . "Shall I show you how it would map to your current process?",
            ],
            [
                'name'         => 'gamavis_logistics_no_software',
                'display_name' => 'Logistics & Transport — running without software',
                'body' => "Hi {{1}} 👋\n\n"
                    . "In transport, the two questions that cost you the most are \"where is my consignment?\" and \"where is the POD?\"\n\n"
                    . "Trip sheets are on paper, status updates depend on calling the driver, and billing waits on a document that is somewhere in a bag.\n\n"
                    . "We build software that tracks trips, consignment status and POD collection, so your team answers the client without making a call first.\n\n"
                    . "Worth 20 minutes to see it on your routes?",
            ],
            [
                'name'         => 'gamavis_associations_no_software',
                'display_name' => 'RWAs, Associations & NGOs — running without software',
                'body' => "Hi {{1}} 👋\n\n"
                    . "For an association or RWA, most of the work is collections and communication — and both are usually done by hand.\n\n"
                    . "Dues are chased member by member on WhatsApp, receipts are written out, notices reach whoever happens to be in the group, and complaints are tracked in a register.\n\n"
                    . "We build software that tracks member dues and receipts, sends notices and reminders automatically, and keeps complaints to closure.\n\n"
                    . "Shall I show you how it would work for your members?",
            ],
            [
                'name'         => 'gamavis_general_no_software',
                'display_name' => 'General business — running without software',
                'body' => "Hi {{1}} 👋\n\n"
                    . "Most businesses we meet run well on diaries, WhatsApp groups and Excel — right up to the point where one person has to remember everything.\n\n"
                    . "Then order status depends on a phone call, stock is whatever was last counted, and follow-ups are lost the week someone is on leave.\n\n"
                    . "We build custom software around how you already work, so the business runs on a system rather than on memory.\n\n"
                    . "Worth a 20-minute look at what we would build for you?",
            ],
            [
                'name'         => 'gamavis_followup_nudge',
                'display_name' => 'Follow-up nudge — no reply to opener',
                'body' => "Hi {{1}}, following up on my last message.\n\n"
                    . "If Excel and WhatsApp are working fine for you, genuinely no problem — reply STOP and I won't message again.\n\n"
                    . "But if order status, stock or follow-ups are eating your team's time, I'm happy to show you what we would build. Twenty minutes, your own process, no slides.\n\n"
                    . "Shall I send a couple of slots?",
            ],
        ];
    }
}
