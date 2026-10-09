<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\ActivityModel;
use App\Models\ConversationModel;
use App\Models\MessageModel;
use App\Models\QuoteModel;
use App\Services\WhatsApp\WindowService;

/**
 * Delivers a quote PDF over WhatsApp (Phase J1) by REUSING the existing media
 * pipeline: the 24h window-check, the provider adapter's uploadMedia + sendMedia.
 * No new WhatsApp protocol code. Outside the window the send is blocked and
 * logged exactly like any other free-form message (policy guardrail).
 *
 * The $client is injected (the real provider adapter in the controller, a fake in
 * tests) so the windowed-send behaviour is testable without a live WABA.
 */
final class QuoteSendService
{
    /**
     * @param object $client provider adapter exposing uploadMedia()/sendMedia()
     * @return array{sent:bool, blocked:bool, result?:array}
     */
    public function deliver(int $tenantId, array $quote, array $deal, array $contact, array $conv, object $client, ?int $now = null): array
    {
        $window = new WindowService(new ConversationModel(), $now);

        // ── Policy gate: a document is a free-form message — window must be open.
        if (! $window->isOpenForConversation($conv)) {
            (new MessageModel())->logBlocked(
                $tenantId,
                (int) ($conv['id'] ?? 0),
                (int) ($contact['id'] ?? 0) ?: null,
                "Quote {$quote['number']}",
                'Policy block: 24-hour window closed. Use a template message.'
            );
            return ['sent' => false, 'blocked' => true];
        }

        $pdfPath = (new QuotePdfService())->generate($quote, $deal, $tenantId);

        $up      = $client->uploadMedia($pdfPath, 'application/pdf');
        $mediaId = (string) ($up['id'] ?? $up['media_id'] ?? '');
        $result  = $client->sendMedia((string) $contact['wa_number'], 'document', $mediaId, "Quote {$quote['number']}", basename($pdfPath));

        if ($result['success'] ?? false) {
            (new MessageModel())->withoutTenantScope()->insert([
                'tenant_id'       => $tenantId,
                'conversation_id' => (int) ($conv['id'] ?? 0),
                'contact_id'      => (int) ($contact['id'] ?? 0) ?: null,
                'direction'       => 'out',
                'type'            => 'document',
                'body'            => "Quote {$quote['number']}",
                'status'          => 'sent',
                'wa_message_id'   => $result['message_id'] ?? null,
            ]);
            (new QuoteModel())->setTenant($tenantId)->update((int) $quote['id'], ['status' => 'sent']);
            (new ActivityModel())->log($tenantId, 'system', 'deal', (int) ($deal['id'] ?? 0), [
                'subject' => "Quote {$quote['number']} sent on WhatsApp",
            ]);
        }

        return ['sent' => (bool) ($result['success'] ?? false), 'blocked' => false, 'result' => $result];
    }
}
