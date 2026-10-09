<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\CampaignModel;

/**
 * Builds a follow-up ("retarget") campaign aimed at the subset of an existing
 * campaign's recipients who match an engagement outcome.
 *
 * Outcomes (computed from the messages this campaign produced — see
 * messages.campaign_id):
 *   - not_delivered : message never reached the device (queued/sent/failed)
 *   - not_read      : delivered but never read
 *   - no_reply      : received the broadcast but never replied
 *   - clicked       : tapped a CTA/quick-reply button (requires click tracking, P1.4)
 *
 * resolveContactIds() is the pure-ish selection step (DB read only). The
 * controller then snapshots the resulting ids into a new draft campaign with a
 * {contact_ids:[...]} segment, which SegmentResolver already understands.
 */
class CampaignRetargeter
{
    public const OUTCOMES = ['not_delivered', 'not_read', 'no_reply', 'clicked'];

    /**
     * Resolve the contact ids of a campaign's recipients matching $outcome.
     *
     * @return int[]
     */
    public function resolveContactIds(int $tenantId, int $campaignId, string $outcome): array
    {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException("Unknown retarget outcome: {$outcome}");
        }

        $db = db_connect();
        $p  = $db->DBPrefix;

        $b = $db->table('messages m')
            ->distinct()
            ->select('m.contact_id')
            ->where('m.tenant_id', $tenantId)
            ->where('m.campaign_id', $campaignId)
            ->where('m.direction', 'out')
            ->where('m.contact_id IS NOT NULL', null, false);

        switch ($outcome) {
            case 'not_delivered':
                $b->whereIn('m.status', ['queued', 'sent', 'failed']);
                break;

            case 'not_read':
                $b->where('m.status !=', 'read');
                break;

            case 'no_reply':
                // No inbound message from this contact at/after the broadcast was sent.
                $b->where(
                    "NOT EXISTS (SELECT 1 FROM {$p}messages r "
                    . "WHERE r.contact_id = m.contact_id AND r.direction = 'in' "
                    . "AND r.created_at >= COALESCE(m.sent_at, m.created_at))",
                    null,
                    false
                );
                break;

            case 'clicked':
                // Recipients who tapped a CTA — joined via the click_events table (P1.4).
                $b->join("{$p}click_events ce", 'ce.message_id = m.id', 'inner');
                break;
        }

        $rows = $b->get()->getResultArray();

        return array_values(array_map(static fn ($r) => (int) $r['contact_id'], $rows));
    }

    /**
     * Create a draft retarget campaign frozen to the given contact ids.
     *
     * @param  int[] $contactIds
     * @return int   The new campaign id.
     */
    public function createRetargetCampaign(
        int $tenantId,
        int $sourceCampaignId,
        array $contactIds,
        ?int $templateId = null,
        ?string $name = null,
    ): int {
        $model  = (new CampaignModel())->setTenant($tenantId);
        $source = $model->find($sourceCampaignId);
        if ($source === null) {
            throw new \RuntimeException("Source campaign #{$sourceCampaignId} not found.");
        }

        $ids = array_values(array_unique(array_map('intval', $contactIds)));

        return (int) $model->insert([
            'name'        => $name ?? ('Retarget: ' . $source['name']),
            'template_id' => $templateId ?? (int) $source['template_id'],
            'segment'     => json_encode(['contact_ids' => $ids]),
            'status'      => 'draft',
        ], true);
    }
}
