<?php

declare(strict_types=1);

namespace App\Services\Flow;

use App\Models\FlowModel;

/**
 * Fires the 'date_reached' trigger for date-based automations (P4.4) —
 * birthdays, renewal reminders, appointment nudges.
 *
 * Run daily by the `flows:dates` cron. For each active date_reached flow it
 * reads trigger_config { field_key, days_before, recurring } and fires the
 * trigger for every contact whose date custom field matches (today + days_before).
 *   recurring=true  → match month + day (annual, e.g. birthdays)
 *   recurring=false → match the full date once
 */
class DateTriggerService
{
    /**
     * @param int $todayTs PHP time() for the run day.
     * @return int Number of flow_start jobs enqueued.
     */
    public function run(int $todayTs): int
    {
        $flows = (new FlowModel())
            ->withoutTenantScope()
            ->where('status', 'active')
            ->where('trigger_type', 'date_reached')
            ->findAll();

        $fired = 0;
        $db    = db_connect();

        foreach ($flows as $flow) {
            $cfg        = json_decode($flow['trigger_config'] ?? '{}', true) ?: [];
            $fieldKey   = (string) ($cfg['field_key'] ?? '');
            $daysBefore = max(0, (int) ($cfg['days_before'] ?? 0));
            $recurring  = (bool) ($cfg['recurring'] ?? true);
            $tenantId   = (int) $flow['tenant_id'];

            if ($fieldKey === '' || $tenantId <= 0) {
                continue;
            }

            $target = (new \DateTimeImmutable('@' . $todayTs))->modify("+{$daysBefore} days");

            $rows = $db->table('contact_field_values v')
                ->join('custom_fields cf', 'cf.id = v.custom_field_id')
                ->join('contacts c', 'c.id = v.contact_id')
                ->select('c.id AS contact_id, v.value AS value')
                ->where('cf.tenant_id', $tenantId)
                ->where('cf.field_key', $fieldKey)
                ->where('c.tenant_id', $tenantId)
                ->where('c.deleted_at', null)
                ->get()->getResultArray();

            foreach ($rows as $row) {
                if (! self::matchesDate((string) $row['value'], $target, $recurring)) {
                    continue;
                }
                $fired += FlowTriggerService::fire('date_reached', $tenantId, (int) $row['contact_id'], [
                    'field_key' => $fieldKey,
                    'date'      => $row['value'],
                ]);
            }
        }

        return $fired;
    }

    /**
     * Does a stored date value match the target date? Pure.
     *
     * @param bool $recurring true → compare month+day only (annual).
     */
    public static function matchesDate(string $stored, \DateTimeImmutable $target, bool $recurring): bool
    {
        $stored = trim($stored);
        if ($stored === '') {
            return false;
        }
        $ts = strtotime($stored);
        if ($ts === false) {
            return false;
        }
        $date = (new \DateTimeImmutable('@' . $ts));

        return $recurring
            ? $date->format('m-d') === $target->format('m-d')
            : $date->format('Y-m-d') === $target->format('Y-m-d');
    }
}
