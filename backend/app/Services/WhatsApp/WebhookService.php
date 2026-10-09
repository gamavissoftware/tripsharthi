<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\ContactFieldValueModel;
use App\Models\FlowRunModel;
use App\Models\MessageModel;
use App\Models\PhoneNumberModel;
use App\Models\TemplateModel;
use App\Services\Flow\FlowTriggerService;
use App\Services\Flow\JobDispatcher;
use App\Services\Leads\ContactDedupeService;
use App\Services\Leads\WaNumberNormalizer;

/**
 * Processes inbound webhook payloads from Meta.
 *
 * Responsibilities:
 *  1. Inbound messages → upsert conversation, refresh window, create/match contact, store message.
 *  2. Status callbacks → update messages.sent_at / delivered_at / read_at / status.
 *
 * NOT responsible for signature verification — the controller handles that before
 * calling this service.
 */
class WebhookService
{
    public function __construct(
        private readonly ConversationModel $conversationModel,
        private readonly MessageModel      $messageModel,
        private readonly ContactModel      $contactModel,
        private readonly PhoneNumberModel  $phoneNumberModel,
        private readonly WindowService     $windowService,
    ) {}

    // ------------------------------------------------------------------
    // Entry point
    // ------------------------------------------------------------------

    /**
     * Process a fully-parsed Meta webhook payload.
     * Returns the number of messages processed.
     */
    public function handle(array $payload): int
    {
        $processed = 0;

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? '';
                $value = $change['value'] ?? [];

                // ── Template status update (Sprint 3) ─────────────────
                if ($field === 'message_template_status_update') {
                    $this->handleTemplateStatusUpdate($value);
                    continue;
                }

                // ── Regular message/status events ─────────────────────
                $metadata = $value['metadata'] ?? [];

                $metaPhoneNumberId = $metadata['phone_number_id'] ?? null;
                $phoneNumber       = $metaPhoneNumberId
                    ? $this->phoneNumberModel->findByMetaPhoneNumberId($metaPhoneNumberId)
                    : null;

                foreach ($value['messages'] ?? [] as $msg) {
                    $this->handleMessage($msg, $value['contacts'] ?? [], $phoneNumber);
                    $processed++;
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->handleStatus($status);
                }
            }
        }

        return $processed;
    }

    // ------------------------------------------------------------------
    // Inbound message
    // ------------------------------------------------------------------

    private function handleMessage(array $msg, array $contactsArray, array|object|null $phoneNumber): void
    {
        $rawFrom  = $msg['from'] ?? '';

        // Meta delivers 'from' as digits-only E.164 without '+' (e.g. "919650609615").
        // Normalise to standard E.164 with '+' so it matches contacts stored as "+919650609615".
        $fromForNorm = ctype_digit($rawFrom) && strlen($rawFrom) >= 10 ? '+' . $rawFrom : $rawFrom;
        $waNumber    = WaNumberNormalizer::normalize($fromForNorm);

        // Fallback: strip leading '+' for numbers that arrive without country code
        if ($waNumber === '' && ctype_digit($rawFrom)) {
            $waNumber = '+' . $rawFrom; // best-effort E.164
        }

        if ($waNumber === '') {
            log_message('warning', "WebhookService: could not normalise sender number '{$rawFrom}', skipping.");
            return;
        }

        $tenantId     = $phoneNumber ? (int) (is_array($phoneNumber) ? $phoneNumber['tenant_id'] : $phoneNumber->tenant_id) : 0;
        $phoneNumRow  = is_array($phoneNumber) ? $phoneNumber : (array) ($phoneNumber ?? []);
        $phoneNumDbId = (int) ($phoneNumRow['id'] ?? 0);

        // Extract contact name from contacts[] array in payload
        $waId        = ltrim($waNumber, '+');
        $contactName = null;
        foreach ($contactsArray as $c) {
            if (ltrim((string) ($c['wa_id'] ?? ''), '+') === $waId) {
                $contactName = $c['profile']['name'] ?? null;
                break;
            }
        }

        // Find or create conversation
        $conversation = $this->conversationModel->findOrCreate($tenantId, $waNumber, [
            'phone_number_id' => $phoneNumDbId ?: null,
            'contact_name'    => $contactName,
        ]);
        $conversationId = (int) $conversation['id'];

        // Update contact_name if we got a better one from Meta
        if ($contactName && empty($conversation['contact_name'])) {
            $this->conversationModel->withoutTenantScope()->update($conversationId, ['contact_name' => $contactName]);
        }

        // ── WINDOW REFRESH — must happen on every inbound ─────────────
        $this->windowService->refreshWindow($conversationId);

        // ── Auto-create/match contact ─────────────────────────────────
        $contactId = (int) ($conversation['contact_id'] ?? 0);
        if ($contactId === 0 && $tenantId > 0) {
            $dedupeService = new ContactDedupeService($this->contactModel, new ContactFieldValueModel());
            $result        = $dedupeService->upsert($tenantId, [
                'wa_number'    => $waNumber,
                'name'         => $contactName,
                'source'       => 'whatsapp_inbound',
                'opt_in'       => 1, // they initiated — presumed opt-in
                // Click-to-WhatsApp ad: ctwa_clid arrives on the first message only.
                '_attribution' => \App\Services\Travel\AttributionService::fromWhatsAppReferral((array) ($msg['referral'] ?? [])),
            ]);
            $contactId = (int) $result['contact_id'];

            // Link conversation → contact (only if the contact actually exists)
            if ($contactId > 0) {
                $contactExists = $this->contactModel->withoutTenantScope()->find($contactId);
                if ($contactExists) {
                    $this->conversationModel->withoutTenantScope()->update($conversationId, [
                        'contact_id' => $contactId,
                    ]);
                }
            }
        }

        // Update contact.last_inbound_at
        if ($contactId > 0) {
            $this->contactModel->withoutTenantScope()->update($contactId, [
                'last_inbound_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // ── Classify message type and body ────────────────────────────
        $msgType  = $msg['type'] ?? 'text';
        $body     = null;
        $type     = $msgType;
        $btnId    = null;
        $btnTitle = null;
        $btnSource = null; // 'quick_reply' | 'list' | 'button' when this inbound is a tap
        $flowReply = null; // parsed WhatsApp Flow submission when this is an nfm_reply

        $mediaId = null; // WhatsApp media ID for inbound media messages

        switch ($msgType) {
            case 'text':
                $body = $msg['text']['body'] ?? null;
                break;
            case 'image':
            case 'audio':
            case 'video':
            case 'document':
                $mediaData = $msg[$msgType] ?? [];
                $mediaId   = $mediaData['id'] ?? null;
                $caption   = $mediaData['caption'] ?? null;
                $filename  = $mediaData['filename'] ?? null;
                // Store caption as body (visible text); media_id stored separately for retrieval
                $body = $caption ?: ($filename ?: "[{$msgType} message]");
                $type = $msgType;
                break;
            case 'interactive':
                $interactiveType = $msg['interactive']['type'] ?? '';
                if ($interactiveType === 'button_reply') {
                    $btnId     = $msg['interactive']['button_reply']['id']    ?? null;
                    $btnTitle  = $msg['interactive']['button_reply']['title'] ?? null;
                    $btnSource = 'quick_reply';
                    $body      = $btnTitle ?? "[button: {$btnId}]";
                } elseif ($interactiveType === 'list_reply') {
                    $btnId     = $msg['interactive']['list_reply']['id']    ?? null;
                    $btnTitle  = $msg['interactive']['list_reply']['title'] ?? null;
                    $btnSource = 'list';
                    $body      = $btnTitle ?? "[list: {$btnId}]";
                } elseif ($interactiveType === 'nfm_reply') {
                    $flowReply = \App\Services\WhatsApp\FlowResponseParser::parse($msg['interactive']);
                    $body      = '📝 Form submitted';
                } else {
                    $btnId = null;
                    $body  = "[interactive message]";
                }
                $type = 'text'; // Store as text for display
                break;
            case 'button':
                // Quick-reply button on a template message.
                $btnId     = $msg['button']['payload'] ?? $msg['button']['text'] ?? null;
                $btnTitle  = $msg['button']['text']    ?? null;
                $btnSource = 'button';
                $body      = $btnTitle ?? "[button: {$btnId}]";
                $type      = 'text';
                break;
            case 'order':
                // Customer sent a cart from the catalog — summarise for the timeline.
                $order = \App\Services\Commerce\OrderParser::parse($msg['order'] ?? []);
                $count = count($order['items']);
                $body  = '🛒 Order: ' . $count . ' item(s), total ₹' . number_format($order['total_paise'] / 100, 2);
                $type  = 'text';
                break;
            default:
                $body = "[{$msgType} message]";
                $type = 'text'; // normalise unknown types to 'text'
        }

        // Idempotency: skip if wa_message_id already stored
        $waMessageId = $msg['id'] ?? null;
        if ($waMessageId && $this->messageModel->findByWaMessageId($waMessageId) !== null) {
            return;
        }

        // ── Store message ─────────────────────────────────────────────
        // media_url stores the WhatsApp media_id for inbound media so the
        // proxy endpoint can fetch the actual file on demand.
        $this->messageModel->withoutTenantScope()->insert([
            'tenant_id'       => $tenantId,
            'contact_id'      => $contactId ?: null,
            'conversation_id' => $conversationId,
            'direction'       => 'in',
            'type'            => in_array($type, ['text','image','document','audio','video'], true) ? $type : 'text',
            'body'            => $body,
            'media_url'       => $mediaId, // stores WA media_id for proxy retrieval
            'wa_message_id'   => $waMessageId,
            'status'          => 'delivered',
            'billable'        => 0,
        ]);

        // ── Capture WhatsApp Flow submissions (nfm_reply) ────────────────
        if ($flowReply !== null && $tenantId > 0) {
            try {
                (new \App\Models\WhatsappFlowResponseModel())->setTenant($tenantId)->insert([
                    'contact_id'      => $contactId ?: null,
                    'conversation_id' => $conversationId,
                    'flow_token'      => $flowReply['flow_token'] ?: null,
                    'response'        => json_encode($flowReply['response']),
                    'wa_message_id'   => $waMessageId,
                ]);
                if ($contactId > 0) {
                    FlowTriggerService::fire('flow_response', $tenantId, $contactId, [
                        'flow_token' => $flowReply['flow_token'],
                        'response'   => $flowReply['response'],
                    ]);
                }
            } catch (\Throwable $e) {
                log_message('error', 'Flow response capture failed: ' . $e->getMessage());
            }
        }

        // ── Outbound webhook (Zapier / CRM): message.received ────────────
        if ($tenantId > 0) {
            \App\Services\Webhooks\OutboundWebhookService::emit($tenantId, 'message.received', [
                'contact_id'      => $contactId ?: null,
                'conversation_id' => $conversationId,
                'wa_number'       => $waNumber,
                'type'            => $msgType,
                'body'            => $body ?? '',
            ]);
        }

        // ── Capture inbound catalog orders ───────────────────────────────
        if ($msgType === 'order' && $tenantId > 0) {
            try {
                $order = \App\Services\Commerce\OrderParser::parse($msg['order'] ?? []);
                (new \App\Models\CommerceOrderModel())->setTenant($tenantId)->insert([
                    'contact_id'      => $contactId ?: null,
                    'conversation_id' => $conversationId,
                    'catalog_id'      => $order['catalog_id'] ?: null,
                    'items'           => json_encode($order['items']),
                    'total_paise'     => $order['total_paise'],
                    'currency'        => $order['currency'],
                    'status'          => 'placed',
                    'wa_message_id'   => $waMessageId,
                ]);

                \App\Services\Webhooks\OutboundWebhookService::emit($tenantId, 'order.placed', [
                    'contact_id'  => $contactId ?: null,
                    'catalog_id'  => $order['catalog_id'] ?? null,
                    'items'       => $order['items'],
                    'total_paise' => $order['total_paise'],
                    'currency'    => $order['currency'],
                ]);
            } catch (\Throwable $e) {
                log_message('error', 'Order capture failed: ' . $e->getMessage());
            }
        }

        // ── Record CTA/button click for analytics + retargeting ──────────
        // Attributed to the campaign via the reply's context.id → outbound message.
        if (! empty($btnId) && $btnSource !== null && $tenantId > 0) {
            // Best-effort: a duplicate-webhook race on the inbound_wa_id unique key
            // must never abort the rest of inbound processing.
            try {
                (new ClickTracker())->record([
                    'tenant_id'       => $tenantId,
                    'conversation_id' => $conversationId,
                    'contact_id'      => $contactId ?: null,
                    'context_wa_id'   => $msg['context']['id'] ?? null,
                    'button_id'       => $btnId,
                    'button_title'    => $btnTitle,
                    'source'          => $btnSource,
                    'inbound_wa_id'   => $waMessageId,
                ]);
            } catch (\Throwable $e) {
                log_message('error', 'ClickTracker failed: ' . $e->getMessage());
            }
        }

        // ── Resume interactive flow runs waiting for this contact's button reply ──
        if (! empty($btnId) && $contactId > 0 && $tenantId > 0) {
            $this->resumeInteractiveFlowRuns($tenantId, $contactId, $btnId);
        }

        // ── Update conversation counters ──────────────────────────────
        $previousUnread = (int) ($conversation['unread_count'] ?? 0);
        $this->conversationModel->withoutTenantScope()->update($conversationId, [
            'unread_count'    => $previousUnread + 1,
            'is_read'         => 0, // a new inbound makes the thread unread again
            'last_message_at' => date('Y-m-d H:i:s'),
            'status'          => 'open', // re-open if was resolved
        ]);

        // ── "New reply" alerts (WhatsApp / email / push) ─────────────────
        // Best-effort + throttled to the first unread reply; must never block.
        if ($tenantId > 0) {
            try {
                $convForAlert = array_merge($conversation, [
                    'id'           => $conversationId,
                    'wa_number'    => $waNumber,
                    'contact_name' => $contactName ?: ($conversation['contact_name'] ?? null),
                ]);
                (new \App\Services\Notifications\NotificationService())
                    ->notifyInbound($tenantId, $convForAlert, ['body' => $body, 'type' => $type], $previousUnread);
                // Native phone push for the first unread message of a thread (a burst of five lines = one push).
                if ($previousUnread === 0) {
                    \App\Services\Push\PushNotifier::safely(function ($n) use ($tenantId, $convForAlert, $body, $type, $contactId) {
                        $cid = (int) ($convForAlert['contact_id'] ?? $contactId ?? 0);
                        $contactRow = $cid ? db_connect()->table('contacts')->where('id', $cid)->where('tenant_id', $tenantId)->get()->getRowArray() : null;
                        // Created by THIS message (seconds ago, WhatsApp-sourced) => announce as a new lead.
                        $isNew = $contactRow && ($contactRow['source'] ?? '') === 'whatsapp_inbound' && strtotime((string) $contactRow['created_at']) > time() - 120;
                        \App\Services\Push\EventPush::reply($n, $tenantId, $convForAlert + ['contact_id' => $cid ?: null], trim((string) $body) !== '' ? (string) $body : '[' . $type . ']', (bool) $isNew, $contactRow);
                    });
                }
            } catch (\Throwable $e) {
                log_message('error', 'NotificationService failed: ' . $e->getMessage());
            }
        }

        // ── Auto-route to an agent (only if unassigned) ──────────────────
        // Guarded: routing is best-effort and must never block inbound storage.
        if ($tenantId > 0) {
            try {
                $tagIds = [];
                if ($contactId > 0) {
                    foreach ($this->contactModel->getTagsFor($contactId) as $t) {
                        $tagIds[] = (int) $t['id'];
                    }
                }
                (new \App\Services\Inbox\AgentRouter())->route($tenantId, $conversationId, [
                    'tag_ids' => $tagIds,
                    'body'    => $body ?? '',
                ]);
            } catch (\Throwable $e) {
                log_message('error', 'AgentRouter failed: ' . $e->getMessage());
            }
        }

        // ── Opt-out (STOP) ───────────────────────────────────────────────
        // Marketing footers promise "Reply STOP to opt out". Honour it here, in
        // the platform, rather than in a flow someone can forget to wire up: an
        // ignored STOP sends people to the Report button instead, and reports are
        // what get a sending number restricted. Suppress flow triggers too — the
        // one thing worse than ignoring STOP is auto-replying to it.
        // $msgType is Meta's RAW type; the switch above normalises into $type.
        // A tap on WhatsApp's own "Stop promotions" button arrives as 'button',
        // never 'text', so gating on $msgType would miss the commonest opt-out
        // of all. $btnSource is set for every button/list tap.
        $isTextualReply = $msgType === 'text' || $btnSource !== null;

        if ($tenantId > 0 && $contactId > 0 && $isTextualReply && ($body ?? '') !== ''
            && (new \App\Services\Leads\OptOutDetector())->isOptOut((string) $body)) {
            try {
                $this->contactModel->withoutTenantScope()->update($contactId, ['opt_in' => 0]);
                log_message('info', "Opt-out honoured: contact #{$contactId} ({$waNumber}) replied '{$body}' — opt_in set to 0.");
            } catch (\Throwable $e) {
                log_message('error', 'Opt-out update failed for contact #' . $contactId . ': ' . $e->getMessage());
            }

            return;
        }

        // ── Flow triggers (after all DB writes are auto-committed) ────────
        if ($tenantId > 0 && $contactId > 0) {
            // inbound_message fires for every inbound, regardless of message type.
            // Flows using this trigger react to any contact initiating a conversation.
            FlowTriggerService::fire('inbound_message', $tenantId, $contactId, [
                'wa_number'       => $waNumber,
                'type'            => $msgType,
                'content'         => $body ?? '',
                'conversation_id' => $conversationId,
                // Explicit, so a flow can tell "they tapped our button" from
                // "they wrote something". Never re-derive this from $msgType.
                'is_button'       => $btnSource !== null,
                // Which button. A carousel sends card{i}_btn{j}, so this is the
                // only thing that says WHICH card the customer tapped — every
                // card's button carries the same visible label.
                'button_id'       => $btnId,
                'button_title'    => $btnTitle,
            ]);

            // keyword_reply fires for anything the contact chose to send as text:
            // a typed message, or a tap on a template quick-reply / interactive
            // button (whose body is the button's own label). Gating on $msgType
            // here silently broke every button-driven campaign flow, because a
            // tap is type 'button'/'interactive' and never 'text'. Media, orders
            // and form replies stay excluded — their body is a synthetic label.
            if ($isTextualReply && $body !== null && $body !== '') {
                FlowTriggerService::fire('keyword_reply', $tenantId, $contactId, [
                    'content'         => $body,
                    'conversation_id' => $conversationId,
                    'button_id'       => $btnId,
                    'button_title'    => $btnTitle,
                ]);
            }
        }
    }

    // ------------------------------------------------------------------
    // Status callback
    // ------------------------------------------------------------------

    private function handleStatus(array $statusUpdate): void
    {
        $waMessageId = $statusUpdate['id']        ?? null;
        $status      = $statusUpdate['status']    ?? null;
        $timestamp   = $statusUpdate['timestamp'] ?? null;

        if (! $waMessageId || ! $status) {
            return;
        }

        $existing = $this->messageModel->findByWaMessageId($waMessageId);
        if ($existing === null) {
            return; // status for a message we didn't originate — ignore
        }

        $dt         = $timestamp ? date('Y-m-d H:i:s', (int) $timestamp) : date('Y-m-d H:i:s');
        $updateData = ['status' => $status];

        switch ($status) {
            case 'sent':
                $updateData['sent_at'] = $dt;
                break;
            case 'delivered':
                $updateData['delivered_at'] = $dt;
                break;
            case 'read':
                $updateData['read_at'] = $dt;
                break;
            case 'failed':
                // Meta's `title` is often the generic "Something went wrong".
                // The actionable part is the numeric code and error_data.details
                // (e.g. 131049 frequency capping, 131047 re-engagement required,
                // 132000 parameter mismatch) — keep all three or the operator has
                // nothing to act on.
                $errors = $statusUpdate['errors'] ?? [];
                $first  = $errors[0] ?? [];

                $parts = array_filter([
                    isset($first['code']) ? '#' . $first['code'] : null,
                    $first['title'] ?? null,
                    $first['error_data']['details'] ?? ($first['details'] ?? null),
                ]);

                $updateData['error'] = $parts !== []
                    ? implode(' — ', $parts)
                    : 'Send failed';

                // Nothing was delivered, so nothing is billable. Meta does not
                // charge for a message it refused to deliver, and leaving this
                // at 1 overstates campaign spend — a #131049 frequency cap can
                // fail a third of a marketing batch, and the operator would be
                // reading a cost that was never incurred.
                $updateData['billable'] = 0;

                if ($errors !== []) {
                    log_message('error', 'WhatsApp send failed: ' . json_encode($errors));
                }
                break;
        }

        // Status is a ladder, and Meta's callbacks are not ordered: a retried
        // `delivered` can land after `read`. Writing it blindly downgrades the
        // row and the message silently stops counting as read — which is the
        // one number the whole delivery report exists to answer. Keep the
        // timestamp (it is still true), drop the backwards status.
        $current = is_array($existing) ? ($existing['status'] ?? 'queued') : ($existing->status ?? 'queued');
        if (! self::statusAdvances((string) $current, (string) $status)) {
            if ($status === 'failed') {
                // A failure callback for an already-delivered message carries an
                // error string and billable = 0 as well as the status; applying
                // any of it would mark a landed, billed message as free and
                // broken. Discard the whole callback.
                log_message('info', "WhatsApp status callback ignored: {$current} → failed for {$waMessageId}");

                return;
            }
            unset($updateData['status']);
        }

        if ($updateData === []) {
            return;
        }

        $msgId = is_array($existing) ? $existing['id'] : $existing->id;
        $this->messageModel->withoutTenantScope()->update((int) $msgId, $updateData);
    }

    /**
     * Does $next move the message forward along the delivery ladder?
     *
     * queued → sent → delivered → read is strictly increasing. `failed` is a
     * separate terminal state that may only be entered from before delivery:
     * a message WhatsApp already handed to the device cannot later have failed,
     * so a stray failure callback must not erase a real delivery.
     */
    private static function statusAdvances(string $current, string $next): bool
    {
        $ladder = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

        if ($next === 'failed') {
            return ($ladder[$current] ?? 0) < 2 && $current !== 'failed';
        }

        if ($current === 'failed' || ! isset($ladder[$next])) {
            return false;
        }

        return $ladder[$next] > ($ladder[$current] ?? 0);
    }

    // ------------------------------------------------------------------
    // Template status update (Sprint 3)
    // ------------------------------------------------------------------

    /**
     * Handle Meta's message_template_status_update webhook event.
     *
     * Meta sends this when a template's approval status changes:
     * APPROVED, REJECTED, DISABLED, PAUSED, FLAGGED, etc.
     *
     * Reuses the same signed webhook endpoint — no extra registration needed.
     * Manual sync (GET /templates/{id}/sync) remains as a fallback.
     *
     * @see https://developers.facebook.com/docs/whatsapp/business-management-api/message-templates/
     */
    private function handleTemplateStatusUpdate(array $value): void
    {
        $metaTemplateId = (string) ($value['message_template_id'] ?? '');
        $event          = strtoupper((string) ($value['event']  ?? ''));
        $reason         = $value['reason'] ?? null;

        if (empty($metaTemplateId) || empty($event)) {
            log_message('warning', 'WebhookService::handleTemplateStatusUpdate — missing template id or event.');
            return;
        }

        $metaStatus = match($event) {
            'APPROVED'         => 'approved',
            'REJECTED'         => 'rejected',
            'DISABLED'         => 'disabled',
            'PAUSED'           => 'paused',
            'FLAGGED'          => 'paused',   // treat flagged as paused
            'PENDING_DELETION' => 'disabled',
            default            => null,
        };

        if ($metaStatus === null) {
            log_message('info', "WebhookService: unhandled template event '{$event}' for template {$metaTemplateId}");
            return;
        }

        $templateModel = new TemplateModel();
        $template      = $templateModel->findByMetaTemplateId($metaTemplateId);

        if ($template === null) {
            log_message('warning', "WebhookService: template status update for unknown meta_template_id {$metaTemplateId}");
            return;
        }

        $id = (int) (is_array($template) ? $template['id'] : $template->id);
        $templateModel->updateMetaStatus($id, $metaStatus, $reason);

        log_message('info', "WebhookService: template #{$id} status updated to '{$metaStatus}' via webhook.");
    }

    // ------------------------------------------------------------------
    // Interactive flow run resumption
    // ------------------------------------------------------------------

    /**
     * Resume any flow runs parked waiting for an interactive (button) reply.
     * Finds runs with state.waiting_for = 'interactive_reply' for this contact,
     * then resumes them on the handle matching the button_id.
     */
    private function resumeInteractiveFlowRuns(int $tenantId, int $contactId, string $buttonId): void
    {
        $runModel = new FlowRunModel();
        $waitingRuns = $runModel->withoutTenantScope()
            ->where('tenant_id',  $tenantId)
            ->where('contact_id', $contactId)
            ->where('status',     'waiting')
            ->findAll();

        foreach ($waitingRuns as $run) {
            $state = json_decode($run['state'] ?? '{}', true) ?: [];
            if (($state['waiting_for'] ?? '') !== 'interactive_reply') {
                continue;
            }

            $graph        = json_decode($run['graph_snapshot'] ?? '{}', true) ?: [];
            $buttonNodeId = $state['button_node_id'] ?? $run['current_node_id'];

            // Find the edge from the button node matching this button's handle (= button_id)
            $matchingEdge = null;
            foreach ($graph['edges'] ?? [] as $edge) {
                if ($edge['source'] === $buttonNodeId
                    && ($edge['sourceHandle'] ?? '') === $buttonId) {
                    $matchingEdge = $edge;
                    break;
                }
            }

            // If no specific button edge found, use the 'fallback' handle
            if ($matchingEdge === null) {
                foreach ($graph['edges'] ?? [] as $edge) {
                    if ($edge['source'] === $buttonNodeId
                        && ($edge['sourceHandle'] ?? '') === 'fallback') {
                        $matchingEdge = $edge;
                        break;
                    }
                }
            }

            if ($matchingEdge === null) {
                // No matching edge — complete the run
                $runModel->withoutTenantScope()->update((int) $run['id'], [
                    'status'       => 'completed',
                    'completed_at' => date('Y-m-d H:i:s'),
                ]);
                continue;
            }

            // Resume the run from the button's target node
            $nextNodeId = $matchingEdge['target'];
            $newState   = array_diff_key($state, ['waiting_for' => null, 'button_node_id' => null]);
            $newState['last_button_id'] = $buttonId;

            $runModel->withoutTenantScope()->update((int) $run['id'], [
                'status'          => 'running',
                'current_node_id' => $nextNodeId,
                'next_run_at'     => date('Y-m-d H:i:s'),
                'state'           => json_encode($newState),
            ]);

            // Dispatch a job to execute immediately
            JobDispatcher::dispatch(
                $tenantId,
                'flow_resume',
                ['flow_run_id' => (int) $run['id']],
                time()
            );

            log_message('info', "WebhookService: resumed flow run #{$run['id']} on button '{$buttonId}'");
        }
    }
}
