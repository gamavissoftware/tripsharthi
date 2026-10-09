<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ItineraryDayModel;
use App\Models\ItineraryItemModel;
use App\Models\ItineraryModel;
use App\Models\TripModel;
use App\Services\Auth\CurrentUser;
use App\Services\Travel\ItineraryService;

class ItinerariesController extends TravelBaseController
{
    private function model(): ItineraryModel
    {
        return (new ItineraryModel())->setTenant(CurrentUser::tenantId());
    }

    public function index()
    {
        $m = $this->model();
        if ($t = (int) $this->request->getGet('trip_id')) { $m->where('trip_id', $t); }
        if ($this->request->getGet('templates') === '1') { $m->where('is_template', 1); }
        return $this->ok($m->orderBy('id', 'DESC')->findAll(300));
    }

    public function show($id = null)
    {
        $full = (new ItineraryService())->full(CurrentUser::tenantId(), (int) $id);
        return $full ? $this->ok($full) : $this->failNotFound('Itinerary not found.');
    }

    public function create()
    {
        $tid = CurrentUser::tenantId();
        $d   = $this->body();
        if (! empty($d['trip_id'])) {
            $trip = (new TripModel())->setTenant($tid)->find((int) $d['trip_id']);
            if (! $trip) { return $this->failNotFound('Trip not found.'); }
            $d += ['title' => $trip['title'], 'adults' => $trip['adults'], 'children' => $trip['children'],
                   'nights' => $trip['nights'] ?? 0, 'is_international' => $trip['is_international'],
                   'destination_id' => $trip['destination_id']];
        }
        $d['created_by'] = CurrentUser::id();
        $d = array_intersect_key($d, array_flip(['trip_id', 'title', 'subtitle', 'destination_id', 'nights', 'adults', 'children',
            'is_international', 'markup_type', 'markup_value', 'discount_amount', 'gst_rate', 'tcs_rate', 'inclusions', 'exclusions',
            'terms', 'cover_image', 'valid_until', 'is_template', 'created_by']));
        $m  = $this->model();
        $id = (int) $m->insert($d, true);
        if (! $id) { return $this->fail($m->errors() ?: 'Invalid.', 422); }
        $svc = new ItineraryService();
        $svc->recalc($tid, $id);
        return $this->ok($svc->full($tid, $id), 201);
    }

    public function update($id = null)
    {
        $tid = CurrentUser::tenantId();
        if (! $this->model()->find((int) $id)) { return $this->failNotFound('Itinerary not found.'); }
        $d = array_intersect_key($this->body(), array_flip(['title', 'subtitle', 'nights', 'adults', 'children', 'is_international',
            'markup_type', 'markup_value', 'discount_amount', 'gst_rate', 'tcs_rate', 'inclusions', 'exclusions', 'terms',
            'cover_image', 'valid_until', 'status', 'display_currency']));
        if (array_key_exists('display_currency', $d)) {
            $dc = strtoupper(trim((string) $d['display_currency']));
            if ($dc !== '' && (! \App\Services\Travel\Currency::valid($dc) || $dc === 'INR')) { return $this->fail('Choose a foreign currency from the list, or none.', 422); }
            $d['display_currency'] = $dc === '' ? null : $dc;
        }
        $this->model()->update((int) $id, $d);
        $svc = new ItineraryService();
        $svc->recalc($tid, (int) $id);
        return $this->ok($svc->full($tid, (int) $id));
    }

    /** POST /itineraries/:id/relock-fx — re-price foreign-currency lines at TODAY'S rates (not allowed once the quote is accepted). */
    public function relockFx($id = null)
    {
        if (! $this->model()->find((int) $id)) { return $this->failNotFound('Itinerary not found.'); }
        try { return $this->ok((new ItineraryService())->relockFx(CurrentUser::tenantId(), (int) $id)); }
        catch (\DomainException $e) { return $this->fail($e->getMessage(), 409); }
        catch (\RuntimeException $e) { return $this->fail($e->getMessage(), 422); }
    }

    public function delete($id = null)
    {
        if (! $this->model()->find((int) $id)) { return $this->failNotFound('Itinerary not found.'); }
        $this->model()->delete((int) $id);
        return $this->ok(['deleted' => true]);
    }

    /** POST /itineraries/:id/days { day_no?, title, city?, description? } */
    public function addDay($id = null)
    {
        $tid = CurrentUser::tenantId();
        if (! $this->model()->find((int) $id)) { return $this->failNotFound('Itinerary not found.'); }
        $d = array_intersect_key($this->body(), array_flip(['day_no', 'title', 'city', 'description', 'image']));
        if (empty($d['day_no'])) {
            $d['day_no'] = (new ItineraryDayModel())->setTenant($tid)->where('itinerary_id', (int) $id)->countAllResults() + 1;
        }
        $d['itinerary_id'] = (int) $id;
        $m = (new ItineraryDayModel())->setTenant($tid);
        $dayId = (int) $m->insert($d, true);
        return $dayId ? $this->ok((new ItineraryDayModel())->setTenant($tid)->find($dayId), 201) : $this->fail($m->errors(), 422);
    }

    /** POST /itineraries/:id/items */
    public function addItem($id = null)
    {
        $tid = CurrentUser::tenantId();
        if (! $this->model()->find((int) $id)) { return $this->failNotFound('Itinerary not found.'); }
        try {
            $item = (new ItineraryService())->addItem($tid, (int) $id, array_intersect_key($this->body(), array_flip([
                'day_id', 'type', 'title', 'details', 'supplier_id', 'rate_id', 'quantity', 'nights', 'unit_cost', 'cost_amount', 'cost_currency', 'unit_cost_fx', 'is_optional', 'position', 'meta',
            ])));
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
        return $this->ok($item, 201);
    }

    /** DELETE /itineraries/:id/items/:itemId */
    public function deleteItem($id = null, $itemId = null)
    {
        $tid = CurrentUser::tenantId();
        $m = (new ItineraryItemModel())->setTenant($tid);
        $item = $m->where('itinerary_id', (int) $id)->find((int) $itemId);
        if (! $item) { return $this->failNotFound('Item not found.'); }
        (new ItineraryItemModel())->setTenant($tid)->delete((int) $itemId);
        return $this->ok((new ItineraryService())->recalc($tid, (int) $id));
    }

    public function newVersion($id = null)
    {
        try { return $this->ok((new ItineraryService())->newVersion(CurrentUser::tenantId(), (int) $id), 201); }
        catch (\InvalidArgumentException $e) { return $this->failNotFound($e->getMessage()); }
    }

    /** POST /itineraries/:id/share → { share_token, url } */
    public function share($id = null)
    {
        try {
            $it = (new ItineraryService())->share(CurrentUser::tenantId(), (int) $id);
        } catch (\InvalidArgumentException $e) { return $this->failNotFound($e->getMessage()); }
        return $this->ok(['share_token' => $it['share_token'], 'url' => \App\Services\Travel\ItineraryService::publicUrl((string) $it['share_token'])]);
    }
}
