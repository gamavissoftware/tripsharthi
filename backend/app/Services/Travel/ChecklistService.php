<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\BookingModel;
use App\Models\TravelerModel;

/** Generates and tracks the document checklist of a booking. Generation is idempotent (UNIQUE booking+traveller+key) and never resets progress. */
final class ChecklistService
{
    public function __construct(private readonly ?int $now = null) {}
    private function today(): string { return date('Y-m-d', $this->now ?? time()); }
    private function stamp(): string { return date('Y-m-d H:i:s', $this->now ?? time()); }

    /** @return int items newly created */
    public function generate(int $tenantId, int $bookingId): int
    {
        $b = (new BookingModel())->setTenant($tenantId)->find($bookingId);
        if (! $b) { throw new \InvalidArgumentException('Booking not found.'); }
        if ($b['status'] === 'cancelled') { throw new \DomainException('This booking is cancelled.'); }
        $travelers = (new TravelerModel())->setTenant($tenantId)->where('booking_id', $bookingId)->findAll();
        $db = db_connect(); $new = 0;
        foreach (ChecklistPlanner::plan($b, $travelers) as $i) {
            $db->query('INSERT IGNORE INTO booking_checklist_items (tenant_id, booking_id, traveler_id, item_key, category, label, required, due_date, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$tenantId, $bookingId, $i['traveler_id'], $i['item_key'], $i['category'], $i['label'], $i['required'], $i['due_date'], $this->stamp(), $this->stamp()]);
            $new += $db->affectedRows() > 0 ? 1 : 0;
        }
        return $new;
    }

    public function forBooking(int $tenantId, int $bookingId): array
    {
        $rows = db_connect()->table('booking_checklist_items')->where('tenant_id', $tenantId)->where('booking_id', $bookingId)->orderBy('traveler_id')->orderBy('id')->get()->getResultArray();
        $items = array_map(fn ($r) => ['id' => (int) $r['id'], 'traveler_id' => (int) $r['traveler_id'], 'key' => $r['item_key'], 'category' => $r['category'], 'label' => $r['label'], 'required' => (int) $r['required'],
            'status' => $r['status'], 'due_date' => $r['due_date'], 'overdue' => $r['status'] === 'pending' && (int) $r['required'] === 1 && $r['due_date'] !== null && $r['due_date'] < $this->today(), 'notes' => $r['notes']], $rows);
        return ['items' => $items, 'progress' => ChecklistPlanner::progress($items)];
    }

    public function setStatus(int $tenantId, int $itemId, string $status, ?string $notes, ?int $userId): array
    {
        if (! in_array($status, ['pending', 'received', 'not_applicable'], true)) { throw new \InvalidArgumentException('Unknown status.'); }
        $db = db_connect();
        $row = $db->table('booking_checklist_items')->where('tenant_id', $tenantId)->where('id', $itemId)->get()->getRowArray();
        if (! $row) { throw new \InvalidArgumentException('Checklist item not found.'); }
        $db->table('booking_checklist_items')->where('tenant_id', $tenantId)->where('id', $itemId)->update([
            'status' => $status, 'received_at' => $status === 'received' ? $this->stamp() : null, 'notes' => $notes !== null ? mb_substr(trim($notes), 0, 255) : $row['notes'], 'updated_by' => $userId, 'updated_at' => $this->stamp()]);
        return $this->forBooking($tenantId, (int) $row['booking_id']);
    }

    /** Upcoming departures that are not document-ready, soonest first. */
    public function overview(int $tenantId, int $withinDays = 60): array
    {
        $today = $this->today(); $to = date('Y-m-d', strtotime($today . " +{$withinDays} days"));
        $bookings = db_connect()->query("SELECT b.id, b.booking_ref, b.title, b.travel_start, b.is_international, c.name AS customer FROM bookings b LEFT JOIN contacts c ON c.id = b.contact_id
            WHERE b.tenant_id = ? AND b.deleted_at IS NULL AND b.status NOT IN ('cancelled','completed') AND b.travel_start IS NOT NULL AND b.travel_start >= ? AND b.travel_start <= ? ORDER BY b.travel_start", [$tenantId, $today, $to])->getResultArray();
        $out = [];
        foreach ($bookings as $b) {
            $c = $this->forBooking($tenantId, (int) $b['id']);
            $missing = array_values(array_filter($c['items'], static fn ($i) => $i['status'] === 'pending' && $i['required'] === 1));
            $out[] = ['booking_id' => (int) $b['id'], 'booking_ref' => $b['booking_ref'], 'title' => $b['title'], 'customer' => (string) $b['customer'], 'travel_start' => $b['travel_start'],
                'days_to_departure' => (int) floor((strtotime($b['travel_start']) - strtotime($today)) / 86400), 'international' => (bool) $b['is_international'], 'generated' => $c['items'] !== [],
                'progress' => $c['progress'], 'missing' => array_map(static fn ($i) => ['label' => $i['label'], 'due_date' => $i['due_date'], 'overdue' => $i['overdue']], array_slice($missing, 0, 6)), 'overdue_count' => count(array_filter($missing, static fn ($i) => $i['overdue']))];
        }
        return ['within_days' => $withinDays, 'bookings' => array_values(array_filter($out, static fn ($o) => ! $o['generated'] || ! $o['progress']['ready']))];
    }
}
