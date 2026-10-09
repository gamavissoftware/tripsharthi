<?php

declare(strict_types=1);

namespace App\Services\Flow;

use App\Models\FlowModel;
use App\Models\FlowRunModel;

/**
 * Fires flow triggers — the bridge between domain events and the flow engine.
 *
 * ── Design invariants ────────────────────────────────────────────────
 *
 * 1. ALWAYS enqueue, NEVER execute inline.
 *    fire() only inserts a flow_start job via JobDispatcher::dispatch().
 *    The engine runs in the cron worker (spark flow:work), never in the
 *    request/webhook path.  This keeps webhooks fast and avoids DB deadlocks.
 *
 * 2. CALL AFTER COMMIT.
 *    fire() must be called after the triggering row (contact, tag, message)
 *    has been auto-committed.  MySQL/SQLite auto-commit each statement unless
 *    an explicit BEGIN is open.  The worker is a separate process; it will
 *    read the DB only after the job is claimed.  Callers must not be inside
 *    an open transaction when calling fire().
 *
 * 3. PRE-CHECK reentry, AUTHORITATIVE check is in FlowStartHandler.
 *    fire() pre-filters to avoid unnecessary jobs (once policy + active run).
 *    FlowStartHandler re-checks when the job executes — belt-and-suspenders
 *    against the TOCTOU window between fire() and job execution.
 *
 * 4. INTER-FLOW LOOP MITIGATION.
 *    A flow A (tag_added) can enqueue flow B (tag_added) which enqueues A…
 *    reentry_policy='once' is the intended mitigation — a contact already
 *    enrolled in A will not be re-enrolled until that run completes.
 *    // KNOWN: inter-flow loop detection across distinct flow runs is deferred.
 *
 * 5. INBOUND TRIGGERS re-enroll after prior run COMPLETES.
 *    For keyword_reply and inbound_message with reentry_policy='once', a
 *    contact whose prior run is COMPLETED (not running/waiting) is eligible
 *    for a new run on the next inbound.  This is intentional: each inbound
 *    message is a fresh trigger event.
 *
 * 6. ERROR HANDLING: catch only \RuntimeException (covers DatabaseException)
 *    so transient DB errors are logged and swallowed, while PHP programming
 *    errors (TypeError, Error) propagate naturally.
 */
final class FlowTriggerService
{
    /**
     * Fire a trigger for a contact.
     *
     * @param  string $triggerType  One of FlowTriggers::all()
     * @param  int    $tenantId
     * @param  int    $contactId
     * @param  array  $context      Trigger-specific context (tag_id, form_id, content, etc.)
     * @return int    Number of flow_start jobs enqueued (useful for testing)
     */
    public static function fire(
        string $triggerType,
        int    $tenantId,
        int    $contactId,
        array  $context = []
    ): int {
        if ($tenantId <= 0 || $contactId <= 0) {
            return 0;
        }

        // ── Query matching active flows ───────────────────────────────
        try {
            $flows = (new FlowModel())->findActiveByTrigger($tenantId, $triggerType);
        } catch (\RuntimeException $e) {
            log_message('error', sprintf(
                '[FlowTrigger] query failed [type=%s tenant=%d contact=%d]: %s',
                $triggerType, $tenantId, $contactId, $e->getMessage()
            ));
            return 0;
        }

        $enqueued = 0;

        foreach ($flows as $flow) {
            $flowId = (int) $flow['id'];

            // ── trigger_config matching ───────────────────────────────
            if (! self::matchesConfig($flow, $context)) {
                continue;
            }

            // ── Reentry pre-check (once policy) ──────────────────────
            if (($flow['reentry_policy'] ?? 'once') === 'once') {
                try {
                    $existing = (new FlowRunModel())->findActiveRun($flowId, $contactId);
                    if ($existing !== null) {
                        continue; // Already enrolled — skip
                    }
                } catch (\RuntimeException $e) {
                    log_message('error', sprintf(
                        '[FlowTrigger] reentry check failed [flow=%d contact=%d]: %s',
                        $flowId, $contactId, $e->getMessage()
                    ));
                    continue;
                }
            }

            // ── Enqueue flow_start (NEVER run inline) ─────────────────
            try {
                JobDispatcher::dispatch($tenantId, 'flow_start', [
                    'flow_id'    => $flowId,
                    'contact_id' => $contactId,
                    'context'    => $context,
                ]);
                $enqueued++;
            } catch (\RuntimeException $e) {
                log_message('error', sprintf(
                    '[FlowTrigger] dispatch failed [type=%s tenant=%d contact=%d flow=%d]: %s',
                    $triggerType, $tenantId, $contactId, $flowId, $e->getMessage()
                ));
                // Continue to other matching flows
            }
        }

        return $enqueued;
    }

    // ------------------------------------------------------------------
    // trigger_config matching
    // ------------------------------------------------------------------

    private static function matchesConfig(array $flow, array $context): bool
    {
        $config = json_decode($flow['trigger_config'] ?? 'null', true);

        if (FlowTriggers::isTravel((string) ($flow['trigger_type'] ?? ''))) {
            return self::matchTravelFilters($config, $context);
        }

        return match($flow['trigger_type'] ?? '') {
            // No config key — always matches
            'lead_created',
            'meta_lead_received',
            'google_lead_received',
            'deal_created',
            'deal_won',
            'deal_lost',
            'ticket_created',
            'ticket_resolved' => true,

            'inbound_message'     => self::matchInboundMessage($config, $context),
            'tag_added'           => self::matchTagAdded($config, $context),
            'form_submitted'      => self::matchFormSubmitted($config, $context),
            'keyword_reply'       => self::matchKeywordReply($config, $context),
            'deal_stage_changed'  => self::matchDealStage($config, $context),
            'record_created'      => self::matchRecordCreated($config, $context),

            default => true,
        };
    }

    /**
     * Travel triggers — optional filters, so e.g. a visa/passport flow runs for international trips only and a
     * honeymoon nurture runs for honeymoons only:
     *   config.international = 'any' | 'yes' | 'no'      (default any)
     *   config.trip_type     = 'honeymoon' | 'family' | …  (blank = any)
     * (The scheduling knobs — days, days_before, within_days, audience — are read by TravelTriggerService.)
     */
    private static function matchTravelFilters(?array $config, array $context): bool
    {
        $intl = strtolower(trim((string) ($config['international'] ?? 'any')));
        if ($intl === 'yes' && (int) ($context['is_international'] ?? 0) !== 1) { return false; }
        if ($intl === 'no' && (int) ($context['is_international'] ?? 0) === 1) { return false; }

        $type = strtolower(trim((string) ($config['trip_type'] ?? '')));
        return $type === '' || $type === strtolower((string) ($context['trip_type'] ?? ''));
    }

    /**
     * inbound_message:
     *   config.message_kind = 'any' | 'typed' | 'button'   (default: 'any')
     *
     * 'typed'  → the contact composed this themselves (text, media, voice note)
     * 'button' → the contact tapped one of OUR buttons (template quick reply,
     *            interactive button, list row)
     *
     * The distinction exists so an auto-acknowledgement can answer a real reply
     * without also firing on a button tap that a keyword_reply flow is already
     * answering — which would send the contact two messages at once.
     *
     * Reads context.is_button, an explicit flag set by WebhookService, rather
     * than re-deriving it from Meta's raw message type. Deriving it twice is
     * what made keyword_reply silently ignore every button tap.
     */
    private static function matchInboundMessage(?array $config, array $context): bool
    {
        $kind = strtolower(trim((string) ($config['message_kind'] ?? 'any')));

        if ($kind === '' || $kind === 'any') {
            return true;
        }

        $isButton = (bool) ($context['is_button'] ?? false);

        return match ($kind) {
            'typed'  => ! $isButton,
            'button' => $isButton,
            default  => true,
        };
    }

    /**
* record_created: config.custom_object_id = N (0 or absent = any object).
     * Lets a flow target "a new Vehicle record" specifically.
     */
    private static function matchRecordCreated(?array $config, array $context): bool
    {
        $configObjectId  = (int) ($config['custom_object_id']  ?? 0);
        $contextObjectId = (int) ($context['custom_object_id'] ?? 0);

        return $configObjectId === 0 || $configObjectId === $contextObjectId;
    }

    /**
     * deal_stage_changed: config.stage_id = N (0 or absent = any stage). Lets a
     * flow target "moved to Proposal" specifically.
     */
    private static function matchDealStage(?array $config, array $context): bool
    {
        $configStageId  = (int) ($config['stage_id']  ?? 0);
        $contextStageId = (int) ($context['stage_id'] ?? 0);

        return $configStageId === 0 || $configStageId === $contextStageId;
    }

    /**
     * tag_added: config.tag_id = N (0 or absent = any tag)
     */
    private static function matchTagAdded(?array $config, array $context): bool
    {
        $configTagId  = (int) ($config['tag_id']  ?? 0);
        $contextTagId = (int) ($context['tag_id'] ?? 0);

        // configTagId=0 means "any tag" (catch-all)
        return $configTagId === 0 || $configTagId === $contextTagId;
    }

    /**
     * form_submitted: config.form_id = N (0 or absent = any form)
     */
    private static function matchFormSubmitted(?array $config, array $context): bool
    {
        $configFormId  = (int) ($config['form_id']  ?? 0);
        $contextFormId = (int) ($context['form_id'] ?? 0);

        return $configFormId === 0 || $configFormId === $contextFormId;
    }

    /**
     * keyword_reply:
     *   config.keywords = ['hello', 'hi']   (case-insensitive)
     *   config.match    = 'exact'|'contains'  (default: contains)
     *
     * 'exact'    → full trimmed string match
     * 'contains' → substring match anywhere in the message
     */
    private static function matchKeywordReply(?array $config, array $context): bool
    {
        $keywords  = $config['keywords'] ?? [];
        $matchType = strtolower(trim($config['match'] ?? 'contains'));
        $content   = strtolower(trim($context['content'] ?? ''));

        if (empty($keywords) || $content === '') {
            return false;
        }

        foreach ($keywords as $keyword) {
            $kw = strtolower(trim((string) $keyword));
            if ($kw === '') continue;

            if ($matchType === 'exact'    && $content === $kw)           return true;
            if ($matchType === 'contains' && str_contains($content, $kw)) return true;
        }

        return false;
    }
}
