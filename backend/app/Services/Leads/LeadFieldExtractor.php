<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Services\Travel\TravelAiService;

/**
 * Pure: turns whatever a travel portal sends (a JSON/form webhook body, or the text/HTML of a notification email)
 * into one canonical lead: name, phone, email, destination, origin, travel_date, nights, adults, children, budget, message.
 * Field names differ per portal ("Mobile No", "contact_number", "Customer Name"), so keys are normalised and matched against an
 * alias table; a source can add its own aliases. Nothing here touches the database or the clock beyond date parsing.
 */
final class LeadFieldExtractor
{
    public const ALIASES = [
        'name'        => ['name', 'full name', 'fullname', 'customer name', 'traveller name', 'traveler name', 'lead name', 'contact name', 'client name', 'user name', 'guest name', 'first name'],
        'phone'       => ['phone', 'mobile', 'mobile number', 'mobile no', 'mobile no.', 'phone number', 'phone no', 'contact number', 'contact no', 'contact', 'whatsapp', 'whatsapp number', 'cell', 'telephone', 'tel', 'customer mobile'],
        'email'       => ['email', 'e mail', 'email id', 'email address', 'mail', 'customer email'],
        'destination' => ['destination', 'destinations', 'going to', 'travel to', 'package', 'package name', 'tour', 'tour name', 'interested in', 'trip to', 'place', 'location', 'enquiry for', 'inquiry for', 'looking for'],
        'origin'      => ['from', 'departure city', 'travelling from', 'traveling from', 'origin', 'departing from', 'city', 'your city', 'from city', 'source city'],
        'travel_date' => ['travel date', 'date of travel', 'departure date', 'start date', 'travelling on', 'traveling on', 'journey date', 'check in', 'checkin', 'travel on', 'date', 'preferred date', 'when'],
        'nights'      => ['nights', 'no of nights', 'number of nights', 'duration', 'days', 'no of days', 'number of days'],
        'adults'      => ['adults', 'adult', 'no of adults', 'number of adults', 'pax', 'travellers', 'travelers', 'number of travellers', 'persons', 'guests', 'people', 'no of pax', 'total pax'],
        'children'    => ['children', 'child', 'kids', 'no of children', 'number of children'],
        'budget'      => ['budget', 'approx budget', 'total budget', 'budget per person', 'estimated budget'],
        'message'     => ['message', 'comments', 'comment', 'requirements', 'requirement', 'query', 'enquiry', 'inquiry', 'special requests', 'notes', 'remarks', 'description', 'additional information', 'details'],
    ];

    public static function key(string $k): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($k))) ?? '');
    }

    /** @return array<string,string> normalised alias => canonical field (custom aliases win over the built-in table) */
    private static function table(array $custom): array
    {
        $t = [];
        foreach (self::ALIASES as $field => $aliases) { foreach ($aliases as $a) { $t[self::key($a)] = $field; } }
        foreach ($custom as $alias => $field) { if (isset(self::ALIASES[$field])) { $t[self::key((string) $alias)] = $field; } }
        return $t;
    }

    /**
     * @param array<string,mixed> $payload nested arrays are flattened (a.b => value)
     * @param array<string,string> $custom extra alias => canonical overrides for this source
     * @return array{fields:array<string,string>,extras:array<string,string>}
     */
    public static function fromPayload(array $payload, array $custom = []): array
    {
        // Match on the LEAF key ("lead": {"full_name": ...} -> full_name); the first non-empty value for a key wins.
        $flat = [];
        $walk = static function ($v, string $key) use (&$walk, &$flat): void {
            if (is_array($v)) { foreach ($v as $k => $x) { $walk($x, is_int($k) ? $key : (string) $k); } return; }
            if (is_scalar($v) && trim((string) $v) !== '' && $key !== '' && ! isset($flat[$key])) { $flat[$key] = trim((string) $v); }
        };
        $walk($payload, '');
        return self::map($flat, $custom);
    }

    /** @return array{fields:array<string,string>,extras:array<string,string>} */
    public static function fromEmail(string $subject, string $body, array $custom = []): array
    {
        $text = self::emailToText($body);
        $pairs = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z][A-Za-z0-9 \/_.\-()]{1,40}?)\s*(?::|\s[-–—]\s)\s*(.{1,500}?)\s*$/u', $line, $m) && ! isset($pairs[$m[1]])) { $pairs[trim($m[1])] = trim($m[2]); }
        }
        $r = self::map($pairs, $custom);
        // Portals that send prose, or put the number outside a labelled line: fall back to pattern search.
        if (empty($r['fields']['phone']) && preg_match('/(?<![\d])((?:\+?91[\s-]?)?[6-9]\d{4}[\s-]?\d{5})(?![\d])/', $text, $m)) { $r['fields']['phone'] = $m[1]; }
        if (empty($r['fields']['email'])) {
            foreach ((preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $mm) ? $mm[0] : []) as $e) {
                if (! preg_match('/^(noreply|no-reply|donotreply|do-not-reply|mailer|notifications?|support|info)@/i', $e)) { $r['fields']['email'] = $e; break; }
            }
        }
        if (empty($r['fields']['destination']) && preg_match('/(?:enquiry|inquiry|lead|query)\s+(?:for|about|regarding)\s+(.{3,80}?)(?:[.\-–|]|$)/iu', $subject, $m)) { $r['fields']['destination'] = trim($m[1]); }
        return $r;
    }

    private static function emailToText(string $body): string
    {
        $t = preg_replace('~</t[dh]>\s*<t[dh][^>]*>~i', ': ', $body) ?? $body;     // table row  "<td>Name</td><td>Asha</td>" -> "Name: Asha"
        $t = preg_replace('~<(?:br\s*/?|/p|/tr|/div|/li|/h\d)[^>]*>~i', "\n", $t) ?? $t;
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return str_replace("\xC2\xA0", ' ', $t);
    }

    /** @param array<string,string> $pairs raw key => value */
    private static function map(array $pairs, array $custom): array
    {
        $t = self::table($custom); $fields = []; $extras = [];
        foreach ($pairs as $k => $v) {
            $field = $t[self::key((string) $k)] ?? null;
            if ($field !== null && ! isset($fields[$field])) { $fields[$field] = mb_substr($v, 0, 1000); }
            elseif ($field === null && count($extras) < 20) { $extras[mb_substr((string) $k, 0, 60)] = mb_substr($v, 0, 300); }
        }
        return ['fields' => $fields, 'extras' => $extras];
    }

    // ---- value normalisers ------------------------------------------------------------------------------------------

    /** Indian-first: "98765 43210", "+91-9876543210", "09876543210", "919876543210" -> +919876543210. '' when it is not a plausible number. */
    public static function phone(string $raw): string
    {
        $raw = trim($raw);
        $plus = str_starts_with($raw, '+');
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        if ($d === '') { return ''; }
        if (! $plus && str_starts_with($d, '00') && strlen($d) > 4) { $plus = true; $d = substr($d, 2); }     // 0044… = +44…
        if (! $plus) {
            $d = ltrim($d, '0');
            if (strlen($d) === 10 && $d[0] >= '6') { return '+91' . $d; }
            if (strlen($d) === 12 && str_starts_with($d, '91') && $d[2] >= '6') { return '+' . $d; }
            return '';                                  // without a + only Indian mobiles are accepted; anything else could be a typo
        }
        return strlen($d) >= 8 && strlen($d) <= 15 ? '+' . $d : '';
    }

    /** "12/01/2027" is 12 January in India (day first); also "12 Jan 2027", "2027-01-12". null when it cannot be read or is in the past. */
    public static function date(string $raw, string $today): ?string
    {
        $raw = trim($raw);
        if ($raw === '') { return null; }
        if (preg_match('~^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})$~', $raw, $m)) {
            $y = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];
            if (! checkdate((int) $m[2], (int) $m[1], $y)) { return null; }
            $ymd = sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]);
        } else {
            $ts = strtotime($raw);
            if ($ts === false) { return null; }
            $ymd = date('Y-m-d', $ts);
        }
        return $ymd >= $today ? $ymd : null;
    }

    /** "₹1,50,000", "1.5 lakh", "80k", "2 lac per person" -> [paise, basis]. null when not understood. */
    public static function budget(string $raw): ?array
    {
        $t = mb_strtolower($raw);
        if (! preg_match('/(\d[\d,]*(?:\.\d+)?)\s*(lakhs?|lacs?|l\b|k\b|crores?|cr\b)?/u', $t, $m)) { return null; }
        $n = (float) str_replace(',', '', $m[1]);
        $mult = match (true) { str_starts_with($m[2] ?? '', 'l') => 100_000, ($m[2] ?? '') === 'k' => 1_000, str_starts_with($m[2] ?? '', 'c') => 10_000_000, default => 1 };
        $paise = (int) round($n * $mult * 100);
        if ($paise < 100_000 || $paise > 100_000_000_000) { return null; }          // below ₹1,000 or above ₹100 Cr is noise
        return ['paise' => $paise, 'basis' => preg_match('/per\s*(?:person|head|pax)|pp\b|\/\s*person/u', $t) ? 'per_person' : 'total'];
    }

    public static function count(string $raw, int $min, int $max): ?int
    {
        if (! preg_match('/\d+/', $raw, $m)) { return null; }
        $n = (int) $m[0];
        return $n >= $min && $n <= $max ? $n : null;
    }

    /** One canonical lead. @return array{name:?string,phone:string,email:?string,trip:array,message:string,extras:array,issues:string[]} */
    public static function normalise(array $parsed, string $today): array
    {
        $f = $parsed['fields']; $issues = []; $trip = [];
        $phone = isset($f['phone']) ? self::phone($f['phone']) : '';
        if (isset($f['phone']) && $phone === '') { $issues[] = 'phone number looks invalid'; }
        $email = isset($f['email']) && filter_var(trim($f['email']), FILTER_VALIDATE_EMAIL) ? strtolower(trim($f['email'])) : null;
        if (isset($f['email']) && $email === null) { $issues[] = 'email looks invalid'; }
        $message = trim((string) ($f['message'] ?? ''));
        // Free text fills the gaps (destination, pax, budget, month…) using the same parser WhatsApp enquiries use.
        $heur = TravelAiService::heuristicParse(trim(($f['destination'] ?? '') . ' ' . $message));
        if (! empty($f['destination'])) { $trip['destination_text'] = mb_substr(trim($f['destination']), 0, 200); }
        $trip = array_merge($heur, $trip);
        if (isset($f['origin'])) { $trip['origin_city'] = mb_substr(trim($f['origin']), 0, 100); }
        if (isset($f['travel_date']) && ($d = self::date($f['travel_date'], $today)) !== null) { $trip['start_date'] = $d; }
        elseif (isset($f['travel_date'])) { $issues[] = 'travel date not understood or already past'; }
        if (isset($f['nights']) && ($n = self::count($f['nights'], 1, 60)) !== null) {
            $isDays = (bool) preg_match('/day/i', $f['nights']) && ! preg_match('/night/i', $f['nights']);   // "7 days" = 6 nights; a bare number is taken as nights
            $trip['nights'] = $isDays ? max(1, $n - 1) : $n;
        }
        if (isset($f['adults']) && ($n = self::count($f['adults'], 1, 60)) !== null) { $trip['adults'] = $n; }
        if (isset($f['children']) && ($n = self::count($f['children'], 0, 30)) !== null) { $trip['children'] = $n; }
        if (isset($f['budget']) && ($b = self::budget($f['budget'])) !== null) { $trip['budget_max'] = $b['paise']; $trip['budget_basis'] = $b['basis']; }
        $name = isset($f['name']) ? trim(mb_substr(preg_replace('/[<>]/', '', $f['name']) ?? '', 0, 120)) : '';
        return ['name' => $name !== '' ? $name : null, 'phone' => $phone, 'email' => $email, 'trip' => $trip, 'message' => mb_substr($message, 0, 1000), 'extras' => $parsed['extras'], 'issues' => $issues];
    }
}
