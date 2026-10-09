/**
 * Travel flow triggers — the ONE frontend definition (mirrors backend FlowTriggers::TRAVEL).
 * NodeMeta (builder), TRIGGER_CFG (flows list) and TRIGGER_LABELS (editor header) are all derived from this,
 * so a new trigger is added here once.
 */
import { Send, Eye, CheckCheck, Hourglass, BadgeCheck, Wallet, AlarmClock, Plane, PlaneTakeoff, PlaneLanding, IdCard } from 'lucide-react'

export const TRAVEL_TRIGGERS = [
  { key: 'quote_sent',        label: 'Quote Sent',        icon: Send,         desc: 'The itinerary link was shared with the customer', color: '#0369a1', bg: '#e0f2fe' },
  { key: 'quote_viewed',      label: 'Quote Viewed',      icon: Eye,          desc: 'Customer opened the quote link (first time)',     color: '#a16207', bg: '#fef9c3' },
  { key: 'quote_accepted',    label: 'Quote Accepted',    icon: CheckCheck,   desc: 'Customer tapped Accept on the quote',             color: '#15803d', bg: '#dcfce7' },
  { key: 'quote_stale',       label: 'Quote Gone Quiet',  icon: Hourglass,    desc: 'Quote sent N days ago and still no answer',       color: '#c2410c', bg: '#ffedd5' },
  { key: 'booking_confirmed', label: 'Booking Confirmed', icon: BadgeCheck,   desc: 'A booking was created from an accepted quote',    color: '#15803d', bg: '#dcfce7' },
  { key: 'payment_received',  label: 'Payment Received',  icon: Wallet,       desc: 'An instalment was paid (link, UPI or manual)',    color: '#15803d', bg: '#dcfce7' },
  { key: 'payment_overdue',   label: 'Payment Overdue',   icon: AlarmClock,   desc: 'An instalment slipped past its due date',         color: '#b91c1c', bg: '#fee2e2' },
  { key: 'departure_soon',    label: 'Departure Soon',    icon: Plane,        desc: 'N days before the trip starts',                   color: '#08569f', bg: '#e8f3fc' },
  { key: 'trip_started',      label: 'Trip Started',      icon: PlaneTakeoff, desc: 'Departure day',                                   color: '#0891b2', bg: '#cffafe' },
  { key: 'trip_completed',    label: 'Trip Completed',    icon: PlaneLanding, desc: 'The trip has ended',                              color: '#0e8f8c', bg: '#ede9fe' },
  { key: 'passport_expiring', label: 'Passport Expiring', icon: IdCard,       desc: 'Passport valid < 6 months after the trip ends',   color: '#b91c1c', bg: '#fee2e2' },
]

export const TRAVEL_TRIGGER_KEYS = TRAVEL_TRIGGERS.map(t => t.key)

/** Template variable sources available to every travel trigger (flow-run state). value = what the variable mapping stores. */
export const TRAVEL_STATE_VARS = [
  ['Trip', [['state:trip_destination', 'Destination'], ['state:trip_title', 'Trip title'], ['state:trip_start_date', 'Start date'], ['state:trip_end_date', 'End date'], ['state:trip_nights', 'Nights'], ['state:trip_travellers', 'Number of travellers']]],
  ['Quote', [['state:quote_total', 'Quote total'], ['state:quote_link', 'Quote link'], ['state:quote_title', 'Quote title'], ['state:quote_valid_until', 'Quote valid until'], ['state:quote_days_since_sent', 'Days since quote sent']]],
  ['Booking', [['state:booking_ref', 'Booking reference'], ['state:booking_total', 'Booking total'], ['state:booking_paid', 'Amount paid'], ['state:booking_due', 'Amount due'], ['state:booking_days_to_departure', 'Days to departure']]],
  ['Payment', [['state:payment_amount', 'Instalment amount'], ['state:payment_label', 'Instalment name'], ['state:payment_due_date', 'Instalment due date'], ['state:payment_link', 'Payment link']]],
  ['Traveller', [['state:traveler_name', 'Traveller name'], ['state:traveler_passport_expiry', 'Passport expiry']]],
]

/** Tokens usable in "Send Text" copy for travel triggers. */
export const TRAVEL_TOKENS = ['{{trip.destination}}', '{{trip.start_date}}', '{{trip.nights}}', '{{quote.total}}', '{{quote.link}}', '{{booking.ref}}', '{{booking.total}}', '{{booking.due}}', '{{booking.days_to_departure}}', '{{payment.amount}}', '{{payment.link}}', '{{traveler.name}}', '{{traveler.passport_expiry}}']
