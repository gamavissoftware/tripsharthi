<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\BookingModel;
use App\Services\Crm\AuditLogger;

/**
 * Group departures with seat inventory.
 *
 * The invariant: confirmed + unexpired-held seats never exceed total_seats. Every state change that consumes seats takes a row
 * lock on the departure first and re-counts, so two agents can never oversell the last seat. Availability is always computed from
 * the seat rows (never a stored counter), so it cannot drift. A hold expires by clock (expired holds are simply not counted;
 * `expireHolds` tidies them). A reservation creates a normal draft itinerary for the trip, so quoting, GST/TCS, booking, payments,
 * invoices and the customer portal all work unchanged.
 */
final class DepartureService
{
    public const DEFAULT_HOLD_HOURS = 24;
    public function __construct(private readonly ?int $now = null) {}
    private function ts(): int { return $this->now ?? time(); }
    private function stamp(): string { return date('Y-m-d H:i:s', $this->ts()); }
    private function today(): string { return (new \DateTimeImmutable('@' . $this->ts()))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d'); }

    // ---- CRUD ---------------------------------------------------------------------------------------------------

    public function save(int $tenantId, array $in, ?int $id = null, ?int $userId = null): array
    {
        $d = $this->clean($in);
        $db = db_connect();
        if ($id === null) {
            if (! preg_match('/^[A-Za-z0-9_-]{2,30}$/', (string) ($in['code'] ?? ''))) { throw new \InvalidArgumentException('Give the departure a short code (letters, numbers, - or _), e.g. BALI-JAN27.'); }
            $d['code'] = strtoupper((string) $in['code']);
            if ($db->table('departures')->where('tenant_id', $tenantId)->where('code', $d['code'])->where('deleted_at', null)->countAllResults() > 0) { throw new \DomainException('That code is already used by another departure.'); }
            $db->table('departures')->insert($d + ['tenant_id' => $tenantId, 'status' => 'open', 'created_by' => $userId, 'created_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
            $id = (int) $db->insertID();
        } else {
            $cur = $this->row($tenantId, $id);
            $seatsTaken = $this->counts($id)['confirmed'] + $this->counts($id)['held'];
            if ($d['total_seats'] < $seatsTaken) { throw new \DomainException("Total seats cannot go below the {$seatsTaken} already sold or held."); }
            if (($d['price_pax'] !== (int) $cur['price_pax'] || $d['start_date'] !== $cur['start_date']) && $seatsTaken > 0) { /* allowed: existing bookings keep the price they were quoted */ }
            $db->table('departures')->where('tenant_id', $tenantId)->where('id', $id)->update($d + ['updated_at' => $this->stamp()]);
        }
        AuditLogger::log('departure.save', 'departure', $id, null, ['code' => $d['code'] ?? null], $tenantId, $userId);
        return $this->show($tenantId, $id);
    }

    private function clean(array $in): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        if ($title === '') { throw new \InvalidArgumentException('Give the departure a title.'); }
        foreach (['start_date', 'end_date'] as $k) { if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in[$k] ?? '')) || strtotime((string) $in[$k]) === false) { throw new \InvalidArgumentException('Enter valid start and end dates.'); } }
        if ($in['end_date'] < $in['start_date']) { throw new \InvalidArgumentException('The end date is before the start date.'); }
        $seats = (int) ($in['total_seats'] ?? 0);
        if ($seats < 1 || $seats > 2000) { throw new \InvalidArgumentException('Total seats must be between 1 and 2000.'); }
        $min = (int) ($in['min_pax'] ?? 0);
        if ($min < 0 || $min > $seats) { throw new \InvalidArgumentException('Minimum travellers cannot exceed total seats.'); }
        $paise = static fn ($k) => max(0, (int) round(((float) ($in[$k] ?? 0)) * 100));
        $cut = (string) ($in['sell_cutoff'] ?? '');
        if ($cut !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $cut) || $cut > $in['start_date'])) { throw new \InvalidArgumentException('The booking cut-off must be on or before the start date.'); }
        $gst = (float) ($in['gst_rate'] ?? 5);
        if (! in_array($gst, [0.0, 5.0, 12.0, 18.0], true)) { throw new \InvalidArgumentException('GST rate must be 0, 5, 12 or 18.'); }
        return ['title' => mb_substr($title, 0, 200), 'destination_id' => ! empty($in['destination_id']) ? (int) $in['destination_id'] : null, 'start_date' => $in['start_date'], 'end_date' => $in['end_date'],
            'total_seats' => $seats, 'min_pax' => $min, 'price_pax' => $paise('price_pax_rs'), 'cost_pax' => $paise('cost_pax_rs'), 'child_price_pct' => max(0, min(100, (int) ($in['child_price_pct'] ?? 100))),
            'single_supplement' => $paise('single_supplement_rs'), 'single_supplement_cost' => $paise('single_supplement_cost_rs'), 'is_international' => ! empty($in['is_international']) ? 1 : 0, 'gst_rate' => $gst,
            'sell_cutoff' => $cut !== '' ? $cut : null, 'inclusions' => $in['inclusions'] ?? null, 'exclusions' => $in['exclusions'] ?? null, 'notes' => $in['notes'] ?? null];
    }

    public function setStatus(int $tenantId, int $id, string $status, ?int $userId = null): array
    {
        if (! in_array($status, ['open', 'closed', 'cancelled', 'completed'], true)) { throw new \InvalidArgumentException('Unknown status.'); }
        $this->row($tenantId, $id);
        if ($status === 'cancelled' && $this->counts($id)['confirmed'] > 0) { throw new \DomainException('This departure has confirmed travellers. Cancel or move their bookings first.'); }
        $db = db_connect();
        $db->table('departures')->where('tenant_id', $tenantId)->where('id', $id)->update(['status' => $status, 'updated_at' => $this->stamp()]);
        if ($status === 'cancelled') { $db->table('departure_seats')->where('tenant_id', $tenantId)->where('departure_id', $id)->where('status', 'held')->update(['status' => 'released', 'released_reason' => 'departure cancelled', 'hold_expires_at' => null, 'updated_at' => $this->stamp()]); }
        AuditLogger::log('departure.status', 'departure', $id, null, ['status' => $status], $tenantId, $userId);
        return $this->show($tenantId, $id);
    }

    private function row(int $tenantId, int $id, bool $lock = false): array
    {
        $r = db_connect()->query('SELECT * FROM departures WHERE tenant_id = ? AND id = ? AND deleted_at IS NULL' . ($lock ? ' FOR UPDATE' : ''), [$tenantId, $id])->getRowArray();
        if (! $r) { throw new \InvalidArgumentException('Departure not found.'); }
        return $r;
    }

    /** @return array{confirmed:int,held:int} unexpired holds only */
    private function counts(int $id): array
    {
        $r = db_connect()->query("SELECT COALESCE(SUM(CASE WHEN status = 'confirmed' THEN seats END),0) c, COALESCE(SUM(CASE WHEN status = 'held' AND hold_expires_at > ? THEN seats END),0) h FROM departure_seats WHERE departure_id = ?", [$this->stamp(), $id])->getRowArray();
        return ['confirmed' => (int) $r['c'], 'held' => (int) $r['h']];
    }

    private function view(array $d): array
    {
        $c = $this->counts((int) $d['id']); $avail = DeparturePricing::available((int) $d['total_seats'], $c['confirmed'], $c['held']);
        return ['id' => (int) $d['id'], 'code' => $d['code'], 'title' => $d['title'], 'destination_id' => $d['destination_id'] ? (int) $d['destination_id'] : null, 'start_date' => $d['start_date'], 'end_date' => $d['end_date'],
            'total_seats' => (int) $d['total_seats'], 'confirmed' => $c['confirmed'], 'held' => $c['held'], 'available' => $avail, 'min_pax' => (int) $d['min_pax'], 'price_pax' => (int) $d['price_pax'], 'cost_pax' => (int) $d['cost_pax'],
            'child_price_pct' => (int) $d['child_price_pct'], 'single_supplement' => (int) $d['single_supplement'], 'single_supplement_cost' => (int) $d['single_supplement_cost'], 'is_international' => (bool) $d['is_international'], 'gst_rate' => (float) $d['gst_rate'],
            'sell_cutoff' => $d['sell_cutoff'], 'status' => $d['status'], 'state' => DeparturePricing::state($d, $avail, $c['confirmed'], $this->today()), 'at_risk' => DeparturePricing::atRisk($d, $c['confirmed'], $this->today()),
            'inclusions' => $d['inclusions'], 'exclusions' => $d['exclusions'], 'notes' => $d['notes']];
    }

    public function show(int $tenantId, int $id): array { return $this->view($this->row($tenantId, $id)); }

    /** @return array<int,array> upcoming first; $scope = upcoming | past | all */
    public function list(int $tenantId, string $scope = 'upcoming'): array
    {
        $today = $this->today();
        $sql = 'SELECT * FROM departures WHERE tenant_id = ? AND deleted_at IS NULL' . ($scope === 'upcoming' ? ' AND end_date >= ? ORDER BY start_date' : ($scope === 'past' ? ' AND end_date < ? ORDER BY start_date DESC' : ' AND ? IS NOT NULL ORDER BY start_date DESC')) . ' LIMIT 200';
        return array_map(fn ($d) => $this->view($d), db_connect()->query($sql, [$tenantId, $today])->getResultArray());
    }

    // ---- holds & bookings -----------------------------------------------------------------------------------------

    /** Hold seats for a trip and draft its itinerary. @return array{hold_id:int,itinerary_id:int,seats:int,expires_at:string,sell:int} */
    public function reserve(int $tenantId, int $departureId, int $tripId, int $adults, int $children, int $singleRooms, int $holdHours = self::DEFAULT_HOLD_HOURS, ?int $userId = null): array
    {
        $trip = db_connect()->table('trips')->where('tenant_id', $tenantId)->where('id', $tripId)->where('deleted_at', null)->get()->getRowArray();
        if (! $trip) { throw new \InvalidArgumentException('Trip not found.'); }
        $holdHours = max(1, min(168, $holdHours));
        $db = db_connect();
        $db->transStart();
        $dep = $this->row($tenantId, $departureId, true);                       // row lock: serialises every seat change on this departure
        $view = $this->view($dep);
        if ($view['state'] !== 'open' && $view['state'] !== 'full') { $db->transComplete(); throw new \DomainException('This departure is ' . $view['state'] . ' and cannot take new bookings.'); }
        $q = DeparturePricing::quote($dep, $adults, $children, $singleRooms);
        $dupe = (int) $db->query("SELECT COUNT(*) n FROM departure_seats WHERE tenant_id = ? AND trip_id = ? AND (status = 'confirmed' OR (status = 'held' AND hold_expires_at > ?))", [$tenantId, $tripId, $this->stamp()])->getRowArray()['n'];
        if ($dupe > 0) { $db->transComplete(); throw new \DomainException('This enquiry already has seats on a departure. Release them first to change the departure.'); }
        if ($q['seats'] > $view['available']) { $db->transComplete(); throw new \DomainException($view['available'] === 0 ? 'This departure is full.' : "Only {$view['available']} seat(s) left on this departure."); }

        $itId = $this->draftItinerary($tenantId, $trip, $dep, $adults, $children, $q);
        $expires = date('Y-m-d H:i:s', $this->ts() + $holdHours * 3600);
        $db->table('departure_seats')->insert(['tenant_id' => $tenantId, 'departure_id' => $departureId, 'trip_id' => $tripId, 'contact_id' => $trip['contact_id'], 'itinerary_id' => $itId, 'adults' => $adults, 'children' => $children,
            'single_rooms' => $singleRooms, 'seats' => $q['seats'], 'status' => 'held', 'hold_expires_at' => $expires, 'created_by' => $userId, 'created_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
        $holdId = (int) $db->insertID();
        $db->table('trips')->where('tenant_id', $tenantId)->where('id', $tripId)->update(['start_date' => $dep['start_date'], 'end_date' => $dep['end_date'], 'is_international' => $dep['is_international'], 'adults' => $adults, 'children' => $children,
            'destination_id' => $dep['destination_id'] ?: $trip['destination_id'], 'nights' => max(0, (int) floor((strtotime($dep['end_date']) - strtotime($dep['start_date'])) / 86400)), 'updated_at' => $this->stamp()]);
        $db->transComplete();
        if (! $db->transStatus()) { throw new \RuntimeException('Could not reserve the seats.'); }
        AuditLogger::log('departure.hold', 'departure_seat', $holdId, null, ['departure' => $departureId, 'seats' => $q['seats']], $tenantId, $userId);
        return ['hold_id' => $holdId, 'itinerary_id' => $itId, 'seats' => $q['seats'], 'expires_at' => $expires, 'sell' => $q['sell']];
    }

    /** One normal itinerary: a single line costing the seats, with a flat markup that reproduces the departure's selling price exactly. */
    private function draftItinerary(int $tenantId, array $trip, array $dep, int $adults, int $children, array $q): int
    {
        $db = db_connect();
        $nights = max(0, (int) floor((strtotime($dep['end_date']) - strtotime($dep['start_date'])) / 86400));
        $db->table('itineraries')->insert(['tenant_id' => $tenantId, 'trip_id' => $trip['id'], 'title' => $dep['title'], 'subtitle' => 'Group departure ' . date('j M Y', strtotime($dep['start_date'])) . ' (' . $dep['code'] . ')',
            'destination_id' => $dep['destination_id'], 'nights' => $nights, 'adults' => $adults, 'children' => $children, 'is_international' => $dep['is_international'], 'status' => 'draft', 'markup_type' => 'flat',
            'markup_value' => $q['sell'] - $q['cost'], 'gst_rate' => $dep['gst_rate'], 'inclusions' => $dep['inclusions'], 'exclusions' => $dep['exclusions'], 'share_token' => bin2hex(random_bytes(16)), 'created_by' => $trip['owner_id'] ?? null,
            'created_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
        $itId = (int) $db->insertID();
        $db->table('itinerary_items')->insert(['tenant_id' => $tenantId, 'itinerary_id' => $itId, 'type' => 'other', 'title' => "Group tour — {$dep['title']} ({$q['seats']} traveller" . ($q['seats'] > 1 ? 's' : '') . ')',
            'quantity' => $q['seats'], 'nights' => 1, 'unit_cost' => 0, 'cost_amount' => $q['cost'], 'position' => 1, 'created_at' => $this->stamp(), 'updated_at' => $this->stamp()]);
        (new ItineraryService())->recalc($tenantId, $itId);
        return $itId;
    }

    public function release(int $tenantId, int $holdId, string $reason = 'released by agent', ?int $userId = null): void
    {
        $db = db_connect();
        $h = $db->table('departure_seats')->where('tenant_id', $tenantId)->where('id', $holdId)->get()->getRowArray();
        if (! $h) { throw new \InvalidArgumentException('Seat hold not found.'); }
        if ($h['status'] === 'confirmed' && $h['booking_id']) { throw new \DomainException('These seats belong to a booking. Cancel the booking to release them.'); }
        $db->table('departure_seats')->where('tenant_id', $tenantId)->where('id', $holdId)->update(['status' => 'released', 'released_reason' => mb_substr($reason, 0, 120), 'hold_expires_at' => null, 'updated_at' => $this->stamp()]);
        AuditLogger::log('departure.release', 'departure_seat', $holdId, null, ['reason' => $reason], $tenantId, $userId);
    }

    /** Booking cancelled -> its seats go back on sale. */
    public function releaseForBooking(int $tenantId, int $bookingId, string $reason = 'booking cancelled'): int
    {
        $db = db_connect();
        $db->table('departure_seats')->where('tenant_id', $tenantId)->where('booking_id', $bookingId)->whereIn('status', ['held', 'confirmed'])
            ->update(['status' => 'released', 'released_reason' => $reason, 'hold_expires_at' => null, 'updated_at' => $this->stamp()]);
        return $db->affectedRows();
    }

    /** Called when a booking is created from an itinerary. If that itinerary holds seats, convert them to confirmed (re-checking under the lock). @return int|null the seat row id (confirmed, booking not attached yet), or null when it is not a departure booking */
    public function confirmForItinerary(int $tenantId, int $itineraryId): ?int
    {
        $db = db_connect();
        $h = $db->table('departure_seats')->where('tenant_id', $tenantId)->where('itinerary_id', $itineraryId)->whereIn('status', ['held', 'released'])->orderBy('id', 'DESC')->get()->getRowArray();
        if (! $h) { return null; }
        $db->transStart();
        $dep = $this->row($tenantId, (int) $h['departure_id'], true);
        $fresh = $db->query('SELECT * FROM departure_seats WHERE id = ? FOR UPDATE', [$h['id']])->getRowArray();
        $stillHeld = $fresh['status'] === 'held' && $fresh['hold_expires_at'] > $this->stamp();
        $c = $this->counts((int) $dep['id']);
        $availWithoutThis = (int) $dep['total_seats'] - $c['confirmed'] - ($c['held'] - ($stillHeld ? (int) $fresh['seats'] : 0));
        if (in_array($dep['status'], ['cancelled', 'closed'], true) || (int) $fresh['seats'] > $availWithoutThis) {
            $db->transComplete();
            throw new \DomainException($stillHeld ? 'This departure is no longer open.' : 'The seat hold expired and those seats have been taken. Check availability and reserve again.');
        }
        $db->table('departure_seats')->where('id', $h['id'])->update(['status' => 'confirmed', 'hold_expires_at' => null, 'released_reason' => null, 'updated_at' => $this->stamp()]);
        $db->transComplete();
        return (int) $h['id'];
    }

    /** Link confirmed seats to the booking that now owns them. */
    public function attachBooking(int $tenantId, int $seatId, int $bookingId): void
    {
        db_connect()->table('departure_seats')->where('tenant_id', $tenantId)->where('id', $seatId)->update(['booking_id' => $bookingId, 'updated_at' => $this->stamp()]);
    }

    /** Tidy: mark lapsed holds released (availability already ignores them). @return int rows tidied */
    public function expireHolds(): int
    {
        $db = db_connect();
        $db->table('departure_seats')->where('status', 'held')->where('hold_expires_at <=', $this->stamp())->update(['status' => 'released', 'released_reason' => 'hold expired', 'hold_expires_at' => null, 'updated_at' => $this->stamp()]);
        return $db->affectedRows();
    }

    // ---- manifest -------------------------------------------------------------------------------------------------------

    /** Who is travelling: bookings on this departure with travellers, passport and dues — for ops and the hotel rooming list. */
    public function manifest(int $tenantId, int $id): array
    {
        $dep = $this->show($tenantId, $id); $db = db_connect();
        $seats = $db->query("SELECT s.*, b.booking_ref, b.total_amount, b.paid_amount, c.name AS customer, c.wa_number FROM departure_seats s LEFT JOIN bookings b ON b.id = s.booking_id AND b.tenant_id = s.tenant_id
            LEFT JOIN contacts c ON c.id = s.contact_id AND c.tenant_id = s.tenant_id WHERE s.tenant_id = ? AND s.departure_id = ? AND (s.status = 'confirmed' OR (s.status = 'held' AND s.hold_expires_at > ?)) ORDER BY s.status, s.id", [$tenantId, $id, $this->stamp()])->getResultArray();
        $groups = array_map(function ($s) use ($db, $tenantId) {
            $trav = $s['booking_id'] ? $db->table('travelers')->where('tenant_id', $tenantId)->where('booking_id', $s['booking_id'])->where('deleted_at', null)->get()->getResultArray() : [];
            return ['hold_id' => (int) $s['id'], 'status' => $s['status'], 'booking_id' => $s['booking_id'] ? (int) $s['booking_id'] : null, 'booking_ref' => $s['booking_ref'], 'customer' => (string) $s['customer'], 'phone' => (string) $s['wa_number'],
                'adults' => (int) $s['adults'], 'children' => (int) $s['children'], 'single_rooms' => (int) $s['single_rooms'], 'seats' => (int) $s['seats'], 'expires_at' => $s['hold_expires_at'],
                'due' => $s['booking_id'] ? max(0, (int) $s['total_amount'] - (int) $s['paid_amount']) : null,
                'travellers' => array_map(static fn ($t) => ['name' => $t['full_name'], 'type' => $t['pax_type'], 'passport_expiry' => $t['passport_expiry'], 'visa_status' => $t['visa_status'], 'meal_pref' => $t['meal_pref']], $trav)];
        }, $seats);
        return ['departure' => $dep, 'groups' => $groups, 'named_travellers' => array_sum(array_map(static fn ($g) => count($g['travellers']), $groups))];
    }

    /** Rooming/passenger list CSV for hotels and ground handlers. No prices, no passport numbers. */
    public function manifestCsv(int $tenantId, int $id): string
    {
        $m = $this->manifest($tenantId, $id);
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Booking', 'Lead customer', 'Phone', 'Traveller', 'Type', 'Passport expiry', 'Visa', 'Meal preference', 'Single room'], ',', '"', '');
        foreach ($m['groups'] as $g) {
            if ($g['status'] !== 'confirmed') { continue; }
            $rows = $g['travellers'] ?: [['name' => '(travellers not entered yet — ' . $g['seats'] . ' seat(s))', 'type' => '', 'passport_expiry' => '', 'visa_status' => '', 'meal_pref' => '']];
            foreach ($rows as $t) { fputcsv($out, [\App\Services\Billing\Docs\GstReturns::csvSafe((string) $g['booking_ref']), \App\Services\Billing\Docs\GstReturns::csvSafe($g['customer']), $g['phone'], \App\Services\Billing\Docs\GstReturns::csvSafe((string) $t['name']), $t['type'], $t['passport_expiry'], $t['visa_status'], \App\Services\Billing\Docs\GstReturns::csvSafe((string) $t['meal_pref']), $g['single_rooms'] > 0 ? 'yes' : ''], ',', '"', ''); }
        }
        rewind($out);
        return "\xEF\xBB\xBF" . stream_get_contents($out);
    }
}
