<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\MeetingModel;
use App\Services\Flow\FlowTriggerService;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Fires the `meeting_reminder` flow trigger shortly before a scheduled meeting
 * starts, so a no-show becomes a reply instead of a wasted slot.
 *
 * Two things decide the shape of the scan:
 *
 *  1. It looks FORWARD from now, never backward. The band is
 *     (now, now + LEAD_MINUTES]. A meeting that has already started is outside
 *     it forever, so a cron that was down overnight wakes up and reminds nobody
 *     about yesterday — it just resumes with the next genuinely upcoming one.
 *     A late cron still delivers a useful reminder (the meeting has not started
 *     yet); an early one is impossible.
 *
 *  2. `reminder_sent` is written BEFORE the trigger fires. If the process dies
 *     between the two, the cost is one missed reminder rather than a duplicate
 *     — the right way round, because a second "your demo starts soon" half an
 *     hour before a demo is exactly the kind of message that gets a number
 *     reported.
 *
 * What the contact actually receives is decided by the flow listening on the
 * trigger, not here. That matters for policy: 30 minutes before a meeting
 * booked days ago the 24-hour window is usually shut, so the flow has to
 * window-check and fall back to an approved template. Putting the wording in a
 * flow also means it is editable on the Flows screen without a deploy.
 */
final class MeetingReminderService
{
    /** How long before the start time the reminder goes out. */
    public const LEAD_MINUTES = 30;

    public function __construct(
        private MeetingModel $meetings = new MeetingModel(),
    ) {}

    /**
     * @return array{due:int, fired:int, skipped:int}
     *         Meetings with no contact at all are filtered out in SQL and never
     *         counted; `skipped` catches the narrower case of a contact_id that
     *         is present but unusable (0), which should not happen.
     */
    public function run(int $tenantId, ?int $nowTs = null, string $timezone = 'Asia/Kolkata'): array
    {
        $utc = new DateTimeZone('UTC');
        $now = (new DateTimeImmutable('@' . ($nowTs ?? time())))->setTimezone($utc);

        $from  = $now->format('Y-m-d H:i:s');
        $until = $now->modify('+' . self::LEAD_MINUTES . ' minutes')->format('Y-m-d H:i:s');

        // withoutVisibilityScope: meetings are owner-scoped, and the scanner must
        // see every agent's, not just the caller's. Harmless from cron (no user),
        // load-bearing if this is ever invoked from an authenticated request.
        $due = $this->meetings->withoutVisibilityScope()->setTenant($tenantId)
            ->where('reminder_sent', 0)
            ->where('status', 'scheduled')
            ->where('contact_id IS NOT NULL', null, false)
            ->where('start_at >', $from)
            ->where('start_at <=', $until)
            ->orderBy('start_at', 'ASC')
            ->findAll();

        $fired   = 0;
        $skipped = 0;

        foreach ($due as $meeting) {
            $id        = (int) $meeting['id'];
            $contactId = (int) ($meeting['contact_id'] ?? 0);

            // Claim it first — see the class note on ordering.
            $this->meetings->withoutVisibilityScope()->setTenant($tenantId)
                ->update($id, ['reminder_sent' => 1]);

            if ($contactId <= 0) {
                $skipped++;
                continue;
            }

            FlowTriggerService::fire('meeting_reminder', $tenantId, $contactId, [
                'meeting_id'      => $id,
                'meeting_title'   => (string) ($meeting['title'] ?? 'Meeting'),
                'start_at'        => (string) ($meeting['start_at'] ?? ''),
                // Pre-formatted in the business timezone: the flow renders this
                // straight into a message and must never show a UTC wall clock.
                'meeting_time'    => $this->label((string) ($meeting['start_at'] ?? ''), $timezone),
                'meeting_minutes' => (string) $this->minutesUntil((string) ($meeting['start_at'] ?? ''), $now),
                // The tracked /meet/{id} redirect rather than the raw Meet code:
                // it survives the code rotating, and a click on it is what tells
                // the owner somebody is waiting in the lobby. Empty when no link
                // is configured, so the copy leaves the token visible rather
                // than offering a "join" that goes nowhere.
                'meeting_link'    => (string) ((new MeetingLinkService())->joinUrlFor($tenantId, $id) ?? ''),
            ]);
            $fired++;
        }

        return ['due' => count($due), 'fired' => $fired, 'skipped' => $skipped];
    }

    /**
     * "Thu 17 Sep, 10:30 AM" — the same wording SlotService offered the slot in,
     * so the reminder names the time the contact actually chose.
     */
    private function label(string $startUtc, string $timezone): string
    {
        if ($startUtc === '') {
            return '';
        }

        try {
            $tz = new DateTimeZone($timezone);
        } catch (\Exception) {
            $tz = new DateTimeZone('Asia/Kolkata');
        }

        try {
            return (new DateTimeImmutable($startUtc, new DateTimeZone('UTC')))
                ->setTimezone($tz)->format('D j M, g:i A');
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * Real minutes to go, not the nominal lead time. They differ whenever the
     * cron ran late, or when a meeting was created inside the reminder band —
     * and a message claiming "30 minutes" nine minutes beforehand is worse than
     * no message at all.
     */
    private function minutesUntil(string $startUtc, DateTimeImmutable $now): int
    {
        if ($startUtc === '') {
            return self::LEAD_MINUTES;
        }

        try {
            $start = new DateTimeImmutable($startUtc, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return self::LEAD_MINUTES;
        }

        return max(1, (int) round(($start->getTimestamp() - $now->getTimestamp()) / 60));
    }
}
