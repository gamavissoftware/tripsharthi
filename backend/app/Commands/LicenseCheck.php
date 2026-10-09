<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Licensing\LicenseService;
use App\Services\Tenancy\FeatureGate;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Daily license phone-home cron — the ONLY place network is called for license checks.
 *
 * Cron (daily, self_hosted installs only):
 *   0 2 * * * /usr/bin/php /path/to/backend/spark license:check >> /dev/null 2>&1
 *
 * What it does:
 *   1. Calls LicenseService::runPhoneHome() — contacts license.gamavis.com.
 *   2. On success   → updates last_check_at, status='active'.
 *   3. On fail (within grace) → logs warning, status stays 'active'.
 *   4. On fail (grace expired) → updates status='grace', system enters read-only.
 *   5. On revoked/expired from server → status='inactive', hard read-only.
 *
 * LicenseFilter::before() ONLY reads the status column from DB — it never
 * calls this command or makes any network request itself.
 *
 * Run manually after any connectivity issue to re-validate:
 *   php spark license:check
 */
class LicenseCheck extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'license:check';
    protected $description = 'Phone-home to Gamavis and update license status (cron: daily, self_hosted only).';

    public function run(array $params): void
    {
        // Self-hosted only. In SaaS the licence endpoint is not even expected to
        // resolve, so running this nightly logged a CRITICAL "system entering
        // read-only mode" every single night — an alarm about a subsystem that
        // LicenseFilter already no-ops in this mode. False alarms train people
        // to ignore real ones.
        if (! FeatureGate::isLicenseRequired()) {
            CLI::write('[license:check] APP_MODE is not self_hosted — nothing to check.', 'yellow');

            return;
        }

        CLI::write('[license:check] Contacting Gamavis license server...', 'cyan');

        try {
            (new LicenseService())->runPhoneHome();
            CLI::write('[license:check] Done.', 'green');
        } catch (\Throwable $e) {
            // runPhoneHome() catches its own errors internally; this is a safety net.
            CLI::error('[license:check] Unexpected error: ' . $e->getMessage());
        }
    }
}
