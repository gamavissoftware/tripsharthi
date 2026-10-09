<?php

declare(strict_types=1);

namespace App\Models;

class WebhookSubscriptionModel extends BaseModel
{
    protected $table      = 'webhook_subscriptions';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'url', 'secret', 'events', 'is_active',
        'last_status', 'last_delivered_at', 'failure_count',
    ];

    /**
     * Active subscriptions for a tenant whose event list includes $event or "*".
     * Cross-tenant scope is NOT used; caller passes tenantId explicitly.
     *
     * @return array<int, array>
     */
    public function activeForEvent(int $tenantId, string $event): array
    {
        $rows = $this->setTenant($tenantId)->where('is_active', 1)->findAll();

        return array_values(array_filter($rows, static function (array $sub) use ($event): bool {
            $events = is_string($sub['events'] ?? null)
                ? (json_decode($sub['events'], true) ?: [])
                : ($sub['events'] ?? []);
            return in_array('*', $events, true) || in_array($event, $events, true);
        }));
    }
}
