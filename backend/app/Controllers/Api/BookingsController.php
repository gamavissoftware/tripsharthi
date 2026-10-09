<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\BookingModel;
use App\Models\BookingPaymentModel;
use App\Models\BookingServiceModel;
use App\Models\TravelerModel;
use App\Services\Auth\CurrentUser;
use App\Services\Travel\BookingService;

class BookingsController extends TravelBaseController
{
    private function model(): BookingModel
    {
        return (new BookingModel())->setTenant(CurrentUser::tenantId());
    }

    public function index()
    {
        $m = $this->model();
        if (($s = $this->request->getGet('status')) && $s !== 'all') { $m->where('status', $s); }
        if ($c = (int) $this->request->getGet('contact_id')) { $m->where('contact_id', $c); }
        return $this->ok($m->orderBy('id', 'DESC')->findAll(500));
    }

    public function show($id = null)
    {
        if (! $this->model()->find((int) $id)) { return $this->failNotFound('Booking not found.'); }
        $b = (new BookingService())->summary(CurrentUser::tenantId(), (int) $id);
        $b['travelers'] = array_map(static function (array $t): array {
            $t['has_passport'] = ! empty($t['passport_no_enc']);
            unset($t['passport_no_enc']);
            return $t;
        }, (new TravelerModel())->setTenant(CurrentUser::tenantId())->where('booking_id', (int) $id)->findAll());
        return $this->ok($b);
    }

    /** POST /bookings { itinerary_id, deposit_pct?, instalments? } */
    public function create()
    {
        $b = $this->body();
        try {
            return $this->ok((new BookingService())->createFromItinerary(CurrentUser::tenantId(), (int) ($b['itinerary_id'] ?? 0), $b, CurrentUser::id()), 201);
        } catch (\DomainException $e) {
            return $this->fail($e->getMessage(), 409);
        } catch (\InvalidArgumentException $e) {
            return $this->failNotFound($e->getMessage());
        }
    }

    /** POST /bookings/payments/:id/paid { mode, reference } */
    public function markPaid($paymentId = null)
    {
        $b = $this->body();
        try {
            return $this->ok((new BookingService())->markPaid(CurrentUser::tenantId(), (int) $paymentId, (string) ($b['mode'] ?? 'upi'), $b['reference'] ?? null));
        } catch (\InvalidArgumentException $e) {
            return $this->failNotFound($e->getMessage());
        }
    }

    /** PATCH /bookings/services/:id { status, confirmation_no, cost_amount, pay_by, notes, supplier_id } — payments go through /payables, never paid_amount */
    public function updateService($serviceId = null)
    {
        $tid = CurrentUser::tenantId();
        $m   = (new BookingServiceModel())->setTenant($tid);
        $svc = $m->find((int) $serviceId);
        if (! $svc) { return $this->failNotFound('Service not found.'); }
        $in = array_intersect_key($this->body(), array_flip(['status', 'confirmation_no', 'cost_amount', 'cost_fx', 'pay_by', 'notes', 'supplier_id']));
        if (($svc['cost_currency'] ?? 'INR') !== 'INR') {
            // A foreign-currency service is priced in ITS currency: the INR figure is derived at the rate locked on the quote, never typed.
            unset($in['cost_amount']);
            if (isset($in['cost_fx'])) {
                $minor = (int) $in['cost_fx'];
                if ($minor < (int) $svc['paid_fx']) { return $this->fail('The cost cannot be lower than what you have already paid.', 422); }
                $in['cost_amount'] = \App\Services\Travel\Currency::toInrPaise($minor, (string) $svc['cost_currency'], (float) $svc['fx_rate'], 0);
            }
        } else { unset($in['cost_fx']); }
        (new BookingServiceModel())->setTenant($tid)->update((int) $serviceId, $in);
        (new BookingService())->rollup($tid, (int) $svc['booking_id']);
        return $this->ok((new BookingServiceModel())->setTenant($tid)->find((int) $serviceId));
    }

    /** POST /bookings/:id/travelers — passport numbers are encrypted at rest. */
    public function addTraveler($id = null)
    {
        $tid = CurrentUser::tenantId();
        if (! $this->model()->find((int) $id)) { return $this->failNotFound('Booking not found.'); }
        $d = array_intersect_key($this->body(), array_flip(['full_name', 'pax_type', 'gender', 'dob', 'nationality', 'passport_expiry', 'visa_status', 'meal_pref', 'is_lead', 'contact_id']));
        if (! empty($this->body()['passport_no'])) {
            $d['passport_no_enc'] = base64_encode(service('encrypter')->encrypt((string) $this->body()['passport_no']));
        }
        $d['booking_id'] = (int) $id;
        $m  = (new TravelerModel())->setTenant($tid);
        $tId = (int) $m->insert($d, true);
        if (! $tId) { return $this->fail($m->errors() ?: 'Invalid.', 422); }
        try { (new \App\Services\Travel\ChecklistService())->generate($tid, (int) $id); } catch (\Throwable $e) { log_message('warning', 'checklist: ' . $e->getMessage()); }
        $row = (new TravelerModel())->setTenant($tid)->find($tId);
        $row['has_passport'] = ! empty($row['passport_no_enc']);
        unset($row['passport_no_enc']);
        return $this->ok($row, 201);
    }

    /** POST /bookings/:id/cancel { reason } */
    public function cancel($id = null)
    {
        if (! $this->model()->find((int) $id)) { return $this->failNotFound('Booking not found.'); }
        (new \App\Services\Travel\DepartureService())->releaseForBooking(CurrentUser::tenantId(), (int) $id);
        $this->model()->update((int) $id, ['status' => 'cancelled', 'cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => $this->body()['reason'] ?? null]);
        return $this->ok($this->model()->find((int) $id));
    }

    /** GET /bookings/dashboard — receivables, payables, upcoming departures. */
    public function dashboard()
    {
        $tid = CurrentUser::tenantId();
        $pay = (new BookingPaymentModel())->setTenant($tid);
        (new BookingService())->markOverdue($tid);
        $row = (new BookingPaymentModel())->setTenant($tid)->select("SUM(CASE WHEN status='overdue' THEN amount ELSE 0 END) overdue, SUM(CASE WHEN status='pending' THEN amount ELSE 0 END) upcoming", false)->first();
        $dep = $this->model()->where('travel_start >=', date('Y-m-d'))->where('travel_start <=', date('Y-m-d', strtotime('+30 days')))
            ->whereNotIn('status', ['cancelled'])->orderBy('travel_start')->findAll(50);
        $tot = $this->model()->select('COUNT(*) n, COALESCE(SUM(total_amount),0) revenue, COALESCE(SUM(total_amount - cost_total),0) gross_margin, COALESCE(SUM(paid_amount),0) collected', false)->where('status !=', 'cancelled')->first();
        return $this->ok(['receivables' => $row, 'departures_30d' => $dep, 'totals' => $tot]);
    }
}
