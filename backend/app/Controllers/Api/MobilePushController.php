<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Push\MobileDeviceService;
use App\Services\Push\PushCategory;
use App\Services\Push\PushNotifier;
use App\Services\Push\PushPreferenceService;

/** The signed-in user's phones and notification settings. Everything here is scoped to the caller. */
class MobilePushController extends TravelBaseController
{
    private function role(): string { return (string) (CurrentUser::get()['role'] ?? 'agent'); }

    public function registerDevice()
    {
        try { return $this->ok((new MobileDeviceService())->register(CurrentUser::tenantId(), CurrentUser::id(), $this->body()), 201); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 422); }
    }

    public function unregisterDevice()
    {
        $ok = (new MobileDeviceService())->unregister(CurrentUser::tenantId(), CurrentUser::id(), trim((string) ($this->body()['expo_token'] ?? '')));
        return $this->ok(['unregistered' => $ok]);
    }

    public function devices() { return $this->ok((new MobileDeviceService())->forUser(CurrentUser::tenantId(), CurrentUser::id())); }

    public function preferences()
    {
        $staff = in_array($this->role(), ['owner', 'admin'], true);
        $cats = [];
        foreach (PushCategory::ALL as $k => $c) { if ($staff || ! $c['staff_only']) { $cats[] = ['key' => $k, 'label' => $c['label'], 'hint' => $c['hint']]; } }
        return $this->ok(['preferences' => (new PushPreferenceService())->get(CurrentUser::tenantId(), CurrentUser::id()), 'categories' => $cats]);
    }

    public function savePreferences()
    {
        try { return $this->ok((new PushPreferenceService())->save(CurrentUser::tenantId(), CurrentUser::id(), $this->body())); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 422); }
    }

    public function test()
    {
        try { return $this->ok((new PushNotifier())->sendTest(CurrentUser::tenantId(), CurrentUser::id())); }
        catch (\DomainException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 409); }
    }

    /** Recent pushes for troubleshooting ("why didn't I get it?"): includes why something was NOT sent. */
    public function log()
    {
        $rows = db_connect()->table('push_log')->select('id, category, event, title, status, reason, created_at, device_id')->where('tenant_id', CurrentUser::tenantId())->where('user_id', CurrentUser::id())
            ->orderBy('id', 'DESC')->limit(40)->get()->getResultArray();
        return $this->ok($rows);
    }
}
