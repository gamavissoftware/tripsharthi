<?php

declare(strict_types=1);

namespace App\Services\Flow;

/**
 * The ONE list of flow trigger types.
 *
 * It used to be copied into FlowTriggerService, FlowValidator, FlowEngine (twice), FlowsController's
 * validation rule, the `flows.trigger_type` ENUM and three frontend maps — and they had drifted
 * (e.g. `task_due` could not be stored; `date_reached` was rejected by the create API). Add a trigger
 * HERE, then add a migration widening the ENUM, then the frontend maps. A MySQL-backed test fails if the
 * ENUM and this list disagree.
 */
final class FlowTriggers
{
    /** Platform triggers inherited from LeadPilot. */
    public const CORE = [
        'lead_created', 'tag_added', 'form_submitted',
        'meta_lead_received', 'google_lead_received', 'keyword_reply', 'inbound_message',
        'order_placed', 'order_fulfilled', 'abandoned_cart', 'flow_response',
        'date_reached', 'task_due',
        'deal_created', 'deal_stage_changed', 'deal_won', 'deal_lost',
        'ticket_created', 'ticket_resolved',
        'meeting_scheduled', 'meeting_reminder', 'no_reply_followup', 'record_created',
    ];

    /**
     * Travel triggers. Each carries a context (see TravelFlowContext) that flows read as `state:*`
     * template variables and `{{trip.*}}`-style tokens in free-form copy.
     */
    public const TRAVEL = [
        'quote_sent',        // itinerary link shared with the customer (first time)
        'quote_viewed',      // customer opened the quote link (first view)
        'quote_accepted',    // customer tapped Accept
        'quote_stale',       // quote sent N days ago, no answer           (cron; config.days)
        'booking_confirmed', // booking created from an accepted quote
        'payment_received',  // an instalment was paid (link, UPI, manual)
        'payment_overdue',   // an instalment slipped past its due date
        'departure_soon',    // N days before departure                    (cron; config.days_before)
        'trip_started',      // travel start date reached
        'trip_completed',    // travel end date passed
        'passport_expiring', // traveller's passport too close to expiry   (cron; config.within_days)
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_unique([...self::CORE, ...self::TRAVEL]));
    }

    public static function isTravel(string $type): bool
    {
        return in_array($type, self::TRAVEL, true);
    }

    /** Comma-separated for a CodeIgniter `in_list[...]` rule. */
    public static function inListRule(): string
    {
        return implode(',', self::all());
    }
}
