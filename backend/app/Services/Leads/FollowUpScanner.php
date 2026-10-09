<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Services\Flow\FlowTriggerService;

/**
 * Finds prospects who were sent a marketing template and never answered, and
 * fires `no_reply_followup` so a flow can nudge them.
 *
 * This is the most dangerous automation in the product, so most of the code
 * here is refusals rather than sends. A follow-up is, by definition, a paid
 * marketing template to a closed 24-hour window, aimed at somebody who already
 * ignored us once. Do that carelessly at this list's size and the outcome is
 * not a poor response rate, it is a restricted sending number.
 *
 * Every gate below exists for a specific reason:
 *
 *  - DELIVERED OR READ ONLY. On this account roughly half of all cold sends
 *    fail outright — wrong number, no WhatsApp, blocked. Chasing a send that
 *    never arrived spends money to talk to nobody and pushes failure rates up,
 *    which is itself a quality signal Meta watches.
 *
 *  - MARKETING ONLY. A meeting reminder or an internal alert is also an
 *    outbound template. Following one of those up with a sales nudge would be
 *    both baffling to the customer and a policy problem.
 *
 *  - NO INBOUND SINCE THE SEND. "Didn't respond" has to mean since this
 *    message, not ever: somebody who replied last month and ignored this one
 *    still deserves a nudge, and somebody who replied an hour ago must never
 *    get one.
 *
 *  - OPT-IN. WebhookService sets opt_in = 0 the moment anyone replies STOP, so
 *    this single check honours every opt-out the platform has ever seen.
 *
 *  - A HARD CAP PER CONTACT, and a HARD CAP PER DAY. Fatigue is what produces
 *    Block taps, and Block is what destroys a number's quality rating. Two
 *    nudges is a follow-up; five is harassment.
 *
 * The row in follow_ups is written BEFORE the trigger fires and carries a
 * unique key on (source_message_id, step), so a double run, an overlapping
 * cron or a crash mid-loop can never produce a second nudge for the same send.
 */
final class FollowUpScanner
{
    /** Wait this long after the original send before nudging. */
    public const MIN_AGE_DAYS = 3;

    /** Never resurrect an ancient campaign; past this the moment has gone. */
    public const MAX_AGE_DAYS = 30;

    /** Total nudges any one contact may ever receive. */
    public const MAX_STEPS = 2;

    /** Gap between the first nudge and the second. */
    public const STEP_GAP_DAYS = 7;

    /** Default ceiling per tenant per calendar day. Override with --limit. */
    public const DAILY_LIMIT = 200;

    /** How long a throttled claim is held before the contact becomes eligible again. */
    public const RETRY_COOLDOWN_DAYS = 7;

    /**
     * Meta errors that mean "not this time" rather than "not this person".
     *
     * #131049 is the per-recipient marketing cap — it is Meta rate-limiting how
     * much marketing that human receives across every business, and on this
     * account it is 72% of all send failures. The prospect never saw the
     * message, so charging it against their two-nudge allowance would quietly
     * spend most of the follow-up capacity on messages nobody read.
     *
     * #131026 is deliberately NOT here. That means the number is not on
     * WhatsApp, which no amount of waiting fixes.
     */
    private const RETRYABLE_ERRORS = ['#131049', '#131000'];

    /**
     * @param  bool $dryRun List who would be nudged, write nothing, send nothing.
     * @return array{eligible:int, fired:int, skipped_cap:int, capped_daily:bool, blocked?:string, preview:list<array>}
     */
    public function run(
        int $tenantId,
        ?int $limit = null,
        bool $dryRun = false,
        ?int $nowTs = null
    ): array {
        $now   = $nowTs ?? time();
        $limit = $limit ?? self::DAILY_LIMIT;

        $released = $dryRun ? 0 : $this->releaseThrottledClaims($tenantId, $now);

        // The handbrake, checked before anything is claimed. A dry run still
        // reports what WOULD go out, because seeing the list is exactly what a
        // person needs while deciding whether to wait for the rating to recover.
        $blocked = (new \App\Services\WhatsApp\SendingGate())->blockedReason($tenantId);
        if ($blocked !== null && ! $dryRun) {
            log_message('warning', '[FollowUp] scan halted — ' . $blocked);

            return ['eligible' => 0, 'fired' => 0, 'skipped_cap' => 0,
                    'capped_daily' => false, 'blocked' => $blocked, 'preview' => []];
        }

        $remaining = $dryRun ? $limit : max(0, $limit - $this->sentToday($tenantId, $now));
        if ($remaining <= 0) {
            return ['eligible' => 0, 'fired' => 0, 'skipped_cap' => 0,
                    'capped_daily' => true, 'preview' => []];
        }

        $candidates = $this->candidates($tenantId, $now, $remaining);

        $fired      = 0;
        $skippedCap = 0;
        $preview    = [];

        foreach ($candidates as $row) {
            $contactId = (int) $row['contact_id'];
            $messageId = (int) $row['message_id'];

            $priorSteps = $this->stepsSoFar($tenantId, $contactId);
            if ($priorSteps >= self::MAX_STEPS) {
                $skippedCap++;
                continue;
            }

            $step = $priorSteps + 1;

            // The second nudge has to be a decent interval after the first, not
            // a decent interval after the original send.
            if ($step > 1 && ! $this->lastStepIsOldEnough($tenantId, $contactId, $now)) {
                continue;
            }

            $hook = FollowUpHooks::resolve(
                $row['template_name'] ?? null,
                $this->categoriesFor($contactId)
            );

            $preview[] = [
                'contact_id' => $contactId,
                'name'       => $row['name'] ?? '',
                'wa_number'  => $row['wa_number'] ?? '',
                'template'   => $row['template_name'] ?? '',
                'step'       => $step,
                'hook'       => $hook,
            ];

            if ($dryRun) {
                $fired++;
                continue;
            }

            // Claim first. The unique key on (source_message_id, step) is the
            // real guard — if a parallel run got here first this throws and we
            // move on rather than sending twice.
            if (! $this->claim($tenantId, $contactId, $messageId, (int) ($row['template_id'] ?? 0), $step, $hook)) {
                continue;
            }

            FlowTriggerService::fire('no_reply_followup', $tenantId, $contactId, [
                'source_message_id'  => $messageId,
                'source_template_id' => (int) ($row['template_id'] ?? 0),
                'source_template'    => (string) ($row['template_name'] ?? ''),
                'followup_step'      => (string) $step,
                // What the flow renders into the template's {{2}}.
                'followup_hook'      => $hook,
            ]);
            $fired++;
        }

        return [
            'eligible'     => count($candidates),
            'fired'        => $fired,
            'skipped_cap'  => $skippedCap,
            'capped_daily' => false,
            'released'     => $released,
            'preview'      => $preview,
        ];
    }

    // ------------------------------------------------------------------

    /**
     * Hands back the allowance spent on messages Meta refused to deliver.
     *
     * A claim is written before the send, which is what makes a duplicate
     * impossible — but it means a send Meta throttled still consumes one of the
     * contact's two nudges for a message they never received. This releases
     * those claims once the cooldown has passed, so the contact is reconsidered
     * on a later run rather than being written off.
     *
     * The cooldown is the point. Retrying a throttled recipient the next
     * morning just hits the same cap and teaches Meta nothing has changed;
     * waiting a week is what gives their per-recipient allowance time to
     * recover.
     *
     * The claim is matched to its send by "first outbound marketing template to
     * that contact at or after the claim", which is exact in practice because
     * the flow sends within a minute of the trigger and the scanner never
     * claims the same contact twice in one run.
     *
     * @return int claims released
     */
    public function releaseThrottledClaims(int $tenantId, ?int $nowTs = null): int
    {
        $db  = db_connect();
        $p   = $db->DBPrefix;
        $now = $nowTs ?? time();

        $cutoff = $db->escape(date('Y-m-d H:i:s', $now - self::RETRY_COOLDOWN_DAYS * 86400));

        $errorLike = implode(
            ' OR ',
            array_map(static fn ($code) => "m.error LIKE " . db_connect()->escape('%' . $code . '%'), self::RETRYABLE_ERRORS)
        );

        $rows = $db->table('follow_ups f')
            ->select('f.id')
            ->where('f.tenant_id', $tenantId)
            ->where('f.deleted_at', null)
            ->where("f.created_at <= {$cutoff}", null, false)
            ->where(
                "EXISTS (SELECT 1 FROM {$p}messages m"
                . " WHERE m.contact_id = f.contact_id AND m.direction = 'out'"
                . " AND m.type = 'template' AND m.category = 'marketing'"
                . " AND m.created_at >= f.created_at"
                . " AND m.status = 'failed' AND ({$errorLike})"
                . " AND m.id = (SELECT MIN(m2.id) FROM {$p}messages m2"
                . "   WHERE m2.contact_id = f.contact_id AND m2.direction = 'out'"
                . "   AND m2.type = 'template' AND m2.category = 'marketing'"
                . "   AND m2.created_at >= f.created_at))",
                null,
                false
            )
            ->get()->getResultArray();

        if ($rows === []) {
            return 0;
        }

        // Deleted outright, not soft-deleted. The unique key on
        // (source_message_id, step) does not care about deleted_at, so a
        // tombstone would keep occupying the slot and the contact could never
        // actually be re-claimed — the release would look like it worked and
        // silently do nothing. The attempt is not lost: the failed send is
        // still on the messages row, with Meta's error code on it.
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        $db->table('follow_ups')->whereIn('id', $ids)->delete();

        log_message('info', '[FollowUp] released ' . count($ids)
            . ' throttled claim(s) — those contacts are eligible again.');

        return count($ids);
    }

    /**
     * The one query that decides who hears from us. Every clause is a gate
     * described in the class docblock.
     *
     * The source template is resolved through the campaign that sent it.
     * A template sent by a flow carries no campaign, so its name comes back
     * NULL and the hook falls back to the contact's category — still specific
     * to their industry, just not to the exact message.
     *
     * @return list<array<string,mixed>>
     */
    private function candidates(int $tenantId, int $now, int $limit): array
    {
        $db = db_connect();
        $p  = $db->DBPrefix;

        $cutoffNew = $db->escape(date('Y-m-d H:i:s', $now - self::MIN_AGE_DAYS * 86400));
        $cutoffOld = $db->escape(date('Y-m-d H:i:s', $now - self::MAX_AGE_DAYS * 86400));

        $rows = $db->table('messages m')
            ->select('m.id AS message_id, m.contact_id, t.id AS template_id, t.name AS template_name,'
                . ' c.name, c.wa_number, COALESCE(m.sent_at, m.created_at) AS sent_at')
            ->join("{$p}contacts c", 'c.id = m.contact_id', 'inner')
            ->join("{$p}campaigns cp", 'cp.id = m.campaign_id', 'left')
            ->join("{$p}templates t", 't.id = cp.template_id', 'left')
            ->where('m.tenant_id', $tenantId)
            ->where('m.direction', 'out')
            ->where('m.type', 'template')
            // Sales outreach only — never a utility reminder or an alert.
            ->where('m.category', 'marketing')
            // It has to have actually arrived. Half of cold sends do not.
            ->whereIn('m.status', ['delivered', 'read'])
            ->where('c.deleted_at', null)
            // STOP is honoured platform-side by flipping opt_in.
            ->where('c.opt_in', 1)
            ->where("COALESCE(m.sent_at, m.created_at) <= {$cutoffNew}", null, false)
            ->where("COALESCE(m.sent_at, m.created_at) >= {$cutoffOld}", null, false)
            // Silence since this message — not silence forever.
            ->where(
                "NOT EXISTS (SELECT 1 FROM {$p}messages r"
                . " WHERE r.contact_id = m.contact_id AND r.direction = 'in'"
                . " AND r.created_at >= COALESCE(m.sent_at, m.created_at))",
                null,
                false
            )
            // Never chase the same send twice.
            ->where(
                "NOT EXISTS (SELECT 1 FROM {$p}follow_ups f"
                . " WHERE f.source_message_id = m.id AND f.deleted_at IS NULL)",
                null,
                false
            )
            ->orderBy('sent_at', 'ASC')
            // Fetch wider than the cap, because several rows may belong to one
            // contact and only the oldest survives the dedupe below.
            ->limit(max($limit * 4, $limit))
            ->get()->getResultArray();

        // One nudge per person per run, whichever of their unanswered sends is
        // oldest. Two campaigns to the same contact must not mean two nudges.
        $byContact = [];
        foreach ($rows as $row) {
            $byContact[(int) $row['contact_id']] ??= $row;
        }

        return array_slice(array_values($byContact), 0, $limit);
    }

    /** Nudges already recorded for this contact, across all campaigns. */
    private function stepsSoFar(int $tenantId, int $contactId): int
    {
        return db_connect()->table('follow_ups')
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->where('deleted_at', null)
            ->countAllResults();
    }

    private function lastStepIsOldEnough(int $tenantId, int $contactId, int $now): bool
    {
        $row = db_connect()->table('follow_ups')
            ->select('created_at')
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->where('deleted_at', null)
            ->orderBy('created_at', 'DESC')
            ->limit(1)->get()->getRowArray();

        if ($row === null || empty($row['created_at'])) {
            return true;
        }

        return strtotime((string) $row['created_at']) <= $now - self::STEP_GAP_DAYS * 86400;
    }

    private function sentToday(int $tenantId, int $now): int
    {
        return db_connect()->table('follow_ups')
            ->where('tenant_id', $tenantId)
            ->where('created_at >=', date('Y-m-d 00:00:00', $now))
            ->where('deleted_at', null)
            ->countAllResults();
    }

    /** @return string[] tag names on this contact. */
    private function categoriesFor(int $contactId): array
    {
        $db = db_connect();
        $p  = $db->DBPrefix;

        $rows = $db->table('contact_tags ct')
            ->select('t.name')
            ->join("{$p}tags t", 't.id = ct.tag_id', 'inner')
            ->where('ct.contact_id', $contactId)
            ->where('t.deleted_at', null)
            ->get()->getResultArray();

        return array_column($rows, 'name');
    }

    /** False when another run already claimed this (source_message_id, step). */
    private function claim(
        int $tenantId,
        int $contactId,
        int $messageId,
        int $templateId,
        int $step,
        string $hook
    ): bool {
        try {
            db_connect()->table('follow_ups')->insert([
                'tenant_id'          => $tenantId,
                'contact_id'         => $contactId,
                'source_message_id'  => $messageId,
                'source_template_id' => $templateId > 0 ? $templateId : null,
                'step'               => $step,
                'hook'               => mb_substr($hook, 0, 255),
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (\Throwable $e) {
            log_message('info', '[FollowUp] claim refused for message #' . $messageId
                . ' step ' . $step . ': ' . $e->getMessage());

            return false;
        }
    }
}
