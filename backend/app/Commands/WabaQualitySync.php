<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\PhoneNumberModel;
use App\Models\WabaAccountModel;
use App\Services\WhatsApp\CloudApiClient;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Syncs phone number quality ratings from Meta for all active WABA accounts.
 *
 * Meta updates quality ratings (green/yellow/red) based on user feedback.
 * A dropped rating is an early warning before a number gets banned.
 *
 * Cron (every 6 hours):
 *   0 *&#47;6 * * * /usr/bin/php /path/to/backend/spark waba:quality-sync >> /dev/null 2>&1
 *
 * What it does:
 *   1. Loads all active WABA accounts across all tenants.
 *   2. For each account, calls Meta's GET /phone-number-id endpoint.
 *   3. Updates phone_numbers.quality_rating in the DB.
 *   4. Marks the account as 'flagged' if any number drops to 'red'.
 */
class WabaQualitySync extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'waba:quality-sync';
    protected $description = 'Sync WhatsApp phone number quality ratings from Meta.';

    public function run(array $params): void
    {
        CLI::write('[waba:quality-sync] Starting…', 'cyan');

        $wabaModel = new WabaAccountModel();
        $pnModel   = new PhoneNumberModel();

        // Load ALL active WABA accounts (cross-tenant)
        // Cron runs outside any tenant context, so both queries are deliberately
        // cross-tenant — without this the command aborts on the tenancy guard
        // before syncing anything.
        $accounts = $wabaModel->withoutTenantScope()->where('status', 'active')->findAll();

        if (empty($accounts)) {
            CLI::write('[waba:quality-sync] No active WABA accounts found. Done.', 'yellow');
            return;
        }

        $updated = 0;
        $flagged = 0;

        foreach ($accounts as $account) {
            $accountId = (int) $account['id'];
            $tenantId  = (int) $account['tenant_id'];

            $rawToken = $wabaModel->getDecryptedToken($account);
            if (empty($rawToken)) {
                CLI::write("[waba:quality-sync] Skipping account #{$accountId} (no token).", 'yellow');
                continue;
            }

            // Get phone numbers for this account
            $phoneNumbers = $pnModel->withoutTenantScope()->where('waba_account_id', $accountId)->findAll();

            foreach ($phoneNumbers as $pn) {
                $phoneNumberId = $pn['phone_number_id'] ?? '';
                if (empty($phoneNumberId)) continue;

                $client = new CloudApiClient($phoneNumberId, $rawToken);
                $result = $client->testConnection();

                if (! $result['success']) {
                    CLI::write("[waba:quality-sync] Failed to fetch phone #{$phoneNumberId}: " . ($result['error'] ?? 'unknown'), 'red');
                    continue;
                }

                $newRating = strtolower($result['info']['quality_rating'] ?? 'unknown');
                $oldRating = $pn['quality_rating'] ?? 'unknown';

                $pnUpdate = ['quality_rating' => $newRating];
                if (! empty($result['info']['display_phone_number'])) {
                    $pnUpdate['display_number'] = $result['info']['display_phone_number'];
                }

                $pnModel->update((int) $pn['id'], $pnUpdate);
                $updated++;

                CLI::write(
                    "[waba:quality-sync] Tenant #{$tenantId} | {$phoneNumberId} | "
                    . "{$oldRating} → {$newRating}",
                    $newRating === 'red' ? 'red' : 'green'
                );

                // Flag account if any number is red (at risk of ban)
                if ($newRating === 'red') {
                    $wabaModel->update($accountId, ['status' => 'flagged']);
                    $flagged++;
                    log_message(
                        'critical',
                        "waba:quality-sync — Tenant #{$tenantId} phone {$phoneNumberId} "
                        . "quality dropped to RED. Account marked flagged."
                    );
                }
            }
        }

        CLI::write(
            "[waba:quality-sync] Done — {$updated} numbers synced, {$flagged} accounts flagged.",
            $flagged > 0 ? 'red' : 'green'
        );
    }
}
