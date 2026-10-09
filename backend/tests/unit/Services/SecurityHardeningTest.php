<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Crm\CrmExporter;
use App\Services\Crm\QuotePdfService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Hardening from the security review: CSV formula-injection neutralization and
 * the quote-PDF logo SSRF guard.
 */
class SecurityHardeningTest extends CIUnitTestCase
{
    public function testCsvFormulaInjectionNeutralized(): void
    {
        foreach (['=1+1', '+cmd', '-2', '@SUM(A1)', "\tx", "\rx"] as $danger) {
            $this->assertSame("'" . $danger, CrmExporter::safeCell($danger), "leading formula char prefixed: {$danger}");
        }
        // Normal values are untouched.
        $this->assertSame('Acme Corp', CrmExporter::safeCell('Acme Corp'));
        $this->assertSame('123', CrmExporter::safeCell(123));
        $this->assertSame('', CrmExporter::safeCell(''));
    }

    public function testLogoUrlSsrfGuard(): void
    {
        // Public URLs allowed.
        $this->assertSame('https://cdn.example.com/logo.png', QuotePdfService::safeLogoUrl('https://cdn.example.com/logo.png'));
        $this->assertSame('http://example.org/l.jpg', QuotePdfService::safeLogoUrl('http://example.org/l.jpg'));

        // Internal / private / non-http targets blocked.
        foreach ([
            'http://localhost/x',
            'http://127.0.0.1/x',
            'http://169.254.169.254/latest/meta-data/', // cloud metadata
            'http://10.0.0.5/x',
            'http://192.168.1.1/x',
            'file:///etc/passwd',
            'javascript:alert(1)',
            'gopher://x/',
        ] as $bad) {
            $this->assertNull(QuotePdfService::safeLogoUrl($bad), "blocked: {$bad}");
        }
        $this->assertNull(QuotePdfService::safeLogoUrl(null));
    }
}
