<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Email\Marketing\EmailInboxRunner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Check the connected IMAP mailbox for replies to email campaigns right now.
 * The same check runs automatically every 5 minutes from
 * campaigns:dispatch-scheduled; this is for testing and one-off catch-up.
 *
 *   php spark email:replies-sync --dry-run     # list what it would record, change nothing
 *   php spark email:replies-sync               # record replies, alert notify_to, stop their follow-ups
 */
class EmailRepliesSync extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'email:replies-sync';
    protected $description = 'Look for replies to email campaigns in the connected IMAP mailbox.';
    protected $options     = ['--tenant' => 'Tenant id (default 1)', '--dry-run' => 'Report only; record and send nothing'];

    public function run(array $params): void
    {
        $opts     = CLI::getOptions();
        $tenantId = (int) ($opts['tenant'] ?? 1) ?: 1;
        $dryRun   = array_key_exists('dry-run', $opts);

        try {
            $line = (new EmailInboxRunner())->pollReplies($tenantId, time(), $dryRun);
        } catch (\Throwable $e) {
            CLI::error($e->getMessage());

            return;
        }
        CLI::write($line !== '' ? ($dryRun ? '[dry run] ' : '') . $line
            : 'No IMAP mailbox connected — add it under Settings → Integrations → Inbound email (IMAP).', $line !== '' ? 'green' : 'yellow');
    }
}
