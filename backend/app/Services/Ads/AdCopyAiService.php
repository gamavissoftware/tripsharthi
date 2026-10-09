<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Services\AI\AiReplyService;
use App\Services\Travel\TravelAiService;

/**
 * Ad copy + keyword suggestions. Guardrails (all deterministic, applied to AI output AND to the fallback):
 *  - every line passes AdCopyLint and the platform's character limits, or it is dropped;
 *  - a ₹ price may appear only if it equals the price the agent supplied — the model cannot invent offers;
 *  - the result is always a SUGGESTION for a human to review; nothing here creates or publishes anything.
 */
final class AdCopyAiService
{
    public function __construct(private readonly ?int $tenantId = null, private readonly ?AiReplyService $ai = null) {}

    /**
     * @param array{destination:string,trip_type?:string,price_from?:int,duration?:string,usp?:string,season?:string} $brief price_from in rupees
     * @return array<string,mixed>
     */
    public function suggest(string $platform, array $brief): array
    {
        $dest = trim((string) ($brief['destination'] ?? ''));
        if ($dest === '') { throw new \InvalidArgumentException('Tell us the destination first.'); }

        $ai  = $this->ai ?? new AiReplyService($this->tenantId);
        $res = $ai->withTimeout(45_000)->generate($this->system($platform), [['role' => 'user', 'content' => $this->brief($brief)]], 1800, false);
        $raw = $res['success'] ? TravelAiService::extractJson($res['text']) : null;

        $source = $raw ? 'ai' : 'template';
        $raw ??= self::fallback($platform, $brief);
        return self::clean($platform, $raw, $brief) + ['_source' => $source];
    }

    private function system(string $platform): string
    {
        $common = 'You write ads for an Indian travel agency. Reply with ONE JSON object only. Rules: be specific and honest; no superlatives or guarantees '
            . '("best", "cheapest", "guaranteed", "100%"); never state a price unless the brief gives one and then use exactly that figure; no ALL-CAPS words; no repeated punctuation. ';
        return $platform === 'google'
            ? $common . 'Schema: {"headlines":[10 strings, each <= 30 characters, no "!"],"descriptions":[4 strings, each <= 90 characters],'
                . '"keywords":[{"text":str,"match":"PHRASE"|"EXACT"|"BROAD"} x 15, real search phrases people type],"negatives":[10 single words/phrases to exclude, e.g. jobs, free, visa-only]}'
            : $common . 'Schema: {"primary_texts":[3 strings, each <= 125 characters],"headlines":[3 strings, each <= 40 characters]}';
    }

    private function brief(array $b): string
    {
        $lines = ['Destination: ' . $b['destination']];
        foreach (['trip_type' => 'Trip type', 'duration' => 'Duration', 'season' => 'Season', 'usp' => 'What makes us different'] as $k => $l) {
            if (! empty($b[$k])) { $lines[] = "{$l}: {$b[$k]}"; }
        }
        $lines[] = ! empty($b['price_from']) ? 'Price from (per person): ₹' . number_format((int) $b['price_from']) : 'No price given — do not mention any price.';
        return implode("\n", $lines);
    }

    /** Deterministic copy so the feature works with no AI key. */
    public static function fallback(string $platform, array $b): array
    {
        $d = trim((string) $b['destination']);
        $type = ! empty($b['trip_type']) && $b['trip_type'] !== 'leisure' ? ucfirst(str_replace('_', ' ', (string) $b['trip_type'])) . ' ' : '';
        $price = ! empty($b['price_from']) ? ' from ₹' . number_format((int) $b['price_from']) . ' per person' : '';
        if ($platform === 'google') {
            return [
                'headlines' => ["{$d} {$type}Packages", "Custom {$d} Itinerary", 'Free Quote in 1 Hour', "Plan Your {$d} Trip", "{$d} Holiday Experts", 'Flexible Payment Options', "{$d} Tour with Hotels", 'Talk to a Travel Expert'],
                'descriptions' => ["Custom {$d} itinerary with hotels, transfers and sightseeing{$price}. Get a free quote.", 'Chat with a travel expert on WhatsApp. Honest pricing, flexible payments and 24x7 trip support.',
                    "Tell us your dates and budget and get a day-wise {$d} plan within an hour.", 'Hand-picked stays and trusted local partners. Instalment payment options available.',
                    'Get a day-wise plan within an hour. Chat with a travel expert on WhatsApp today.', 'Tell us your dates and budget. We plan the rest, with 24x7 support during your trip.'],
                'keywords' => array_map(static fn ($t) => ['text' => $t, 'match' => 'PHRASE'], ["{$d} tour packages", "{$d} package", strtolower($type) . "{$d} package", "{$d} trip from india", "{$d} holiday packages", "book {$d} trip"]),
                'negatives' => ['jobs', 'free', 'visa only', 'flight only', 'hotel only', 'wikipedia', 'images'],
            ];
        }
        return [
            'primary_texts' => ["Planning a {$type}trip to {$d}? Share your dates and budget and get a custom day-wise plan{$price} within an hour.",
                "{$d} made simple: hotels, transfers and sightseeing in one custom quote. Message us on WhatsApp.",
                "Not sure where to start with {$d}? Our travel experts will plan it around you. Flexible payment options."],
            'headlines' => ["{$d} {$type}Packages", "Custom {$d} Itinerary", 'Get a Free Quote'],
        ];
    }

    /** Lint, length-limit, price-check, de-duplicate. @return array<string,mixed> */
    public static function clean(string $platform, array $raw, array $brief): array
    {
        $allowed = ! empty($brief['price_from']) ? (int) $brief['price_from'] : null;
        $dropped = [];
        $keep = function (array $list, int $max, string $field) use ($platform, $allowed, &$dropped): array {
            $out = [];
            foreach ($list as $t) {
                $t = trim(preg_replace('/\s+/', ' ', (string) $t));
                if ($t === '') { continue; }
                $lint = AdCopyLint::check($t, $field, $platform, $max);
                if ($lint['errors'] || self::badPrice($t, $allowed)) { $dropped[] = $t; continue; }
                if (! in_array(mb_strtolower($t), array_map('mb_strtolower', $out), true)) { $out[] = $t; }
            }
            return $out;
        };

        if ($platform === 'google') {
            $kw = [];
            foreach ((array) ($raw['keywords'] ?? []) as $k) {
                $text = trim(mb_strtolower((string) ($k['text'] ?? $k)));
                $match = in_array($k['match'] ?? 'PHRASE', GooglePlanBuilder::MATCH_TYPES, true) ? ($k['match'] ?? 'PHRASE') : 'PHRASE';
                if ($text !== '' && mb_strlen($text) <= 80 && ! isset($kw[$text])) { $kw[$text] = ['text' => $text, 'match' => $match]; }
            }
            return ['headlines' => array_slice($keep((array) ($raw['headlines'] ?? []), 30, 'headline'), 0, 15), 'descriptions' => array_slice($keep((array) ($raw['descriptions'] ?? []), 90, 'description'), 0, 4),
                'keywords' => array_slice(array_values($kw), 0, 50), 'negatives' => array_slice(array_values(array_unique(array_filter(array_map('trim', (array) ($raw['negatives'] ?? []))))), 0, 30), 'dropped' => $dropped];
        }
        return ['primary_texts' => array_slice($keep((array) ($raw['primary_texts'] ?? []), 125, 'ad text'), 0, 5), 'headlines' => array_slice($keep((array) ($raw['headlines'] ?? []), 40, 'headline'), 0, 5), 'dropped' => $dropped];
    }

    /** True when the text states a rupee amount that is not the agent-supplied price. */
    public static function badPrice(string $text, ?int $allowed): bool
    {
        if (! preg_match_all('/(?:₹|rs\.?|inr)\s*([\d,]+)/iu', $text, $m)) { return false; }
        foreach ($m[1] as $n) {
            if ($allowed === null || (int) str_replace(',', '', $n) !== $allowed) { return true; }
        }
        return false;
    }
}
