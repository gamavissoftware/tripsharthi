<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Email\ImapPollingService;
use App\Services\Email\Mailbox\ImapMailboxReader;
use App\Services\WhatsApp\TokenCipher;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Polls each tenant's connected IMAP mailbox for new inbound email (Phase M).
 * Opt-in — only tenants with an active `email_imap` integration are polled, and
 * only runs if scheduled. A connection failure for one tenant is logged and
 * skipped; it never aborts the others.
 *
 * Run every few minutes via cron:
 *   * /5 * * * * /usr/bin/php /path/to/backend/spark email:poll-imap >> /dev/null 2>&1
 */
class EmailPollImap extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'email:poll-imap';
    protected $description = 'Poll connected IMAP mailboxes and ingest inbound email.';

    public function run(array $params): void
    {
        $rows = db_connect()->table('integrations')
            ->where('type', 'email_imap')->where('status', 'active')->where('deleted_at', null)
            ->get()->getResultArray();

        if ($rows === []) {
            CLI::write('[email:poll-imap] No tenants with an active IMAP integration.', 'yellow');
            return;
        }

        $svc = new ImapPollingService();
        $totalFetched = $totalIngested = 0;

        foreach ($rows as $row) {
            $tenantId = (int) $row['tenant_id'];
            $config   = json_decode($row['config'] ?? '{}', true) ?: [];
            if (! empty($config['password_enc'])) {
                $config['password'] = TokenCipher::decrypt($config['password_enc']);
            }

            try {
                $reader = new ImapMailboxReader($config);
                $res    = $svc->poll($tenantId, $reader);
                $totalFetched  += $res['fetched'];
                $totalIngested += $res['ingested'];
                CLI::write("[email:poll-imap] tenant {$tenantId}: fetched {$res['fetched']}, ingested {$res['ingested']} ({$res['created']} new contacts).", 'green');
            } catch (\Throwable $e) {
                CLI::write("[email:poll-imap] tenant {$tenantId}: {$e->getMessage()}", 'red');
                log_message('error', "email:poll-imap tenant {$tenantId} failed: {$e->getMessage()}");
            }
        }

        CLI::write("[email:poll-imap] Done — fetched {$totalFetched}, ingested {$totalIngested}.", 'green');
    }
}
