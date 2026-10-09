<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Models\ClickEventModel;
use App\Models\MessageModel;

/**
 * Records inbound button / list / quick-reply taps as click events and
 * attributes them to the campaign that sent the original message.
 *
 * Attribution path:
 *   inbound reply.context.id  →  outbound message.wa_message_id
 *                             →  outbound message.campaign_id
 *
 * If the original message can't be resolved (no context, or a non-campaign
 * message), the click is still recorded with NULL campaign_id/message_id so it
 * counts as an engagement signal — it just won't roll up to a campaign.
 */
class ClickTracker
{
    public function __construct(
        private readonly ?MessageModel    $messageModel    = null,
        private readonly ?ClickEventModel $clickEventModel = null,
    ) {}

    /**
     * @param array{
     *   tenant_id:int, conversation_id?:int|null, contact_id?:int|null,
     *   context_wa_id?:string|null, button_id?:string|null, button_title?:string|null,
     *   source?:string, inbound_wa_id?:string|null, clicked_at?:string|null
     * } $p
     * @return int|null  click_event id, or null if it was a duplicate / skipped.
     */
    public function record(array $p): ?int
    {
        $tenantId = (int) ($p['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            return null;
        }

        $messageModel = $this->messageModel    ?? new MessageModel();
        $clickModel   = $this->clickEventModel ?? new ClickEventModel();

        // Idempotency: a replayed webhook for the same inbound reply must not double-count.
        $inboundWaId = $p['inbound_wa_id'] ?? null;
        if ($inboundWaId) {
            $existing = $clickModel->withoutTenantScope()
                ->where('inbound_wa_id', $inboundWaId)
                ->first();
            if ($existing !== null) {
                return null;
            }
        }

        // Resolve the outbound message that was replied to → campaign attribution.
        $campaignId = null;
        $messageId  = null;
        $contextWaId = $p['context_wa_id'] ?? null;
        if ($contextWaId) {
            $orig = $messageModel->findByWaMessageId($contextWaId);
            if ($orig !== null) {
                $orig = is_array($orig) ? $orig : (array) $orig;
                if (($orig['direction'] ?? '') === 'out') {
                    $messageId  = (int) $orig['id'];
                    $campaignId = isset($orig['campaign_id']) ? (int) $orig['campaign_id'] : null;
                    $campaignId = $campaignId ?: null;
                }
            }
        }

        $now = date('Y-m-d H:i:s');

        return (int) $clickModel->setTenant($tenantId)->insert([
            'campaign_id'     => $campaignId,
            'message_id'      => $messageId,
            'contact_id'      => ($p['contact_id'] ?? 0) ?: null,
            'conversation_id' => ($p['conversation_id'] ?? 0) ?: null,
            'button_id'       => $p['button_id']    ?? null,
            'button_title'    => $p['button_title'] ?? null,
            'source'          => $p['source']       ?? 'quick_reply',
            'inbound_wa_id'   => $inboundWaId,
            'clicked_at'      => $p['clicked_at']   ?? $now,
            'created_at'      => $now,
        ], true);
    }
}
