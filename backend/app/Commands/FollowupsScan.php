<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\TenantModel;
use App\Services\Leads\FollowUpScanner;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Nudges prospects who received a marketing template and never answered.
 *
 * ALWAYS --dry-run first on a new list. It prints exactly who would be
 * contacted, with the hook each of them would see, and writes nothing:
 *   php spark followups:scan --dry-run --limit 20
 *
 * Once live, run it ONCE A DAY, not every minute. A nudge is a paid marketing
 * template to a closed window; spreading it over a day buys nothing, and the
 * daily cap is the thing standing between a follow-up campaign and a
 * restricted number:
 *   [30 10 * * *] /usr/bin/php8.3 /path/to/backend/spark followups:scan --limit 100
 */
class FollowupsScan extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'followups:scan';
    protected $description = 'Fire no_reply_followup triggers for unanswered marketing templates.';
    protected $usage       = 'followups:scan [--dry-run] [--limit N] [--tenant N]';

    public function run(array $params): void
    {
        $dryRun = $this->flag($params, 'dry-run');
        $limit  = (int) ($this->opt($params, 'limit') ?? FollowUpScanner::DAILY_LIMIT);
        $tenant = (int) ($this->opt($params, 'tenant') ?? 0);

        $svc     = new FollowUpScanner();
        $tenants = $tenant > 0
            ? [['id' => $tenant]]
            : (new TenantModel())->findAll();

        $total = 0;
        foreach ($tenants as $t) {
            $res = $svc->run((int) $t['id'], $limit, $dryRun);

            if (($res['released'] ?? 0) > 0) {
                CLI::write("[followups:scan] tenant {$t['id']}: released {$res['released']} throttled claim(s) — eligible again.", 'cyan');
            }

            if (! empty($res['blocked'])) {
                CLI::write("[followups:scan] tenant {$t['id']}: {$res['blocked']}", 'red');
                continue;
            }

            if ($res['capped_daily']) {
                CLI::write("[followups:scan] tenant {$t['id']}: daily cap of {$limit} already reached — nothing sent.", 'yellow');
                continue;
            }

            foreach ($res['preview'] as $row) {
                CLI::write(sprintf(
                    '  %-22s %-16s step %d  %s',
                    mb_substr((string) $row['name'], 0, 22),
                    $row['wa_number'],
                    $row['step'],
                    $row['hook']
                ));
            }

            $verb = $dryRun ? 'would nudge' : 'nudged';
            CLI::write(
                "[followups:scan] tenant {$t['id']}: {$res['eligible']} eligible, {$verb} {$res['fired']}"
                . ($res['skipped_cap'] > 0 ? ", {$res['skipped_cap']} already at the per-contact cap" : ''),
                $res['fired'] > 0 ? 'cyan' : 'green'
            );
            $total += $res['fired'];
        }

        if ($dryRun) {
            CLI::write("Dry run — nothing was written and nothing was sent. Total: {$total}.", 'yellow');
        }
    }

    /** CI4 does not split --opt=value, so accept both spellings. */
    private function opt(array $params, string $name): ?string
    {
        if (isset($params[$name]) && $params[$name] !== null && $params[$name] !== true) {
            return (string) $params[$name];
        }
        foreach ($params as $k => $v) {
            $token = is_string($k) ? $k : (string) $v;
            if (str_starts_with($token, "{$name}=")) {
                return substr($token, strlen($name) + 1);
            }
        }

        return null;
    }

    private function flag(array $params, string $name): bool
    {
        if (array_key_exists($name, $params)) {
            return true;
        }

        return in_array($name, array_map('strval', array_values($params)), true);
    }
}
