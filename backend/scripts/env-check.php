<?php

declare(strict_types=1);

/**
 * Pre-flight .env check — run this after EVERY edit to .env, before reloading.
 *
 *   php scripts/env-check.php            # checks backend/.env
 *   php scripts/env-check.php /path/.env # checks a specific file
 *
 * Deliberately does NOT boot CodeIgniter: a malformed .env kills the framework
 * during boot, before the logger exists, so `spark golive:check` cannot run and
 * every request returns a bare 500 with nothing in any log. This script only
 * needs Composer's autoloader, so it still works when the app is dead.
 *
 * Exits 1 on a fatal problem, 0 otherwise.
 */

use App\Services\Env\EnvFileValidator;

require __DIR__ . '/../vendor/autoload.php';

$path = $argv[1] ?? __DIR__ . '/../.env';

if (! is_readable($path)) {
    fwrite(STDERR, "env-check: cannot read {$path}\n");

    exit(1);
}

$validator = new EnvFileValidator();
$problems  = $validator->validate((string) file_get_contents($path));

echo "env-check: {$path}\n";

if ($problems === []) {
    echo "  OK — parses cleanly, no unfilled placeholders.\n";

    exit(0);
}

foreach ($problems as $problem) {
    $label = $problem['severity'] === EnvFileValidator::FATAL ? 'FATAL' : 'WARN ';
    $where = $problem['line'] > 0 ? "line {$problem['line']}" : 'missing';

    printf("  [%s] %-28s %-12s %s\n", $label, $problem['key'], $where, $problem['message']);
}

if (! $validator->bootable($problems)) {
    echo "\n  The app will NOT boot with this file. Fix the FATAL lines before reloading.\n";
    echo "  Values containing spaces must be quoted: KEY = \"two words\"\n";

    exit(1);
}

echo "\n  No fatal problems — the app will boot, but review the warnings above.\n";

exit(0);
