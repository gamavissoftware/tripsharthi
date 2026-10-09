<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Database-backed job queue worker — Sprint 3+.
 *
 * Processes pending rows from the jobs table (CSV imports, broadcast sends,
 * Meta Lead Ads fetches, etc.).
 *
 * Run via cron every minute:
 *   * * * * * /usr/bin/php /path/to/backend/spark jobs:run >> /dev/null 2>&1
 */
class JobsRun extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'jobs:run';
    protected $description = 'Process pending database queue jobs (Sprint 3 — not yet implemented).';

    public function run(array $params): void
    {
        CLI::write('[jobs:run] Job queue not yet implemented (Sprint 3).', 'yellow');
    }
}
