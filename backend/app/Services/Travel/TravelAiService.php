<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\SupplierRateModel;
use App\Models\SupplierModel;
use App\Services\AI\AiReplyService;

/**
 * Travel AI:
 *  - parseEnquiry(): free text (English/Hinglish WhatsApp message) -> structured trip fields.
 *  - draftItinerary(): day-wise plan grounded in the agency's OWN supplier rate cards.
 *
 * Guardrails: the model never supplies prices. It may only reference a rate by
 * rate_id from the list we hand it; costs are then read from supplier_rates. When
 * the model is unavailable (no key / mock mode / bad JSON) deterministic fallbacks
 * keep the feature working offline.
 */
final class TravelAiService
{
    private const INTL = ['bali', 'dubai', 'thailand', 'phuket', 'bangkok', 'singapore', 'maldives', 'europe', 'paris', 'switzerland',
        'london', 'turkey', 'vietnam', 'malaysia', 'sri lanka', 'nepal', 'bhutan', 'mauritius', 'japan', 'australia', 'new zealand', 'egypt', 'usa', 'hong kong', 'azerbaijan', 'georgia', 'kenya', 'seychelles'];
    private const DOMESTIC = ['goa', 'kerala', 'kashmir', 'manali', 'shimla', 'rajasthan', 'jaipur', 'udaipur', 'ladakh', 'leh', 'andaman', 'sikkim', 'darjeeling',
        'ooty', 'munnar', 'coorg', 'varanasi', 'kedarnath', 'char dham', 'uttarakhand', 'rishikesh', 'himachal', 'kodaikanal', 'hampi', 'mysore', 'gujarat', 'meghalaya', 'spiti'];
    private const TYPES = ['honeymoon' => 'honeymoon', 'family' => 'family', 'friends' => 'friends', 'solo' => 'solo', 'pilgrim' => 'pilgrimage',
        'yatra' => 'pilgrimage', 'dham' => 'pilgrimage', 'corporate' => 'corporate', 'adventure' => 'adventure', 'trek' => 'adventure', 'umrah' => 'umrah_hajj', 'hajj' => 'umrah_hajj', 'group' => 'group_departure'];
    private const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

    public function __construct(private readonly ?int $tenantId = null, private readonly ?AiReplyService $ai = null) {}

    private function ai(): AiReplyService
    {
        return $this->ai ?? new AiReplyService($this->tenantId);
    }

    // ---- enquiry parsing ---------------------------------------------------

    /** @return array<string,mixed> trip fields (subset of the trips table) + _source: ai|heuristic */
    public function parseEnquiry(string $text): array
    {
        $heur = self::heuristicParse($text);
        $res  = $this->ai()->generate(
            'You extract structured travel enquiry details for an Indian travel agency from a customer message '
            . '(English, Hindi or Hinglish). Reply with ONE JSON object only, no prose. Keys (omit unknown): '
            . 'destination_text, is_international (bool), origin_city, travel_month, nights (int), adults (int), children (int), '
            . 'infants (int), child_ages (string), budget_max_inr (int, total for the whole group in rupees), trip_type '
            . '(leisure|honeymoon|family|friends|solo|pilgrimage|adventure|corporate|group_departure|umrah_hajj|other), '
            . 'hotel_category (budget|3_star|4_star|5_star|luxury), interests (string array), requirements (string). '
            . 'Never guess a value that the message does not support.',
            [['role' => 'user', 'content' => $text]], 500, false
        );
        $parsed = $res['success'] ? self::extractJson($res['text']) : null;
        if (! $parsed) {
            return $heur + ['_source' => 'heuristic'];
        }
        if (isset($parsed['budget_max_inr'])) {
            $parsed['budget_max']   = (int) $parsed['budget_max_inr'] * 100;
            $parsed['budget_basis'] = 'total';
            unset($parsed['budget_max_inr']);
        }
        // Model output fills gaps; the deterministic parse wins on numbers it found verbatim.
        return array_merge($parsed, array_intersect_key($heur, array_flip(['adults', 'children', 'nights']))) + ['_source' => 'ai'];
    }

    /** Offline parser: covers the common Indian enquiry phrasings. Public for tests. */
    public static function heuristicParse(string $text): array
    {
        $t   = mb_strtolower($text);
        $out = [];
        foreach ([...self::INTL, ...self::DOMESTIC] as $place) {
            if (preg_match('/\b' . preg_quote($place, '/') . '\b/u', $t)) {
                $out['destination_text'] = ucwords($place);
                $out['is_international'] = in_array($place, self::INTL, true) ? 1 : 0;
                break;
            }
        }
        if (preg_match('/(\d+)\s*(?:adults?|pax|persons?|people|ppl)/', $t, $m)) {
            $out['adults'] = (int) $m[1];
        } elseif (preg_match('/\b(?:couple|honeymoon)\b/', $t)) {
            $out['adults'] = 2;
        }
        if (preg_match('/(\d+)\s*(?:kids?|child(?:ren)?|bachch\w*)/', $t, $m)) {
            $out['children'] = (int) $m[1];
        }
        if (preg_match('/(\d+)\s*(?:nights?|raat)/', $t, $m)) {
            $out['nights'] = (int) $m[1];
        } elseif (preg_match('/(\d+)\s*(?:days?|din)/', $t, $m)) {
            $out['nights'] = max(1, (int) $m[1] - 1);
        }
        // Budget: "5 lakh", "₹80,000", "rs 1.5L", "2 lac"
        if (preg_match('/(?:₹|rs\.?|inr)?\s*(\d+(?:\.\d+)?)\s*(?:lakhs?|lacs?|l\b)/u', $t, $m)) {
            $out['budget_max'] = (int) round((float) $m[1] * 100_000 * 100);
            $out['budget_basis'] = 'total';
        } elseif (preg_match('/(?:₹|rs\.?|inr|budget)\s*:?\s*(\d[\d,]{3,})/u', $t, $m)) {
            $out['budget_max'] = (int) str_replace(',', '', $m[1]) * 100;
            $out['budget_basis'] = 'total';
        }
        foreach (self::MONTHS as $mo) {
            if (preg_match('/\b' . $mo . '[a-z]*\b/', $t)) {
                $out['travel_month'] = ucfirst($mo);
                break;
            }
        }
        foreach (self::TYPES as $needle => $type) {
            if (str_contains($t, $needle)) {
                $out['trip_type'] = $type;
                break;
            }
        }
        return $out;
    }

    // ---- itinerary drafting -------------------------------------------------

    /**
     * Draft an itinerary structure for a trip. Returns the plan only (no DB writes):
     * {title, days:[{day_no,title,city,description,items:[{type,title,details,rate_id?,nights?}]}], inclusions, exclusions}
     *
     * @param array $trip   trips row
     * @param array $rates  candidate supplier_rates rows (id, supplier name, service_name, service_type, unit, cost_amount)
     */
    public function draftItinerary(array $trip, array $rates): array
    {
        $nights = max(1, (int) ($trip['nights'] ?: 4));
        $list   = array_map(static fn (array $r): string => sprintf('#%d %s | %s | %s | %s', $r['id'], $r['service_type'], $r['supplier_name'] ?? '', $r['service_name'], $r['unit']), array_slice($rates, 0, 60));
        $brief  = sprintf(
            "Destination: %s\nType: %s\nNights: %d\nTravellers: %d adults, %d children\nHotel category: %s\nInterests: %s\nNotes: %s\n\nAvailable rate cards (reference by id only):\n%s",
            $trip['destination_text'] ?: 'unspecified', $trip['trip_type'], $nights, $trip['adults'], $trip['children'], $trip['hotel_category'],
            is_string($trip['interests'] ?? null) ? $trip['interests'] : json_encode($trip['interests'] ?? []), $trip['requirements'] ?: '-', $list ? implode("\n", $list) : '(none)'
        );
        $res = $this->ai()->withTimeout(60_000)->generate(
            'You are a senior Indian holiday designer. Create a day-wise itinerary as ONE JSON object only: '
            . '{"title":str,"days":[{"day_no":int,"title":str,"city":str,"description":str(2-3 sentences),'
            . '"items":[{"type":"hotel|transfer|sightseeing|activity|meal|flight|visa","title":str,"details":str,"rate_id":int|null,"nights":int}]}],'
            . '"inclusions":str,"exclusions":str}. Rules: use rate_id ONLY from the provided list and only when it genuinely fits; '
            . 'otherwise null. NEVER state prices. Pace sensibly (no more than 2 major activities a day), include arrival and departure days, '
            . 'and match the traveller type (honeymoon = romantic, family = kid-friendly).',
            [['role' => 'user', 'content' => $brief]], 3500, false
        );
        $plan = $res['success'] ? self::extractJson($res['text']) : null;
        if (! $plan || empty($plan['days']) || ! is_array($plan['days'])) {
            return self::fallbackPlan($trip, $nights) + ['_source' => 'template'];
        }
        // Drop rate ids the model invented.
        $valid = array_column($rates, 'id');
        foreach ($plan['days'] as &$d) {
            foreach ($d['items'] ?? [] as &$it) {
                if (isset($it['rate_id']) && ! in_array((int) $it['rate_id'], array_map('intval', $valid), true)) {
                    $it['rate_id'] = null;
                }
            }
            unset($it);
        }
        unset($d);
        return $plan + ['_source' => 'ai'];
    }

    /** Deterministic skeleton so the feature degrades gracefully. */
    public static function fallbackPlan(array $trip, int $nights): array
    {
        $dest = $trip['destination_text'] ?: 'your destination';
        $days = [['day_no' => 1, 'title' => "Arrival in {$dest}", 'city' => $dest, 'description' => 'Arrive, meet our representative and transfer to the hotel. Rest of the day at leisure.',
            'items' => [['type' => 'transfer', 'title' => 'Airport pickup and hotel transfer', 'details' => '', 'rate_id' => null], ['type' => 'hotel', 'title' => "Hotel stay in {$dest}", 'details' => '', 'rate_id' => null, 'nights' => $nights]]]];
        for ($i = 2; $i <= $nights; $i++) {
            $days[] = ['day_no' => $i, 'title' => "Explore {$dest}", 'city' => $dest, 'description' => 'Guided sightseeing of the main attractions with time for shopping and local food.',
                'items' => [['type' => 'sightseeing', 'title' => "{$dest} sightseeing", 'details' => '', 'rate_id' => null]]];
        }
        $days[] = ['day_no' => $nights + 1, 'title' => 'Departure', 'city' => $dest, 'description' => 'Breakfast, check-out and transfer to the airport.',
            'items' => [['type' => 'transfer', 'title' => 'Hotel to airport transfer', 'details' => '', 'rate_id' => null]]];
        return ['title' => "{$nights}N/" . ($nights + 1) . "D {$dest}", 'days' => $days,
            'inclusions' => "Accommodation\nDaily breakfast\nAirport transfers\nSightseeing as per itinerary", 'exclusions' => "Airfare\nPersonal expenses\nAnything not mentioned in inclusions"];
    }

    /** Rate cards for a destination, with the supplier name attached. */
    public function candidateRates(int $tenantId, ?int $destinationId): array
    {
        $m = (new SupplierRateModel())->setTenant($tenantId);
        if ($destinationId) {
            $m->groupStart()->where('destination_id', $destinationId)->orWhere('destination_id', null)->groupEnd();
        }
        $rates = $m->orderBy('id', 'DESC')->findAll(200);
        $names = [];
        foreach ((new SupplierModel())->setTenant($tenantId)->whereIn('id', array_unique(array_column($rates, 'supplier_id')) ?: [0])->findAll() as $s) {
            $names[$s['id']] = $s['name'];
        }
        foreach ($rates as &$r) { $r['supplier_name'] = $names[$r['supplier_id']] ?? ''; }
        return $rates;
    }

    public static function extractJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
    }
}
