<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ContactModel;
use App\Models\BookingModel;
use App\Models\TripModel;
use App\Services\Auth\CurrentUser;
use App\Models\ItineraryModel;
use App\Services\AI\AiUsageService;
use App\Services\Billing\PlanLimitChecker;
use App\Services\Travel\ItineraryService;
use App\Services\Travel\TravelAiService;
use App\Services\Travel\TripService;

/** Trips = travel enquiries (each linked to a CRM deal in the Travel Sales pipeline). */
class TripsController extends TravelBaseController
{
    private function model(): TripModel
    {
        return (new TripModel())->setTenant(CurrentUser::tenantId());
    }

    public function index()
    {
        $m = $this->model();
        foreach (['status', 'trip_type', 'contact_id', 'owner_id'] as $f) {
            if (($v = $this->request->getGet($f)) !== null && $v !== '' && $v !== 'all') {
                $m->where($f, $v);
            }
        }
        if ($from = $this->request->getGet('start_from')) { $m->where('start_date >=', $from); }
        if ($to = $this->request->getGet('start_to')) { $m->where('start_date <=', $to); }
        if ($q = trim((string) $this->request->getGet('q'))) {
            $m->groupStart()->like('title', $q)->orLike('destination_text', $q)->groupEnd();
        }
        return $this->ok($m->orderBy('id', 'DESC')->findAll(500));
    }

    public function show($id = null)
    {
        $tid  = CurrentUser::tenantId();
        $trip = $this->model()->find((int) $id);
        if (! $trip) {
            return $this->failNotFound("Trip #{$id} not found.");
        }
        $trip['interests']    = $trip['interests'] ? json_decode((string) $trip['interests'], true) : [];
        $trip['contact']      = $trip['contact_id'] ? (new ContactModel())->setTenant($tid)->find((int) $trip['contact_id']) : null;
        $trip['itineraries']  = (new ItineraryModel())->setTenant($tid)->where('trip_id', $trip['id'])->orderBy('version', 'DESC')->findAll();
        $trip['booking']      = (new BookingModel())->setTenant($tid)->where('trip_id', $trip['id'])->where('status !=', 'cancelled')->first();
        return $this->ok($trip);
    }

    public function create()
    {
        $d = $this->body();
        if (empty($d['title'])) {
            $d['title'] = trim(($d['destination_text'] ?? 'Trip') . ' enquiry');
        }
        try {
            return $this->ok((new TripService())->create(CurrentUser::tenantId(), $d, CurrentUser::id()), 201);
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    /** POST /trips/quick { name, phone, destination?, adults?, children?, nights?, start_date?, budget_rs?, notes? } — contact + enquiry in one call (mobile). */
    public function quick()
    {
        try {
            $r = (new \App\Services\Travel\QuickEnquiryService())->create(CurrentUser::tenantId(), (int) CurrentUser::id(), $this->body());
            return $this->ok($r, $r['existing'] ? 200 : 201);
        } catch (\InvalidArgumentException $e) { return $this->fail($e->getMessage(), 422); }
    }

    public function update($id = null)
    {
        $m = $this->model();
        if (! $m->find((int) $id)) {
            return $this->failNotFound("Trip #{$id} not found.");
        }
        $d = $this->encodeJson($this->body(), ['interests']);
        unset($d['deal_id'], $d['status'], $d['tenant_id']);
        $m->update((int) $id, $d);
        return $this->ok($this->model()->find((int) $id));
    }

    /** POST /trips/:id/status { status, lost_reason? } */
    public function setStatus($id = null)
    {
        $b = $this->body();
        if (! in_array($b['status'] ?? '', ['enquiry', 'quoted', 'negotiating', 'booked', 'travelling', 'completed', 'lost', 'cancelled'], true)) {
            return $this->fail(['status' => 'Invalid status.'], 422);
        }
        try {
            return $this->ok((new TripService())->setStatus(CurrentUser::tenantId(), (int) $id, $b['status'], $b['lost_reason'] ?? null));
        } catch (\InvalidArgumentException $e) {
            return $this->failNotFound($e->getMessage());
        }
    }

    /** POST /trips/ai-parse { text } — turn a pasted enquiry into trip fields (metered). */
    public function aiParse()
    {
        $tid  = CurrentUser::tenantId();
        $text = trim((string) ($this->body()['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 4000) {
            return $this->fail(['text' => 'Provide the enquiry text (max 4000 chars).'], 422);
        }
        $parsed = (new TravelAiService($tid))->parseEnquiry($text);
        if (($parsed['_source'] ?? '') === 'ai') { (new AiUsageService())->record($tid); }
        return $this->ok($parsed);
    }

    /** POST /trips/:id/ai-itinerary — draft a day-wise itinerary grounded in the agency's rate cards (metered). */
    public function aiItinerary($id = null)
    {
        $tid  = CurrentUser::tenantId();
        $trip = $this->model()->find((int) $id);
        if (! $trip) {
            return $this->failNotFound("Trip #{$id} not found.");
        }
        $usage = new AiUsageService();
        if (! $usage->canUse($tid)) {
            $snap = $usage->snapshot($tid);
            return $this->fail((new PlanLimitChecker())->limitExceededResponse('ai_replies', $snap['used'], $snap['limit']), 422);
        }
        $ai    = new TravelAiService($tid);
        $rates = $ai->candidateRates($tid, $trip['destination_id'] ? (int) $trip['destination_id'] : null);
        $plan  = $ai->draftItinerary($trip, $rates);

        $itId = (int) (new ItineraryModel())->setTenant($tid)->insert([
            'trip_id' => $trip['id'], 'title' => $plan['title'] ?? $trip['title'], 'adults' => $trip['adults'], 'children' => $trip['children'],
            'nights' => $trip['nights'] ?? 0, 'is_international' => $trip['is_international'], 'destination_id' => $trip['destination_id'],
            'created_by' => CurrentUser::id(),
        ], true);
        $svc = new ItineraryService();
        $svc->applyPlan($tid, $itId, $plan, $rates);
        if (($plan['_source'] ?? '') === 'ai') { $usage->record($tid); }
        $out = $svc->full($tid, $itId);
        $out['_source'] = $plan['_source'] ?? 'template';
        return $this->ok($out, 201);
    }
}
