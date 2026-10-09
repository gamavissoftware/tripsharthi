<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\TenantModel;
use App\Services\Crm\TaskDueService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Fires the task_due flow trigger for matured task reminders (Phase H4).
 * Strictly once-only via the reminder_sent flag.
 *
 * Run every few minutes via cron (it pairs with spark flow:work), e.g. a
 * "every 5 minutes" schedule:
 *   [*\/5 * * * *] /usr/bin/php /path/to/backend/spark tasks:remind >> /dev/null 2>&1
 */
class TasksRemind extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'tasks:remind';
    protected $description = 'Fire task_due flow triggers for matured task reminders.';

    public function run(array $params): void
    {
        $svc   = new TaskDueService();
        $fired = 0;
        foreach ((new TenantModel())->findAll() as $t) {
            $fired += $svc->run((int) $t['id'])['fired'];
        }
        CLI::write("[tasks:remind] Fired task_due for {$fired} reminder(s).", $fired > 0 ? 'cyan' : 'green');
    }
}
