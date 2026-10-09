<?php

declare(strict_types=1);

namespace App\Services\Ads;

/** What a platform must implement for AdCampaignManager to drive it. Money in/out is always paise. */
interface AdPlatformAdapter
{
    /** Spec problems the user can fix (no network). @return list<string> */
    public function validate(array $spec): array;

    /** Network pre-flight: account currency/status, platform-side validation. @return array{currency:string,notes:list<string>} */
    public function preflight(array $spec): array;

    /**
     * Create everything PAUSED. Must roll back what it created if a later step fails.
     * @return array{external_id:string,children:array,account_ref:string,objective:string,currency:string}
     */
    public function create(array $spec): array;

    /** @param array $campaign ad_campaigns row. $status is 'ACTIVE' or 'PAUSED'. */
    public function setStatus(array $campaign, string $status): void;

    public function setBudget(array $campaign, int $dailyPaise): void;

    /** Pull campaigns + last-30-day insights into the local tables. @return array{campaigns:int,insight_days:int} */
    public function sync(int $tenantId): array;
}
