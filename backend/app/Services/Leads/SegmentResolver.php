<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\ContactModel;
use App\Models\SegmentModel;

/**
 * Converts a campaign segment JSON definition into a scoped ContactModel query.
 *
 * Segment JSON shapes:
 *   {"all": true}                         — all non-deleted contacts
 *   {"tag_ids": [1, 2]}                   — contacts with any of these tags
 *   {"status": "new"}                     — contacts with this status
 *   {"source": "csv_import"}              — contacts from this source
 *   {"category": "Apparel, Textiles…"}    — contacts in this company category
 *   {"not_replied": true}                 — never sent us an inbound message
 *   {"replied": true}                     — has written to us at least once
 *   {"tag_ids": [1], "status": "new"}     — AND combination
 *   {"segment_id": 3, "name": "Hot Leads"}— saved segment (resolves filters from DB)
 *
 * status / source / category also accept a list, and their plural spellings
 * ("statuses", "sources", "categories"); tags also accept a scalar "tag_id".
 *
 * A non-empty segment that matches NO known key resolves to zero contacts, never
 * to the whole list — see the fail-closed guard in apply().
 *
 * For marketing templates, opt_in=1 is added automatically by the caller
 * (CampaignSender handles this as the gate layer).
 */
class SegmentResolver
{
    /**
     * Apply segment filters to an already-tenant-scoped ContactModel.
     * Caller must have called setTenant() before passing the model in.
     */
    public function apply(ContactModel $model, array $segment): ContactModel
    {
        if (empty($segment) || ! empty($segment['all'])) {
            return $model;
        }

        // ── Explicit contact-id list (used by retargeting) ────────────
        // A frozen audience snapshot: send only to these contacts.
        if (! empty($segment['contact_ids']) && is_array($segment['contact_ids'])) {
            $ids = array_values(array_filter(array_map('intval', $segment['contact_ids'])));
            // Guard against an empty IN () which MySQL rejects — force no rows.
            $model->whereIn('contacts.id', $ids !== [] ? $ids : [0]);
            return $model;
        }

        // ── Saved segment: resolve filters from DB ────────────────────
        if (! empty($segment['segment_id'])) {
            // Scope the saved-segment lookup to the same tenant as the contact
            // query — never resolve another tenant's segment definition.
            $segRow = (new SegmentModel())
                ->setTenant($model->getTenantId())
                ->find((int) $segment['segment_id']);
            if ($segRow !== null) {
                $filters = is_string($segRow['filters'])
                    ? (json_decode($segRow['filters'], true) ?: [])
                    : ($segRow['filters'] ?? []);

                foreach ($filters as $f) {
                    $field    = $f['field']    ?? '';
                    $operator = $f['operator'] ?? 'equals';
                    $value    = $f['value']    ?? null;
                    $values   = $f['values']   ?? [];

                    match(true) {
                        $field === 'status' && $operator === 'equals'     => $model->where('contacts.status', $value),
                        $field === 'status' && $operator === 'not_equals' => $model->where('contacts.status !=', $value),
                        $field === 'source' && $operator === 'equals'     => $model->where('contacts.source', $value),
                        $field === 'source' && $operator === 'not_equals' => $model->where('contacts.source !=', $value),
                        $field === 'opt_in'                               => $model->where('contacts.opt_in', ($value === 'true' || $value === '1') ? 1 : 0),
                        $field === 'tag_id' && $operator === 'equals' && $value > 0 =>
                            $model->join('contact_tags ct_seg', 'ct_seg.contact_id = contacts.id')
                                  ->where('ct_seg.tag_id', (int) $value)
                                  ->distinct(),
                        default => null,
                    };
                }
            }
            return $model;
        }

        // Each recognised key narrows the audience. `$matched` tracks whether any
        // did — see the fail-closed guard at the end.
        $matched = false;

        // ── Tags ──────────────────────────────────────────────────────
        // Accept the documented `tag_ids` list and the campaign wizard's
        // historical scalar `tag_id`. Without the second spelling a wizard
        // tag campaign matched nothing here and fell through to everyone.
        $tagIds = [];
        if (! empty($segment['tag_ids']) && is_array($segment['tag_ids'])) {
            $tagIds = array_values(array_filter(array_map('intval', $segment['tag_ids'])));
        } elseif (! empty($segment['tag_id'])) {
            $tagIds = array_values(array_filter([(int) $segment['tag_id']]));
        }
        if ($tagIds !== []) {
            $model->join('contact_tags ct_seg', 'ct_seg.contact_id = contacts.id')
                  ->whereIn('ct_seg.tag_id', $tagIds)
                  ->distinct();
            $matched = true;
        }

        // ── Status / source / category — scalar or list ───────────────
        $statuses = $this->toList($segment['status'] ?? $segment['statuses'] ?? null);
        if ($statuses !== []) {
            $model->whereIn('contacts.status', $statuses);
            $matched = true;
        }

        $sources = $this->toList($segment['source'] ?? $segment['sources'] ?? null);
        if ($sources !== []) {
            $model->whereIn('contacts.source', $sources);
            $matched = true;
        }

        // Company category, as shown on the contacts list and inbox.
        $categories = $this->toList($segment['category'] ?? $segment['categories'] ?? null);
        if ($categories !== []) {
            $model->whereIn('contacts.business_type', $categories);
            $matched = true;
        }

        // ── Reply state ───────────────────────────────────────────────
        // The follow-up audience: people the opener reached who never wrote
        // back. last_inbound_at is set on every inbound message, so NULL means
        // this contact has never once replied to us.
        if (! empty($segment['not_replied'])) {
            $model->where('contacts.last_inbound_at', null);
            $matched = true;
        } elseif (! empty($segment['replied'])) {
            $model->where('contacts.last_inbound_at IS NOT NULL');
            $matched = true;
        }

        if (! $matched) {
            // Fail CLOSED. A segment we cannot interpret must never widen to
            // the whole contact list: the caller asked for a subset, and
            // silently broadcasting to every contact instead is how a WABA
            // gets banned. Zero recipients is visible and recoverable.
            log_message('error', '[Segment] unrecognised definition, resolving to no contacts: '
                . json_encode(array_keys($segment)));
            $model->whereIn('contacts.id', [0]);
        }

        return $model;
    }

    /**
     * Normalise a scalar-or-list filter value into a list of non-empty strings.
     *
     * @param  mixed $value
     * @return list<string>
     */
    private function toList($value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        $items = is_array($value) ? $value : [$value];

        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                continue;
            }
            $s = trim((string) $item);
            if ($s !== '') {
                $out[$s] = $s;
            }
        }

        return array_values($out);
    }

    /**
     * Count contacts matching the segment (used to snapshot total_contacts).
     * Creates a fresh ContactModel to avoid polluting the caller's builder state.
     */
    public function countTotal(int $tenantId, array $segment, bool $marketingOptInOnly = false): int
    {
        $model = new ContactModel();
        $model->setTenant($tenantId)->select('contacts.id');

        $this->apply($model, $segment);

        if ($marketingOptInOnly) {
            $model->where('contacts.opt_in', 1);
        }

        return $model->countAllResults();
    }
}
