<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Marketing\ContactEnquiryService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * The team's view of website enquiries.
 *   php spark contact:list                 newest 20 (any status)
 *   php spark contact:list --new           only unhandled
 *   php spark contact:list --handled 12    mark enquiry 12 handled
 *   php spark contact:list --retry         re-send notification emails that failed (cron every 15 min is fine)
 */
class ContactList extends BaseCommand
{
    protected $group       = 'TravelPilot';
    protected $name        = 'contact:list';
    protected $description = 'List website contact-form enquiries, mark them handled, or retry unsent notification emails.';

    public function run(array $params): void
    {
        $db = db_connect();
        if (CLI::getOption('retry') !== null) { CLI::write('Notification emails sent: ' . (new ContactEnquiryService())->retryUnsent()); return; }
        if (($id = CLI::getOption('handled')) !== null && $id !== true) { $db->table('contact_enquiries')->where('id', (int) $id)->update(['status' => 'handled']); CLI::write("Enquiry {$id} marked handled."); return; }
        $q = $db->table('contact_enquiries')->orderBy('id', 'DESC')->limit(20);
        if (CLI::getOption('new') !== null) { $q->where('status', 'new'); }
        $rows = $q->get()->getResultArray();
        if (! $rows) { CLI::write('No enquiries.'); return; }
        foreach ($rows as $r) {
            CLI::write(sprintf('#%d  %s  [%s]  %s <%s>  %s%s', $r['id'], $r['created_at'], $r['status'], $r['name'], $r['email'], $r['phone'] ?: '', $r['notified_at'] ? '' : '  (not emailed)'), $r['status'] === 'new' ? 'yellow' : 'white');
            CLI::write('      ' . ($r['topic']) . ': ' . mb_substr(str_replace("\n", ' ', (string) $r['message']), 0, 120));
        }
    }
}
