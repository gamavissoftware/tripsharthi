<?php

declare(strict_types=1);

namespace App\Models;

class MessageModel extends BaseModel
{
    protected $table      = 'messages';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false;

    protected $allowedFields = [
        'tenant_id', 'contact_id', 'conversation_id', 'campaign_id', 'template_id', 'variant',
        'direction', 'type', 'category',
        'body', 'media_url', 'wa_message_id',
        'status', 'billable', 'error',
        'sent_at', 'delivered_at', 'read_at',
    ];

    /**
     * Find a message by Meta's wa_message_id for status callback updates.
     * Cross-tenant — status callbacks don't carry tenant context.
     */
    public function findByWaMessageId(string $waMessageId): array|object|null
    {
        return $this->withoutTenantScope()
                    ->where('wa_message_id', $waMessageId)
                    ->first();
    }

    /**
     * Attach the carousel `cards` of the template each message used, so a
     * carousel renders as cards rather than only its intro text.
     *
     * @param array<int,array|object> $rows
     * @return array<int,array|object>
     */
    public static function withTemplateCards(array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $tid = (int) (is_array($r) ? ($r['template_id'] ?? 0) : ($r->template_id ?? 0));
            if ($tid > 0) {
                $ids[$tid] = true;
            }
        }
        if ($ids === []) {
            return $rows;
        }

        $cardsById = [];
        foreach (db_connect()->table('templates')->select('id, cards')->whereIn('id', array_keys($ids))->get()->getResultArray() as $t) {
            $decoded = json_decode((string) ($t['cards'] ?? ''), true);
            if (is_array($decoded) && $decoded !== []) {
                $cardsById[(int) $t['id']] = $decoded;
            }
        }

        foreach ($rows as &$r) {
            $tid   = (int) (is_array($r) ? ($r['template_id'] ?? 0) : ($r->template_id ?? 0));
            $cards = $cardsById[$tid] ?? null;
            if ($cards === null) {
                continue;
            }
            if (is_array($r)) {
                $r['cards'] = $cards;
            } else {
                $r->cards = $cards;
            }
        }
        unset($r);

        return $rows;
    }

    /**
     * Messages for a conversation, newest-last, with optional ?since= cursor.
     */
    public function forConversation(int $tenantId, int $conversationId, ?string $since = null, int $limit = 50): array
    {
        $this->setTenant($tenantId)->where('conversation_id', $conversationId);

        if ($since !== null) {
            // Incremental poll: everything newer than the last seen message, oldest-first.
            return $this->where('created_at >', $since)
                        ->orderBy('created_at', 'ASC')
                        ->orderBy('id', 'ASC')
                        ->findAll($limit);
        }

        // Initial load: return the most RECENT $limit messages (not the oldest).
        // Ordering ASC + LIMIT returned the oldest N, so once a conversation had
        // more than $limit messages, brand-new inbound/outbound messages fell
        // outside the window and never appeared in the inbox. Fetch newest-first,
        // then reverse so the thread still renders oldest → newest.
        $rows = $this->orderBy('created_at', 'DESC')
                     ->orderBy('id', 'DESC')
                     ->findAll($limit);

        return array_reverse($rows);
    }

    /**
     * Record a policy-blocked outbound attempt.
     * These are logged so the business can audit what was blocked.
     */
    /**
     * Outbound message counts per campaign, keyed by campaign id.
     *
     * The campaigns.stats JSON records send-time bookkeeping (billable_sends,
     * skipped_opt_out, …), NOT delivery outcomes — so anything that reports
     * sent/delivered/read must count the message rows themselves.
     *
     * 'sent' counts everything that left the system (delivered/read imply sent)
     * and 'delivered' includes read, mirroring WhatsApp's status ladder.
     *
     * @param  list<int> $campaignIds
     * @return array<int, array{sent:int, delivered:int, read:int, failed:int}>
     */
    public function deliveryCountsByCampaign(int $tenantId, array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }

        $rows = $this->builder()
            ->select('campaign_id, status, COUNT(*) AS c')
            ->where('tenant_id', $tenantId)
            ->where('direction', 'out')
            ->whereIn('campaign_id', $campaignIds)
            ->groupBy(['campaign_id', 'status'])
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $id     = (int) $r['campaign_id'];
            $status = (string) $r['status'];
            $n      = (int) $r['c'];

            $out[$id] ??= ['sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0];

            if (in_array($status, ['sent', 'delivered', 'read'], true)) {
                $out[$id]['sent'] += $n;
            }
            if (in_array($status, ['delivered', 'read'], true)) {
                $out[$id]['delivered'] += $n;
            }
            if ($status === 'read') {
                $out[$id]['read'] += $n;
            }
            if ($status === 'failed') {
                $out[$id]['failed'] += $n;
            }
        }

        return $out;
    }

    public function logBlocked(
        int $tenantId,
        int $conversationId,
        ?int $contactId,
        string $attemptedBody,
        string $reason
    ): int {
        return (int) $this->withoutTenantScope()->insert([
            'tenant_id'       => $tenantId,
            'contact_id'      => $contactId,
            'conversation_id' => $conversationId,
            'direction'       => 'out',
            'type'            => 'text',
            'body'            => $attemptedBody,
            'status'          => 'failed',
            'billable'        => 0,
            'error'           => $reason,
            // Sprint 3: compute billable from category + window state
        ], true);
    }
}
