<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\BookingModel;
use App\Models\ContactModel;
use App\Models\BookingPaymentModel;
use App\Models\FlowModel;
use App\Models\ItineraryModel;
use App\Models\PaymentLinkModel;
use App\Models\TripModel;
use App\Services\Flow\FlowTriggerService;

/**
 * Fires the travel flow triggers.
 *
 * Event-driven triggers are called from the code path that causes them (share, view, accept, booking, payment).
 * Scheduled ones (quote_stale, departure_soon, trip_started/completed, passport_expiring) are scanned by
 * `php spark travel:triggers`. Every firing is CLAIMED first in travel_trigger_log (UNIQUE), so retries,
 * double webhooks and overlapping crons can never start the same automation twice for the same entity.
 *
 * Nothing here sends a message. A flow listening on the trigger decides what the customer receives, and the
 * flow engine enforces the WhatsApp 24-hour-window and template rules (platform spec §1).
 */
final class TravelTriggerService
{
    public function __construct(private readonly ?int $now = null) {}

    /** Run a trigger call from inside a business operation: a failed automation must never fail the operation itself. */
    public static function safely(callable $fn): void
    {
        try { $fn(new self()); } catch (\Throwable $e) { log_message('error', 'travel trigger failed: ' . $e->getMessage()); }
    }

    private function now(): int { return $this->now ?? time(); }
    private function today(): string { return (new \DateTimeImmutable('@' . $this->now()))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d'); }

    // ---- claim -------------------------------------------------------------------------------

    /** @return bool true when THIS call won the right to fire */
    public function claim(int $tenantId, string $trigger, string $entityType, int $entityId, string $key = ''): bool
    {
        $db = db_connect();
        $db->table('travel_trigger_log')->ignore(true)->insert([
            'tenant_id' => $tenantId, 'trigger_type' => $trigger, 'entity_type' => $entityType,
            'entity_id' => $entityId, 'claim_key' => $key, 'fired_at' => date('Y-m-d H:i:s', $this->now()),
        ]);
        return $db->affectedRows() > 0;
    }

    private function fire(string $trigger, int $tenantId, ?int $contactId, array $ctx): int
    {
        if (! $contactId) { return 0; }
        return FlowTriggerService::fire($trigger, $tenantId, $contactId, $ctx);
    }

    // ---- event-driven ---------------------------------------------------------------------------

    public function quoteEvent(int $tenantId, int $itineraryId, string $trigger): int
    {
        $it = (new ItineraryModel())->setTenant($tenantId)->find($itineraryId);
        if (! $it || ! $it['trip_id'] || ! $this->claim($tenantId, $trigger, 'itinerary', $itineraryId)) { return 0; }
        $trip = (new TripModel())->setTenant($tenantId)->find((int) $it['trip_id']);
        if ($trip && in_array($trigger, ['quote_viewed', 'quote_accepted'], true)) {   // tell the team (phone push) — independent of whether any flow listens
            $contact = $trip['contact_id'] ? (new ContactModel())->setTenant($tenantId)->find((int) $trip['contact_id']) : null;
            \App\Services\Push\PushNotifier::safely(fn ($n) => \App\Services\Push\EventPush::quote($n, $tenantId, $trigger, $trip, $it, $contact));
        }
        return $trip ? $this->fire($trigger, $tenantId, $trip['contact_id'] ? (int) $trip['contact_id'] : null, TravelFlowContext::trip($trip) + TravelFlowContext::quote($it, $this->now())) : 0;
    }

    public function bookingEvent(int $tenantId, int $bookingId, string $trigger, array $extra = [], string $claimKey = ''): int
    {
        $b = (new BookingModel())->setTenant($tenantId)->find($bookingId);
        if (! $b || ! $this->claim($tenantId, $trigger, 'booking', $bookingId, $claimKey)) { return 0; }
        if ($trigger === 'booking_confirmed') {
            $trip = $b['trip_id'] ? (new TripModel())->setTenant($tenantId)->find((int) $b['trip_id']) : null;
            $contact = $b['contact_id'] ? (new ContactModel())->setTenant($tenantId)->find((int) $b['contact_id']) : null;
            \App\Services\Push\PushNotifier::safely(fn ($n) => \App\Services\Push\EventPush::bookingConfirmed($n, $tenantId, $b, $trip, $contact));
        }
        return $this->fire($trigger, $tenantId, $b['contact_id'] ? (int) $b['contact_id'] : null, $this->bookingContext($tenantId, $b) + $extra);
    }

    public function paymentEvent(int $tenantId, int $paymentId, string $trigger): int
    {
        $p = (new BookingPaymentModel())->setTenant($tenantId)->find($paymentId);
        if (! $p || ! $this->claim($tenantId, $trigger, 'payment', $paymentId)) { return 0; }
        $b = (new BookingModel())->setTenant($tenantId)->find((int) $p['booking_id']);
        if (! $b) { return 0; }
        $contact = $b['contact_id'] ? (new ContactModel())->setTenant($tenantId)->find((int) $b['contact_id']) : null;
        \App\Services\Push\PushNotifier::safely(fn ($n) => \App\Services\Push\EventPush::payment($n, $tenantId, $trigger, $b, $p, $contact));
        $link = $p['payment_link_id'] ? (new PaymentLinkModel())->setTenant($tenantId)->find((int) $p['payment_link_id']) : null;
        return $this->fire($trigger, $tenantId, $b['contact_id'] ? (int) $b['contact_id'] : null, $this->bookingContext($tenantId, $b) + TravelFlowContext::payment($p, $link));
    }

    private function bookingContext(int $tenantId, array $b): array
    {
        $ctx = TravelFlowContext::booking($b, $this->now());
        if ($b['trip_id'] && ($trip = (new TripModel())->setTenant($tenantId)->find((int) $b['trip_id']))) {
            $ctx = TravelFlowContext::trip($trip) + $ctx;
        }
        return $ctx;
    }

    // ---- scheduled ----------------------------------------------------------------------------------

    /** Run every scanner. @return array<string,int> flow starts enqueued per trigger */
    public function runAll(): array
    {
        return [
            'quote_stale'       => $this->scanQuoteStale(),
            'departure_soon'    => $this->scanDeparture(),
            'passport_expiring' => $this->scanPassports(),
            'lifecycle'         => $this->advanceLifecycle(),
        ];
    }

    /** @return list<array> active flows for a trigger, across tenants */
    private function activeFlows(string $trigger): array
    {
        return (new FlowModel())->withoutTenantScope()->where('status', 'active')->where('trigger_type', $trigger)->findAll();
    }

    private static function cfg(array $flow): array
    {
        return json_decode($flow['trigger_config'] ?? '{}', true) ?: [];
    }

    /**
     * quote_stale — config.days (default 3), config.audience: any | unviewed | viewed (opened but not accepted).
     * Hot "viewed, not accepted" quotes and never-opened quotes call for different follow-ups, hence the audience.
     */
    public function scanQuoteStale(): int
    {
        $fired = 0;
        foreach ($this->activeFlows('quote_stale') as $flow) {
            $tid = (int) $flow['tenant_id'];
            $cfg = self::cfg($flow);
            $days = max(1, (int) ($cfg['days'] ?? 3));
            $audience = in_array($cfg['audience'] ?? 'any', ['any', 'unviewed', 'viewed'], true) ? ($cfg['audience'] ?? 'any') : 'any';
            $statuses = ['any' => ['sent', 'viewed'], 'unviewed' => ['sent'], 'viewed' => ['viewed']][$audience];

            $rows = (new ItineraryModel())->setTenant($tid)->whereIn('status', $statuses)->where('trip_id IS NOT NULL', null, false)
                ->where('sent_at <=', date('Y-m-d H:i:s', $this->now() - $days * 86400))->findAll(500);
            foreach ($rows as $it) {
                $trip = (new TripModel())->setTenant($tid)->find((int) $it['trip_id']);
                if (! $trip || ! in_array($trip['status'], ['enquiry', 'quoted', 'negotiating'], true) || ! $trip['contact_id']) { continue; }
                if (! $this->claim($tid, 'quote_stale', 'itinerary', (int) $it['id'], 'flow' . $flow['id'])) { continue; }
                $fired += $this->fire('quote_stale', $tid, (int) $trip['contact_id'], TravelFlowContext::trip($trip) + TravelFlowContext::quote($it, $this->now()));
            }
        }
        return $fired;
    }

    /** departure_soon — config.days_before (default 7): bookings whose travel_start is exactly that many days away. */
    public function scanDeparture(): int
    {
        $fired = 0;
        foreach ($this->activeFlows('departure_soon') as $flow) {
            $tid  = (int) $flow['tenant_id'];
            $n    = max(0, (int) (self::cfg($flow)['days_before'] ?? 7));
            $date = date('Y-m-d', strtotime($this->today() . " +{$n} days"));
            $rows = (new BookingModel())->setTenant($tid)->where('travel_start', $date)->whereNotIn('status', ['cancelled', 'completed'])->findAll(500);
            foreach ($rows as $b) {
                $fired += $this->bookingEvent($tid, (int) $b['id'], 'departure_soon', ['days_before' => $n], 'flow' . $flow['id']);
            }
        }
        return $fired;
    }

    /**
     * passport_expiring — config.within_days (default 120): travellers on bookings departing within that window whose
     * passport is valid for less than 6 months after the trip ends (a common entry requirement). Fires per traveller.
     */
    public function scanPassports(): int
    {
        $fired = 0;
        foreach ($this->activeFlows('passport_expiring') as $flow) {
            $tid    = (int) $flow['tenant_id'];
            $within = max(1, (int) (self::cfg($flow)['within_days'] ?? 120));
            $until  = date('Y-m-d', strtotime($this->today() . " +{$within} days"));
            $rows = db_connect()->query(
                "SELECT t.id AS traveler_id, t.full_name, t.passport_expiry, t.contact_id AS traveler_contact, b.id AS booking_id
                   FROM travelers t JOIN bookings b ON b.id = t.booking_id AND b.tenant_id = t.tenant_id
                  WHERE t.tenant_id = ? AND t.deleted_at IS NULL AND b.deleted_at IS NULL AND t.passport_expiry IS NOT NULL
                    AND b.status NOT IN ('cancelled','completed') AND b.is_international = 1
                    AND b.travel_start BETWEEN ? AND ?
                    AND t.passport_expiry < DATE_ADD(COALESCE(b.travel_end, b.travel_start), INTERVAL 6 MONTH)
                  LIMIT 500", [$tid, $this->today(), $until]
            )->getResultArray();
            foreach ($rows as $r) {
                if (! $this->claim($tid, 'passport_expiring', 'traveler', (int) $r['traveler_id'], 'flow' . $flow['id'])) { continue; }
                $b = (new BookingModel())->setTenant($tid)->find((int) $r['booking_id']);
                $contact = (int) ($r['traveler_contact'] ?: $b['contact_id']);
                $fired += $this->fire('passport_expiring', $tid, $contact ?: null, $this->bookingContext($tid, $b) + TravelFlowContext::traveler(['full_name' => $r['full_name'], 'passport_expiry' => $r['passport_expiry']]));
            }
        }
        return $fired;
    }

    /**
     * Move bookings through their life and announce it: departure day -> `travelling` + trip_started;
     * day after the end date -> `completed` + trip_completed. Updates trips directly (not via TripService) so no
     * spurious deal-stage trigger or ad conversion is produced for a stage the deal is already in.
     */
    public function advanceLifecycle(): int
    {
        $fired = 0;
        $today = $this->today();
        $rows = (new BookingModel())->withoutTenantScope()->whereIn('status', ['confirmed', 'documents_pending', 'ready', 'travelling'])
            ->where('travel_start <=', $today)->findAll(1000);
        foreach ($rows as $b) {
            $tid = (int) $b['tenant_id'];
            $end = $b['travel_end'] ?: $b['travel_start'];
            if ($end < $today) {                       // trip is over
                if ($b['status'] !== 'travelling') {   // never saw it start (cron gap): announce the start first, once
                    $fired += $this->bookingEvent($tid, (int) $b['id'], 'trip_started');
                }
                (new BookingModel())->setTenant($tid)->update((int) $b['id'], ['status' => 'completed']);
                $b['trip_id'] && (new TripModel())->setTenant($tid)->update((int) $b['trip_id'], ['status' => 'completed']);
                $fired += $this->bookingEvent($tid, (int) $b['id'], 'trip_completed');
            } elseif ($b['status'] !== 'travelling') {
                (new BookingModel())->setTenant($tid)->update((int) $b['id'], ['status' => 'travelling']);
                $b['trip_id'] && (new TripModel())->setTenant($tid)->update((int) $b['trip_id'], ['status' => 'travelling']);
                $fired += $this->bookingEvent($tid, (int) $b['id'], 'trip_started');
            }
        }
        return $fired;
    }
}
