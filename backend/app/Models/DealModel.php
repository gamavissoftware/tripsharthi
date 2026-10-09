<?php

declare(strict_types=1);

namespace App\Models;

class DealModel extends BaseModel
{
    protected $table      = 'deals';
    protected bool $ownable    = true;
    protected string $entityType = 'deal';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'tenant_id', 'title', 'pipeline_id', 'stage_id', 'account_id', 'primary_contact_id',
        'owner_id', 'value_amount', 'is_recurring', 'recurring_interval', 'next_renewal_at',
        'currency', 'price_book_id', 'expected_close_date', 'status', 'source', 'lost_reason',
        'won_at', 'last_activity_at',
    ];

    protected $validationRules = [
        'title'  => 'required|max_length[255]',
        'status' => 'permit_empty|in_list[open,won,lost]',
    ];

    /** Billing intervals → how many of that interval fall in a year (for MRR/ARR math). */
    public const INTERVALS = ['monthly' => 12, 'quarterly' => 4, 'annual' => 1];

    /** Billing interval → number of months per cycle (for advancing renewal dates). */
    public const INTERVAL_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'annual' => 12];

    /** Advance a Y-m-d date by one billing interval; null/unknown interval → null. */
    public static function advanceRenewal(?string $fromDate, ?string $interval): ?string
    {
        $months = self::INTERVAL_MONTHS[$interval] ?? 0;
        if ($months === 0 || ! $fromDate) {
            return null;
        }
        return date('Y-m-d', strtotime("{$fromDate} +{$months} months"));
    }

    /**
     * Normalize a per-cycle amount (paise) to monthly recurring revenue (paise):
     * monthly → ×1, quarterly → ÷3, annual → ÷12. Unknown interval → 0.
     */
    public static function monthlyRevenue(int $perCyclePaise, ?string $interval): int
    {
        $perYear = self::INTERVALS[$interval] ?? 0; // cycles per year
        return $perYear > 0 ? (int) round($perCyclePaise * $perYear / 12) : 0;
    }

    /** Deals in a pipeline. By default only open deals (board view). */
    public function forPipeline(int $tenantId, int $pipelineId, bool $includeClosed = false): array
    {
        $q = $this->setTenant($tenantId)->where('pipeline_id', $pipelineId);
        if (! $includeClosed) {
            $q->where('status', 'open');
        }
        return $q->orderBy('updated_at', 'DESC')->findAll(1000);
    }

    /** Deals attached to a contact (as primary), newest first. */
    public function forContact(int $tenantId, int $contactId): array
    {
        return $this->setTenant($tenantId)
            ->where('primary_contact_id', $contactId)
            ->orderBy('updated_at', 'DESC')
            ->findAll(200);
    }
}
