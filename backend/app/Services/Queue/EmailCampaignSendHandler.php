<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Models\EmailCampaignModel;
use App\Services\Email\Marketing\EmailCampaignScheduler;
use App\Services\Email\Marketing\EmailCampaignSender;
use App\Services\Email\Marketing\EmailComposer;

/**
 * Handles 'email_campaign_send' jobs: one batch per job, re-enqueued for the
 * next worker minute until the audience is exhausted. A paused / cancelled
 * campaign is skipped silently with its cursor intact — resume dispatches a
 * fresh job that carries on from the cursor. A batch held back by the daily
 * limit is retried every 15 minutes until the 24-hour window has room.
 */
class EmailCampaignSendHandler
{
    public function __construct(
        private readonly ?EmailCampaignSender $senderOverride = null,
    ) {}

    public function handle(array $job, int $tenantId): void
    {
        $payload    = json_decode($job['payload'] ?? '{}', true) ?: [];
        $campaignId = (int) ($payload['email_campaign_id'] ?? 0);

        if ($campaignId <= 0 || $tenantId <= 0) {
            throw new \RuntimeException("email_campaign_send job #{$job['id']} missing email_campaign_id or tenant_id.");
        }

        $campaign = (new EmailCampaignModel())->setTenant($tenantId)->find($campaignId);
        if ($campaign === null || ($campaign['status'] ?? '') !== 'processing') {
            return;
        }

        $sender = $this->senderOverride ?? new EmailCampaignSender(EmailComposer::defaultTransport());
        $result = $sender->processBatch($campaignId, $tenantId);

        if (($result['status'] ?? '') === 'processing') {
            // At the daily cap: look again in 15 minutes rather than every minute.
            $runAt = ! empty($result['throttled']) ? time() + 900 : null;
            EmailCampaignScheduler::enqueue($tenantId, $campaignId, $runAt);
        }
    }
}
