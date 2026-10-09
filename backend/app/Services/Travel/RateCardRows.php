<?php

declare(strict_types=1);

namespace App\Services\Travel;

/**
 * PURE rules for turning messy rate-sheet rows (CSV columns, spreadsheet rows or AI-extracted JSON) into supplier_rates rows.
 * The central safety rule: a PRICE is never trusted from the AI. For rows that did not come from a real table cell, the amount must
 * appear in the source text (as printed, or as "12k" / "1.5 lakh") — a number the model computed or invented is reported as unverified.
 * Amounts leave here as integer MINOR units of the row's currency (INR paise, USD cents).
 */
final class RateCardRows
{
    public const TYPES = ['hotel', 'transfer', 'sightseeing', 'activity', 'flight', 'visa', 'insurance', 'meal', 'other'];
    public const UNITS = ['per_night', 'per_pax', 'per_vehicle', 'per_day', 'per_room', 'flat'];

    private const HEADER_ALIASES = [
        'service_name' => ['service', 'servicename', 'name', 'item', 'description', 'particulars', 'hotel', 'room', 'roomtype', 'package', 'product', 'vehicle', 'category', 'service name'],
        'service_type' => ['type', 'servicetype', 'service type'],
        'unit'         => ['unit', 'per', 'basis', 'pricingbasis', 'rate basis', 'ratebasis'],
        'amount'       => ['rate', 'price', 'cost', 'amount', 'tariff', 'netrate', 'net rate', 'net', 'rates', 'contract rate', 'contractrate', 'nett', 'nettrate'],
        'currency'     => ['currency', 'curr', 'ccy'],
        'valid_from'   => ['validfrom', 'valid from', 'from', 'start', 'startdate', 'start date', 'season start', 'fromdate', 'from date'],
        'valid_to'     => ['validto', 'valid to', 'to', 'end', 'enddate', 'end date', 'season end', 'todate', 'to date', 'validtill', 'valid till'],
        'notes'        => ['notes', 'remarks', 'remark', 'comment', 'comments', 'inclusions', 'season'],
    ];

    /** Map header cells to fields. @return array<int,string> column index => field */
    public static function columns(array $header): array
    {
        $map = []; $used = [];
        foreach ($header as $i => $h) {
            $key = preg_replace('/[^a-z ]/', '', strtolower(trim((string) $h))) ?? '';
            $flat = str_replace(' ', '', $key);
            foreach (self::HEADER_ALIASES as $field => $aliases) {
                if (isset($used[$field])) { continue; }
                foreach ($aliases as $a) {
                    if ($key === $a || $flat === str_replace(' ', '', $a)) { $map[$i] = $field; $used[$field] = true; continue 3; }
                }
            }
        }
        return $map;
    }

    /** A table is usable without AI when it has a name column and an amount column. */
    public static function tableUsable(array $map): bool { return in_array('service_name', $map, true) && in_array('amount', $map, true); }

    /** Find the header row within the first rows of a sheet. @return array{0:int,1:array}|null [header row index, column map] */
    public static function findHeader(array $rows): ?array
    {
        foreach (array_slice($rows, 0, 10, true) as $i => $r) {
            $m = self::columns(array_values($r));
            if (self::tableUsable($m)) { return [(int) $i, $m]; }
        }
        return null;
    }

    /** @return list<array<string,string>> raw rows (strings) from a 2-D table below the header */
    public static function fromTable(array $rows, int $headerAt, array $map): array
    {
        $out = [];
        foreach (array_slice($rows, $headerAt + 1, null, true) as $r) {
            $r = array_values($r);
            $row = [];
            foreach ($map as $i => $field) { $row[$field] = trim((string) ($r[$i] ?? '')); }
            if (($row['service_name'] ?? '') === '' && ($row['amount'] ?? '') === '') { continue; }
            $out[] = $row;
        }
        return $out;
    }

    /** "₹12,500", "Rs. 12500/-", "USD 1,250.50", "12k", "1.5 lakh" -> [major amount, currency|null]; null amount when unreadable. */
    public static function parseAmount(string $s): array
    {
        $s = trim($s);
        $cur = null;
        foreach (['₹' => 'INR', 'rs.' => 'INR', 'rs' => 'INR', 'inr' => 'INR', '$' => 'USD', 'usd' => 'USD', '€' => 'EUR', 'eur' => 'EUR', '£' => 'GBP', 'gbp' => 'GBP', 'aed' => 'AED'] as $sym => $c) {
            if (stripos($s, $sym) !== false) { $cur = $c; break; }
        }
        if (! preg_match('/(\d[\d,]*(?:\.\d+)?)\s*(k|lakhs?|lacs?|crores?|cr)?\b/i', $s, $m)) { return [null, $cur]; }
        $v = (float) str_replace(',', '', $m[1]);
        $mult = strtolower($m[2] ?? '');
        if ($mult === 'k') { $v *= 1_000; } elseif (str_starts_with($mult, 'lakh') || str_starts_with($mult, 'lac')) { $v *= 100_000; } elseif ($mult !== '') { $v *= 10_000_000; }
        return [$v, $cur];
    }

    /** Every number the source text states, as plain floats (commas stripped; "12k"/"1.5 lakh" expanded too). @return list<float> */
    public static function numbersIn(string $text): array
    {
        $out = [];
        if (preg_match_all('/(\d[\d,]*(?:\.\d+)?)\s*(k|lakhs?|lacs?)?\b/i', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $v = (float) str_replace(',', '', $x[1]); $out[] = $v;
                $mult = strtolower($x[2] ?? '');
                if ($mult === 'k') { $out[] = $v * 1_000; } elseif ($mult !== '') { $out[] = $v * 100_000; }
            }
        }
        return $out;
    }

    public static function appearsIn(float $amount, string $source): bool
    {
        foreach (self::numbersIn($source) as $n) { if (abs($n - $amount) < 0.005) { return true; } }
        return false;
    }

    public static function type(string $s, string $name = ''): string
    {
        $t = strtolower(trim($s));
        if (in_array($t, self::TYPES, true)) { return $t; }
        $hay = $t . ' ' . strtolower($name);
        foreach (['hotel' => ['hotel', 'resort', 'villa', 'stay', 'room', 'suite', 'homestay', 'houseboat', 'accommodation'], 'transfer' => ['transfer', 'cab', 'taxi', 'innova', 'tempo', 'coach', 'sedan', 'suv', 'car ', 'vehicle', 'bus'],
            'sightseeing' => ['sightseeing', 'city tour', 'excursion', 'tour'], 'activity' => ['activity', 'safari', 'cruise', 'rafting', 'show', 'ticket', 'entry', 'ride', 'parasailing', 'snorkel', 'dive'],
            'flight' => ['flight', 'airfare', 'airline'], 'visa' => ['visa'], 'insurance' => ['insurance'], 'meal' => ['meal', 'dinner', 'lunch', 'breakfast', 'buffet']] as $type => $words) {
            foreach ($words as $w) { if (str_contains($hay, $w)) { return $type; } }
        }
        return 'other';
    }

    public static function unit(string $s, string $type = 'other'): string
    {
        $u = strtolower(trim(str_replace(['_', '-', '/'], ' ', $s)));
        if (in_array(str_replace(' ', '_', $u), self::UNITS, true)) { return str_replace(' ', '_', $u); }
        foreach (['per_night' => ['night', 'pn'], 'per_pax' => ['pax', 'person', 'pp', 'head', 'adult', 'ticket'], 'per_vehicle' => ['vehicle', 'car', 'cab', 'coach', 'bus', 'trip'], 'per_day' => ['day'],
            'per_room' => ['room'], 'flat' => ['flat', 'group', 'lump', 'fixed', 'package']] as $unit => $words) {
            foreach ($words as $w) { if ($u === $w || str_contains($u, $w)) { return $unit; } }
        }
        return match ($type) { 'hotel' => 'per_night', 'transfer' => 'per_vehicle', 'sightseeing', 'activity', 'visa', 'insurance', 'flight', 'meal' => 'per_pax', default => 'flat' };
    }

    /** day-first dates; null when unreadable. */
    public static function date(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') { return null; }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) { return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $s : null; }
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})$/', $s, $m)) {
            $y = (int) $m[3]; if ($y < 100) { $y += 2000; }
            return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, (int) $m[2], (int) $m[1]) : null;
        }
        $t = strtotime($s);
        return $t !== false && $t > 0 ? date('Y-m-d', $t) : null;
    }

    /**
     * @param array  $raw     service_name, service_type, unit, amount (major units, string or number), currency, valid_from, valid_to, notes
     * @param string $source  full source text for the price check ('' = a real table cell: the price is what the sheet says)
     * @return array{row:array,errors:list<string>,warnings:list<string>}
     */
    public static function normalise(array $raw, string $defaultCurrency = 'INR', string $source = '', bool $verifyAmount = false): array
    {
        $e = []; $w = [];
        $name = trim(preg_replace('/\s+/', ' ', (string) ($raw['service_name'] ?? '')) ?? '');
        if ($name === '') { $e[] = 'Service name is missing.'; }
        if (mb_strlen($name) > 200) { $name = mb_substr($name, 0, 200); $w[] = 'Service name was shortened to 200 characters.'; }
        $type = self::type((string) ($raw['service_type'] ?? ''), $name);
        $unit = self::unit((string) ($raw['unit'] ?? ''), $type);

        $rawAmount = $raw['amount'] ?? null;
        [$major, $symCur] = is_numeric($rawAmount) ? [(float) $rawAmount, null] : self::parseAmount((string) $rawAmount);
        $cur = strtoupper(trim((string) ($raw['currency'] ?? ''))) ?: ($symCur ?? $defaultCurrency);
        if (! Currency::valid($cur)) { $e[] = "Currency {$cur} is not supported."; $cur = $defaultCurrency; }
        if ($major === null || $major <= 0) { $e[] = 'Price is missing or not a positive number.'; $major = 0.0; }
        elseif ($cur === 'INR' && $major > 100_000_000) { $e[] = 'Price is unrealistically large (over ₹10 crore) — check for a typo.'; }
        if ($verifyAmount && $major > 0 && $source !== '' && ! self::appearsIn($major, $source)) { $e[] = 'Price not found in your text — the AI may have calculated or guessed it. Check it, then correct or skip this row.'; }
        if ($major > 0 && $major !== floor($major) && Currency::exp($cur) === 0) { $w[] = "{$cur} has no decimals — the amount was rounded."; }

        $from = trim((string) ($raw['valid_from'] ?? '')) === '' ? null : self::date((string) $raw['valid_from']);
        $to   = trim((string) ($raw['valid_to'] ?? '')) === '' ? null : self::date((string) $raw['valid_to']);
        if (trim((string) ($raw['valid_from'] ?? '')) !== '' && $from === null) { $e[] = 'Valid-from date is not a readable date.'; }
        if (trim((string) ($raw['valid_to'] ?? '')) !== '' && $to === null) { $e[] = 'Valid-to date is not a readable date.'; }
        if ($from && $to && $to < $from) { $e[] = 'Valid-to is before valid-from.'; }
        if ($to && $to < date('Y-m-d')) { $w[] = 'This rate has already expired.'; }

        return ['row' => ['service_name' => $name, 'service_type' => $type, 'unit' => $unit, 'currency' => $cur, 'amount_major' => $major, 'cost_amount' => $major > 0 ? Currency::toMinor($major, $cur) : 0,
            'valid_from' => $from, 'valid_to' => $to, 'notes' => mb_substr(trim((string) ($raw['notes'] ?? '')), 0, 300)], 'errors' => $e, 'warnings' => $w];
    }

    /** Identity of a rate for matching against what is already stored. */
    public static function key(array $r): string
    {
        return mb_strtolower(trim((string) $r['service_name'])) . '|' . $r['unit'] . '|' . $r['currency'] . '|' . ($r['valid_from'] ?? '');
    }

    /**
     * Compare prepared rows with the supplier's existing rates.
     * @param list<array> $rows   normalised rows (with errors)
     * @param array<string,array> $existing key => existing rate row
     * @return list<string> a status per row: invalid | duplicate_in_file | new | same | change
     */
    public static function classify(array $rows, array $existing): array
    {
        $seen = []; $out = [];
        foreach ($rows as $r) {
            if (! empty($r['errors'])) { $out[] = 'invalid'; continue; }
            $k = self::key($r);
            if (isset($seen[$k])) { $out[] = 'duplicate_in_file'; continue; }
            $seen[$k] = true;
            $ex = $existing[$k] ?? null;
            $out[] = $ex === null ? 'new' : ((int) $ex['cost_amount'] === (int) $r['cost_amount'] && ($ex['valid_to'] ?? null) === ($r['valid_to'] ?? null) ? 'same' : 'change');
        }
        return $out;
    }

    /** A price move this large is more likely a typo than a repricing. */
    public static function bigChange(int $old, int $new): bool
    {
        return $old > 0 && abs($new - $old) / $old > 0.5;
    }

    /** Deterministic line reader used when no AI is available: "Deluxe Room - Rs 12,500 per night" -> one row per line that carries a price. */
    public static function heuristicLines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! preg_match('/\d/', $line)) { continue; }
            if (! preg_match('/^(.{3,}?)\s*(?:[-–—:|=@]|\s{2,}|\t)\s*((?:₹|rs\.?|inr|usd|\$|€|£|aed)?\s*\d[\d,]*(?:\.\d+)?\s*(?:k|lakhs?)?\s*(?:\/-)?)(.*)$/iu', $line, $m)) { continue; }
            $out[] = ['service_name' => trim($m[1], " \t-–—:|"), 'amount' => trim($m[2]), 'unit' => trim($m[3]), 'notes' => ''];
        }
        return $out;
    }
}
