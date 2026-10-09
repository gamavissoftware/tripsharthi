<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\WhatsApp\TokenCipher;

/**
 * Connected Facebook Page (+ optional linked Instagram Business account).
 *
 * access_token_enc stores AES-256-GCM ciphertext — use getDecryptedToken().
 * Never log or return the raw token in responses.
 */
class SocialAccountModel extends BaseModel
{
    protected $table      = 'social_accounts';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'page_id', 'page_name', 'ig_user_id', 'ig_username',
        'access_token_enc', 'token_expires_at', 'connected_by', 'connect_method',
        'status', 'last_error',
    ];

    public function setToken(int $accountId, string $rawToken): void
    {
        $this->withoutTenantScope()->update($accountId, [
            'access_token_enc' => TokenCipher::encrypt($rawToken),
        ]);
    }

    public function getDecryptedToken(array|object $account): string
    {
        $enc = is_array($account) ? ($account['access_token_enc'] ?? '') : ($account->access_token_enc ?? '');
        if (empty($enc)) {
            return '';
        }

        return TokenCipher::decrypt($enc);
    }

    /**
     * Connect (or re-connect) a page, surviving a previous disconnect.
     *
     * (tenant_id, page_id) is UNIQUE while disconnect is a SOFT delete, so the
     * row is still there after "Disconnect" and a plain find() cannot see it —
     * a naive insert on reconnect dies with "Duplicate entry". Uses the raw
     * builder for the same reasons IntegrationModel::saveConfig does: to clear
     * deleted_at (not in allowedFields) and to bypass the soft-delete scope.
     *
     * @param array<string,mixed> $data
     */
    public function upsertPage(int $tenantId, string $pageId, array $data): int
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        $existing = $db->table($this->table)
            ->where('tenant_id', $tenantId)->where('page_id', $pageId)
            ->get()->getRowArray();

        if ($existing) {
            $db->table($this->table)->where('id', $existing['id'])->update(array_merge($data, [
                'deleted_at' => null, 'updated_at' => $now,
            ]));

            return (int) $existing['id'];
        }

        $db->table($this->table)->insert(array_merge($data, [
            'tenant_id'  => $tenantId,
            'page_id'    => $pageId,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        return (int) $db->insertID();
    }

    /**
     * Public shape — everything except the ciphertext.
     */
    public function publicRow(array $account): array
    {
        unset($account['access_token_enc']);

        return $account;
    }
}
