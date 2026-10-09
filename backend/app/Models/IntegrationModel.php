<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\WhatsApp\TokenCipher;

class IntegrationModel extends BaseModel
{
    protected $table      = 'integrations';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'type', 'page_id', 'verify_token', 'config', 'status',
    ];

    /**
     * Resolve a Facebook page_id → integration → tenant for webhook handling.
     * Cross-tenant lookup — page_id is globally unique in Meta's system.
     */
    public function findByPageId(string $pageId): array|object|null
    {
        return $this->withoutTenantScope()
                    ->where('type', 'meta_lead_ads')
                    ->where('page_id', $pageId)
                    ->where('status', 'active')
                    
                    ->first();
    }

    /**
     * Resolve a Google Ads Lead Forms shared key → integration → tenant.
     *
     * The raw google_key is NEVER stored. verify_token holds a SHA-256 hash of it
     * (replay-safe: a DB read cannot recover the key), and config.google_key_enc
     * holds the encrypted key for owner re-display. We hash the incoming key and
     * match on the indexed hash. Filtering on type keeps this isolated from Meta's
     * plaintext verify_token challenge scheme.
     */
    public function findByGoogleKey(string $key): array|object|null
    {
        return $this->withoutTenantScope()
                    ->where('type', 'google_lead_forms')
                    ->where('verify_token', self::hashGoogleKey($key))
                    ->where('status', 'active')
                    ->first();
    }

    /** Deterministic lookup hash for a Google webhook key (high-entropy input → no salt needed). */
    public static function hashGoogleKey(string $key): string
    {
        return hash('sha256', $key);
    }

    /** Decrypt the stored google_key for display to the integration's own owner/admin. */
    public function decryptGoogleKey(array|object $integration): string
    {
        $row    = is_array($integration) ? $integration : (array) $integration;
        $config = json_decode($row['config'] ?? '{}', true) ?: [];
        $enc    = $config['google_key_enc'] ?? '';
        return $enc ? TokenCipher::decrypt($enc) : '';
    }

    /**
     * Resolve a verify_token → integration for webhook challenge handling.
     */
    public function findByVerifyToken(string $token): array|object|null
    {
        return $this->withoutTenantScope()
                    ->where('verify_token', $token)
                    ->where('status', 'active')
                    
                    ->first();
    }

    /**
     * Return the decrypted Page Access Token from a loaded integration row.
     */
    public function decryptPageToken(array|object $integration): string
    {
        $row    = is_array($integration) ? $integration : (array) $integration;
        $config = json_decode($row['config'] ?? '{}', true) ?: [];
        $enc    = $config['page_access_token_enc'] ?? '';
        return $enc ? TokenCipher::decrypt($enc) : '';
    }

    public function getDefaultCountryCode(array|object $integration): string
    {
        $row    = is_array($integration) ? $integration : (array) $integration;
        $config = json_decode($row['config'] ?? '{}', true) ?: [];
        return $config['default_country_code'] ?? '';
    }

    /**
     * Upsert a tenant's integration of a given type — restoring a previously
     * soft-deleted row instead of inserting a duplicate.
     *
     * The UNIQUE(tenant_id, type) constraint counts soft-deleted rows, so a
     * disconnect→reconnect would hit "Duplicate entry" if we naively inserted.
     * Uses the raw builder so we can clear deleted_at (not in allowedFields)
     * and bypass the soft-delete read scope.
     *
     * @param array $data e.g. ['config' => '{...}', 'page_id' => '...']
     */
    public function saveConfig(int $tenantId, string $type, array $data): int
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        $existing = $db->table('integrations')
            ->where('tenant_id', $tenantId)->where('type', $type)
            ->get()->getRowArray();

        if ($existing) {
            $db->table('integrations')->where('id', $existing['id'])->update(array_merge($data, [
                'status' => 'active', 'deleted_at' => null, 'updated_at' => $now,
            ]));
            return (int) $existing['id'];
        }

        $db->table('integrations')->insert(array_merge($data, [
            'tenant_id' => $tenantId, 'type' => $type, 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]));
        return (int) $db->insertID();
    }

    /**
     * Find a tenant's active integration of a given type.
     */
    public function findActiveByType(int $tenantId, string $type): array|object|null
    {
        return $this->setTenant($tenantId)
            ->where('type', $type)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Find any integration of a type by a config/top-level column, cross-tenant
     * (webhook resolution where tenant context isn't carried).
     */
    public function findByTypeCrossTenant(string $type, string $column, string $value): array|object|null
    {
        return $this->withoutTenantScope()
            ->where('type', $type)
            ->where($column, $value)
            ->first();
    }

    /**
     * Decode a tenant's Razorpay payment credentials from an integration row.
     * key_id is stored plaintext; key_secret / webhook_secret are encrypted.
     *
     * @return array{key_id:string, key_secret:string, webhook_secret:string}
     */
    public function razorpayKeys(array|object $integration): array
    {
        $row    = is_array($integration) ? $integration : (array) $integration;
        $config = json_decode($row['config'] ?? '{}', true) ?: [];

        return [
            'key_id'         => $config['key_id'] ?? '',
            'key_secret'     => ! empty($config['key_secret_enc'])     ? TokenCipher::decrypt($config['key_secret_enc'])     : '',
            'webhook_secret' => ! empty($config['webhook_secret_enc']) ? TokenCipher::decrypt($config['webhook_secret_enc']) : '',
        ];
    }

    /** Decrypt the webhook_secret from any integration's config. */
    public function decryptWebhookSecret(array|object $integration): string
    {
        $row    = is_array($integration) ? $integration : (array) $integration;
        $config = json_decode($row['config'] ?? '{}', true) ?: [];
        return ! empty($config['webhook_secret_enc']) ? TokenCipher::decrypt($config['webhook_secret_enc']) : '';
    }

    /** Read a plaintext config value. */
    public function configValue(array|object $integration, string $key, string $default = ''): string
    {
        $row    = is_array($integration) ? $integration : (array) $integration;
        $config = json_decode($row['config'] ?? '{}', true) ?: [];
        return (string) ($config[$key] ?? $default);
    }
}
