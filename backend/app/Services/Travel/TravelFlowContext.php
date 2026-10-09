<?php

declare(strict_types=1);

namespace App\Services\Travel;

use App\Services\Email\EmailService;

/**
 * What a travel flow can say about a trip. Everything is PRE-FORMATTED (₹ amounts, readable dates, public
 * links) because a flow renders these straight into a customer message — the same rule the meeting
 * reminder follows. Keys become flow-run state: templates read them as `state:trip_destination`, free-form
 * copy as `{{trip.destination}}`. Customer-safe by construction: no cost, margin or supplier data, ever.
 */
final class TravelFlowContext
{
    /** Token groups the flow engine will resolve in free-form copy ({{group.key}} -> state[group_key]). */
    public const TOKEN_GROUPS = ['trip', 'quote', 'booking', 'payment', 'traveler'];

    public static function money(int|string|null $paise): string
    {
        return '₹' . number_format(((int) $paise) / 100, 0, '.', ',');
    }

    public static function date(?string $d): string
    {
        return $d ? date('j M Y', strtotime($d)) : '';
    }

    public static function quoteUrl(?string $token): string
    {
        return $token ? EmailService::appUrl() . '/#/q/' . $token : '';
    }

    public static function trip(array $t): array
    {
        $pax = (int) $t['adults'] + (int) ($t['children'] ?? 0);
        return [
            'trip_id'          => (int) $t['id'],
            'trip_title'       => (string) $t['title'],
            'trip_destination' => (string) ($t['destination_text'] ?? ''),
            'trip_start_date'  => self::date($t['start_date'] ?? null),
            'trip_end_date'    => self::date($t['end_date'] ?? null),
            'trip_nights'      => (string) ($t['nights'] ?? ''),
            'trip_travellers'  => (string) $pax,
            // raw values used only for flow filters (config.international / config.trip_type)
            'is_international' => (int) ($t['is_international'] ?? 0),
            'trip_type'        => (string) ($t['trip_type'] ?? ''),
        ];
    }

    public static function quote(array $it, ?int $now = null): array
    {
        return [
            'quote_id'          => (int) $it['id'],
            'quote_title'       => (string) $it['title'],
            'quote_total'       => self::money($it['grand_total'] ?? 0),
            'quote_link'        => self::quoteUrl($it['share_token'] ?? null),
            'quote_valid_until' => self::date($it['valid_until'] ?? null),
            'quote_days_since_sent' => $it['sent_at'] ? (string) max(0, (int) floor((($now ?? time()) - strtotime($it['sent_at'])) / 86400)) : '',
        ];
    }

    /** Calendar date in India for a unix time (default: now) — the product's day boundary, matching the trigger scanners. */
    private static function istDate(?int $now): string { return (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new \DateTimeZone('Asia/Kolkata'))->format('Y-m-d'); }

    public static function booking(array $b, ?int $now = null): array
    {
        $due = max(0, (int) $b['total_amount'] - (int) $b['paid_amount']);
        $days = $b['travel_start'] ? (int) ceil((strtotime($b['travel_start']) - strtotime(self::istDate($now))) / 86400) : null;
        return [
            'booking_id'      => (int) $b['id'],
            'booking_ref'     => (string) $b['booking_ref'],
            'booking_total'   => self::money($b['total_amount']),
            'booking_paid'    => self::money($b['paid_amount']),
            'booking_due'     => self::money($due),
            'booking_days_to_departure' => $days === null ? '' : (string) max(0, $days),
            'is_international' => (int) ($b['is_international'] ?? 0),
        ];
    }

    public static function payment(array $p, ?array $link = null): array
    {
        return [
            'payment_id'       => (int) $p['id'],
            'payment_label'    => (string) $p['label'],
            'payment_amount'   => self::money($p['amount']),
            'payment_due_date' => self::date($p['due_date'] ?? null),
            'payment_link'     => (string) ($link['short_url'] ?? ''),
        ];
    }

    public static function traveler(array $t): array
    {
        return [
            'traveler_name'            => (string) $t['full_name'],
            'traveler_passport_expiry' => self::date($t['passport_expiry'] ?? null),
        ];
    }
}
