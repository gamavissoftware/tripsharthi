<?php

declare(strict_types=1);

namespace App\Services\Email\Marketing;

use App\Models\EmailSuppressionModel;

/**
 * The per-tenant "never bulk-email again" list. Checked for every campaign
 * recipient and every flow email; written by the unsubscribe link, by the
 * owner by hand, and (reason=bounced) when an address is rejected outright.
 *
 * 1:1 emails an agent sends from a contact's page are NOT gated — a contact
 * who unsubscribed from newsletters can still be answered personally.
 */
final class SuppressionService
{
    public const REASONS = ['unsubscribed', 'bounced', 'complaint', 'manual'];

    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    public function isSuppressed(int $tenantId, string $email): bool
    {
        return $this->suppressedAmong($tenantId, [$email]) !== [];
    }

    /**
     * @param  list<string> $emails
     * @return array<string,true> the suppressed subset, keyed by normalised address
     */
    public function suppressedAmong(int $tenantId, array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map([self::class, 'normalize'], $emails))));
        if ($emails === []) {
            return [];
        }

        $rows = db_connect()->table('email_suppressions')
            ->select('email')
            ->where('tenant_id', $tenantId)
            ->whereIn('email', $emails)
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $r) {
            $out[self::normalize((string) $r['email'])] = true;
        }

        return $out;
    }

    /** Idempotent: suppressing an already-suppressed address keeps the first reason. */
    public function suppress(int $tenantId, string $email, string $reason = 'manual', ?int $contactId = null, ?int $emailId = null): bool
    {
        $email = self::normalize($email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $reason = in_array($reason, self::REASONS, true) ? $reason : 'manual';
        $now    = date('Y-m-d H:i:s');

        db_connect()->table('email_suppressions')->ignore(true)->insert([
            'tenant_id'       => $tenantId,
            'email'           => $email,
            'contact_id'      => $contactId,
            'reason'          => $reason,
            'source_email_id' => $emailId,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        return true;
    }

    public function remove(int $tenantId, int $id): bool
    {
        $model = (new EmailSuppressionModel())->setTenant($tenantId);
        if (! $model->find($id)) {
            return false;
        }

        return (bool) $model->delete($id);
    }
}
