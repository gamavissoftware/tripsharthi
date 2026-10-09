<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\TemplateModel;
use App\Models\WabaAccountModel;
use App\Services\WhatsApp\TemplateApiClient;
use App\Services\WhatsApp\TemplateComponentBuilder;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Submit draft templates to Meta for approval from the CLI.
 *
 * The Templates screen submits one template per click, which is fine for one
 * and tedious for ten. This does the same work — identical component building
 * and the same "already exists" recovery — for a list of ids.
 *
 *   php spark templates:submit --ids 25            # submit one, check, then the rest
 *   php spark templates:submit --drafts --dry-run  # show payloads, call nothing
 *
 * Submission is a real, outward-facing call against a live WABA, so there is
 * no "submit everything" default: either --ids or --drafts must be given, and
 * --dry-run prints exactly what would be sent.
 *
 * Media headers and carousel cards need a Meta header_handle from the
 * resumable upload API; those are refused here and should go through the UI.
 */
class TemplatesSubmit extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'templates:submit';
    protected $description = 'Submit draft WhatsApp templates to Meta for approval.';
    protected $usage       = 'templates:submit [--ids 25,26] [--drafts] [--tenant 1] [--dry-run]';
    protected $options     = [
        '--ids'     => 'Comma-separated template ids to submit.',
        '--drafts'  => 'Submit every template currently in draft for the tenant.',
        '--tenant'  => 'Tenant id. Default: 1.',
        '--dry-run' => 'Print the payload Meta would receive, and send nothing.',
    ];

    public function run(array $params): void
    {
        $tenantId = (int) ($this->opt($params, 'tenant', '1') ?? 1);
        $idsRaw   = (string) ($this->opt($params, 'ids') ?? '');
        $drafts   = $this->flag($params, 'drafts');
        $dryRun   = $this->flag($params, 'dry-run');

        if ($idsRaw === '' && ! $drafts) {
            CLI::error('Give --ids 25,26 or --drafts. Refusing to guess what to send to Meta.');
            return;
        }

        $model = new TemplateModel();
        $query = $model->withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('deleted_at', null)
            ->whereIn('meta_status', ['draft', 'rejected']);

        if ($idsRaw !== '') {
            $ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
            if ($ids === []) {
                CLI::error('--ids contained no usable template ids.');
                return;
            }
            $query->whereIn('id', $ids);
        }

        $templates = $query->orderBy('id', 'ASC')->findAll();

        if ($templates === []) {
            CLI::write('Nothing to submit — no draft or rejected templates matched.', 'yellow');
            return;
        }

        $client = $dryRun ? null : $this->client($tenantId);
        if (! $dryRun && $client === null) {
            CLI::error("No active WABA account for tenant {$tenantId}.");
            return;
        }

        CLI::write(sprintf(
            '[templates:submit] %d template(s), tenant %d%s',
            count($templates), $tenantId, $dryRun ? '  (DRY RUN — nothing is sent)' : ''
        ), 'cyan');

        $ok = $failed = 0;

        foreach ($templates as $tpl) {
            $name = $tpl['name'];

            if (($tpl['cards'] ?? '') !== '' && $tpl['cards'] !== '[]'
                || in_array($tpl['header_type'] ?? 'none', ['image', 'video', 'document'], true)
            ) {
                CLI::write("  ! {$name}: has media or cards — submit from the Templates screen instead.", 'yellow');
                $failed++;
                continue;
            }

            $payload = [
                'name'       => $name,
                'language'   => $tpl['language'],
                'category'   => strtoupper($tpl['category']),
                'components' => TemplateComponentBuilder::forSubmission($tpl),
            ];

            if ($dryRun) {
                CLI::write("  · {$name}", 'white');
                CLI::write('    ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'dark_gray');
                $ok++;
                continue;
            }

            $result = $client->create($payload);

            // A slow create can succeed at Meta but time out here; the retry
            // then reports "already exists". Recover by reading back what Meta
            // actually holds rather than reporting a failure.
            if (! $result['success'] && stripos((string) ($result['error'] ?? ''), 'already') !== false) {
                $synced = $this->syncByName($client, $name);
                if ($synced !== null) {
                    $model->withoutTenantScope()->update((int) $tpl['id'], $synced + ['submitted_at' => date('Y-m-d H:i:s')]);
                    CLI::write("  ✓ {$name}: already at Meta — status {$synced['meta_status']} (recovered)", 'green');
                    $ok++;
                    continue;
                }
            }

            if (! $result['success']) {
                CLI::write("  ✗ {$name}: " . ($result['error'] ?? 'submission failed'), 'red');
                $failed++;
                continue;
            }

            $model->withoutTenantScope()->update((int) $tpl['id'], [
                'meta_template_id' => $result['meta_template_id'],
                'meta_status'      => $result['meta_status'],
                'submitted_at'     => date('Y-m-d H:i:s'),
                'rejection_reason' => null,
            ]);

            CLI::write("  ✓ {$name}: {$result['meta_status']} (meta id {$result['meta_template_id']})", 'green');
            $ok++;
        }

        CLI::write("[templates:submit] {$ok} ok, {$failed} failed.", $failed > 0 ? 'yellow' : 'green');
        if (! $dryRun && $ok > 0) {
            CLI::write('  Meta review is asynchronous — run template:sync, or wait for the 2-hourly cron.', 'white');
        }
    }

    private function client(int $tenantId): ?TemplateApiClient
    {
        $wabaModel = new WabaAccountModel();
        $account   = $wabaModel->findActive($tenantId);
        if (! $account) {
            return null;
        }

        return new TemplateApiClient(
            is_array($account) ? ($account['waba_id'] ?? '') : ($account->waba_id ?? ''),
            $wabaModel->getDecryptedToken($account)
        );
    }

    /** @return array{meta_template_id:string, meta_status:string, rejection_reason:null}|null */
    private function syncByName(TemplateApiClient $client, string $name): ?array
    {
        $res = $client->listAll();
        if (! ($res['success'] ?? false)) {
            return null;
        }

        foreach ($res['templates'] ?? [] as $t) {
            if (($t['name'] ?? '') === $name) {
                return [
                    'meta_template_id' => (string) ($t['id'] ?? ''),
                    'meta_status'      => strtolower($t['status'] ?? 'pending'),
                    'rejection_reason' => null,
                ];
            }
        }

        return null;
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
