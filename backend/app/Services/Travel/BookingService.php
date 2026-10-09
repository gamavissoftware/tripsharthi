<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\ActivityModel;
use App\Models\BookingModel;
use App\Models\BookingPaymentModel;
use App\Models\BookingServiceModel;
use App\Models\ItineraryItemModel;
use App\Models\ItineraryModel;
use App\Models\TripModel;

/** Turns an accepted itinerary into a booking with payment schedule + supplier to-dos. */
final class BookingService
{
    public function createFromItinerary(int $tenantId, int $itineraryId, array $opts = [], ?int $actorId = null): array
    {
        $it = (new ItineraryModel())->setTenant($tenantId)->find($itineraryId);
        if (! $it || ! $it['trip_id']) {
            throw new \InvalidArgumentException('Itinerary not found or not attached to a trip.');
        }
        $trip = (new TripModel())->setTenant($tenantId)->find((int) $it['trip_id']);
        $dupe = (new BookingModel())->setTenant($tenantId)->where('itinerary_id', $itineraryId)->where('status !=', 'cancelled')->first();
        if ($dupe) {
            throw new \DomainException('A booking already exists for this itinerary.');
        }

        // Group departure? Convert the seat hold to confirmed FIRST (re-checks availability under a lock) so a lapsed hold can never oversell.
        $departures = new DepartureService();
        $seatId = $departures->confirmForItinerary($tenantId, $itineraryId);

        try {
        $bookingId = (int) (new BookingModel())->setTenant($tenantId)->insert([
            'booking_ref'      => $this->nextRef($tenantId),
            'trip_id'          => $trip['id'],
            'deal_id'          => $trip['deal_id'],
            'itinerary_id'     => $itineraryId,
            'contact_id'       => $trip['contact_id'],
            'owner_id'         => $trip['owner_id'] ?? $actorId,
            'title'            => $it['title'],
            'status'           => 'documents_pending',
            'travel_start'     => $trip['start_date'],
            'travel_end'       => $trip['end_date'],
            'is_international' => $it['is_international'],
            'subtotal'         => $it['sell_subtotal'],
            'gst_amount'       => $it['gst_amount'],
            'tcs_amount'       => $it['tcs_amount'],
            'total_amount'     => $it['grand_total'],
            'cost_total'       => $it['cost_total'],
        ], true);
        if ($seatId !== null) { $departures->attachBooking($tenantId, $seatId, $bookingId); }
        } catch (\Throwable $e) {
            if ($seatId !== null) { $departures->release($tenantId, $seatId, 'booking could not be created'); }
            throw $e;
        }

        $rows = PricingService::paymentSchedule(
            (int) $it['grand_total'], date('Y-m-d'), $trip['start_date'],
            (int) ($opts['deposit_pct'] ?? 30), (int) ($opts['instalments'] ?? 2)
        );
        foreach ($rows as $r) {
            (new BookingPaymentModel())->setTenant($tenantId)->insert($r + ['booking_id' => $bookingId]);
        }

        $items = (new ItineraryItemModel())->setTenant($tenantId)->where('itinerary_id', $itineraryId)->where('is_optional', 0)->findAll();
        foreach ($items as $item) {
            if (! $item['supplier_id'] && ! in_array($item['type'], ['hotel', 'transfer', 'flight', 'activity', 'sightseeing', 'visa'], true)) {
                continue;
            }
            $foreign = ($item['cost_currency'] ?? 'INR') !== 'INR' && $item['unit_cost_fx'] !== null;
            $mult = $item['type'] === 'hotel' ? (float) $item['quantity'] * max(1, (int) $item['nights']) : (float) $item['quantity'];
            (new BookingServiceModel())->setTenant($tenantId)->insert([
                'booking_id' => $bookingId, 'item_id' => $item['id'], 'supplier_id' => $item['supplier_id'],
                'service_type' => $item['type'], 'title' => $item['title'], 'cost_amount' => $item['cost_amount'],
                'voucher_token' => bin2hex(random_bytes(16)),
            ] + ($foreign ? ['cost_currency' => $item['cost_currency'], 'cost_fx' => (int) round((int) $item['unit_cost_fx'] * $mult), 'fx_rate' => $item['fx_rate']] : []));
        }

        (new ItineraryModel())->setTenant($tenantId)->update($itineraryId, ['status' => 'accepted', 'accepted_at' => date('Y-m-d H:i:s')]);
        (new TripService())->setStatus($tenantId, (int) $trip['id'], 'booked');
        (new ActivityModel())->log($tenantId, 'system', 'deal', (int) $trip['deal_id'], ['subject' => 'Booking confirmed', 'actor_user_id' => $actorId]);

        TravelTriggerService::safely(fn (TravelTriggerService $t) => $t->bookingEvent($tenantId, $bookingId, 'booking_confirmed'));

        return $this->summary($tenantId, $bookingId);
    }

    /** Record a received payment against a schedule row and roll up the booking. */
    public function markPaid(int $tenantId, int $paymentId, string $mode = 'upi', ?string $reference = null): array
    {
        $pay = (new BookingPaymentModel())->setTenant($tenantId)->find($paymentId);
        if (! $pay) {
            throw new \InvalidArgumentException('Payment row not found.');
        }
        $wasPaid = $pay['status'] === 'paid';
        if (! $wasPaid) {
            (new BookingPaymentModel())->setTenant($tenantId)->update($paymentId, [
                'status' => 'paid', 'paid_at' => date('Y-m-d H:i:s'), 'mode' => $mode, 'reference' => $reference,
            ]);
        }
        $this->rollup($tenantId, (int) $pay['booking_id']);
        if (! $wasPaid) {
            TravelTriggerService::safely(fn (TravelTriggerService $t) => $t->paymentEvent($tenantId, $paymentId, 'payment_received'));
        }
        return $this->summary($tenantId, (int) $pay['booking_id']);
    }

    /**
     * A tenant-Razorpay payment link was paid: settle the instalment it belongs to (idempotent)
     * and, when dunning is on, send the customer a receipt.
     */
    public function onPaymentLinkPaid(int $tenantId, int $linkId, ?string $razorpayPaymentId): ?array
    {
        $pay = (new BookingPaymentModel())->setTenant($tenantId)->where('payment_link_id', $linkId)->first();
        if (! $pay) {
            return null;
        }
        if ($pay['status'] === 'paid') {
            // Duplicate webhook — or the agent marked it paid by hand and the customer also paid the link.
            if ($razorpayPaymentId !== null && $pay['reference'] !== $razorpayPaymentId) {
                log_message('warning', "possible double payment: booking_payment #{$pay['id']} was already settled ({$pay['mode']}) but Razorpay payment {$razorpayPaymentId} arrived — review for refund.");
            }
            return $this->summary($tenantId, (int) $pay['booking_id']);
        }
        $summary = $this->markPaid($tenantId, (int) $pay['id'], 'razorpay', $razorpayPaymentId);
        try {
            $dun = new DunningService();
            if ($dun->settings($tenantId)['enabled']) {
                $paid = (new BookingPaymentModel())->setTenant($tenantId)->find((int) $pay['id']);
                $tpl  = (new \App\Models\TemplateModel())->setTenant($tenantId)->where('name', 'tp_payment_receipt')->where('meta_status', 'approved')->first();
                $dun->sendStep($tenantId, $paid, ['key' => 'receipt', 'kind' => 'message', 'template_id' => $tpl['id'] ?? null]);
            }
        } catch (\Throwable $e) {
            log_message('error', 'receipt send failed: ' . $e->getMessage()); // never fail the payment webhook over a receipt
        }
        return $summary;
    }

    public function rollup(int $tenantId, int $bookingId): void
    {
        $paid = 0;
        foreach ((new BookingPaymentModel())->setTenant($tenantId)->where('booking_id', $bookingId)->where('status', 'paid')->findAll() as $p) {
            $paid += (int) $p['amount'];
        }
        $supplierPaid = 0; $cost = 0;
        foreach ((new BookingServiceModel())->setTenant($tenantId)->where('booking_id', $bookingId)->findAll() as $s) {
            $supplierPaid += (int) $s['paid_amount'];
            if ($s['status'] !== 'cancelled') { $cost += (int) $s['cost_amount']; }
        }
        (new BookingModel())->setTenant($tenantId)->update($bookingId, ['paid_amount' => $paid, 'supplier_paid' => $supplierPaid, 'cost_total' => $cost]);
    }

    /** Flip pending instalments past their due date to overdue. Returns rows changed. */
    public function markOverdue(int $tenantId): int
    {
        $m = (new BookingPaymentModel())->setTenant($tenantId);
        $rows = $m->where('status', 'pending')->where('due_date <', date('Y-m-d'))->findAll();
        foreach ($rows as $r) {
            (new BookingPaymentModel())->setTenant($tenantId)->update((int) $r['id'], ['status' => 'overdue']);
            TravelTriggerService::safely(fn (TravelTriggerService $t) => $t->paymentEvent($tenantId, (int) $r['id'], 'payment_overdue'));
        }
        return count($rows);
    }

    public function summary(int $tenantId, int $bookingId): array
    {
        $b = (new BookingModel())->setTenant($tenantId)->find($bookingId);
        $b['payments'] = (new BookingPaymentModel())->setTenant($tenantId)->where('booking_id', $bookingId)->orderBy('due_date')->findAll();
        $b['services'] = (new BookingServiceModel())->setTenant($tenantId)->where('booking_id', $bookingId)->findAll();
        $b['due_amount'] = (int) $b['total_amount'] - (int) $b['paid_amount'];
        return $b;
    }

    private function nextRef(int $tenantId): string
    {
        $year = date('Y');
        $n = (new BookingModel())->setTenant($tenantId)->withDeleted()->like('booking_ref', "TP-{$year}-", 'after')->countAllResults() + 1;
        return sprintf('TP-%s-%04d', $year, $n);
    }
}
