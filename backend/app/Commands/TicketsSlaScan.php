<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\TenantModel;
use App\Services\Crm\SlaEscalationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Escalates SLA-breached tickets (Phase H5). Idempotent via escalated_at.
 *
 * Run every few minutes via cron, e.g. an "every 10 minutes" schedule:
 *   [*\/10 * * * *] /usr/bin/php /path/to/backend/spark tickets:sla-scan >> /dev/null 2>&1
 */
class TicketsSlaScan extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'tickets:sla-scan';
    protected $description = 'Escalate tickets whose SLA has breached.';

    public function run(array $params): void
    {
        $svc = new SlaEscalationService();
        $n   = 0;
        foreach ((new TenantModel())->findAll() as $t) {
            $n += $svc->run((int) $t['id']);
        }
        CLI::write("[tickets:sla-scan] Escalated {$n} breached ticket(s).", $n > 0 ? 'yellow' : 'green');
    }
}
