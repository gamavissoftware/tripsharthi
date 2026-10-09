<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\Billing\Docs\InvoiceService;
use App\Services\Billing\Docs\QuoteDocument;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Dev/QA: render a document to a PDF file.  php spark docs:render quote <itineraryId> [tenant=1] [out=/tmp/x.pdf] */
class DocsRender extends BaseCommand
{
    protected $group       = 'TravelPilot';
    protected $name        = 'docs:render';
    protected $description = 'Render a quote/invoice PDF to a file for inspection.';
    protected $usage       = 'docs:render <quote|invoice> <id> [tenant] [out]';

    public function run(array $params): void
    {
        [$type, $id] = [$params[0] ?? '', (int) ($params[1] ?? 0)];
        $tenant = (int) ($params[2] ?? 1);
        $out    = $params[3] ?? WRITEPATH . "cache/{$type}-{$id}.pdf";
        if ($type === 'quote') {
            $r = (new QuoteDocument())->render($tenant, $id);
            file_put_contents($out, $r['pdf']);
            CLI::write("{$out} (" . number_format(strlen($r['pdf'])) . ' bytes) as ' . $r['filename']);
            return;
        }
        // invoice|receipt|credit: ISSUES a real document in this tenant (dev DB only!) and copies its PDF to $out.
        // Extra args are key=value buyer / credit options, e.g.  state=Karnataka gstin=... full=1 reason=...
        $opts = [];
        foreach (array_slice($params, 4) as $kv) { if (str_contains($kv, '=')) { [$k, $v] = explode('=', $kv, 2); $opts[$k] = $v; } }
        $svc = new InvoiceService();
        $inv = match ($type) {
            'invoice' => $svc->issueForBooking($tenant, $id, $opts),
            'receipt' => $svc->receipt($tenant, $id),
            'credit'  => $svc->creditNote($tenant, $id, $opts + ['reason' => 'Customer cancelled one traveller'] + (isset($opts['amount']) ? ['taxable_value' => (int) $opts['amount']] : [])),
            default   => throw new \InvalidArgumentException('Unknown document type.'),
        };
        file_put_contents($out, $svc->pdf($inv));
        CLI::write("{$inv['number']} total={$inv['total']} -> {$out}");
    }
}
