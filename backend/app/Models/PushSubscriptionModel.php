<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Browser push subscriptions. Plain model (not tenant-guarded) because it's
 * queried from the webhook context where there is no logged-in user — callers
 * always pass tenant_id explicitly.
 */
class PushSubscriptionModel extends Model
{
    protected $table         = 'push_subscriptions';
    protected $primaryKey    = 'id';
    protected $allowedFields  = ['tenant_id', 'user_id', 'endpoint', 'p256dh', 'auth'];
    protected $useTimestamps = true;

    /** Insert or refresh a subscription keyed by its unique endpoint. */
    public function upsert(int $tenantId, ?int $userId, string $endpoint, string $p256dh, string $auth): void
    {
        $existing = $this->where('endpoint', $endpoint)->first();
        if ($existing) {
            $this->update($existing['id'], ['tenant_id' => $tenantId, 'user_id' => $userId, 'p256dh' => $p256dh, 'auth' => $auth]);
        } else {
            $this->insert(['tenant_id' => $tenantId, 'user_id' => $userId, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth]);
        }
    }

    public function forTenant(int $tenantId): array
    {
        return $this->where('tenant_id', $tenantId)->findAll();
    }
}
