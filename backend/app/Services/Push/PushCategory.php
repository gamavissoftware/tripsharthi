<?php

declare(strict_types=1);

namespace App\Services\Push;

/**
 * The notification categories a user can switch on/off. The key doubles as the Android notification-channel id,
 * so the OS settings screen shows the same names. `staff_only`: only owners/admins ever receive it.
 */
final class PushCategory
{
    public const ALL = [
        'lead'    => ['label' => 'New leads & enquiries',      'hint' => 'A new lead from your ads, website or WhatsApp — be first to reply.', 'importance' => 'high', 'staff_only' => false],
        'reply'   => ['label' => 'Customer replies',           'hint' => 'A customer wrote to you on WhatsApp.',                                'importance' => 'high', 'staff_only' => false],
        'quote'   => ['label' => 'Quote opened or accepted',   'hint' => 'Know the moment a customer looks at or accepts your quote.',          'importance' => 'high', 'staff_only' => false],
        'payment' => ['label' => 'Payments',                   'hint' => 'Money received, or an instalment gone overdue.',                      'importance' => 'high', 'staff_only' => false],
        'booking' => ['label' => 'Bookings & trips',           'hint' => 'Confirmed bookings and trip alerts.',                                 'importance' => 'default', 'staff_only' => false],
        'task'    => ['label' => 'Tasks & mentions',           'hint' => 'Tasks due and when a teammate mentions you.',                         'importance' => 'default', 'staff_only' => false],
        'ads'     => ['label' => 'Ad campaign alerts',         'hint' => 'A rule paused a campaign, or an ad account needs attention.',         'importance' => 'default', 'staff_only' => true],
        'target'  => ['label' => 'Sales target nudges',        'hint' => 'A friendly nudge when you are behind pace on your monthly target.',     'importance' => 'default', 'staff_only' => false],
        'digest'  => ['label' => 'Morning briefing',           'hint' => 'One summary each morning: departures, dues and tasks for today.',     'importance' => 'low', 'staff_only' => false],
    ];

    public static function valid(string $c): bool { return isset(self::ALL[$c]); }

    /** @return array<string,bool> every category on */
    public static function defaults(): array
    {
        return array_fill_keys(array_keys(self::ALL), true);
    }

    /** Map a legacy in-app notification `type` to a category. */
    public static function forLegacyType(string $type): string
    {
        return match ($type) { 'ad_rule', 'ad_issue' => 'ads', 'mention', 'task_due' => 'task', default => 'task' };
    }

    /** Lock-screen text when the user chose "minimal" privacy: no names, no amounts. */
    public static function genericText(string $category): array
    {
        return match ($category) {
            'lead' => ['New lead', 'Open TravelPilot to see who it is.'],
            'reply' => ['New message', 'A customer replied. Open TravelPilot to read it.'],
            'quote' => ['Quote update', 'A customer responded to a quote.'],
            'payment' => ['Payment update', 'There is a payment update on a booking.'],
            'booking' => ['Booking update', 'There is an update on a booking.'],
            'ads' => ['Ad alert', 'An ad campaign needs your attention.'],
            'target' => ['Sales target', 'Open TripSarthi to see how your month is going.'],
            'digest' => ['Your day', 'Open TravelPilot for today\'s briefing.'],
            default => ['TravelPilot', 'You have a new notification.'],
        };
    }
}
