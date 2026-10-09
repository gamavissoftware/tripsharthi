<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\ItineraryDayModel;
use App\Models\ItineraryItemModel;
use App\Models\ItineraryModel;

/** Recomputes itinerary totals from items and handles versioning/sharing. */
final class ItineraryService
{
    /** Re-price after any item/markup change. Optional items are excluded from totals. */
    public function recalc(int $tenantId, int $itineraryId): array
    {
        $it = (new ItineraryModel())->setTenant($tenantId)->find($itineraryId);
        if (! $it) {
            throw new \InvalidArgumentException('Itinerary not found.');
        }
        $items = (new ItineraryItemModel())->setTenant($tenantId)->where('itinerary_id', $itineraryId)->findAll();
        $cost = 0;
        foreach ($items as $row) {
            if ((int) $row['is_optional'] === 1) { continue; }
            $cost += (int) $row['cost_amount'];
        }
        $p = PricingService::price(
            $cost, (string) $it['markup_type'], (float) $it['markup_value'], (int) $it['discount_amount'],
            (bool) $it['is_international'], (float) $it['gst_rate'],
            (float) ($it['tcs_rate'] > 0 ? $it['tcs_rate'] : PricingService::DEFAULT_TCS_RATE)
        );
        unset($p['discount_amount']);
        (new ItineraryModel())->setTenant($tenantId)->update($itineraryId, $p);
        return (new ItineraryModel())->setTenant($tenantId)->find($itineraryId);
    }

    /** Add an item; cost_amount defaults to unit_cost × quantity × nights (for hotels). */
    public function addItem(int $tenantId, int $itineraryId, array $d): array
    {
        $qty    = (float) ($d['quantity'] ?? 1);
        $nights = max(1, (int) ($d['nights'] ?? 1));
        $mult   = ($d['type'] ?? '') === 'hotel' ? $qty * $nights : $qty;
        $d      = $this->priceInCurrency($tenantId, $d);                 // foreign-currency line -> INR unit_cost at the locked rate
        $unit   = (int) ($d['unit_cost'] ?? 0);
        $d['cost_amount'] = isset($d['cost_amount']) ? (int) $d['cost_amount'] : (int) round($unit * $mult);
        $d['itinerary_id'] = $itineraryId;
        if (isset($d['meta'])) { $d['meta'] = json_encode($d['meta']); }
        $m  = (new ItineraryItemModel())->setTenant($tenantId);
        $id = (int) $m->insert($d, true);
        if (! $id) {
            throw new \RuntimeException(json_encode($m->errors()) ?: 'Could not add item.');
        }
        $this->recalc($tenantId, $itineraryId);
        return (new ItineraryItemModel())->setTenant($tenantId)->find($id);
    }

    /**
     * A line priced in a foreign currency carries `cost_currency` + `unit_cost_fx` (minor units per unit). We convert it ONCE, here, at the
     * tenant's cost rate (market + forex buffer) and lock that rate on the line; everything downstream stays in INR paise.
     * INR lines are normalised so no stale foreign fields linger.
     */
    private function priceInCurrency(int $tenantId, array $d): array
    {
        $cur = strtoupper(trim((string) ($d['cost_currency'] ?? 'INR')));
        if ($cur === '' || $cur === 'INR') { $d['cost_currency'] = 'INR'; $d['unit_cost_fx'] = null; $d['fx_rate'] = null; return $d; }
        if (! Currency::valid($cur)) { throw new \RuntimeException("Unknown currency {$cur}."); }
        $fx = (int) ($d['unit_cost_fx'] ?? 0);
        if ($fx < 0) { throw new \RuntimeException('The price cannot be negative.'); }
        $r = (new FxService())->costRate($tenantId, $cur);
        $d['cost_currency'] = $cur; $d['unit_cost_fx'] = $fx; $d['fx_rate'] = $r['cost_rate'];
        $d['unit_cost'] = Currency::toInrPaise($fx, $cur, $r['rate'], $r['buffer_pct']);
        unset($d['cost_amount']);                                          // never trust a client-supplied INR total for a foreign line
        return $d;
    }

    /**
     * Re-price every foreign-currency line at today's rates. A quote the customer already accepted is a promise, so it is refused.
     * @return array{itinerary:array,changed:int,old_total:int,new_total:int,stale:list<string>}
     */
    public function relockFx(int $tenantId, int $itineraryId): array
    {
        $it = (new ItineraryModel())->setTenant($tenantId)->find($itineraryId);
        if (! $it) { throw new \InvalidArgumentException('Itinerary not found.'); }
        if (in_array($it['status'], ['accepted'], true)) { throw new \DomainException('This quote was accepted, so its price is locked. Create a new version to re-price it.'); }
        $old = (int) $it['grand_total']; $changed = 0; $stale = [];
        foreach ((new ItineraryItemModel())->setTenant($tenantId)->where('itinerary_id', $itineraryId)->where('cost_currency !=', 'INR')->findAll() as $row) {
            $r = (new FxService())->costRate($tenantId, $row['cost_currency']);
            if ($r['stale']) { $stale[$row['cost_currency']] = $row['cost_currency']; }
            $unit = Currency::toInrPaise((int) $row['unit_cost_fx'], $row['cost_currency'], $r['rate'], $r['buffer_pct']);
            $mult = $row['type'] === 'hotel' ? (float) $row['quantity'] * max(1, (int) $row['nights']) : (float) $row['quantity'];
            $cost = (int) round($unit * $mult);
            if ($unit !== (int) $row['unit_cost'] || $cost !== (int) $row['cost_amount']) { $changed++; }
            (new ItineraryItemModel())->setTenant($tenantId)->update((int) $row['id'], ['unit_cost' => $unit, 'cost_amount' => $cost, 'fx_rate' => $r['cost_rate']]);
        }
        $this->recalc($tenantId, $itineraryId);
        $new = (int) (new ItineraryModel())->setTenant($tenantId)->find($itineraryId)['grand_total'];
        return ['itinerary' => $this->full($tenantId, $itineraryId), 'changed' => $changed, 'old_total' => $old, 'new_total' => $new, 'stale' => array_values($stale)];
    }

    /** Copy an itinerary (and days/items) as the next version, superseding the old one. */
    public function newVersion(int $tenantId, int $itineraryId): array
    {
        $old = (new ItineraryModel())->setTenant($tenantId)->find($itineraryId);
        if (! $old) {
            throw new \InvalidArgumentException('Itinerary not found.');
        }
        $copy = $old;
        unset($copy['id'], $copy['created_at'], $copy['updated_at'], $copy['deleted_at']);
        $copy += [];
        $copy['parent_id'] = $itineraryId;
        $copy['version']   = (int) $old['version'] + 1;
        $copy['status']    = 'draft';
        $copy['share_token'] = null;
        $copy['sent_at'] = $copy['viewed_at'] = $copy['accepted_at'] = null;
        $copy['view_count'] = 0;
        $newId = (int) (new ItineraryModel())->setTenant($tenantId)->insert($copy, true);

        $dayMap = [];
        foreach ((new ItineraryDayModel())->setTenant($tenantId)->where('itinerary_id', $itineraryId)->orderBy('day_no')->findAll() as $day) {
            $oldDayId = $day['id'];
            unset($day['id'], $day['created_at'], $day['updated_at'], $day['deleted_at']);
            $day['itinerary_id'] = $newId;
            $dayMap[$oldDayId] = (int) (new ItineraryDayModel())->setTenant($tenantId)->insert($day, true);
        }
        foreach ((new ItineraryItemModel())->setTenant($tenantId)->where('itinerary_id', $itineraryId)->findAll() as $item) {
            unset($item['id'], $item['created_at'], $item['updated_at'], $item['deleted_at']);
            $item['itinerary_id'] = $newId;
            $item['day_id'] = $dayMap[$item['day_id']] ?? null;
            (new ItineraryItemModel())->setTenant($tenantId)->insert($item);
        }
        if ($old['status'] !== 'accepted') {
            (new ItineraryModel())->setTenant($tenantId)->update($itineraryId, ['status' => 'superseded']);
        }
        return $this->recalc($tenantId, $newId);
    }

    /** Public share link token (idempotent). */
    public function share(int $tenantId, int $itineraryId): array
    {
        $it = (new ItineraryModel())->setTenant($tenantId)->find($itineraryId);
        if (! $it) {
            throw new \InvalidArgumentException('Itinerary not found.');
        }
        $token = $it['share_token'] ?: bin2hex(random_bytes(16));
        $firstShare = ! $it['sent_at'];
        (new ItineraryModel())->setTenant($tenantId)->update($itineraryId, [
            'share_token' => $token,
            'status'      => in_array($it['status'], ['draft'], true) ? 'sent' : $it['status'],
            'sent_at'     => $it['sent_at'] ?: date('Y-m-d H:i:s'),
        ]);
        if ($firstShare) {
            TravelTriggerService::safely(fn (TravelTriggerService $t) => $t->quoteEvent($tenantId, $itineraryId, 'quote_sent'));
        }
        return (new ItineraryModel())->setTenant($tenantId)->find($itineraryId);
    }

    /** Public URL of the customer quote page. */
    public static function publicUrl(string $token): string
    {
        return TravelFlowContext::quoteUrl($token);
    }

    /** Full itinerary payload (days with items) — customer-safe when $forCustomer hides cost data. */
    public function full(int $tenantId, int $itineraryId, bool $forCustomer = false): ?array
    {
        $it = (new ItineraryModel())->setTenant($tenantId)->find($itineraryId);
        if (! $it) {
            return null;
        }
        $days  = (new ItineraryDayModel())->setTenant($tenantId)->where('itinerary_id', $itineraryId)->orderBy('day_no')->findAll();
        $items = (new ItineraryItemModel())->setTenant($tenantId)->where('itinerary_id', $itineraryId)->orderBy('position')->findAll();
        foreach ($items as &$i) {
            $i['meta'] = $i['meta'] ? json_decode((string) $i['meta'], true) : null;
            if ($forCustomer) { unset($i['unit_cost'], $i['cost_amount'], $i['supplier_id'], $i['rate_id'], $i['cost_currency'], $i['unit_cost_fx'], $i['fx_rate']); }
        }
        unset($i);
        $byDay = [];
        foreach ($items as $i) { $byDay[(int) $i['day_id']][] = $i; }
        foreach ($days as &$d) { $d['items'] = $byDay[(int) $d['id']] ?? []; }
        unset($d);
        $it['days']  = $days;
        $it['extras'] = $byDay[0] ?? [];
        $it['fx_display'] = $this->fxDisplay($tenantId, $it);
        if ($forCustomer) {
            unset($it['cost_total'], $it['margin_amount'], $it['markup_type'], $it['markup_value'], $it['created_by']);
        }
        return $it;
    }

    /** Indicative equivalent of the INR total in the quote's display currency (mid-market rate; the customer still pays in INR). */
    public function fxDisplay(int $tenantId, array $it): ?array
    {
        $c = (string) ($it['display_currency'] ?? '');
        if ($c === '' || ! Currency::valid($c)) { return null; }
        $r = (new FxService())->get($tenantId, $c);
        if (! $r) { return null; }
        $minor = Currency::fromInrPaise((int) $it['grand_total'], $c, $r['rate']);
        return ['currency' => $c, 'rate' => $r['rate'], 'amount' => $minor, 'formatted' => Currency::format($minor, $c), 'as_of' => $r['as_of'],
            'note' => 'Indicative only, at ₹' . rtrim(rtrim(number_format($r['rate'], 4, '.', ''), '0'), '.') . ' per ' . $c . '. You pay in Indian rupees; the exact amount depends on the exchange rate on the day you pay.'];
    }

    /**
     * Materialise an AI/template plan into days + items. Costs come ONLY from the
     * referenced supplier rate card; items without a rate are added at zero cost
     * for the agent to price (never guessed).
     */
    public function applyPlan(int $tenantId, int $itineraryId, array $plan, array $rates): array
    {
        $byId = [];
        foreach ($rates as $r) { $byId[(int) $r['id']] = $r; }
        $pos = 0;
        foreach ($plan['days'] ?? [] as $i => $d) {
            $dayModel = (new ItineraryDayModel())->setTenant($tenantId);
            $dayId = (int) $dayModel->insert([
                'itinerary_id' => $itineraryId, 'day_no' => (int) ($d['day_no'] ?? $i + 1),
                'title' => (string) ($d['title'] ?? 'Day ' . ($i + 1)), 'city' => $d['city'] ?? null, 'description' => $d['description'] ?? null,
            ], true);
            foreach ($d['items'] ?? [] as $it) {
                $rate = isset($it['rate_id']) ? ($byId[(int) $it['rate_id']] ?? null) : null;
                $type = in_array($it['type'] ?? '', ['hotel', 'flight', 'train', 'transfer', 'sightseeing', 'activity', 'meal', 'visa', 'insurance'], true) ? $it['type'] : 'other';
                $rateCur = strtoupper((string) ($rate['currency'] ?? 'INR'));
                // A rate card in USD/AED/… must be converted, not read as rupees (a USD 100 rate is 10,000 CENTS, not ₹100).
                $price = ($rate && $rateCur !== 'INR') ? ['cost_currency' => $rateCur, 'unit_cost_fx' => (int) $rate['cost_amount']] : ['unit_cost' => (int) ($rate['cost_amount'] ?? 0)];
                $this->addItem($tenantId, $itineraryId, $price + [
                    'day_id' => $dayId, 'type' => $type, 'title' => (string) ($it['title'] ?? 'Item'), 'details' => $it['details'] ?? null,
                    'supplier_id' => $rate['supplier_id'] ?? null, 'rate_id' => $rate['id'] ?? null,
                    'nights' => max(1, (int) ($it['nights'] ?? 1)),
                    'position' => $pos++,
                ]);
            }
        }
        $upd = array_filter(['inclusions' => $plan['inclusions'] ?? null, 'exclusions' => $plan['exclusions'] ?? null, 'ai_generated' => 1]);
        (new ItineraryModel())->setTenant($tenantId)->update($itineraryId, $upd);
        return $this->recalc($tenantId, $itineraryId);
    }
}
