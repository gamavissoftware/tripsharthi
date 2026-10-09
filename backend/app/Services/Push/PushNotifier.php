<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\MobileDeviceModel;
use App\Models\NotificationModel;
use App\Models\PushLogModel;

/**
 * THE entry point for notifying a person. In order, and each step is tested:
 *   1 duplicate?        -> nothing at all (same event never notifies twice)
 *   2 in-app row        -> always (the bell + phone inbox are the durable record)
 *   3 preferences       -> off / category off / not staff / quiet hours  => logged as suppressed (with the reason)
 *   4 flood throttle    -> after PUSH_HOURLY_CAP pushes an hour, one "more updates" summary instead of a storm
 *   5 devices           -> none registered => logged, in-app only
 *   6 privacy           -> "minimal" swaps names/amounts for generic text
 *   7 queue + deliver   -> best effort inline (short timeout); anything left queued is retried by `push:run`
 * A push failure must NEVER break the action that caused it: callers go through safely().
 */
final class PushNotifier
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly ?ExpoPushClient $client = null, private readonly ?int $now = null) {}

    private function now(): int { return $this->now ?? time(); }
    private function stamp(): string { return date('Y-m-d H:i:s', $this->now()); }
    private function client(): ExpoPushClient { return $this->client ?? new ExpoPushClient(); }

    /** Run inside a business operation without ever letting a push problem escape. */
    public static function safely(callable $fn): void
    {
        try { $fn(new self()); } catch (\Throwable $e) { log_message('error', 'push failed: ' . $e->getMessage()); }
    }

    /** @return array{status:string,devices:int,reason:?string} */
    public function notify(int $tenantId, int $userId, string $category, string $event, string $title, string $body, array $data = [], ?string $dedupeKey = null, ?string $webLink = null, bool $inApp = true, bool $bypassThrottle = false): array
    {
        $db = db_connect();
        $user = $db->table('users')->select('id, role')->where('id', $userId)->where('tenant_id', $tenantId)->where('deleted_at', null)->get()->getRowArray();
        if (! $user || ! PushCategory::valid($category)) { return ['status' => 'ignored', 'devices' => 0, 'reason' => 'unknown_user_or_category']; }

        if ($dedupeKey !== null && $db->table('push_log')->where('user_id', $userId)->where('dedupe_key', $dedupeKey)->countAllResults() > 0) {
            return ['status' => 'duplicate', 'devices' => 0, 'reason' => 'duplicate'];
        }
        if ($inApp) {
            (new NotificationModel())->insert(['tenant_id' => $tenantId, 'user_id' => $userId, 'type' => mb_substr($event, 0, 30), 'body' => mb_substr($title . ($body !== '' ? ' — ' . $body : ''), 0, 500), 'link' => $webLink, 'created_at' => $this->stamp()]);
        }
        // array_merge, NOT `+`: the overrides in $f (privacy-minimal text, the injected clock) must win over these defaults.
        $log = fn (array $f) => array_merge(['tenant_id' => $tenantId, 'user_id' => $userId, 'category' => $category, 'event' => mb_substr($event, 0, 30), 'dedupe_key' => $dedupeKey,
            'title' => mb_substr($title, 0, 120), 'body' => mb_substr($body, 0, 300), 'created_at' => $this->stamp()], $f);

        $pref = (new PushPreferenceService())->get($tenantId, $userId);
        if ($reason = PushPreferenceService::blockedReason($pref, $category, $this->now(), (string) $user['role'])) {
            (new PushLogModel())->insert($log(['status' => 'suppressed', 'reason' => $reason, 'created_at' => $this->stamp()]));
            return ['status' => 'suppressed', 'devices' => 0, 'reason' => $reason];
        }

        if (! $bypassThrottle) {
            $cap = (int) (env('PUSH_HOURLY_CAP') ?: 30);
            $recent = $db->table('push_log')->where('user_id', $userId)->whereIn('status', ['queued', 'sent', 'delivered'])->where('created_at >', date('Y-m-d H:i:s', $this->now() - 3600))->countAllResults();
            if ($recent >= $cap) {
                (new PushLogModel())->insert($log(['status' => 'suppressed', 'reason' => 'throttled', 'created_at' => $this->stamp()]));
                $held = $db->table('push_log')->where('user_id', $userId)->where('reason', 'throttled')->where('created_at >', date('Y-m-d H:i:s', $this->now() - 3600))->countAllResults();
                $this->notify($tenantId, $userId, $category, 'summary', 'More updates waiting', "You have {$held} more update" . ($held > 1 ? 's' : '') . ' — open TravelPilot to see them.',
                    ['screen' => 'Notifications', 'params' => new \stdClass()], 'summary:' . date('YmdH', $this->now()), null, false, true);
                return ['status' => 'suppressed', 'devices' => 0, 'reason' => 'throttled'];
            }
        }

        $devices = (new MobileDeviceModel())->setTenant($tenantId)->where('user_id', $userId)->where('disabled_at', null)->findAll();
        if (! $devices) {
            (new PushLogModel())->insert($log(['status' => 'suppressed', 'reason' => 'no_device', 'created_at' => $this->stamp()]));
            return ['status' => 'suppressed', 'devices' => 0, 'reason' => 'no_device'];
        }

        if ($pref['privacy'] === 'minimal') { [$title, $body] = PushCategory::genericText($category); }
        $ids = [];
        foreach ($devices as $d) {
            $ids[] = (int) (new PushLogModel())->insert($log(['device_id' => $d['id'], 'title' => $title, 'body' => $body, 'data' => json_encode($data + ['category' => $category, 'event' => $event], JSON_UNESCAPED_UNICODE),
                'status' => 'queued', 'created_at' => $this->stamp()]), true);
        }
        $this->deliver($ids);
        return ['status' => 'queued', 'devices' => count($devices), 'reason' => null];
    }

    /**
     * "Send me a test": an explicit user action, so it ignores category/quiet-hour preferences (but needs a device),
     * and is limited to 5 an hour. @return array{devices:int,sent:int,error:int}
     */
    public function sendTest(int $tenantId, int $userId): array
    {
        $db = db_connect();
        if ($db->table('push_log')->where('user_id', $userId)->where('event', 'test')->where('created_at >', date('Y-m-d H:i:s', $this->now() - 3600))->countAllResults() >= 5) {
            throw new \DomainException('That is enough test notifications for now — try again in an hour.');
        }
        $devices = (new MobileDeviceModel())->setTenant($tenantId)->where('user_id', $userId)->where('disabled_at', null)->findAll();
        if (! $devices) { throw new \DomainException('No phone is registered for your account yet. Open the TravelPilot app and allow notifications.'); }
        $ids = [];
        foreach ($devices as $d) {
            $ids[] = (int) (new PushLogModel())->insert(['tenant_id' => $tenantId, 'user_id' => $userId, 'device_id' => $d['id'], 'category' => 'task', 'event' => 'test', 'title' => 'TravelPilot test',
                'body' => 'Notifications are working on this phone. 🎉', 'data' => json_encode(['screen' => 'Notifications', 'params' => new \stdClass(), 'category' => 'task', 'event' => 'test']), 'status' => 'queued', 'created_at' => $this->stamp()], true);
        }
        $r = $this->deliver($ids);
        return ['devices' => count($devices), 'sent' => $r['sent'], 'error' => $r['error']];
    }

    /** @param list<int> $userIds */
    public function notifyMany(int $tenantId, array $userIds, string $category, string $event, string $title, string $body, array $data = [], ?string $dedupeKey = null, ?string $webLink = null): void
    {
        foreach (array_unique($userIds) as $uid) { $this->notify($tenantId, (int) $uid, $category, $event, $title, $body, $data, $dedupeKey, $webLink); }
    }

    // ---- delivery ---------------------------------------------------------------------------------------------

    /** Send the given queued rows now (≤100 per Expo request). Rows Expo could not take stay queued for push:run. */
    public function deliver(array $logIds): array
    {
        $out = ['sent' => 0, 'error' => 0, 'queued' => 0];
        if (! $logIds) { return $out; }
        $rows = db_connect()->table('push_log l')->select('l.*, d.expo_token, d.id AS dev_id, d.disabled_at')->join('mobile_devices d', 'd.id = l.device_id', 'left')
            ->whereIn('l.id', $logIds)->where('l.status', 'queued')->get()->getResultArray();
        foreach (array_chunk($rows, 100) as $batch) {
            $msgs = []; $map = [];
            foreach ($batch as $r) {
                if (empty($r['expo_token']) || $r['disabled_at']) { $this->mark((int) $r['id'], ['status' => 'error', 'reason' => 'device_disabled']); $out['error']++; continue; }
                $badge = (new NotificationModel())->unreadCount((int) $r['tenant_id'], (int) $r['user_id']);
                $msgs[] = ['to' => $r['expo_token'], 'title' => $r['title'], 'body' => $r['body'], 'data' => json_decode((string) $r['data'], true) ?: new \stdClass(), 'sound' => 'default', 'priority' => 'high',
                    'channelId' => $r['category'], 'ttl' => 21600, 'badge' => $badge];
                $map[] = $r;
            }
            if (! $msgs) { continue; }
            try {
                $tickets = $this->client()->send($msgs);
            } catch (PushTransportException $e) {
                foreach ($map as $r) { $this->retryLater($r); $out['queued']++; }
                log_message('warning', 'push transport: ' . $e->getMessage());
                continue;
            } catch (\RuntimeException $e) {                     // 4xx: our request is wrong — retrying cannot help
                foreach ($map as $r) { $this->mark((int) $r['id'], ['status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 110)]); $out['error']++; }
                log_message('error', 'push rejected: ' . $e->getMessage());
                continue;
            }
            foreach ($map as $i => $r) {
                $t = $tickets[$i];
                if ($t['status'] === 'ok') {
                    $this->mark((int) $r['id'], ['status' => 'sent', 'ticket_id' => $t['id'], 'sent_at' => $this->stamp(), 'attempts' => (int) $r['attempts'] + 1]);
                    $out['sent']++;
                } elseif (ExpoPushClient::isDeadToken($t['error'] ?? null)) {
                    $this->disableDevice((int) $r['dev_id'], 'DeviceNotRegistered');
                    $this->mark((int) $r['id'], ['status' => 'error', 'reason' => 'DeviceNotRegistered']);
                    $out['error']++;
                } elseif (($t['error'] ?? '') === 'MessageRateExceeded') {
                    $this->retryLater($r); $out['queued']++;
                } else {
                    $this->mark((int) $r['id'], ['status' => 'error', 'reason' => mb_substr((string) ($t['error'] ?: $t['message'] ?: 'error'), 0, 110)]);
                    $out['error']++;
                }
            }
        }
        return $out;
    }

    private function retryLater(array $r): void
    {
        $n = (int) $r['attempts'] + 1;
        $this->mark((int) $r['id'], $n >= self::MAX_ATTEMPTS ? ['status' => 'failed', 'reason' => 'expo_unreachable', 'attempts' => $n] : ['attempts' => $n]);
    }

    private function mark(int $id, array $f): void
    {
        db_connect()->table('push_log')->where('id', $id)->update($f);
    }

    private function disableDevice(int $deviceId, string $reason): void
    {
        db_connect()->table('mobile_devices')->where('id', $deviceId)->update(['disabled_at' => $this->stamp(), 'disabled_reason' => $reason]);
    }

    // ---- cron: retries, receipts, housekeeping -----------------------------------------------------------------------

    /** @return array{retried:int,receipts:int,disabled:int,purged:int} */
    public function maintain(): array
    {
        $db = db_connect();
        $out = ['retried' => 0, 'receipts' => 0, 'disabled' => 0, 'purged' => 0];

        $queued = $db->table('push_log')->select('id')->where('status', 'queued')->where('attempts <', self::MAX_ATTEMPTS)->where('created_at <', date('Y-m-d H:i:s', $this->now() - 30))->limit(300)->get()->getResultArray();
        if ($queued) { $r = $this->deliver(array_map(static fn ($x) => (int) $x['id'], $queued)); $out['retried'] = $r['sent']; }

        // Receipts are ready ~15 minutes after sending. They tell us what the phone vendor (APNs/FCM) did with it.
        $due = $db->table('push_log')->where('status', 'sent')->where('receipt_at', null)->where('ticket_id IS NOT NULL', null, false)->where('sent_at <=', date('Y-m-d H:i:s', $this->now() - 900))->limit(1000)->get()->getResultArray();
        if ($due) {
            try {
                $rec = $this->client()->receipts(array_map(static fn ($r) => (string) $r['ticket_id'], $due));
            } catch (PushTransportException) { $rec = null; }
            if ($rec !== null) {
                foreach ($due as $r) {
                    $x = $rec[$r['ticket_id']] ?? null;
                    if ($x === null) {                                               // Expo forgets receipts after 24h
                        if (strtotime((string) $r['sent_at']) < $this->now() - 86400) { $this->mark((int) $r['id'], ['receipt_at' => $this->stamp()]); }
                        continue;
                    }
                    if ($x['status'] === 'ok') { $this->mark((int) $r['id'], ['status' => 'delivered', 'receipt_at' => $this->stamp()]); $out['receipts']++; continue; }
                    $this->mark((int) $r['id'], ['status' => 'error', 'reason' => mb_substr((string) ($x['error'] ?: $x['message'] ?: 'error'), 0, 110), 'receipt_at' => $this->stamp()]);
                    if (ExpoPushClient::isDeadToken($x['error'] ?? null) && $r['device_id']) { $this->disableDevice((int) $r['device_id'], 'DeviceNotRegistered'); $out['disabled']++; }
                    $out['receipts']++;
                }
            }
        }
        $db->table('push_log')->where('created_at <', date('Y-m-d H:i:s', $this->now() - 30 * 86400))->delete();
        $out['purged'] = $db->affectedRows();
        return $out;
    }
}
