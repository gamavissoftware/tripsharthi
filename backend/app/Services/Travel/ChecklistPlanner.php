<?php

declare(strict_types=1);

namespace App\Services\Travel;

/**
 * Pure: which documents a booking needs. International trips need a passport (valid 6 months past return), photo and visa per
 * traveller unless the traveller's visa_status is not_required; minors need a birth certificate; domestic trips need a photo ID.
 * Due dates are relative to departure so the overview can warn early. Rules are guidance, not legal advice — visa rules differ per country.
 */
final class ChecklistPlanner
{
    /**
     * @param array{is_international:bool|int,travel_start:?string} $booking
     * @param array<int,array{id:int,full_name:string,pax_type:string,visa_status?:string}> $travelers
     * @return array<int,array{traveler_id:int,item_key:string,category:string,label:string,required:int,due_date:?string}>
     */
    public static function plan(array $booking, array $travelers): array
    {
        $intl = (bool) $booking['is_international'];
        $start = $booking['travel_start'] ?: null;
        $due = static fn (int $days): ?string => $start ? date('Y-m-d', strtotime($start . " -{$days} days")) : null;
        $out = [];
        $add = static function (int $tid, string $key, string $cat, string $label, int $req, ?string $due) use (&$out): void {
            $out[] = ['traveler_id' => $tid, 'item_key' => $key, 'category' => $cat, 'label' => $label, 'required' => $req, 'due_date' => $due];
        };
        foreach ($travelers as $t) {
            $tid = (int) $t['id']; $n = $t['full_name']; $minor = in_array($t['pax_type'] ?? 'adult', ['child', 'infant'], true);
            if ($intl) {
                $add($tid, 'passport_copy', 'passport', "Passport copy — $n (valid 6+ months after return)", 1, $due(45));
                $add($tid, 'photo', 'passport', "Passport-size photo — $n", 1, $due(35));
                if (($t['visa_status'] ?? 'pending') !== 'not_required') { $add($tid, 'visa', 'visa', "Visa — $n", 1, $due(14)); }
                if ($minor) { $add($tid, 'birth_certificate', 'forms', "Birth certificate — $n", 1, $due(35)); }
            } else {
                $add($tid, 'id_proof', 'passport', "Government photo ID — $n", 1, $due(10));
            }
        }
        if ($intl) { $add(0, 'travel_insurance', 'insurance', 'Travel insurance policy', 0, $due(7)); }
        $add(0, 'traveller_details', 'forms', 'All traveller names match their ID/passport exactly', 1, $due(30));
        return $out;
    }

    /** @return array{done:int,total:int,percent:int,ready:bool} required items only; not_applicable counts as done */
    public static function progress(array $items): array
    {
        $req = array_values(array_filter($items, static fn ($i) => (int) $i['required'] === 1));
        $done = count(array_filter($req, static fn ($i) => in_array($i['status'], ['received', 'not_applicable'], true)));
        $total = count($req);
        return ['done' => $done, 'total' => $total, 'percent' => $total === 0 ? 100 : (int) floor($done * 100 / $total), 'ready' => $total > 0 && $done === $total];
    }
}
