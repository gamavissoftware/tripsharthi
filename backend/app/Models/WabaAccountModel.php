<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\WhatsApp\ProviderAdapter;
use App\Services\WhatsApp\TokenCipher;
// PhoneNumberModel loaded lazily inside buildAdapter

/**
 * WABA account model — tenant-scoped.
 *
 * IMPORTANT: access_token_enc stores AES-256-GCM ciphertext.
 * Use getDecryptedToken() to obtain the raw token for API calls.
 * Never log or return the raw token in responses.
 */
class WabaAccountModel extends BaseModel
{
    protected $table      = 'waba_accounts';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'waba_id', 'business_id', 'app_id',
        'access_token_enc', 'display_name', 'verify_token', 'status',
        'provider', 'provider_config_json',
    ];

    public function findActive(int $tenantId): array|object|null
    {
        return $this->setTenant($tenantId)
                    ->where('status', 'active')
                    ->first();
    }

    /**
     * Encrypt and store the raw access token.
     */
    public function setToken(int $accountId, string $rawToken): void
    {
        $this->withoutTenantScope()->update($accountId, [
            'access_token_enc' => TokenCipher::encrypt($rawToken),
        ]);
    }

    /**
     * Return the decrypted raw access token for a given account row.
     * Returns '' if not set.
     */
    public function getDecryptedToken(array|object $account): string
    {
        $enc = is_array($account) ? ($account['access_token_enc'] ?? '') : ($account->access_token_enc ?? '');
        if (empty($enc)) return '';
        return TokenCipher::decrypt($enc);
    }

    /**
     * Build a ProviderAdapter for this account (decrypts the token + resolves phone_number_id).
     */
    public function buildAdapter(array|object $account): ProviderAdapter
    {
        $rawToken  = $this->getDecryptedToken($account);
        $accountArr = is_array($account) ? $account : (array) $account;
        $accountId  = (int) ($accountArr['id'] ?? 0);
        $tenantId   = (int) ($accountArr['tenant_id'] ?? 0);

        // Inject phone_number_id into the account array so ProviderAdapter can build the Meta client
        if (empty($accountArr['phone_number_id']) && $accountId > 0) {
            $pn = (new PhoneNumberModel())->setTenant($tenantId)->where('waba_account_id', $accountId)->first();
            if ($pn) {
                $accountArr['phone_number_id'] = is_array($pn) ? ($pn['phone_number_id'] ?? '') : ($pn->phone_number_id ?? '');
            }
        }

        return ProviderAdapter::fromAccount($accountArr, $rawToken ?: null);
    }

    /**
     * Find account by verify_token (used in webhook verification — cross-tenant).
     */
    public function findByVerifyToken(string $token): array|object|null
    {
        return $this->withoutTenantScope()
                    ->where('verify_token', $token)
                    ->where('deleted_at IS NULL', null, false)
                    ->first();
    }
}
