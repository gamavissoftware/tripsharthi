<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\FlowModel;
use App\Services\Flow\FlowValidator;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Activate, pause or draft a flow from the CLI, through the same validation
 * the Flows screen runs.
 *
 * Flipping flows.status directly in SQL skips FlowValidator, and an invalid
 * graph then fails per-contact at execution time — after the trigger has
 * already fired. This refuses to activate anything the validator rejects.
 *
 *   php spark flows:activate --id 27
 *   php spark flows:activate --id 27 --status paused
 *   php spark flows:activate --id 27 --check     # validate only, change nothing
 */
class FlowsActivate extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'flows:activate';
    protected $description = 'Validate and set a flow\'s status (active / paused / draft).';
    protected $usage       = 'flows:activate --id 27 [--status active] [--tenant 1] [--check]';
    protected $options     = [
        '--id'     => 'Flow id (required).',
        '--status' => 'active | paused | draft. Default: active.',
        '--tenant' => 'Tenant id. Default: 1.',
        '--check'  => 'Validate only; do not change the status.',
    ];

    public function run(array $params): void
    {
        $id       = (int) ($this->opt($params, 'id', '0') ?? 0);
        $status   = (string) ($this->opt($params, 'status', 'active') ?? 'active');
        $tenantId = (int) ($this->opt($params, 'tenant', '1') ?? 1);
        $checkOnly = $this->flag($params, 'check');

        if ($id <= 0) {
            CLI::error('--id is required.');
            return;
        }
        if (! in_array($status, ['active', 'paused', 'draft'], true)) {
            CLI::error("--status must be active, paused or draft (got '{$status}').");
            return;
        }

        $model = new FlowModel();
        $flow  = $model->withoutTenantScope()
            ->where('id', $id)->where('tenant_id', $tenantId)
            ->where('deleted_at', null)->first();

        if ($flow === null) {
            CLI::error("Flow #{$id} not found for tenant {$tenantId}.");
            return;
        }

        $flow  = (array) $flow;
        $graph = json_decode((string) ($flow['graph'] ?? '{}'), true) ?: [];

        CLI::write("[flows:activate] #{$id} \"{$flow['name']}\" — currently {$flow['status']}", 'cyan');

        // Validate whenever going live; a pause or a return to draft cannot
        // make anything worse, so it is not gated on the graph being perfect.
        if ($status === 'active' || $checkOnly) {
            $result = (new FlowValidator())->validate($flow, $graph, $tenantId);

            foreach (($result['warnings'] ?? []) as $warning) {
                CLI::write('  ! ' . (is_array($warning) ? json_encode($warning) : $warning), 'yellow');
            }
            foreach (($result['errors'] ?? []) as $error) {
                CLI::write('  ✗ ' . (is_array($error) ? json_encode($error) : $error), 'red');
            }

            if (! ($result['ok'] ?? $result['valid'] ?? empty($result['errors']))) {
                CLI::error('  Validation failed — status unchanged.');
                return;
            }
            CLI::write('  ✓ Graph validates.', 'green');
        }

        if ($checkOnly) {
            CLI::write('  --check given; status left as ' . $flow['status'] . '.', 'white');
            return;
        }

        $model->withoutTenantScope()->update($id, ['status' => $status]);
        CLI::write("  → status is now {$status}.", 'green');
    }

    /** Accepts both `--opt value` and `--opt=value`; CI4 only splits the former. */
    private function opt(array $params, string $name, ?string $default = null): ?string
    {
        if (isset($params[$name]) && ! is_bool($params[$name])) {
            return (string) $params[$name];
        }
        $value = CLI::getOption($name);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        $prefix = '--' . $name . '=';
        foreach (($_SERVER['argv'] ?? []) as $arg) {
            if (is_string($arg) && str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
        }
        return $default;
    }

    private function flag(array $params, string $name): bool
    {
        if (array_key_exists($name, $params) || CLI::getOption($name) !== null) {
            return true;
        }
        foreach (($_SERVER['argv'] ?? []) as $arg) {
            if ($arg === '--' . $name || (is_string($arg) && str_starts_with($arg, '--' . $name . '='))) {
                return true;
            }
        }
        return false;
    }
}
