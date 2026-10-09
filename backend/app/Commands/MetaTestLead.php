<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Leads\MetaTestLeadService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Fire a real test lead at a Facebook lead form with a real phone number, so
 * the whole pipeline — webhook → fetch → contact → flow — can be proven from
 * the command line.
 *
 *   php spark meta:test-lead --tenant=1 --list
 *   php spark meta:test-lead --tenant=1 --form=1065491639694344 --phone=+919718991797
 */
class MetaTestLead extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'meta:test-lead';
    protected $description = 'Create a real Meta test lead (with a real phone) on a linked Page\'s lead form.';
    protected $options     = [
        '--tenant' => 'Tenant id (default 1)',
        '--list'   => 'List the Page\'s lead forms and exit',
        '--form'   => 'Lead form id',
        '--phone'  => 'WhatsApp number to put in the lead, e.g. +919718991797',
        '--name'   => 'Full name (default "TravelPilot Test Lead")',
        '--email'  => 'Email (default test@travelpilot.test)',
    ];

    public function run(array $params): void
    {
        $tenantId = (int) (CLI::getOption('tenant') ?? 1);
        $service  = new MetaTestLeadService();

        try {
            if (CLI::getOption('list') !== null) {
                foreach ($service->forms($tenantId) as $f) {
                    CLI::write(sprintf('%-20s %-8s %5d  %s', $f['id'], $f['status'], $f['leads_count'], $f['name']));
                }

                return;
            }

            $form  = (string) (CLI::getOption('form') ?? '');
            $phone = (string) (CLI::getOption('phone') ?? '');
            if ($form === '' || $phone === '') {
                CLI::error('Both --form and --phone are required (or use --list).');

                return;
            }

            $result = $service->create(
                $tenantId,
                $form,
                $phone,
                (string) (CLI::getOption('name') ?? 'TravelPilot Test Lead'),
                (string) (CLI::getOption('email') ?? 'test@travelpilot.test'),
            );
        } catch (\Throwable $e) {
            CLI::error('[meta:test-lead] ' . $e->getMessage());

            return;
        }

        CLI::write("[meta:test-lead] Created test lead {$result['leadgen_id']} on form {$result['form_id']}.", 'green');
        CLI::write('Fields sent: ' . implode(', ', array_map(
            static fn (array $f): string => $f['name'] . '=' . $f['values'][0],
            $result['field_data']
        )));
        CLI::write('Meta will now call the webhook; flow:work picks it up within a minute.');
    }
}
