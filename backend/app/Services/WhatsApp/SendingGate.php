<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\PhoneNumberModel;

/**
 * The handbrake: answers "may this tenant send MARKETING right now?".
 *
 * `waba:quality-sync` has always written phone_numbers.quality_rating every six
 * hours, and until now nothing read it. A number could slide from green to red
 * overnight and every campaign, drip and follow-up would keep firing at full
 * rate into a number Meta was already restricting. That is survivable while a
 * human watches each send; it is not survivable unattended, which is the whole
 * point of running this on cron.
 *
 * Deliberate choices:
 *
 *  - YELLOW BLOCKS, not just red. Yellow is Meta saying quality has already
 *    dropped. Waiting for red means waiting for the damage to be done — yellow
 *    is precisely the moment a person should look before anything else sends.
 *
 *  - UNKNOWN DOES NOT BLOCK. 'unknown' is the column default and is also what a
 *    failed sync leaves behind. Halting the business because an API call timed
 *    out would be a worse failure than the one being guarded against, so it
 *    passes with a log line instead.
 *
 *  - UTILITY IS NEVER BLOCKED. A meeting reminder is a promise already made to
 *    somebody who booked a demo; silently dropping it damages the relationship
 *    that the quality rating exists to protect. Only marketing is throttled
 *    here, and marketing is what moves the rating.
 *
 *  - THE WORST NUMBER WINS. A tenant sending from several numbers is gated by
 *    the unhealthiest of them, because they share one WABA reputation.
 */
final class SendingGate
{
    /** Ratings that stop marketing. */
    public const DEGRADED = ['yellow', 'red'];

    public function __construct(
        private ?PhoneNumberModel $numbers = null,
    ) {
        $this->numbers ??= new PhoneNumberModel();
    }

    /** True when marketing may go out for this tenant. */
    public function allowsMarketing(int $tenantId): bool
    {
        return $this->blockedReason($tenantId) === null;
    }

    /**
     * Why marketing is blocked, or null when it is fine.
     *
     * Returned as a sentence because every caller logs it, and a log line that
     * only says "blocked" sends whoever reads it back to the database.
     */
    public function blockedReason(int $tenantId): ?string
    {
        if ($tenantId <= 0) {
            return null;
        }

        try {
            $rows = $this->numbers->withoutTenantScope()
                ->where('tenant_id', $tenantId)
                ->findAll();
        } catch (\Throwable $e) {
            // Never let a database hiccup halt sending — that would turn a
            // transient fault into an outage.
            log_message('error', '[SendingGate] rating lookup failed, allowing send: ' . $e->getMessage());

            return null;
        }

        $worst = null;
        foreach ($rows as $row) {
            $rating = strtolower(trim((string) ($row['quality_rating'] ?? '')));
            if (in_array($rating, self::DEGRADED, true)) {
                // red outranks yellow.
                if ($worst === null || $rating === 'red') {
                    $worst = ['rating' => $rating, 'number' => (string) ($row['display_number'] ?? '?')];
                }
            }
        }

        if ($worst === null) {
            return null;
        }

        return sprintf(
            'Marketing paused: %s quality rating is %s. Sending resumes automatically once Meta restores it to green.',
            $worst['number'],
            strtoupper($worst['rating'])
        );
    }
}
