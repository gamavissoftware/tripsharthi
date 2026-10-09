<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Models\CampaignModel;
use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\TemplateModel;
use App\Services\Flow\JobDispatcher;
use App\Services\Leads\CampaignSender;
use App\Services\Leads\SegmentResolver;
use App\Services\Leads\VariableResolver;
use App\Services\WhatsApp\WindowService;

/**
 * Handles 'campaign_send' jobs from the durable queue.
 *
 * ── Relocation seam (Sprint 3 → Sprint 4 Phase 5) ────────────────────
 * The ONLY change from Sprint 3's CampaignsController::send() is the
 * dispatch path: instead of the controller calling processBatch() directly,
 * this handler does it on behalf of the cron worker.
 *
 * processBatch() body is VERBATIM — not one character changed.
 *
 * ── Crash / resume durability ────────────────────────────────────────
 * The keyset cursor (campaigns.cursor = max contact_id of last batch) is
 * committed to the DB INSIDE processBatch() before this handler returns.
 *
 * If this handler throws after processBatch() (e.g. before re-enqueue),
 * the job queue retries the job.  On retry, processBatch() reads the
 * committed cursor and processes ONLY the next batch of contacts —
 * zero duplicate sends.
 *
 * ── Pause semantics ──────────────────────────────────────────────────
 * If the campaign is 'paused' when the handler runs, it skips silently.
 * The cursor is preserved; a manual re-trigger via POST /campaigns/{id}/send
 * dispatches a new job that resumes from the committed cursor.
 *
 * ── Injectable sender (for testing) ──────────────────────────────────
 * Pass a $senderOverride to inject a subclass with a different BATCH_SIZE.
 */
class CampaignSendHandler
{
    public function __construct(
        private readonly ?CampaignSender $senderOverride = null,
    ) {}

    public function handle(array $job, int $tenantId): void
    {
        $payload    = json_decode($job['payload'] ?? '{}', true) ?: [];
        $campaignId = (int) ($payload['campaign_id'] ?? 0);

        if ($campaignId <= 0 || $tenantId <= 0) {
            throw new \RuntimeException(
                "campaign_send job #{$job['id']} missing campaign_id or tenant_id."
            );
        }

        // Pause = skip silently; cursor preserved for manual re-trigger
        $campaign = (new CampaignModel())->setTenant($tenantId)->find($campaignId);
        if ($campaign === null) {
            log_message('warning', "campaign_send: campaign #{$campaignId} not found, skipping.");
            return;
        }
        if (($campaign['status'] ?? '') === 'paused') {
            log_message('info', "campaign_send: campaign #{$campaignId} is paused — batch skipped. Cursor preserved.");
            return;
        }

        // Quality handbrake. Treated exactly like a pause — the batch is skipped
        // and the cursor is left where it is, so the campaign resumes by itself
        // from the same contact once Meta restores the rating. Nothing is marked
        // failed and nobody is skipped over.
        $blocked = (new \App\Services\WhatsApp\SendingGate())->blockedReason($tenantId);
        if ($blocked !== null) {
            log_message('warning', "campaign_send: campaign #{$campaignId} held — {$blocked} Cursor preserved.");
            return;
        }

        $sender = $this->senderOverride ?? new CampaignSender(
            new CampaignModel(),
            new TemplateModel(),
            new ContactModel(),
            new MessageModel(),
            new ConversationModel(),
            new VariableResolver(),
            new SegmentResolver(),
            new WindowService(new ConversationModel()),
        );

        // ── processBatch() — VERBATIM from Sprint 3 ──────────────────
        // Cursor committed to DB inside processBatch() BEFORE this line returns.
        // Any exception thrown after this line = safe retry (resumes from cursor).
        $result = $sender->processBatch($campaignId, $tenantId);

        if (($result['status'] ?? '') === 'processing') {
            // Re-enqueue for next batch
            JobDispatcher::dispatch($tenantId, 'campaign_send', [
                'campaign_id' => $campaignId,
            ]);
        }
        // status = 'done' → job marks done naturally, no re-enqueue
    }
}
