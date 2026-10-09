<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Unified, immutable timeline log. Append via log(); read a record's history
 * via forRecord(). WhatsApp messages are mirrored here so a record's timeline
 * shows the full thread inline alongside notes, calls and system events.
 */
class ActivityModel extends BaseModel
{
    protected $table      = 'activities';
    protected $primaryKey = 'id';

    protected $useSoftDeletes = false; // append-only log

    protected $allowedFields = [
        'tenant_id', 'type', 'subject', 'body', 'related_type', 'related_id',
        'actor_user_id', 'meta', 'occurred_at',
    ];

    /**
     * Append a timeline entry for a record. Returns the new activity id.
     *
     * @param array $extra  Optional: subject, body, actor_user_id, meta (array), occurred_at
     */
    public function log(int $tenantId, string $type, string $relatedType, int $relatedId, array $extra = []): int
    {
        $meta = $extra['meta'] ?? null;

        return (int) $this->setTenant($tenantId)->insert([
            'type'          => $type,
            'subject'       => $extra['subject'] ?? null,
            'body'          => $extra['body'] ?? null,
            'related_type'  => $relatedType,
            'related_id'    => $relatedId,
            'actor_user_id' => $extra['actor_user_id'] ?? null,
            'meta'          => $meta !== null ? json_encode($meta) : null,
            'occurred_at'   => $extra['occurred_at'] ?? date('Y-m-d H:i:s'),
        ], true);
    }

    /** Newest-first timeline for one record. */
    public function forRecord(int $tenantId, string $relatedType, int $relatedId, int $limit = 100): array
    {
        return $this->setTenant($tenantId)
            ->where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->orderBy('occurred_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll($limit);
    }
}
