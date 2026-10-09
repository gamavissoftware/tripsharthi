<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Crm\QuotePdfService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Traits\CrmTestSchema;

/**
 * CRM Phase J1: quote PDF render — the snapshot HTML carries the quote's number,
 * currency-formatted totals, and line items; render() emits a valid PDF.
 */
class QuotePdfServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use CrmTestSchema;

    protected $migrate = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCrmSchema();
    }

    private function quote(): array
    {
        return [
            'number'   => 'Q-0007', 'currency' => 'USD', 'valid_until' => '2026-07-01',
            'subtotal' => 150000, 'total' => 165000, 'notes' => 'Thanks!',
            'items'    => [['name' => 'Onboarding', 'quantity' => 1, 'unit_price' => 150000, 'discount_pct' => 0, 'tax_pct' => 10, 'total' => 165000]],
        ];
    }

    public function testHtmlContainsSnapshot(): void
    {
        $html = (new QuotePdfService())->html($this->quote(), ['title' => 'Acme Deal'], ['app_name' => 'Gamavis', 'primary_color' => '#123456', 'logo_url' => null, 'support_email' => null]);

        $this->assertStringContainsString('Q-0007', $html);
        $this->assertStringContainsString('Acme Deal', $html);
        $this->assertStringContainsString('Onboarding', $html);
        $this->assertStringContainsString('$1,650.00', $html, 'total formatted in USD');
        $this->assertStringContainsString('Gamavis', $html, 'tenant branding name');
    }

    public function testRenderEmitsPdf(): void
    {
        $pdf = (new QuotePdfService())->render($this->quote(), ['title' => 'Acme Deal'], 1);
        $this->assertSame('%PDF', substr($pdf, 0, 4), 'output is a PDF');
        $this->assertGreaterThan(800, strlen($pdf));
    }
}
