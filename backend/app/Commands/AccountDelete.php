<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Admin\AccountDeletionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Delete a customer workspace or one team member on request. Read docs/ACCOUNT_DELETION_RUNBOOK.md first.
 *   php spark account:delete workspace <tenant_id>                                   dry run: shows what would go and what is kept
 *   php spark account:delete workspace <tenant_id> --apply --confirm=<slug> --ref="ticket #123" [--keep-tax-docs]
 *   php spark account:delete user <user_id> --apply --confirm=<email> --ref="e-mail 23 Oct"
 */
class AccountDelete extends BaseCommand
{
    protected $group       = 'TravelPilot';
    protected $name        = 'account:delete';
    protected $description = 'Delete a workspace or a team member on request (dry run unless --apply).';
    protected $usage       = 'account:delete <workspace|user> <id> [--apply --confirm=<slug|email> --ref="..." --keep-tax-docs]';

    public function run(array $params): void
    {
        $scope = (string) ($params[0] ?? ''); $id = (int) ($params[1] ?? 0);
        if (! in_array($scope, ['workspace', 'user'], true) || $id <= 0) { CLI::error($this->usage); return; }
        $svc = new AccountDeletionService();
        $apply = CLI::getOption('apply') !== null;
        try {
            if ($scope === 'user') {
                $u = db_connect()->table('users')->where('id', $id)->get()->getRowArray();
                if (! $u) { CLI::error("User #{$id} does not exist."); return; }
                CLI::write("User #{$id}: {$u['name']} <{$u['email']}> ({$u['role']}, workspace {$u['tenant_id']})");
                if (! $apply) { CLI::write('Dry run. Would remove their sign-ins, phone tokens, notifications and anonymise the login. Add --apply --confirm=<email> --ref="..." to do it.'); return; }
                $r = $svc->deleteUser($id, (string) CLI::getOption('confirm'), (string) CLI::getOption('ref'), 'cli:' . get_current_user());
                CLI::write('Done. ' . json_encode($r), 'green'); return;
            }
            $keep = CLI::getOption('keep-tax-docs') !== null;
            $p = $svc->plan($id, $keep);
            CLI::write("Workspace #{$id}: {$p['tenant']['name']} (slug {$p['tenant']['slug']}, plan {$p['tenant']['plan']}, status {$p['tenant']['status']}); {$p['users']} user login(s)");
            CLI::write('WOULD DELETE:'); foreach ($p['tables'] as $t => $n) { CLI::write(sprintf('  %-28s %d', $t, $n)); }
            CLI::write('KEPT (TripSarthi business records' . ($keep ? ' + the customer\'s tax documents' : '') . '):'); foreach ($p['kept'] as $t => $n) { CLI::write(sprintf('  %-28s %d', $t, $n)); }
            CLI::write('FILES: ' . ($p['folders'] ? implode(', ', $p['folders']) : 'none'));
            if (! $apply) { CLI::write("\nDry run: nothing was changed. To delete, take a backup (deploy/backup.sh), then add --apply --confirm={$p['tenant']['slug']} --ref=\"...\"", 'yellow'); return; }
            $r = $svc->deleteWorkspace($id, (string) CLI::getOption('confirm'), (string) CLI::getOption('ref'), 'cli:' . get_current_user(), $keep);
            CLI::write('Deleted. Rows removed: ' . array_sum($r['rows']) . '; folders: ' . (implode(', ', $r['folders']) ?: 'none') . '. A record was written to account_deletions.', 'green');
        } catch (\InvalidArgumentException $e) { CLI::error($e->getMessage()); }
    }
}
