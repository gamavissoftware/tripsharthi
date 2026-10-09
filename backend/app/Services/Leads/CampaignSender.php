<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\CampaignModel;
use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\TemplateModel;
use App\Models\WabaAccountModel;
use App\Services\WhatsApp\BillableComputer;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\ProviderAdapter;
use App\Services\WhatsApp\TemplateComponentBuilder;
use App\Services\WhatsApp\WindowService;

/**
 * Chunked campaign batch sender with keyset cursor.
 *
 * Cursor design:
 *   - campaigns.cursor stores the MAX contact id processed in the last batch.
 *   - Each batch: WHERE contacts.id > cursor ORDER BY id ASC LIMIT BATCH_SIZE
 *   - Done when the batch returns fewer rows than BATCH_SIZE (no more contacts).
 *   - total_contacts is snapshotted once before the first batch and never changes.
 *
 * This avoids OFFSET drift (contacts inserted during a send don't corrupt the
 * cursor) and ports cleanly to the Sprint 4 job handler — processBatch()
 * signature is unchanged; the job handler just calls it with a campaign id.
 *
 * Sprint 4 porting note: replace the controller call to processBatch() with:
 *   JobDispatcher::dispatch('campaign_send', ['campaign_id' => $id, 'tenant_id' => $tenantId]);
 * The method body moves verbatim into the CampaignSendJob handler.
 */
class CampaignSender
{
    public const BATCH_SIZE = 500;

    /**
     * Persist the cursor every N contacts inside a batch. Without this the cursor
     * advanced only after the whole 500-contact loop, so a mid-batch crash or a
     * stale-lock reclaim re-ran from the start and re-sent already-delivered
     * WhatsApp messages (billed to the customer's WABA — a §1 policy hazard).
     */
    public const CHECKPOINT_EVERY = 25;

    public function __construct(
        private readonly CampaignModel          $campaignModel,
        private readonly TemplateModel          $templateModel,
        private readonly ContactModel           $contactModel,
        private readonly MessageModel           $messageModel,
        private readonly ConversationModel      $conversationModel,
        private readonly VariableResolver       $variableResolver,
        private readonly SegmentResolver        $segmentResolver,
        private readonly WindowService          $windowService,
        // Inject a ProviderAdapter or CloudApiClient for testing (null = construct from WABA config)
        private readonly CloudApiClient|ProviderAdapter|null $apiClientOverride = null,
    ) {}

    // ------------------------------------------------------------------
    // Entry point
    // ------------------------------------------------------------------

    /**
     * Process one batch of up to BATCH_SIZE contacts.
     *
     * @return array{
     *   status: string,    'processing' | 'done'
     *   sent:   int,
     *   failed: int,
     *   total:  int,
     *   cursor: int,       last contact id processed
     *   stats:  array,
     * }
     */
    public function processBatch(int $campaignId, int $tenantId): array
    {
        // ── Load and validate campaign ────────────────────────────────
        $campaign = $this->campaignModel->setTenant($tenantId)->find($campaignId);
        if ($campaign === null) {
            throw new \RuntimeException("Campaign #{$campaignId} not found.");
        }

        if (! in_array($campaign['status'], ['draft', 'processing'], true)) {
            throw new \RuntimeException(
                "Campaign #{$campaignId} is not in a sendable state (status: {$campaign['status']})."
            );
        }

        // ── Load and validate template ────────────────────────────────
        $template = $this->templateModel->setTenant($tenantId)->find((int) $campaign['template_id']);
        if ($template === null || $template['meta_status'] !== 'approved') {
            throw new \RuntimeException(
                "Template for campaign #{$campaignId} is not approved."
            );
        }

        // ── Get the API client ────────────────────────────────────────
        $client = $this->apiClientOverride ?? $this->buildApiClient($tenantId);

        // ── Decode mutable campaign state ─────────────────────────────
        $cursor         = (int) $campaign['cursor'];
        $totalContacts  = (int) $campaign['total_contacts'];
        $sentCount      = (int) $campaign['sent_count'];
        $failedCount    = (int) $campaign['failed_count'];
        $stats          = json_decode($campaign['stats'] ?? 'null', true) ?: CampaignModel::emptyStats();

        $segment        = json_decode($campaign['segment']           ?? '{}', true) ?: [];
        $varMap         = json_decode($campaign['variable_mapping']  ?? '{}', true) ?: [];
        $varDefaults    = json_decode($campaign['variable_defaults'] ?? '{}', true) ?: [];
        $isMarketing    = $template['category'] === 'marketing';

        // ── A/B variant template (optional) ───────────────────────────
        // ab_split = % of contacts routed to variant B (deterministic by id).
        $abSplit    = max(0, min(100, (int) ($campaign['ab_split'] ?? 0)));
        $variantBId = (int) ($campaign['variant_template_id'] ?? 0);
        $templateB  = null;
        if ($abSplit > 0 && $variantBId > 0) {
            $tb = $this->templateModel->setTenant($tenantId)->find($variantBId);
            if ($tb !== null && ($tb['meta_status'] ?? '') === 'approved') {
                $templateB = $tb;
            }
        }

        // Per-contact language localization (batch-cached).
        $templateResolver = new TemplateResolver(new TemplateModel());

        // ── Snapshot total_contacts on the very first batch ───────────
        if ($cursor === 0 && $totalContacts === 0) {
            if ($isMarketing) {
                // Count all in segment (including opt-outs) to compute the skip stat,
                // then use the opt-in-only count as the actual send target.
                $fullCount    = $this->segmentResolver->countTotal($tenantId, $segment, false);
                $optInCount   = $this->segmentResolver->countTotal($tenantId, $segment, true);
                $totalContacts = $optInCount;
                $stats['skipped_opt_out'] += max(0, $fullCount - $optInCount);
            } else {
                $totalContacts = $this->segmentResolver->countTotal($tenantId, $segment, false);
            }

            $this->campaignModel->setTenant($tenantId)->update($campaignId, [
                'total_contacts' => $totalContacts,
                'status'         => 'processing',
                'stats'          => json_encode($stats),
            ]);
        }

        // ── Fetch batch: keyset cursor ────────────────────────────────
        $batchModel = new ContactModel();
        $batchModel->setTenant($tenantId)->select('contacts.*');
        $this->segmentResolver->apply($batchModel, $segment);

        // Email-only contacts can't receive WhatsApp — exclude them. In SQL
        // `wa_number != ''` also excludes NULL (NULL != '' is not TRUE), so this
        // single escaped clause covers both. Escaped (not raw) so CI4 applies the
        // table prefix.
        $batchModel->where('contacts.wa_number !=', '');

        if ($isMarketing) {
            $batchModel->where('contacts.opt_in', 1); // pre-filter layer 1
        }

        $contacts = $batchModel
            ->where('contacts.id >', $cursor)
            ->orderBy('contacts.id', 'ASC')
            ->findAll(static::BATCH_SIZE);

        // ── Process each contact ──────────────────────────────────────
        $newCursor = $cursor;
        $processed = 0;

        foreach ($contacts as $contact) {
            $contactId  = (int) $contact['id'];
            $newCursor  = max($newCursor, $contactId);

            // Opt-in gate layer 2 (belt-and-suspenders for marketing)
            if ($isMarketing && ! (bool) ($contact['opt_in'] ?? 1)) {
                $stats['skipped_opt_out']++;
                continue;
            }

            // A/B: deterministically bucket this contact to variant A or B.
            $variant      = 'A';
            $sendTemplate = $template;
            if ($templateB !== null && ($contactId % 100) < $abSplit) {
                $variant      = 'B';
                $sendTemplate = $templateB;
            }

            // Multi-language: swap to the contact's language version if available.
            $sendTemplate = $templateResolver->localize($tenantId, $sendTemplate, $contact['language'] ?? null);

            // Resolve template variables with fallback. Restricted (owner/admin-only)
            // custom fields are excluded from personalization — never merged outbound.
            $cfv      = (new ContactFieldValueModel())->getForContact($contactId, false);
            $resolved = $this->variableResolver->resolve($varMap, $contact, $cfv, $varDefaults);
            $stats['missing_variables'] += $resolved['missing_count'];

            // Billable + conversation. Create the conversation BEFORE the send so
            // the reserved message row links to a real conversation.
            $conv       = (new ConversationModel())->setTenant($tenantId)
                              ->where('wa_number', $contact['wa_number'])->first();
            $convArr    = $conv ? (is_array($conv) ? $conv : (array) $conv) : [];
            $windowOpen = $conv ? $this->windowService->isOpenForConversation($convArr) : false;
            $billable   = BillableComputer::compute($sendTemplate['category'], $windowOpen);

            $convId = (int) ($convArr['id'] ?? 0);
            if ($convId === 0) {
                $newConv = (new ConversationModel())->findOrCreate($tenantId, $contact['wa_number']);
                $convId  = (int) $newConv['id'];
            }

            // ── Idempotency: reserve this contact BEFORE sending ──────────
            // INSERT IGNORE against UNIQUE(campaign_id, contact_id). If a row
            // already exists, a prior or concurrent run already sent to this
            // contact, so we skip rather than re-send — duplicate WhatsApp
            // messages are billed to the customer's WABA and risk a ban (§1).
            $now = date('Y-m-d H:i:s');
            $db  = db_connect();
            $db->table('messages')->ignore(true)->insert([
                'tenant_id'       => $tenantId,
                'contact_id'      => $contactId,
                'conversation_id' => $convId,
                'campaign_id'     => $campaignId,
                'direction'       => 'out',
                'type'            => 'template',
                'template_id'     => (int) ($sendTemplate['id'] ?? 0) ?: null,
                'category'        => $sendTemplate['category'],
                'body'            => $sendTemplate['body'],
                'variant'         => $templateB !== null ? $variant : null,
                'status'          => 'queued',
                'billable'        => 0,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
            if ($db->affectedRows() === 0) {
                // Already reserved/sent by another run — skip without re-sending.
                $stats['skipped_duplicate'] = ($stats['skipped_duplicate'] ?? 0) + 1;
                continue;
            }
            $msgId = (int) $db->insertID();

            // Build Meta components array
            $components = TemplateComponentBuilder::forSend($sendTemplate, $resolved['body_params']);

            // Send
            $result = $client->sendTemplate(
                $contact['wa_number'],
                $sendTemplate['name'],
                $sendTemplate['language'],
                $components
            );

            $status = $result['success'] ? 'sent' : 'failed';

            // ── Meta rate-limit guard: max ~4 msg/s per phone number ──
            // Meta allows up to 80 msg/s for Tier 4 WABAs, but new/unverified
            // numbers start at ~1-5 msg/s. 250 ms between sends is safe for all tiers.
            // Override via CAMPAIGN_SEND_DELAY_MS env (set to 0 for Tier 4 accounts).
            $delayMs = (int) (env('CAMPAIGN_SEND_DELAY_MS', 250) ?? 250);
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            // Finalize the reserved row with the send result.
            $db->table('messages')->where('id', $msgId)->update([
                'wa_message_id' => $result['message_id'],
                'status'        => $status,
                // A failed send was never delivered — never bill for it.
                'billable'      => $result['success'] ? $billable : 0,
                'error'         => $result['error'],
                'sent_at'       => $result['success'] ? date('Y-m-d H:i:s') : null,
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

            if ($result['success']) {
                $sentCount++;
                $billable ? $stats['billable_sends']++ : $stats['free_sends']++;
            } else {
                $failedCount++;
                $stats['failed']++;
                log_message('error', "CampaignSender: failed to send to {$contact['wa_number']}: {$result['error']}");
            }

            // Checkpoint progress mid-batch so a crash/reclaim resumes AFTER the
            // contacts already sent rather than re-sending the whole batch.
            if (++$processed % static::CHECKPOINT_EVERY === 0) {
                $this->campaignModel->setTenant($tenantId)->update($campaignId, [
                    'cursor'       => $newCursor,
                    'sent_count'   => $sentCount,
                    'failed_count' => $failedCount,
                    'stats'        => json_encode($stats),
                ]);
            }
        }

        // ── Determine done state ──────────────────────────────────────
        $isDone = count($contacts) < static::BATCH_SIZE;

        // A finished run that delivered nothing is not a success — reporting it
        // as 'done' shows a green "Completed" badge on a campaign where every
        // message failed, which is exactly when the user needs to look at it.
        $newStatus = match (true) {
            ! $isDone                                  => 'processing',
            $sentCount === 0 && $failedCount > 0       => 'failed',
            default                                    => 'done',
        };

        $this->campaignModel->setTenant($tenantId)->update($campaignId, [
            'cursor'         => $newCursor,
            'sent_count'     => $sentCount,
            'failed_count'   => $failedCount,
            'status'         => $newStatus,
            'stats'          => json_encode($stats),
            'total_contacts' => $totalContacts,
        ]);

        return [
            'status' => $newStatus,
            'sent'   => $sentCount,
            'failed' => $failedCount,
            'total'  => $totalContacts,
            'cursor' => $newCursor,
            'stats'  => $stats,
        ];
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    private function buildApiClient(int $tenantId): ProviderAdapter
    {
        $wabaModel = new WabaAccountModel();
        $account   = $wabaModel->findActive($tenantId);

        if ($account === null) {
            throw new \RuntimeException("No active WABA account for tenant #{$tenantId}.");
        }

        return $wabaModel->buildAdapter($account);
    }
}
