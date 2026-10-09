<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\MeetingModel;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Bookable demo slots: business hours, minus what is already booked.
 *
 * Timezone is the whole difficulty here. The app and the database run in UTC,
 * but "9 to 6, Monday to Friday" means nine o'clock where the customer is.
 * Slots are therefore generated and labelled in the business timezone and
 * returned with a UTC timestamp for storage — never the other way round, or a
 * 10:00 slot is offered at 15:30 IST.
 *
 * Slot ids are self-describing ("slot:2026-09-17T10:00") so a selection coming
 * back from WhatsApp needs no server-side session to interpret.
 */
class SlotService
{
    public const ID_PREFIX = 'slot:';

    /** Demo length. A 20-minute demo in a 30-minute slot leaves room to overrun. */
    public const SLOT_MINUTES = 30;

    /** Never offer a slot sooner than this — nobody can take a call in 10 minutes. */
    private const LEAD_HOURS = 2;

    /** WhatsApp interactive lists allow at most 10 rows. */
    public const MAX_SLOTS = 10;

    /** How far ahead to look before giving up. */
    private const HORIZON_DAYS = 14;

    public function __construct(
        private BusinessHoursService $hours = new BusinessHoursService(),
        private MeetingModel $meetings = new MeetingModel(),
    ) {}

    /**
     * The next bookable slots.
     *
     * @return list<array{id:string, label:string, description:string, start_utc:string, end_utc:string}>
     */
    public function available(
        int $tenantId,
        int $limit = 6,
        string $timezone = 'Asia/Kolkata',
        ?int $nowTs = null
    ): array {
        $limit = max(1, min($limit, self::MAX_SLOTS));
        $cfg   = $this->hours->config($tenantId);
        $tz    = $this->timezone($timezone);

        $now    = (new DateTimeImmutable('@' . ($nowTs ?? time())))->setTimezone($tz);
        $cursor = $this->firstCandidate($now);
        $taken  = $this->bookedStarts($tenantId, $tz);

        $slots = [];
        $limitTs = $now->modify('+' . self::HORIZON_DAYS . ' days')->getTimestamp();

        while (count($slots) < $limit && $cursor->getTimestamp() < $limitTs) {
            if ($this->isBookable($cursor, $cfg)) {
                $key = $cursor->format('Y-m-d H:i');
                if (! isset($taken[$key])) {
                    $slots[] = $this->describe($cursor);
                }
            }
            $cursor = $cursor->modify('+' . self::SLOT_MINUTES . ' minutes');
        }

        return $slots;
    }

    /**
     * Turn a slot id back into its UTC window, or null if it is unparseable,
     * in the past, or outside business hours. Booking must re-check all three:
     * the id came from a phone and may arrive hours after it was offered.
     *
     * @return array{start_utc:string, end_utc:string, label:string}|null
     */
    public function resolve(
        int $tenantId,
        string $slotId,
        string $timezone = 'Asia/Kolkata',
        ?int $nowTs = null
    ): ?array {
        if (! str_starts_with($slotId, self::ID_PREFIX)) {
            return null;
        }

        $tz  = $this->timezone($timezone);
        $raw = substr($slotId, strlen(self::ID_PREFIX));

        $start = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw, $tz);
        if ($start === false || $start->format('Y-m-d\TH:i') !== $raw) {
            return null;
        }

        $now = (new DateTimeImmutable('@' . ($nowTs ?? time())))->setTimezone($tz);
        if ($start->getTimestamp() <= $now->getTimestamp()) {
            return null;
        }
        if (! $this->isBookable($start, $this->hours->config($tenantId))) {
            return null;
        }

        $described = $this->describe($start);

        return [
            'start_utc' => $described['start_utc'],
            'end_utc'   => $described['end_utc'],
            'label'     => $described['label'],
        ];
    }

    /** True when this exact start is already held by a scheduled meeting. */
    public function isTaken(int $tenantId, string $startUtc): bool
    {
        return $this->meetings->withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('start_at', $startUtc)
            ->where('status', 'scheduled')
            ->where('deleted_at', null)
            ->countAllResults() > 0;
    }

    // ------------------------------------------------------------------

    /**
     * Scheduled meeting starts, keyed by local "Y-m-d H:i" so they can be
     * compared against the generation cursor directly.
     *
     * start_at is stored UTC and the cursor walks in the business timezone, so
     * the conversion has to happen here — comparing the two raw would hide
     * every booking by the UTC offset.
     *
     * @return array<string, true>
     */
    private function bookedStarts(int $tenantId, DateTimeZone $tz): array
    {
        $rows = $this->meetings->withoutTenantScope()
            ->select('start_at')
            ->where('tenant_id', $tenantId)
            ->where('status', 'scheduled')
            ->where('deleted_at', null)
            ->findAll();

        $utc  = new DateTimeZone('UTC');
        $out  = [];
        foreach ($rows as $row) {
            $raw = is_array($row) ? ($row['start_at'] ?? '') : ($row->start_at ?? '');
            if ($raw === '' || $raw === null) {
                continue;
            }
            try {
                $out[(new DateTimeImmutable((string) $raw, $utc))->setTimezone($tz)->format('Y-m-d H:i')] = true;
            } catch (\Throwable) {
                continue;
            }
        }

        return $out;
    }

    private function timezone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (\Throwable) {
            // An unknown zone must not take the booking flow down with it.
            return new DateTimeZone('Asia/Kolkata');
        }
    }

    /** First candidate: lead time away, rounded up to the next slot boundary. */
    private function firstCandidate(DateTimeImmutable $now): DateTimeImmutable
    {
        $c       = $now->modify('+' . self::LEAD_HOURS . ' hours');
        $minutes = (int) $c->format('i');
        $step    = self::SLOT_MINUTES;
        $add     = ($step - ($minutes % $step)) % $step;

        return $c->modify("+{$add} minutes")->setTime((int) $c->format('H'), ($minutes + $add) % 60, 0);
    }

    /** @param array{start_hour:int, end_hour:int, workdays:int[]} $cfg */
    private function isBookable(DateTimeImmutable $ts, array $cfg): bool
    {
        if (! in_array((int) $ts->format('N'), $cfg['workdays'], true)) {
            return false;
        }

        $hour = (int) $ts->format('G');
        // The slot must END within business hours, not merely start inside them.
        $endsAt = $ts->modify('+' . self::SLOT_MINUTES . ' minutes');
        $endsSameDay = $endsAt->format('Y-m-d') === $ts->format('Y-m-d');

        return $hour >= $cfg['start_hour']
            && $endsSameDay
            && ($endsAt->format('G') < $cfg['end_hour']
                || ($endsAt->format('G') == $cfg['end_hour'] && (int) $endsAt->format('i') === 0));
    }

    /** @return array{id:string, label:string, description:string, start_utc:string, end_utc:string} */
    private function describe(DateTimeImmutable $start): array
    {
        $end = $start->modify('+' . self::SLOT_MINUTES . ' minutes');
        $utc = new DateTimeZone('UTC');

        return [
            'id'          => self::ID_PREFIX . $start->format('Y-m-d\TH:i'),
            // WhatsApp row titles cap at 24 characters — "Wed 17 Sep, 10:00 AM" fits.
            'label'       => $start->format('D j M, g:i A'),
            'description' => self::SLOT_MINUTES . '-minute demo',
            'start_utc'   => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'end_utc'     => $end->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }
}
