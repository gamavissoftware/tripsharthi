<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\MeetingModel;
use App\Services\Flow\FlowTriggerService;

/**
 * Turns a slot chosen on WhatsApp into a meeting.
 *
 * The id arrives from a phone, so nothing about it can be trusted: it may be
 * malformed, it may be for a time that has since passed, and — most likely of
 * all — two people may tap the same slot. Every one of those is re-checked
 * here rather than at the point the slots were offered, because offering and
 * booking can be minutes or hours apart.
 *
 * A successful booking fires `meeting_scheduled`, so confirmation messages and
 * internal alerts are built as a flow rather than hard-coded here.
 */
class BookingService
{
    public function __construct(
        private SlotService  $slots    = new SlotService(),
        private MeetingModel $meetings = new MeetingModel(),
    ) {}

    /**
     * @return array{ok:bool, reason?:string, meeting_id?:int, label?:string, start_utc?:string}
     *         reason is one of: invalid_slot, slot_taken, insert_failed.
     */
    public function book(
        int $tenantId,
        int $contactId,
        string $slotId,
        string $title = 'Product demo',
        string $timezone = 'Asia/Kolkata',
        ?int $nowTs = null
    ): array {
        if ($tenantId <= 0 || $contactId <= 0) {
            return ['ok' => false, 'reason' => 'invalid_slot'];
        }

        $slot = $this->slots->resolve($tenantId, $slotId, $timezone, $nowTs);
        if ($slot === null) {
            return ['ok' => false, 'reason' => 'invalid_slot'];
        }

        // Idempotency before conflict: a double-tap, or WhatsApp redelivering
        // the same inbound, must return the original booking rather than a
        // "slot taken" error against the contact's own meeting.
        $mine = $this->meetings->withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->where('start_at', $slot['start_utc'])
            ->where('status', 'scheduled')
            ->where('deleted_at', null)
            ->first();

        if ($mine !== null) {
            return [
                'ok'         => true,
                'meeting_id' => (int) (is_array($mine) ? $mine['id'] : $mine->id),
                'label'      => $slot['label'],
                'start_utc'  => $slot['start_utc'],
                'reason'     => 'already_booked',
            ];
        }

        if ($this->slots->isTaken($tenantId, $slot['start_utc'])) {
            return ['ok' => false, 'reason' => 'slot_taken', 'label' => $slot['label']];
        }

        // Inherit the lead's owner. MeetingModel is ownership-scoped, so a
        // booking with no owner is invisible to an agent-role user — including
        // the very agent the lead is assigned to.
        $ownerId = $this->contactOwner($tenantId, $contactId);

        $meetingId = (int) $this->meetings->withoutTenantScope()->insert(array_filter([
            'tenant_id'  => $tenantId,
            'title'      => $title,
            'contact_id' => $contactId,
            'owner_id'   => $ownerId,
            'start_at'   => $slot['start_utc'],
            'end_at'     => $slot['end_utc'],
            'location'   => 'WhatsApp / video call',
            'notes'      => 'Booked by the contact from a WhatsApp slot list.',
            'status'     => 'scheduled',
        ], static fn ($v) => $v !== null), true);

        if ($meetingId <= 0) {
            return ['ok' => false, 'reason' => 'insert_failed'];
        }

        // Fired after the insert is committed, so a flow reacting to it sees
        // the meeting row. Confirmation wording lives in that flow, not here.
        FlowTriggerService::fire('meeting_scheduled', $tenantId, $contactId, [
            'meeting_id' => $meetingId,
            'slot_label' => $slot['label'],
            'start_at'   => $slot['start_utc'],
        ]);

        return [
            'ok'         => true,
            'meeting_id' => $meetingId,
            'label'      => $slot['label'],
            'start_utc'  => $slot['start_utc'],
        ];
    }

    /** The agent who owns this lead, if any. */
    private function contactOwner(int $tenantId, int $contactId): ?int
    {
        try {
            $row = db_connect()->table('contacts')
                ->select('owner_id')
                ->where('id', $contactId)->where('tenant_id', $tenantId)
                ->get()->getRowArray();
        } catch (\Throwable) {
            return null;
        }

        $ownerId = (int) ($row['owner_id'] ?? 0);

        return $ownerId > 0 ? $ownerId : null;
    }

    /** True when an inbound reply id is a slot selection. */
    public static function isSlotSelection(?string $buttonId): bool
    {
        return is_string($buttonId) && str_starts_with($buttonId, SlotService::ID_PREFIX);
    }
}
