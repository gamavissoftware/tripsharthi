<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Travel\ConversionFeedbackService;
use PHPUnit\Framework\TestCase;

final class ConversionFeedbackServiceTest extends TestCase
{
    private function row(): array
    {
        return ['event_name' => 'Purchase', 'event_id' => 'trip9-Purchase', 'value_amount' => 5172930, 'currency' => 'INR', 'event_time' => '2026-10-08 12:00:00'];
    }

    public function testMetaConversionLeadsEventUsesLeadIdAndHashesPii(): void
    {
        $ev = ConversionFeedbackService::metaEvent($this->row(), ['lead_id' => '123456789012345'], ['wa_number' => '+91 98765-43210', 'email' => ' Rohit@Example.com ']);
        $this->assertSame('system_generated', $ev['action_source']);
        $this->assertSame(123456789012345, $ev['user_data']['lead_id']);
        $this->assertSame(hash('sha256', '919876543210'), $ev['user_data']['ph'][0]);
        $this->assertSame(hash('sha256', 'rohit@example.com'), $ev['user_data']['em'][0]);
        $this->assertSame(51729.30, $ev['custom_data']['value']);
        $this->assertSame('crm', $ev['custom_data']['event_source']);
        $this->assertSame('trip9-Purchase', $ev['event_id']);
    }

    public function testMetaClickToWhatsAppUsesBusinessMessaging(): void
    {
        $ev = ConversionFeedbackService::metaEvent($this->row(), ['ctwa_clid' => 'ARAkLk'], [], 'WABA1');
        $this->assertSame('business_messaging', $ev['action_source']);
        $this->assertSame('whatsapp', $ev['messaging_channel']);
        $this->assertSame('ARAkLk', $ev['user_data']['ctwa_clid']);
        $this->assertSame('WABA1', $ev['user_data']['whatsapp_business_account_id']);
    }

    public function testGoogleConversionUsesIstOffsetAndRequiresClickId(): void
    {
        $c = ConversionFeedbackService::googleConversion($this->row(), ['gclid' => 'abc'], 'customers/1/conversionActions/2');
        $this->assertSame('abc', $c['gclid']);
        $this->assertSame('2026-10-08 17:30:00+05:30', $c['conversionDateTime']);
        $this->assertSame('trip9-Purchase', $c['orderId']);
        $this->assertNull(ConversionFeedbackService::googleConversion($this->row(), [], 'x'));
    }
}
