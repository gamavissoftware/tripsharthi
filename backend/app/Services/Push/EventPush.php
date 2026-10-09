<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Services\Travel\TravelFlowContext;

/**
 * What each business event says on a lock screen, who hears it, and where tapping it goes.
 * Wording is short, specific and action-first. Each method is idempotent via its dedupe key.
 * `screen` names are the mobile app's screens (Trip, Booking, Contact, Notifications).
 */
final class EventPush
{
    private static function who(?array $contact): string
    {
        $n = trim((string) ($contact['name'] ?? ''));
        return $n !== '' ? $n : 'A customer';
    }

    private static function nav(string $screen, array $params): array
    {
        return ['screen' => $screen, 'params' => $params ?: new \stdClass()];
    }

    /** quote_viewed | quote_accepted */
    public static function quote(PushNotifier $n, int $tenantId, string $trigger, array $trip, array $itinerary, ?array $contact): void
    {
        $who = self::who($contact);
        [$title, $event] = $trigger === 'quote_accepted' ? ["✅ {$who} accepted your quote", 'quote_accepted'] : ["👀 {$who} opened your quote", 'quote_viewed'];
        $body = trim($itinerary['title'] . ' · ' . TravelFlowContext::money($itinerary['grand_total']));
        $n->notifyMany($tenantId, PushRecipients::ownerOrStaff($tenantId, $trip['owner_id'] ? (int) $trip['owner_id'] : null), 'quote', $event, $title, $body,
            self::nav('Trip', ['id' => (int) $trip['id']]), "{$event}:{$itinerary['id']}", '/trips/' . $trip['id']);
    }

    /** payment_received | payment_overdue */
    public static function payment(PushNotifier $n, int $tenantId, string $trigger, array $booking, array $payment, ?array $contact): void
    {
        $who = self::who($contact);
        $amount = TravelFlowContext::money($payment['amount']);
        [$title, $event] = $trigger === 'payment_overdue' ? ["⚠️ {$amount} overdue — {$who}", 'payment_overdue'] : ["💰 {$amount} received", 'payment_received'];
        $body = $trigger === 'payment_overdue' ? "{$booking['booking_ref']} · {$payment['label']} was due " . TravelFlowContext::date($payment['due_date']) : "{$who} · {$booking['booking_ref']} · {$payment['label']}";
        $n->notifyMany($tenantId, PushRecipients::ownerOrStaff($tenantId, $booking['owner_id'] ? (int) $booking['owner_id'] : null), 'payment', $event, $title, $body,
            self::nav('Booking', ['id' => (int) $booking['id']]), "{$event}:{$payment['id']}", '/bookings/' . $booking['id']);
    }

    public static function bookingConfirmed(PushNotifier $n, int $tenantId, array $booking, ?array $trip, ?array $contact): void
    {
        $who = self::who($contact);
        $n->notifyMany($tenantId, PushRecipients::ownerOrStaff($tenantId, $booking['owner_id'] ? (int) $booking['owner_id'] : null), 'booking', 'booking_confirmed', "🎉 Booking confirmed — {$who}",
            trim(($trip['destination_text'] ?? $booking['title']) . ' · ' . TravelFlowContext::money($booking['total_amount']) . ' · ' . $booking['booking_ref']),
            self::nav('Booking', ['id' => (int) $booking['id']]), 'booking_confirmed:' . $booking['id'], '/bookings/' . $booking['id']);
    }

    /** A brand-new lead from an ad, website form or email. $campaign = ad campaign name when known. */
    public static function lead(PushNotifier $n, int $tenantId, array $contact, string $source, ?string $campaign): void
    {
        $label = ['meta_lead_ads' => 'Facebook/Instagram lead ad', 'google_lead_forms' => 'Google lead form', 'web_form' => 'Website form', 'email_inbound' => 'Email enquiry', 'portal' => 'Travel portal enquiry', 'whatsapp_inbound' => 'WhatsApp'][$source] ?? 'New lead';
        $body = $campaign ? "{$label} · {$campaign}" : $label;
        $n->notifyMany($tenantId, PushRecipients::ownerOrStaff($tenantId, ! empty($contact['owner_id']) ? (int) $contact['owner_id'] : null), 'lead', 'lead_new', '🔔 New lead: ' . self::who($contact), $body,
            self::nav('Contact', ['id' => (int) $contact['id']]), 'lead:' . $contact['id'], '/contacts/' . $contact['id']);
    }

    /** First unread customer message. A contact created seconds ago by this very message is a NEW LEAD, not just a reply. */
    public static function reply(PushNotifier $n, int $tenantId, array $conv, string $preview, bool $newContact, ?array $contact): void
    {
        $who = $conv['contact_name'] ?: self::who($contact);
        $assignee = ! empty($conv['assigned_user_id']) ? (int) $conv['assigned_user_id'] : (! empty($contact['owner_id']) ? (int) $contact['owner_id'] : null);
        $text = mb_strlen($preview) > 110 ? mb_substr($preview, 0, 110) . '…' : $preview;
        $target = ! empty($conv['contact_id']) ? self::nav('Contact', ['id' => (int) $conv['contact_id']]) : self::nav('Notifications', []);
        $n->notifyMany($tenantId, PushRecipients::ownerOrStaff($tenantId, $assignee), $newContact ? 'lead' : 'reply', $newContact ? 'lead_new' : 'reply',
            ($newContact ? '🔔 New WhatsApp lead: ' : '💬 ') . $who, $text !== '' ? $text : 'Sent a message', $target,
            ($newContact ? 'lead:' : 'reply:' . ($conv['id'] ?? 0) . ':') . ($newContact ? (string) ($conv['contact_id'] ?? $conv['id'] ?? 0) : (string) ($conv['last_inbound_at'] ?? date('YmdHi'))), '/inbox');
    }
}
