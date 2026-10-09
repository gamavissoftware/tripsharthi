<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Email\Marketing\EmailDailyReport;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Print — or email — the daily email-marketing report now. The report is
 * sent automatically every morning at 09:00 IST to the notify_to address;
 * --notify-to saves that address.
 *
 *   php spark email:report                           # print last 24h summary
 *   php spark email:report --send                    # email it to notify_to now
 *   php spark email:report --notify-to=me@x.com      # save alert/report address (then --send to test)
 */
class EmailReport extends BaseCommand
{
    protected $group       = 'travelpilot';
    protected $name        = 'email:report';
    protected $description = 'Show or send the daily email-marketing report.';
    protected $options     = [
        '--tenant'    => 'Tenant id (default 1)',
        '--send'      => 'Email the report now',
        '--notify-to' => 'Save the address that receives reply alerts and the daily report',
    ];

    public function run(array $params): void
    {
        $opts     = $this->options();
        $tenantId = (int) ($opts['tenant'] ?? 1) ?: 1;

        if (isset($opts['notify-to'])) {
            $to = trim((string) $opts['notify-to']);
            if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                CLI::error("--notify-to is not a valid email: {$to}");

                return;
            }
            $db  = db_connect();
            $row = $db->table('integrations')->where('tenant_id', $tenantId)->where('type', 'email_smtp')->where('status', 'active')->get()->getRowArray();
            if ($row === null) {
                CLI::error('No SMTP settings for this tenant.');

                return;
            }
            $cfg              = json_decode((string) $row['config'], true) ?: [];
            $cfg['notify_to'] = $to;
            $db->table('integrations')->where('id', $row['id'])->update(['config' => json_encode($cfg), 'updated_at' => date('Y-m-d H:i:s')]);
            CLI::write("Reply alerts and the daily report will go to {$to}.", 'green');
        }

        $r = new EmailDailyReport();
        $d = $r->build($tenantId, time());
        CLI::write("Last 24h — sent {$d['sent']}, opened {$d['opened']}, clicked {$d['clicked']}, replied {$d['replied']}, unsubscribed {$d['unsubscribed']}");
        foreach ($d['campaigns'] as $c) {
            $f = $c['funnel'];
            CLI::write(sprintf('  #%d %s — sent %d, opened %d (%s%%), clicked %d, replied %d',
                $c['id'], html_entity_decode((string) $c['name']), $f['sent'], $f['opened'], $f['open_rate'], $f['clicked'], $c['replied']));
        }

        if (array_key_exists('send', $opts)) {
            [$ok, $err] = $r->send($tenantId, time());
            $ok ? CLI::write('Report emailed.', 'green') : CLI::error("Not sent: {$err}");
        }
    }

    /** `--name value` and `--name=value` (CodeIgniter only parses the first). */
    private function options(): array
    {
        $out = [];
        foreach (CLI::getOptions() as $k => $v) {
            if (str_contains((string) $k, '=')) {
                [$k, $v] = explode('=', (string) $k, 2);
            }
            $out[$k] = $v;
        }

        return $out;
    }
}
