<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Services\Leads\ContactDedupeService;
use App\Services\Leads\LeadFieldExtractor;

/**
 * "Add enquiry" from the phone: one call creates (or finds) the contact and opens the trip. Built for flaky mobile networks:
 * a retried submit finds the open enquiry for the same person + place instead of creating a second one.
 */
final class QuickEnquiryService
{
    public function __construct(private readonly ?int $now = null) {}
    private function today(): string { return (new \DateTimeImmutable('@' . ($this->now ?? time())))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d'); }

    /** @return array{trip:array,contact_id:int,existing:bool} */
    public function create(int $tenantId, int $userId, array $in): array
    {
        $name = trim(preg_replace('/[<>]/', '', (string) ($in['name'] ?? '')) ?? '');
        if ($name === '') { throw new \InvalidArgumentException('Enter the customer\'s name.'); }
        $phone = LeadFieldExtractor::phone((string) ($in['phone'] ?? ''));
        if ($phone === '') { throw new \InvalidArgumentException('Enter a valid phone number (10-digit Indian mobile, or with + and country code).'); }
        $dest = trim((string) ($in['destination'] ?? ''));
        $adults = max(1, min(60, (int) ($in['adults'] ?? 2))); $children = max(0, min(30, (int) ($in['children'] ?? 0)));
        $start = trim((string) ($in['start_date'] ?? ''));
        if ($start !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || strtotime($start) === false || $start < $this->today())) { throw new \InvalidArgumentException('The travel date must be today or later.'); }
        $nights = isset($in['nights']) && $in['nights'] !== '' ? (int) $in['nights'] : null;
        if ($nights !== null && ($nights < 1 || $nights > 90)) { throw new \InvalidArgumentException('Nights must be between 1 and 90.'); }
        $budget = isset($in['budget_rs']) && $in['budget_rs'] !== '' ? (int) round(((float) $in['budget_rs']) * 100) : null;
        if ($budget !== null && ($budget < 0 || $budget > 100_000_000_000)) { throw new \InvalidArgumentException('That budget does not look right.'); }

        $res = (new ContactDedupeService(new ContactModel(), new ContactFieldValueModel()))->upsert($tenantId, array_filter(['wa_number' => $phone, 'name' => $name, 'source' => 'manual', 'email' => filter_var($in['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null], static fn ($v) => $v !== null && $v !== ''), false);
        $contactId = (int) $res['contact_id'];
        $trips = new TripService();
        if (($open = $trips->openEnquiryFor($tenantId, $contactId, $dest !== '' ? $dest : null, 14, $this->now)) !== null) {
            return ['trip' => (new \App\Models\TripModel())->setTenant($tenantId)->find($open), 'contact_id' => $contactId, 'existing' => true];
        }
        $heur = $dest !== '' ? TravelAiService::heuristicParse($dest) : [];
        $trip = $trips->create($tenantId, array_filter([
            'title' => mb_substr(($dest !== '' ? $dest : 'Travel') . ' enquiry – ' . $name, 0, 200), 'contact_id' => $contactId, 'owner_id' => $userId, 'source' => 'manual',
            'destination_text' => $dest !== '' ? mb_substr($dest, 0, 200) : null, 'is_international' => $heur['is_international'] ?? null, 'adults' => $adults, 'children' => $children,
            'start_date' => $start !== '' ? $start : null, 'nights' => $nights, 'budget_max' => $budget, 'budget_basis' => $budget !== null ? 'total' : null,
            'requirements' => isset($in['notes']) && trim((string) $in['notes']) !== '' ? mb_substr(trim((string) $in['notes']), 0, 2000) : null,
        ], static fn ($v) => $v !== null), $userId);
        return ['trip' => $trip, 'contact_id' => $contactId, 'existing' => false];
    }
}
