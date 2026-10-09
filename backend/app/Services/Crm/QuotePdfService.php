<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\TenantModel;
use Dompdf\Dompdf;

/**
 * Renders a quote snapshot to a branded PDF and stores it (Phase J1). The PDF is
 * the deliverable that the existing windowed send_media path then uploads to Meta.
 * Read-only over the quote data; writes only the generated file.
 */
final class QuotePdfService
{
    private const STORE = WRITEPATH . 'uploads/quotes/';

    /** Currency symbols for display (Phase J4 reuses this map). */
    public const SYMBOL = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED ', 'AUD' => 'A$', 'SGD' => 'S$'];

    /** Generate (or regenerate) the PDF file; returns its absolute path. */
    public function generate(array $quote, array $deal, int $tenantId): string
    {
        if (! is_dir(self::STORE)) {
            mkdir(self::STORE, 0755, true);
        }
        $path = $this->path($tenantId, (string) $quote['number']);
        file_put_contents($path, $this->render($quote, $deal, $tenantId));
        return $path;
    }

    public function path(int $tenantId, string $number): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $number);
        return self::STORE . $tenantId . '_' . $safe . '.pdf';
    }

    /** Render the quote to a PDF binary string. */
    public function render(array $quote, array $deal, int $tenantId): string
    {
        $brand = $this->branding($tenantId);
        // SSRF guard: only let dompdf fetch a logo from a public http(s) URL; drop
        // it (falling back to the text logo) and disable remote fetching otherwise.
        $brand['logo_url'] = self::safeLogoUrl($brand['logo_url'] ?? null);
        $remoteEnabled     = $brand['logo_url'] !== null;

        $dompdf = new Dompdf(['isRemoteEnabled' => $remoteEnabled, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($this->html($quote, $deal, $brand));
        $dompdf->render();
        return (string) $dompdf->output();
    }

    /** Allow only a public http(s) URL as a remotely-fetched logo (blocks SSRF to internal hosts). */
    public static function safeLogoUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $p = parse_url($url);
        if (! $p || ! in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) {
            return null;
        }
        $host = strtolower($p['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return null;
        }
        // Reject private / reserved IP literals (link-local, loopback, RFC1918, etc.).
        if (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
        return $url;
    }

    /** @return array{app_name:string, primary_color:string, logo_url:?string, support_email:?string} */
    public function branding(int $tenantId): array
    {
        $defaults = ['app_name' => 'TravelPilot', 'primary_color' => '#0a6cc4', 'logo_url' => null, 'support_email' => null];
        $tenant   = (new TenantModel())->find($tenantId);
        $settings = $tenant ? (json_decode($tenant['settings'] ?? '{}', true) ?: []) : [];
        return array_merge($defaults, $settings['branding'] ?? []);
    }

    private function money(int $paise, string $currency): string
    {
        return CurrencyService::format($paise, $currency);
    }

    /** The HTML that becomes the PDF (public for snapshot testing). */
    public function html(array $quote, array $deal, array $brand): string
    {
        $items    = is_array($quote['items'] ?? null) ? $quote['items'] : (json_decode($quote['items'] ?? '[]', true) ?: []);
        $currency = (string) ($quote['currency'] ?? 'INR');
        $color    = htmlspecialchars($brand['primary_color'] ?: '#0a6cc4');
        $appName  = htmlspecialchars($brand['app_name'] ?: 'TravelPilot');
        $logo     = $brand['logo_url'] ? '<img src="' . htmlspecialchars($brand['logo_url']) . '" style="height:42px">' : '<span style="font-size:20px;font-weight:bold;color:' . $color . '">' . $appName . '</span>';

        $rows = '';
        foreach ($items as $it) {
            $rows .= '<tr>'
                . '<td style="padding:7px 8px;border-bottom:1px solid #eee">' . htmlspecialchars((string) ($it['name'] ?? '')) . '</td>'
                . '<td style="padding:7px 8px;border-bottom:1px solid #eee;text-align:center">' . (int) ($it['quantity'] ?? 0) . '</td>'
                . '<td style="padding:7px 8px;border-bottom:1px solid #eee;text-align:right">' . $this->money((int) ($it['unit_price'] ?? 0), $currency) . '</td>'
                . '<td style="padding:7px 8px;border-bottom:1px solid #eee;text-align:center">' . (int) ($it['discount_pct'] ?? 0) . '%</td>'
                . '<td style="padding:7px 8px;border-bottom:1px solid #eee;text-align:center">' . (int) ($it['tax_pct'] ?? 0) . '%</td>'
                . '<td style="padding:7px 8px;border-bottom:1px solid #eee;text-align:right;font-weight:bold">' . $this->money((int) ($it['total'] ?? 0), $currency) . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" style="padding:12px;text-align:center;color:#999">No line items</td></tr>';
        }

        $number   = htmlspecialchars((string) $quote['number']);
        $dealName = htmlspecialchars((string) ($deal['title'] ?? ''));
        $valid    = htmlspecialchars((string) ($quote['valid_until'] ?? ''));
        $notes    = $quote['notes'] ? '<p style="margin-top:18px;color:#555;font-size:12px"><strong>Notes:</strong> ' . htmlspecialchars((string) $quote['notes']) . '</p>' : '';
        $support  = $brand['support_email'] ? '<div style="color:#888;font-size:11px;margin-top:4px">' . htmlspecialchars($brand['support_email']) . '</div>' : '';

        return '<html><body style="font-family:DejaVu Sans,sans-serif;color:#222;font-size:13px">'
            . '<table style="width:100%"><tr>'
            . '<td>' . $logo . $support . '</td>'
            . '<td style="text-align:right"><div style="font-size:24px;font-weight:bold;color:' . $color . '">QUOTE</div>'
            . '<div style="color:#666">' . $number . '</div>'
            . '<div style="color:#888;font-size:11px">Valid until ' . $valid . '</div></td>'
            . '</tr></table>'
            . '<div style="margin:18px 0 6px;font-size:14px;font-weight:bold">' . $dealName . '</div>'
            . '<table style="width:100%;border-collapse:collapse;margin-top:8px">'
            . '<thead><tr style="background:' . $color . ';color:#fff">'
            . '<th style="padding:8px;text-align:left">Item</th><th style="padding:8px">Qty</th><th style="padding:8px;text-align:right">Unit</th><th style="padding:8px">Disc</th><th style="padding:8px">Tax</th><th style="padding:8px;text-align:right">Total</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<table style="width:100%;margin-top:14px"><tr><td></td><td style="width:220px">'
            . '<table style="width:100%"><tr><td style="padding:4px 8px;color:#666">Subtotal</td><td style="padding:4px 8px;text-align:right">' . $this->money((int) ($quote['subtotal'] ?? 0), $currency) . '</td></tr>'
            . '<tr><td style="padding:8px;font-weight:bold;border-top:2px solid ' . $color . '">Total</td><td style="padding:8px;text-align:right;font-weight:bold;font-size:15px;border-top:2px solid ' . $color . '">' . $this->money((int) ($quote['total'] ?? 0), $currency) . '</td></tr></table>'
            . '</td></tr></table>' . $notes
            . '</body></html>';
    }
}
