<?php

declare(strict_types=1);

namespace App\Services\Billing\Docs;

use App\Models\ContactModel;
use App\Models\ItineraryModel;
use App\Models\TripModel;
use App\Services\Travel\ItineraryService;
use App\Services\Travel\PricingService;
use App\Services\Travel\TravelFlowContext;

/** Customer-facing quote PDF. Built ONLY from the customer-safe itinerary payload: cost, margin and suppliers never reach the template. */
final class QuoteDocument
{
    private const TAG = ['hotel' => 'STAY', 'flight' => 'FLIGHT', 'train' => 'TRAIN', 'transfer' => 'TRANSFER', 'sightseeing' => 'SIGHTSEEING', 'activity' => 'ACTIVITY', 'meal' => 'MEAL', 'visa' => 'VISA', 'insurance' => 'INSURANCE', 'other' => 'INCLUDED'];

    /** Template data. Public so tests can assert on it without rendering. @return array<string,mixed> */
    public function data(int $tenantId, int $itineraryId, bool $withImages = true): array
    {
        $it = (new ItineraryService())->full($tenantId, $itineraryId, true);
        if (! $it) { throw new \InvalidArgumentException('Itinerary not found.'); }
        $raw  = (new ItineraryModel())->setTenant($tenantId)->find($itineraryId);   // share_token + dates live on the row
        $trip = $it['trip_id'] ? (new TripModel())->setTenant($tenantId)->find((int) $it['trip_id']) : null;
        $contact = $trip && $trip['contact_id'] ? (new ContactModel())->setTenant($tenantId)->find((int) $trip['contact_id']) : null;
        $profile = (new BusinessProfileService())->get($tenantId);

        $pax = max(1, (int) $it['adults'] + (int) $it['children']);
        $total = (int) $it['grand_total'];
        $tag = static fn (string $t): string => self::TAG[$t] ?? 'INCLUDED';
        $mapItem = static fn (array $i): array => ['tag' => $tag((string) $i['type']), 'title' => (string) $i['title'], 'details' => (string) ($i['details'] ?? ''), 'nights' => $i['type'] === 'hotel' ? (int) $i['nights'] : 0];

        $days = [];
        foreach ($it['days'] as $d) {
            $days[] = ['no' => (int) $d['day_no'], 'title' => (string) $d['title'], 'city' => (string) ($d['city'] ?? ''), 'description' => (string) ($d['description'] ?? ''),
                'image' => $withImages ? SafeImage::dataUri($d['image'] ?? null, 900) : null, 'items' => array_map($mapItem, $d['items'])];
        }
        $lines = static fn (?string $t): array => array_values(array_filter(array_map(static fn ($l) => trim(ltrim(trim($l), "-•*· \t")), preg_split('/\R/', (string) $t))));

        $start = $trip['start_date'] ?? null; $end = $trip['end_date'] ?? null;
        $facts = array_values(array_filter([
            $contact ? ['Prepared for', (string) $contact['name']] : null,
            ! empty($trip['destination_text']) ? ['Destination', (string) $trip['destination_text']] : null,
            $start ? ['Travel dates', DocFormat::date($start) . ($end ? ' – ' . DocFormat::date($end) : '')] : (! empty($trip['travel_month']) ? ['Travel month', (string) $trip['travel_month']] : null),
            ['Duration', (int) $it['nights'] . ' nights / ' . ((int) $it['nights'] + 1) . ' days'],
            ['Travellers', (int) $it['adults'] . ' adult' . ((int) $it['adults'] > 1 ? 's' : '') . ((int) $it['children'] ? ', ' . (int) $it['children'] . ' child' . ((int) $it['children'] > 1 ? 'ren' : '') : '')],
            ! empty($trip['origin_city']) ? ['Departing from', (string) $trip['origin_city']] : null,
        ]));

        $defaultTerms = [
            ['Validity & availability', "This quote is valid until " . ($raw['valid_until'] ? DocFormat::date($raw['valid_until']) : 'the date shown above') . ". Hotel and service availability and rates are confirmed only at the time of booking."],
        ];
        $terms = $defaultTerms;
        if (! empty($profile['cancellation_policy'])) { $terms[] = ['Cancellation & refund policy', (string) $profile['cancellation_policy']]; }
        if (! empty($profile['quote_terms'])) { $terms[] = ['Other terms', (string) $profile['quote_terms']]; }

        $accept = ['url' => null, 'qr' => null];
        if (! empty($raw['share_token'])) { $accept['url'] = TravelFlowContext::quoteUrl($raw['share_token']); $accept['qr'] = Qr::dataUri($accept['url']); }

        $sellerState = $profile['state_code'];
        return [
            'brand'  => ['color' => $profile['brand_color'] ?: '#0a6cc4', 'logo' => $withImages ? SafeImage::dataUri($profile['logo_url'], 500) : null, 'name' => $profile['trade_name'] ?: $profile['legal_name'] ?: 'Travel quotation'],
            'seller' => ['display_name' => $profile['trade_name'] ?: $profile['legal_name'] ?: '', 'address' => DocFormat::addressLines($profile), 'phone' => $profile['phone'], 'email' => $profile['email'], 'website' => $profile['website'], 'gstin' => $profile['gstin']],
            'quote'  => ['number' => 'QT-' . $it['id'] . '-v' . $it['version'], 'date' => DocFormat::date($raw['sent_at'] ?: $raw['created_at']), 'valid_until' => $raw['valid_until'] ? DocFormat::date($raw['valid_until']) : null,
                'kicker' => $trip ? str_replace('_', ' ', (string) $trip['trip_type']) . ' · ' . ((int) $trip['is_international'] ? 'International' : 'Domestic') : 'Holiday package',
                'title' => (string) $it['title'], 'subtitle' => (string) ($it['subtitle'] ?? ''), 'cover' => $withImages ? SafeImage::dataUri($it['cover_image'] ?? null, 1100) : null, 'pax' => $pax, 'intro' => null],
            'facts'  => $facts, 'days' => $days, 'extras' => array_map($mapItem, $it['extras'] ?? []),
            'inclusions' => $lines($it['inclusions'] ?? ''), 'exclusions' => $lines($it['exclusions'] ?? ''),
            'pricing' => ['subtotal' => (int) $it['sell_subtotal'], 'discount' => (int) $it['discount_amount'], 'gst_rate' => (float) $it['gst_rate'], 'gst' => (int) $it['gst_amount'],
                'tcs_rate' => (float) $it['tcs_rate'], 'tcs' => (int) $it['tcs_amount'], 'total' => $total, 'per_person' => intdiv($total, $pax),
                'fx' => $it['fx_display'] ?? null],
            'schedule' => array_map(static fn ($r) => ['label' => $r['label'], 'due' => DocFormat::date($r['due_date']), 'amount' => (int) $r['amount']],
                $total > 0 ? PricingService::paymentSchedule($total, date('Y-m-d'), $start, 30, 2) : []),
            'terms'  => $terms, 'accept' => $accept, 'seller_state' => $sellerState,
        ];
    }

    /**
     * Cached on (itinerary last change + business profile last change): the public quote link can be hit by anyone
     * and a render downloads images, so repeat requests must not repeat the work.
     * @return array{pdf:string,filename:string}
     */
    public function render(int $tenantId, int $itineraryId): array
    {
        $raw = (new ItineraryModel())->setTenant($tenantId)->find($itineraryId);
        $prof = (new BusinessProfileService())->get($tenantId);
        $cacheFile = $raw ? WRITEPATH . 'cache/quote-pdf/' . sha1("{$tenantId}|{$itineraryId}|{$raw['updated_at']}|{$raw['share_token']}|" . ($prof['updated_at'] ?? '') . '|' . date('Y-m-d')) . '.pdf' : null;
        $d = $this->data($tenantId, $itineraryId);
        $safe = trim(preg_replace('/[^A-Za-z0-9._-]+/', '_', $d['quote']['title']), '_');
        $filename = $safe . '_' . $d['quote']['number'] . '.pdf';
        if ($cacheFile && is_file($cacheFile)) { return ['pdf' => (string) file_get_contents($cacheFile), 'filename' => $filename]; }
        $html = view('pdf/quote', $d);
        $pdf = PdfRenderer::render($html, $d['quote']['number'] . ' · ' . $d['seller']['display_name'], $d['quote']['title']);
        if ($cacheFile) {
            if (! is_dir(dirname($cacheFile))) { @mkdir(dirname($cacheFile), 0755, true); }
            @file_put_contents($cacheFile, $pdf);
        }
        return ['pdf' => $pdf, 'filename' => $filename];
    }
}
