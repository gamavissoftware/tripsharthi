<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Auth\CurrentUser;
use App\Services\Travel\TravelFlowRecipes;

/** Starter travel automations: list + one-click install (drafts only; nothing is sent by installing). */
class TravelAutomationsController extends TravelBaseController
{
    public function index()
    {
        return $this->ok((new TravelFlowRecipes())->status(CurrentUser::tenantId()));
    }

    /** POST /travel-automations/install { keys?: string[] } */
    public function install()
    {
        $keys = $this->body()['keys'] ?? null;
        $res  = (new TravelFlowRecipes())->install(CurrentUser::tenantId(), is_array($keys) ? $keys : null);
        return $this->ok(['installed' => $res, 'automations' => (new TravelFlowRecipes())->status(CurrentUser::tenantId())], 201);
    }
}
