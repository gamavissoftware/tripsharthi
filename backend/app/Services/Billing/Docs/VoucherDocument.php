<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

use App\Models\BookingModel;
use App\Models\BookingServiceModel;
use App\Models\SupplierModel;
use App\Models\TravelerModel;

/** Service voucher for one booked service (hotel, transfer…). Shows NO cost and no payment status — it is what the traveller hands over. */
final class VoucherDocument
{
    private const KIND = ['hotel' => 'Hotel accommodation', 'transfer' => 'Transfer', 'sightseeing' => 'Sightseeing', 'activity' => 'Activity', 'flight' => 'Flight', 'train' => 'Train', 'visa' => 'Visa service', 'insurance' => 'Travel insurance', 'meal' => 'Meal'];

    /** @return array{pdf:string,filename:string} */
    public function render(int $tenantId, int $serviceId, bool $images = true): array
    {
        $svc = (new BookingServiceModel())->setTenant($tenantId)->find($serviceId);
        if (! $svc) { throw new \InvalidArgumentException('Service not found.'); }
        $b = (new BookingModel())->setTenant($tenantId)->find((int) $svc['booking_id']);
        $sup = $svc['supplier_id'] ? (new SupplierModel())->setTenant($tenantId)->find((int) $svc['supplier_id']) : null;
        $profile = (new BusinessProfileService())->get($tenantId);
        $trav = array_map(static fn ($t) => ['name' => (string) $t['full_name'], 'type' => ucfirst((string) $t['pax_type'])], (new TravelerModel())->setTenant($tenantId)->where('booking_id', (int) $b['id'])->findAll());

        $rows = array_values(array_filter([
            $svc['service_date'] ? ['Service date', DocFormat::date($svc['service_date'], 'D, d M Y')] : null,
            $b['travel_start'] ? ['Trip dates', DocFormat::date($b['travel_start']) . ($b['travel_end'] ? ' – ' . DocFormat::date($b['travel_end']) : '')] : null,
            ['Booking', $b['title'] . ' (' . $b['booking_ref'] . ')'],
        ]));
        $v = ['booking_ref' => $b['booking_ref'], 'kind' => self::KIND[$svc['service_type']] ?? 'Service', 'title' => (string) $svc['title'], 'supplier' => $sup['name'] ?? '',
            'supplier_contact' => $sup ? implode(' · ', array_filter([$sup['city'] ?? '', $sup['phone'] ?? ''])) : '', 'confirmation' => (string) ($svc['confirmation_no'] ?? ''),
            'status' => $svc['status'] === 'confirmed' ? 'Confirmed' : ($svc['status'] === 'cancelled' ? 'Cancelled' : 'Awaiting confirmation'), 'rows' => $rows, 'travellers' => $trav, 'notes' => (string) ($svc['notes'] ?? '')];
        $brand = ['color' => $profile['brand_color'] ?: '#0a6cc4', 'logo' => $images ? SafeImage::dataUri($profile['logo_url'], 500) : null, 'name' => $profile['trade_name'] ?: $profile['legal_name'] ?: ''];
        $seller = ['display_name' => $profile['trade_name'] ?: $profile['legal_name'] ?: '', 'phone' => DocFormat::phone((string) $profile['phone']), 'email' => $profile['email']];
        $pdf = PdfRenderer::render(view('pdf/voucher', ['brand' => $brand, 'seller' => $seller, 'v' => $v]), $b['booking_ref'] . ' · service voucher · ' . $seller['display_name'], 'Voucher ' . $svc['title']);
        return ['pdf' => $pdf, 'filename' => 'Voucher_' . $b['booking_ref'] . '_' . trim(preg_replace('/[^A-Za-z0-9]+/', '_', $svc['title']), '_') . '.pdf'];
    }
}
