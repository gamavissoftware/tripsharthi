<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\PhoneNumberModel;
use App\Models\WabaAccountModel;
use App\Services\Env\EnvFileValidator;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Pre-launch configuration gate.
 *
 * Every check here corresponds to something that silently misbehaves in
 * production rather than failing loudly: a password-reset link pointing at
 * localhost, an unauthenticated webhook endpoint, a queue worker that isn't
 * actually running. Run it until everything is green, and again after any
 * environment change.
 *
 *   php spark golive:check
 *
 * Exit code 0 = ready, 1 = at least one blocker.
 */
class GoLiveCheck extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'golive:check';
    protected $description = 'Verify the environment is safe to serve real customers.';

    /** @var list<array{level:string, label:string, detail:string}> */
    private array $results = [];

    public function run(array $params): int
    {
        CLI::write('TravelPilot go-live check', 'yellow');
        CLI::newLine();

        $this->checkEnvironment();
        $this->checkEnvFile();
        $this->checkSecrets();
        $this->checkPublicUrls();
        $this->checkCutover();
        $this->checkWebhookVerification();
        $this->checkDatabase();
        $this->checkWaba();
        $this->checkQueueWorker();
        $this->checkDemoAccounts();

        return $this->report();
    }

    // ── Individual checks ─────────────────────────────────────────────

    private function checkEnvironment(): void
    {
        $env = ENVIRONMENT;

        $env === 'production'
            ? $this->pass('Environment', 'CI_ENVIRONMENT=production')
            : $this->fail('Environment', "CI_ENVIRONMENT={$env} — leaks stack traces and file paths in API errors, and injects the debug toolbar into public pages such as the hosted lead form. Set CI_ENVIRONMENT = production in .env.");
    }

    /**
     * A malformed .env is uniquely dangerous: DotEnv throws during boot, before
     * the logger exists, so every request becomes a bare 500 with nothing in any
     * log while the static frontend keeps serving. That cannot be detected from
     * in here (this command would not run at all), but unfilled placeholders and
     * unterminated quotes parse fine and still break things quietly.
     *
     * Run scripts/env-check.php after editing .env — it works without booting.
     */
    private function checkEnvFile(): void
    {
        $path = ROOTPATH . '.env';

        if (! is_readable($path)) {
            $this->warn('Env file', 'no .env at ' . $path . ' — the app is running on defaults and committed config only.');

            return;
        }

        $validator = new EnvFileValidator();
        $problems  = $validator->validate((string) file_get_contents($path));

        if ($problems === []) {
            $this->pass('Env file', 'parses cleanly, no unfilled placeholders');

            return;
        }

        $summary = [];

        foreach ($problems as $problem) {
            $where     = $problem['line'] > 0 ? "line {$problem['line']}" : 'missing';
            $summary[] = "{$problem['key']} ({$where}) {$problem['message']}";
        }

        $detail = implode('; ', $summary) . ' — check with: php scripts/env-check.php';

        $validator->bootable($problems)
            ? $this->warn('Env file', $detail)
            : $this->fail('Env file', $detail);
    }

    private function checkSecrets(): void
    {
        $key = (string) env('encryption.key', '');

        $key !== ''
            ? $this->pass('Encryption key', 'set — WABA and provider tokens can be encrypted at rest')
            : $this->fail('Encryption key', 'encryption.key is empty. Access tokens cannot be encrypted. Generate one with: php spark key:generate');
    }

    private function checkPublicUrls(): void
    {
        foreach ([
            'APP_URL'      => 'password-reset, invite and welcome email links',
            'app.baseURL'  => 'webhook URLs shown to the customer, and email link fallback',
            'CORS_ORIGIN'  => 'which origin the browser app may call the API from',
        ] as $key => $purpose) {
            $value = trim((string) env($key, ''));

            if ($value === '') {
                $this->fail("Config {$key}", "not set — controls {$purpose}.");
                continue;
            }

            $this->isLocal($value)
                ? $this->fail("Config {$key}", "points at a local address ({$value}) — controls {$purpose}. No external recipient or service can reach it.")
                : $this->pass("Config {$key}", $value);
        }
    }

    /**
     * APP_URL and app.baseURL naming different hosts means a cutover is half
     * finished: email links go to one domain while the hosted form, embed
     * snippet and webhook URL still point at the other.
     */
    private function checkCutover(): void
    {
        $appHost  = parse_url((string) env('APP_URL', ''), PHP_URL_HOST);
        $baseHost = parse_url((string) base_url(), PHP_URL_HOST);

        if ($appHost === null || $baseHost === null || $appHost === '' || $baseHost === '') {
            return; // the individual URL checks already reported this
        }

        $appHost === $baseHost
            ? $this->pass('Domain cutover', "APP_URL and app.baseURL agree ({$appHost})")
            : $this->warn('Domain cutover', "APP_URL is {$appHost} but app.baseURL is still {$baseHost} — the hosted lead form, embed snippet and the webhook URL shown in WhatsApp settings all use app.baseURL. Point app.baseURL at {$appHost} once that host serves this backend, then re-register the callback URL in Meta.");
    }

    private function checkWebhookVerification(): void
    {
        $enabled = filter_var(env('WEBHOOK_VERIFY_SIGNATURE', true), FILTER_VALIDATE_BOOLEAN);
        $secret  = trim((string) env('META_APP_SECRET', ''));

        if (! $enabled) {
            $this->fail(
                'Meta webhook signature',
                'WEBHOOK_VERIFY_SIGNATURE is false — the webhook endpoint accepts ANY unauthenticated POST. '
                . 'Anyone who knows the URL can inject inbound messages, open 24-hour messaging windows on '
                . 'arbitrary numbers, create contacts and fire flows. Set META_APP_SECRET first, then set '
                . 'WEBHOOK_VERIFY_SIGNATURE = true (that order — the flag without the secret rejects every webhook).'
            );
        } elseif ($secret === '') {
            $this->fail('Meta webhook signature', 'verification is on but META_APP_SECRET is empty — every inbound webhook will be rejected and inbound messages will stop. Set META_APP_SECRET (Meta App Dashboard → Settings → Basic → App Secret).');
        } else {
            $this->pass('Meta webhook signature', 'verified against META_APP_SECRET');
        }

        filter_var(env('RAZORPAY_VERIFY_SIGNATURE', true), FILTER_VALIDATE_BOOLEAN)
            ? $this->pass('Razorpay webhook signature', 'verification enabled')
            : $this->fail('Razorpay webhook signature', 'RAZORPAY_VERIFY_SIGNATURE is false — forged payment callbacks would be trusted.');
    }

    private function checkDatabase(): void
    {
        try {
            $db = db_connect();
            $db->query('SELECT 1');
            $this->pass('Database', 'reachable (' . $db->getDatabase() . ')');
        } catch (Throwable $e) {
            $this->fail('Database', 'not reachable: ' . $e->getMessage());
            return;
        }

        try {
            $pending = service('migrations')->findMigrations();
            $applied = db_connect()->table('migrations')->countAllResults();

            count($pending) <= $applied
                ? $this->pass('Migrations', "{$applied} applied, none pending")
                : $this->fail('Migrations', (count($pending) - $applied) . ' migration(s) not applied. Run: php spark migrate');
        } catch (Throwable $e) {
            $this->warn('Migrations', 'could not be verified: ' . $e->getMessage());
        }
    }

    private function checkWaba(): void
    {
        try {
            $account = (new WabaAccountModel())->withoutTenantScope()->where('status', 'active')->first();

            if ($account === null) {
                $this->warn('WhatsApp account', 'no active WABA connected — no message can be sent until one is.');
                return;
            }

            $this->pass('WhatsApp account', 'active (' . ($account['display_name'] ?? 'unnamed') . ')');

            $phone = (new PhoneNumberModel())->withoutTenantScope()->where('is_default', 1)->first();
            $phone !== null
                ? $this->pass('Sending number', (string) ($phone['display_number'] ?? $phone['phone_number_id']))
                : $this->fail('Sending number', 'no default phone number — sends have no origin number.');
        } catch (Throwable $e) {
            $this->warn('WhatsApp account', 'could not be verified: ' . $e->getMessage());
        }
    }

    private function checkQueueWorker(): void
    {
        try {
            $row = db_connect()->table('jobs')
                ->select('MAX(updated_at) AS last_activity')
                ->whereIn('status', ['done', 'failed'])
                ->get()->getRowArray();

            $last = $row['last_activity'] ?? null;

            if ($last === null) {
                $this->warn('Queue worker', 'no job has ever been processed — confirm the flow:work cron is installed.');
                return;
            }

            $ageMin = (int) round((time() - strtotime($last)) / 60);

            $ageMin <= 15
                ? $this->pass('Queue worker', "last processed a job {$ageMin} min ago")
                : $this->warn('Queue worker', "last processed a job {$ageMin} min ago — campaigns, flows and imports all stall if flow:work is not running every minute.");
        } catch (Throwable $e) {
            $this->warn('Queue worker', 'could not be verified: ' . $e->getMessage());
        }
    }

    private function checkDemoAccounts(): void
    {
        try {
            $demo = db_connect()->table('users')
                ->like('email', '@demo.test', 'before')
                ->where('deleted_at', null)
                ->countAllResults();

            $demo === 0
                ? $this->pass('Demo accounts', 'none present')
                : $this->warn('Demo accounts', "{$demo} seeded @demo.test account(s) still active — they share a published password. Remove or change them before real customers sign in.");
        } catch (Throwable $e) {
            $this->warn('Demo accounts', 'could not be verified: ' . $e->getMessage());
        }
    }

    // ── Result plumbing ───────────────────────────────────────────────

    private function pass(string $label, string $detail): void
    {
        $this->results[] = ['level' => 'pass', 'label' => $label, 'detail' => $detail];
    }

    private function warn(string $label, string $detail): void
    {
        $this->results[] = ['level' => 'warn', 'label' => $label, 'detail' => $detail];
    }

    private function fail(string $label, string $detail): void
    {
        $this->results[] = ['level' => 'fail', 'label' => $label, 'detail' => $detail];
    }

    private function report(): int
    {
        $failed = 0;
        $warned = 0;

        foreach ($this->results as $r) {
            [$mark, $colour] = match ($r['level']) {
                'pass'  => ['PASS', 'green'],
                'warn'  => ['WARN', 'yellow'],
                default => ['FAIL', 'red'],
            };

            if ($r['level'] === 'fail') {
                $failed++;
            }
            if ($r['level'] === 'warn') {
                $warned++;
            }

            CLI::write('  ' . CLI::color("[{$mark}]", $colour) . ' ' . str_pad($r['label'], 26) . ' ' . $r['detail']);
        }

        CLI::newLine();

        if ($failed > 0) {
            CLI::write("NOT READY — {$failed} blocker(s), {$warned} warning(s).", 'red');
            return EXIT_ERROR;
        }

        $warned > 0
            ? CLI::write("Ready, with {$warned} warning(s) to review.", 'yellow')
            : CLI::write('Ready to go live.', 'green');

        return EXIT_SUCCESS;
    }

    /** True for anything no external service or email recipient could reach. */
    private function isLocal(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        return $host === 'localhost'
            || str_starts_with($host, '127.')
            || $host === '::1'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');
    }
}
