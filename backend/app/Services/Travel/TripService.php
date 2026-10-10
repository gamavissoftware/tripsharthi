<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\ActivityModel;
use App\Models\DealModel;
use App\Models\TripModel;
use App\Services\Flow\FlowTriggerService;

/** Creates trips (enquiries) and keeps the linked CRM deal in step. */
final class TripService
{
    /** trip.status => travel pipeline stage name */
    private const STAGE_FOR_STATUS = [
        'enquiry' => 'New Enquiry', 'quoted' => 'Itinerary Sent', 'negotiating' => 'Negotiation',
        'booked' => 'Booking Confirmed', 'travelling' => 'Booking Confirmed',
        'completed' => 'Booking Confirmed', 'lost' => 'Lost', 'cancelled' => 'Lost',
    ];

    public function create(int $tenantId, array $data, ?int $actorId = null): array
    {
        $pipe   = new TravelPipelineService();
        $pl     = $pipe->ensure($tenantId);
        $stages = $pipe->stages($tenantId, (int) $pl['id']);

        $budget = (int) ($data['budget_max'] ?? 0);
        $pax    = max(1, (int) ($data['adults'] ?? 2) + (int) ($data['children'] ?? 0));
        $value  = ($data['budget_basis'] ?? 'per_person') === 'per_person' ? $budget * $pax : $budget;

        $dealId = (int) (new DealModel())->setTenant($tenantId)->insert([
            'title'              => $data['title'],
            'pipeline_id'        => $pl['id'],
            'stage_id'           => $stages['New Enquiry']['id'],
            'primary_contact_id' => $data['contact_id'] ?? null,
            'owner_id'           => $data['owner_id'] ?? $actorId,
            'value_amount'       => $value,
            'currency'           => 'INR',
            'expected_close_date' => $data['start_date'] ?? null,
            'status'             => 'open',
            'source'             => $data['source'] ?? 'manual',
        ], true);

        $trip = $this->only($data);
        $trip['deal_id']  = $dealId ?: null;
        $trip['owner_id'] = $data['owner_id'] ?? $actorId;
        $trip['status']   = 'enquiry';
        $tripModel = (new TripModel())->setTenant($tenantId);
        $tripId    = (int) $tripModel->insert($trip, true);
        if (! $tripId) {
            throw new \RuntimeException(json_encode($tripModel->errors()) ?: 'Could not create trip.');
        }

        (new ActivityModel())->log($tenantId, 'system', 'deal', $dealId, ['subject' => 'Trip enquiry created', 'actor_user_id' => $actorId]);
        FlowTriggerService::fire('deal_created', $tenantId, (int) ($data['contact_id'] ?? 0), ['deal_id' => $dealId, 'trip_id' => $tripId]);

        $this->queueConversion($tenantId, $tripId, 'enquiry');

        return (new TripModel())->setTenant($tenantId)->find($tripId);
    }

    /**
     * Open tasks ("activities") per CRM deal, for the pipeline board: how many are open and which one is next.
     * "Next" = the earliest due date (tasks with no date come last). Whether it is overdue is decided by the browser, because
     * task due times are stored exactly as the person typed them (no time zone).
     *
     * @param  list<int> $dealIds
     * @return array<int, array{open:int, next:array{id:int,title:string,type:string,due_at:?string}}>  keyed by deal id; deals with no open task are absent
     */
    public function taskSummary(int $tenantId, array $dealIds): array
    {
        $dealIds = array_values(array_unique(array_filter(array_map('intval', $dealIds))));
        if (! $dealIds) { return []; }
        $rows = db_connect()->table('tasks')->select('id, title, type, due_at, related_id')
            ->where('tenant_id', $tenantId)->where('related_type', 'deal')->whereIn('related_id', $dealIds)
            ->where('status', 'open')->where('deleted_at', null)
            ->orderBy('due_at IS NULL', 'ASC', false)->orderBy('due_at', 'ASC')->orderBy('id', 'ASC')->get()->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $d = (int) $r['related_id'];
            if (! isset($out[$d])) { $out[$d] = ['open' => 0, 'next' => ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'type' => (string) $r['type'], 'due_at' => $r['due_at']]]; }
            $out[$d]['open']++;
        }
        return $out;
    }

    /** Move the trip + its deal to a new status/stage. */
    public function setStatus(int $tenantId, int $tripId, string $status, ?string $lostReason = null): array
    {
        $trip = (new TripModel())->setTenant($tenantId)->find($tripId);
        if (! $trip) {
            throw new \InvalidArgumentException('Trip not found.');
        }
        (new TripModel())->setTenant($tenantId)->update($tripId, ['status' => $status]);

        if ($trip['deal_id'] && isset(self::STAGE_FOR_STATUS[$status])) {
            $pl     = (new TravelPipelineService())->ensure($tenantId);
            $stages = (new TravelPipelineService())->stages($tenantId, (int) $pl['id']);
            $stage  = $stages[self::STAGE_FOR_STATUS[$status]] ?? null;
            if ($stage) {
                $upd = ['stage_id' => $stage['id']];
                if ((int) $stage['is_won'] === 1) { $upd['status'] = 'won'; $upd['won_at'] = date('Y-m-d H:i:s'); }
                if ((int) $stage['is_lost'] === 1) { $upd['status'] = 'lost'; $upd['lost_reason'] = $lostReason; }
                (new DealModel())->setTenant($tenantId)->update((int) $trip['deal_id'], $upd);
                $event = ['booked' => 'deal_won', 'lost' => 'deal_lost'][$status] ?? 'deal_stage_changed';
                FlowTriggerService::fire($event, $tenantId, (int) ($trip['contact_id'] ?? 0), ['deal_id' => (int) $trip['deal_id'], 'trip_id' => $tripId]);
            }
        }
        $this->queueConversion($tenantId, $tripId, $status);
        return (new TripModel())->setTenant($tenantId)->find($tripId);
    }

    /** Outbox a CRM outcome for the ad platforms; never lets a feedback failure break the trip flow. */
    private function queueConversion(int $tenantId, int $tripId, string $status): void
    {
        try {
            $value = 0;
            if ($status === 'booked' || $status === 'quoted') {
                $it = (new \App\Models\ItineraryModel())->setTenant($tenantId)->where('trip_id', $tripId)
                    ->whereIn('status', ['accepted', 'sent', 'viewed'])->orderBy('id', 'DESC')->first();
                $value = (int) ($it['grand_total'] ?? 0);
            }
            (new ConversionFeedbackService())->queueForTrip($tenantId, $tripId, $status, $value);
        } catch (\Throwable $e) {
            log_message('error', 'conversion queue failed: ' . $e->getMessage());
        }
    }

    /** An enquiry this person already has open for the same place (or no place stated on either side) in the last $days days. */
    public function openEnquiryFor(int $tenantId, int $contactId, ?string $destination, int $days = 14, ?int $now = null): ?int
    {
        $dest = strtolower(trim((string) $destination));
        $rows = db_connect()->table('trips')->where('tenant_id', $tenantId)->where('contact_id', $contactId)->where('deleted_at', null)->whereIn('status', ['enquiry', 'quoted', 'negotiating'])
            ->where('created_at >=', date('Y-m-d H:i:s', ($now ?? time()) - $days * 86400))->orderBy('id', 'DESC')->get()->getResultArray();
        foreach ($rows as $r) {
            $rd = strtolower(trim((string) $r['destination_text']));
            if ($dest === '' || $rd === '' || $rd === $dest) { return (int) $r['id']; }
        }
        return null;
    }

    private function only(array $d): array
    {
        $keys = ['contact_id', 'title', 'trip_type', 'destination_id', 'destination_text', 'is_international', 'origin_city',
            'start_date', 'end_date', 'flexible_dates', 'travel_month', 'nights', 'adults', 'children', 'infants', 'child_ages',
            'budget_min', 'budget_max', 'budget_basis', 'hotel_category', 'meal_plan', 'requirements', 'passport_status', 'visa_status'];
        $out = array_intersect_key($d, array_flip($keys));
        if (isset($d['interests'])) {
            $out['interests'] = json_encode(array_values((array) $d['interests']));
        }
        if (empty($out['nights']) && ! empty($out['start_date']) && ! empty($out['end_date'])) {
            $out['nights'] = max(0, (int) ((strtotime($out['end_date']) - strtotime($out['start_date'])) / 86400));
        }
        foreach ($out as $k => $v) {
            if ($v === '') { $out[$k] = null; }
        }
        return $out;
    }
}
