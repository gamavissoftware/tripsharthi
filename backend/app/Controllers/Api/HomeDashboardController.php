<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Travel\TravelHomeService;

/** GET /api/v1/home[?user=ID] - the bird's-eye dashboard. Agents always get their own numbers; ?user= is for owners/admins only. */
class HomeDashboardController extends TravelBaseController
{
    public function index()
    {
        $manager = CurrentUser::isPrivileged();
        $focus   = null;
        $u = $this->request->getGet('user');
        if ($manager && $u !== null && $u !== '' && ctype_digit((string) $u)) {
            $focus = (int) $u;
            $exists = db_connect()->table('users')->where('tenant_id', CurrentUser::tenantId())->where('id', $focus)->countAllResults();
            if (! $exists) { return $this->respond(['success' => false, 'message' => 'That person is not in your team.', 'kind' => 'invalid'], 422); }
        }
        return $this->ok((new TravelHomeService())->build(CurrentUser::tenantId(), CurrentUser::id(), $manager, $focus));
    }

    /** GET /api/v1/home/targets[?user=ID] - only the target progress (the phone app's Targets tab). Same scoping as index(). */
    public function targets()
    {
        $manager = CurrentUser::isPrivileged();
        $focus = null;
        $u = $this->request->getGet('user');
        if ($manager && $u !== null && $u !== '' && ctype_digit((string) $u)) {
            $focus = (int) $u;
            if (! db_connect()->table('users')->where('tenant_id', CurrentUser::tenantId())->where('id', $focus)->countAllResults()) { return $this->respond(['success' => false, 'message' => 'That person is not in your team.', 'kind' => 'invalid'], 422); }
        }
        return $this->ok((new TravelHomeService())->targets(CurrentUser::tenantId(), CurrentUser::id(), $manager, $focus));
    }
}
