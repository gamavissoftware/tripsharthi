<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\TaskModel;
use App\Services\Flow\FlowTriggerService;

/**
 * Fires the `task_due` flow trigger for tasks whose reminder/due time has matured
 * (Phase H4). The reminder_sent flag makes it strictly once-only — a task can
 * never fire twice. The flow itself decides what to do (reusing the existing
 * send_template action); this service adds NO new WhatsApp code.
 */
final class TaskDueService
{
    public function __construct(private TaskModel $tasks = new TaskModel()) {}

    /**
     * @return array{matured:int, fired:int}
     */
    public function run(int $tenantId, ?int $now = null): array
    {
        $now    = $now ?? time();
        $nowEsc = db_connect()->escape(date('Y-m-d H:i:s', $now));

        $rows = $this->tasks->setTenant($tenantId)
            ->where('reminder_sent', 0)
            ->where('status !=', 'done')
            ->where('COALESCE(reminder_at, due_at) IS NOT NULL', null, false)
            ->where("COALESCE(reminder_at, due_at) <= {$nowEsc}", null, false)
            ->findAll();

        $fired = 0;
        foreach ($rows as $task) {
            // Mark sent first so a crash mid-loop can't double-fire on retry.
            $this->tasks->setTenant($tenantId)->update((int) $task['id'], ['reminder_sent' => 1]);

            if (($task['related_type'] ?? '') === 'contact' && (int) ($task['related_id'] ?? 0) > 0) {
                FlowTriggerService::fire('task_due', $tenantId, (int) $task['related_id'], [
                    'task_id'    => (int) $task['id'],
                    'task_title' => $task['title'] ?? '',
                ]);
                $fired++;
            }
        }

        return ['matured' => count($rows), 'fired' => $fired];
    }
}
