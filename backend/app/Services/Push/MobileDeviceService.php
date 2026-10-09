<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\MobileDeviceModel;

final class MobileDeviceService
{
    public const MAX_PER_USER = 10;

    /**
     * Register (or re-register) a phone. A token belongs to ONE user at a time: if it was registered to someone else
     * it MOVES here — a phone handed to a colleague must stop receiving the previous person's alerts.
     */
    public function register(int $tenantId, int $userId, array $in): array
    {
        $token = trim((string) ($in['expo_token'] ?? ''));
        if (! ExpoPushClient::validToken($token)) { throw new \InvalidArgumentException('That is not a valid Expo push token.'); }
        $platform = in_array($in['platform'] ?? '', ['ios', 'android', 'web'], true) ? $in['platform'] : 'unknown';
        $data = ['tenant_id' => $tenantId, 'user_id' => $userId, 'expo_token' => $token, 'platform' => $platform, 'device_name' => mb_substr(trim((string) ($in['device_name'] ?? '')), 0, 120) ?: null,
            'app_version' => mb_substr(trim((string) ($in['app_version'] ?? '')), 0, 30) ?: null, 'last_seen_at' => date('Y-m-d H:i:s'), 'disabled_at' => null, 'disabled_reason' => null];

        $existing = (new MobileDeviceModel())->withoutTenantScope()->where('expo_token', $token)->first();
        if ($existing) { (new MobileDeviceModel())->withoutTenantScope()->update((int) $existing['id'], $data); $id = (int) $existing['id']; }
        else { $id = (int) (new MobileDeviceModel())->withoutTenantScope()->insert($data, true); }

        // Cap devices per user: retire the least recently seen beyond the limit.
        $all = (new MobileDeviceModel())->setTenant($tenantId)->where('user_id', $userId)->where('disabled_at', null)->orderBy('last_seen_at', 'DESC')->findAll();
        foreach (array_slice($all, self::MAX_PER_USER) as $old) {
            (new MobileDeviceModel())->setTenant($tenantId)->update((int) $old['id'], ['disabled_at' => date('Y-m-d H:i:s'), 'disabled_reason' => 'too_many_devices']);
        }
        return $this->present((new MobileDeviceModel())->withoutTenantScope()->find($id));
    }

    /** Only the owner of a token may unregister it (logout on the phone). */
    public function unregister(int $tenantId, int $userId, string $token): bool
    {
        $d = (new MobileDeviceModel())->setTenant($tenantId)->where('user_id', $userId)->where('expo_token', $token)->first();
        if (! $d) { return false; }
        (new MobileDeviceModel())->setTenant($tenantId)->update((int) $d['id'], ['disabled_at' => date('Y-m-d H:i:s'), 'disabled_reason' => 'logged_out']);
        return true;
    }

    /** @return list<array> */
    public function forUser(int $tenantId, int $userId): array
    {
        return array_map([$this, 'present'], (new MobileDeviceModel())->setTenant($tenantId)->where('user_id', $userId)->where('disabled_at', null)->orderBy('last_seen_at', 'DESC')->findAll());
    }

    /** The token itself is a credential for sending to that phone — never echo it back in full. */
    private function present(array $d): array
    {
        return ['id' => (int) $d['id'], 'platform' => $d['platform'], 'device_name' => $d['device_name'], 'app_version' => $d['app_version'], 'last_seen_at' => $d['last_seen_at'],
            'token_hint' => '…' . substr((string) $d['expo_token'], -6, 5), 'active' => $d['disabled_at'] === null];
    }
}
