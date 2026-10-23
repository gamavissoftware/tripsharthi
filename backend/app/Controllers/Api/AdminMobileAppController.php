<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Admin\MobileAppLinks;
use App\Services\Auth\CurrentUser;

/** /api/v1/admin/mobile-app - the store links shown on the login screen and the website (filter: adminauth). 422 with plain words, never 401. */
class AdminMobileAppController extends TravelBaseController
{
    public function current()
    {
        $st = MobileAppLinks::status();
        return $this->ok($st + ['errors' => \App\Services\Admin\AppErrorService::recent(), 'installs' => \App\Services\Admin\AppInstallService::summary($st['latest_version'] ?? null), 'update_watch' => \App\Services\Admin\AppUpdateWatch::status()]);
    }

    public function save()
    {
        $b = $this->body();
        try { return $this->ok(MobileAppLinks::save($b, CurrentUser::id())); }
        catch (\InvalidArgumentException $e) { return $this->respond(['success' => false, 'message' => $e->getMessage()], 422); }
    }
}
