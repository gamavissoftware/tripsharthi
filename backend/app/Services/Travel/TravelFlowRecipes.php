<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\FlowModel;
use App\Models\TemplateModel;

/**
 * Starter automations for travel agencies. Installing creates DRAFT templates (to submit to Meta) and DRAFT flows
 * (to activate once the templates are approved) — nothing is ever sent by installing.
 *
 * Every send follows the WhatsApp rules: window_check -> free-form when the 24h window is open, otherwise an
 * approved template. Template categories are chosen honestly: booking/payment/passport messages are UTILITY;
 * sales nudges are MARKETING (Meta reclassifies templates that don't match their category).
 */
final class TravelFlowRecipes
{
    /** @return array<string,array{name:string,summary:string,trigger:string,config:array,tpl:array,free:string,delay?:array}> */
    public static function all(): array
    {
        return [
            'quote_viewed_followup' => [
                'name' => 'Quote follow-up — viewed, not accepted', 'trigger' => 'quote_stale', 'config' => ['days' => 2, 'audience' => 'viewed'],
                'summary' => 'Two days after a quote was sent and opened but not accepted, ask if they want changes.',
                'tpl' => ['name' => 'tp_quote_viewed_followup', 'category' => 'marketing',
                    'body' => 'Hi {{1}}, thanks for looking at your {{2}} itinerary. Would you like us to adjust the hotels, dates or budget? Your quote: {{3}}',
                    'map' => ['1' => 'name', '2' => 'state:trip_destination', '3' => 'state:quote_link'], 'sample' => ['Rohit', 'Bali', 'https://example.com/q/abc']],
                'free' => "Hi {{contact.name}}, I saw you had a look at your {{trip.destination}} itinerary ({{quote.total}}). Want me to adjust the hotels, dates or budget? Your quote: {{quote.link}}",
            ],
            'quote_unopened_followup' => [
                'name' => 'Quote follow-up — not opened', 'trigger' => 'quote_stale', 'config' => ['days' => 3, 'audience' => 'unviewed'],
                'summary' => 'Three days after sending, if the quote link was never opened, check it reached them.',
                'tpl' => ['name' => 'tp_quote_unopened_followup', 'category' => 'marketing',
                    'body' => 'Hi {{1}}, just checking you received the {{2}} itinerary we shared. Here is the link again: {{3}}',
                    'map' => ['1' => 'name', '2' => 'state:trip_destination', '3' => 'state:quote_link'], 'sample' => ['Rohit', 'Bali', 'https://example.com/q/abc']],
                'free' => "Hi {{contact.name}}, just checking you received the {{trip.destination}} itinerary. Here it is again: {{quote.link}}",
            ],
            'booking_confirmed' => [
                'name' => 'Booking confirmed — welcome', 'trigger' => 'booking_confirmed', 'config' => [],
                'summary' => 'Confirms the booking right after it is created.',
                'tpl' => ['name' => 'tp_booking_confirmed', 'category' => 'utility',
                    'body' => 'Hi {{1}}, your {{2}} trip booking {{3}} is confirmed. Total: {{4}}. We will share the payment schedule and documents checklist shortly.',
                    'map' => ['1' => 'name', '2' => 'state:trip_destination', '3' => 'state:booking_ref', '4' => 'state:booking_total'], 'sample' => ['Rohit', 'Bali', 'TP-2026-0001', '₹51,729']],
                'free' => "Hi {{contact.name}}, your {{trip.destination}} trip is confirmed! Booking {{booking.ref}}, total {{booking.total}}. We'll share the payment schedule and documents checklist shortly.",
            ],
            'departure_checklist' => [
                'name' => 'Pre-departure checklist (7 days)', 'trigger' => 'departure_soon', 'config' => ['days_before' => 7],
                'summary' => 'A week before departure: documents, vouchers and a reminder of any balance due.',
                'tpl' => ['name' => 'tp_departure_checklist', 'category' => 'utility',
                    'body' => 'Hi {{1}}, your {{2}} trip starts in {{3}} days. Please keep your ID/passport, tickets and vouchers ready. Balance due on your booking: {{4}}. Reply here if you need anything.',
                    'map' => ['1' => 'name', '2' => 'state:trip_destination', '3' => 'state:booking_days_to_departure', '4' => 'state:booking_due'], 'sample' => ['Rohit', 'Bali', '7', '₹0']],
                'free' => "Hi {{contact.name}}, your {{trip.destination}} trip starts in {{booking.days_to_departure}} days! Please keep your ID/passport, tickets and vouchers ready. Balance due: {{booking.due}}. Reply here if you need anything.",
            ],
            'passport_expiring' => [
                'name' => 'Passport expiry alert (international)', 'trigger' => 'passport_expiring', 'config' => ['within_days' => 120, 'international' => 'yes'],
                'summary' => 'Warns when a traveller\'s passport is valid for under 6 months after the trip ends.',
                'tpl' => ['name' => 'tp_passport_expiring', 'category' => 'utility',
                    'body' => 'Hi {{1}}, the passport for {{2}} expires on {{3}}, which is less than 6 months after your {{4}} trip. Many countries require 6 months\' validity, so please renew it soon or talk to us.',
                    'map' => ['1' => 'name', '2' => 'state:traveler_name', '3' => 'state:traveler_passport_expiry', '4' => 'state:trip_destination'], 'sample' => ['Rohit', 'Priya', '12 Mar 2027', 'Bali']],
                'free' => "Hi {{contact.name}}, the passport for {{traveler.name}} expires on {{traveler.passport_expiry}}, less than 6 months after your {{trip.destination}} trip. Many countries need 6 months' validity — please renew it soon or talk to us.",
            ],
            'trip_started' => [
                'name' => 'Wishing a great trip (departure day)', 'trigger' => 'trip_started', 'config' => [],
                'summary' => 'Sends good wishes on the departure day with a reminder that support is a message away.',
                'tpl' => ['name' => 'tp_trip_started', 'category' => 'utility',
                    'body' => 'Hi {{1}}, wishing you a wonderful {{2}} trip! If you need any help on the way, message us here and we will assist.',
                    'map' => ['1' => 'name', '2' => 'state:trip_destination'], 'sample' => ['Rohit', 'Bali']],
                'free' => "Hi {{contact.name}}, wishing you a wonderful {{trip.destination}} trip! If you need any help on the way, message us here and we'll assist.",
            ],
            'trip_feedback' => [
                'name' => 'Post-trip feedback (1 day after)', 'trigger' => 'trip_completed', 'config' => [], 'delay' => ['value' => 1, 'unit' => 'days'],
                'summary' => 'A day after the trip ends, ask how it went — the start of reviews and repeat bookings.',
                'tpl' => ['name' => 'tp_trip_feedback', 'category' => 'utility',
                    'body' => 'Welcome back {{1}}! How was your {{2}} trip? Reply with a rating from 1 to 5 and any feedback — it helps us a lot.',
                    'map' => ['1' => 'name', '2' => 'state:trip_destination'], 'sample' => ['Rohit', 'Bali']],
                'free' => "Welcome back {{contact.name}}! How was your {{trip.destination}} trip? Reply with a rating from 1 to 5 and any feedback — it helps us a lot.",
            ],
        ];
    }

    /** Install (idempotent by name). @return array<string,array{template_id:int,flow_id:int,created:bool}> */
    public function install(int $tenantId, ?array $only = null): array
    {
        $out = [];
        foreach (self::all() as $key => $r) {
            if ($only !== null && ! in_array($key, $only, true)) { continue; }
            $tpl = (new TemplateModel())->setTenant($tenantId)->where('name', $r['tpl']['name'])->first();
            $tplId = $tpl ? (int) $tpl['id'] : (int) (new TemplateModel())->setTenant($tenantId)->insert([
                'name' => $r['tpl']['name'], 'display_name' => $r['name'], 'language' => 'en', 'category' => $r['tpl']['category'], 'header_type' => 'none',
                'body' => $r['tpl']['body'], 'variables' => json_encode($r['tpl']['sample'], JSON_UNESCAPED_UNICODE), 'meta_status' => 'draft',
            ], true);

            $flow = (new FlowModel())->setTenant($tenantId)->where('name', $r['name'])->first();
            if ($flow) { $out[$key] = ['template_id' => $tplId, 'flow_id' => (int) $flow['id'], 'created' => false]; continue; }
            $flowId = (int) (new FlowModel())->setTenant($tenantId)->insert([
                'name' => $r['name'], 'trigger_type' => $r['trigger'], 'trigger_config' => $r['config'] ? json_encode($r['config']) : null,
                'graph' => json_encode(self::graph($key, $r, $tplId), JSON_UNESCAPED_UNICODE), 'reentry_policy' => 'always', 'status' => 'draft',
            ], true);
            $out[$key] = ['template_id' => $tplId, 'flow_id' => $flowId, 'created' => true];
        }
        return $out;
    }

    /** What is installed, for the UI. */
    public function status(int $tenantId): array
    {
        $rows = [];
        foreach (self::all() as $key => $r) {
            $flow = (new FlowModel())->setTenant($tenantId)->where('name', $r['name'])->first();
            $tpl  = (new TemplateModel())->setTenant($tenantId)->where('name', $r['tpl']['name'])->first();
            $rows[] = ['key' => $key, 'name' => $r['name'], 'summary' => $r['summary'], 'trigger' => $r['trigger'], 'config' => $r['config'],
                'category' => $r['tpl']['category'], 'installed' => (bool) $flow, 'flow_id' => $flow['id'] ?? null, 'flow_status' => $flow['status'] ?? null,
                'template_status' => $tpl['meta_status'] ?? null];
        }
        return $rows;
    }

    /** trigger -> [delay] -> window_check; open -> free-form; closed -> approved template. */
    public static function graph(string $key, array $r, int $templateId): array
    {
        $nodes = [['id' => 't', 'type' => $r['trigger'], 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => $r['name']]]];
        $edges = [];
        $prev  = 't';
        $x     = 260;
        if (! empty($r['delay'])) {
            $nodes[] = ['id' => 'd', 'type' => 'delay', 'position' => ['x' => $x, 'y' => 0], 'data' => ['label' => 'Wait ' . $r['delay']['value'] . ' ' . $r['delay']['unit']] + $r['delay']];
            $edges[] = ['id' => 'e0', 'source' => $prev, 'target' => 'd', 'sourceHandle' => 'next'];
            $prev = 'd'; $x += 260;
        }
        $nodes[] = ['id' => 'w', 'type' => 'window_check', 'position' => ['x' => $x, 'y' => 0], 'data' => ['label' => '24h window open?']];
        $nodes[] = ['id' => 'f', 'type' => 'send_freeform', 'position' => ['x' => $x + 260, 'y' => -80], 'data' => ['label' => 'Message (window open)', 'content' => $r['free']]];
        $nodes[] = ['id' => 'm', 'type' => 'send_template', 'position' => ['x' => $x + 260, 'y' => 100], 'data' => [
            'label' => 'Template (window closed)', 'template_id' => $templateId, 'template_name' => $r['tpl']['name'],
            'variable_mapping' => json_encode($r['tpl']['map']), 'variable_defaults' => json_encode(new \stdClass()),
        ]];
        $edges[] = ['id' => 'e1', 'source' => $prev, 'target' => 'w', 'sourceHandle' => 'next'];
        $edges[] = ['id' => 'e2', 'source' => 'w', 'target' => 'f', 'sourceHandle' => 'open'];
        $edges[] = ['id' => 'e3', 'source' => 'w', 'target' => 'm', 'sourceHandle' => 'closed'];
        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
