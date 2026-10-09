<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Make an EXISTING user a TripSarthi platform admin (can manage every customer workspace, subscriptions, website chat and enquiries).
 * This is the only way to grant it — no request or registration can — so register the account normally first.
 *   php spark admin:grant you@company.com      php spark admin:grant you@company.com --revoke      php spark admin:grant --list
 */
class AdminGrant extends BaseCommand
{
    protected $group       = 'TravelPilot';
    protected $name        = 'admin:grant';
    protected $description = 'Grant, revoke or list TripSarthi platform admins.';
    protected $usage       = 'admin:grant <email> [--revoke] | admin:grant --list';

    public function run(array $params): void
    {
        $db = db_connect();
        if (CLI::getOption('list') !== null) {
            $rows = $db->table('users')->select('id,tenant_id,name,email')->where('is_platform_admin', 1)->where('deleted_at', null)->get()->getResultArray();
            if (! $rows) { CLI::write('No platform admins yet.'); return; }
            foreach ($rows as $r) { CLI::write("#{$r['id']}  {$r['name']} <{$r['email']}>  (workspace {$r['tenant_id']})"); }
            return;
        }
        $email = strtolower(trim((string) ($params[0] ?? '')));
        if ($email === '') { CLI::error('Give the user\'s email address.'); return; }
        $u = $db->table('users')->where('email', $email)->where('deleted_at', null)->get()->getRowArray();
        if (! $u) { CLI::error("No user with email {$email}. Register that account first, then run this again."); return; }
        $revoke = CLI::getOption('revoke') !== null;
        if ($revoke && (int) $db->table('users')->where('is_platform_admin', 1)->where('deleted_at', null)->countAllResults() <= 1 && (int) $u['is_platform_admin'] === 1) {
            CLI::error('That is the last platform admin — grant someone else first.'); return;
        }
        $db->table('users')->where('id', $u['id'])->update(['is_platform_admin' => $revoke ? 0 : 1]);
        \App\Services\Crm\AuditLogger::log($revoke ? 'platform_admin.revoke' : 'platform_admin.grant', 'user', (int) $u['id'], null, ['email' => $email, 'by' => 'cli'], (int) $u['tenant_id'], null);
        CLI::write(($revoke ? 'Revoked platform admin from ' : 'Granted platform admin to ') . "{$u['name']} <{$email}>.", 'green');
    }
}
