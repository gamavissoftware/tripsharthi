<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\WhatsApp\FlowResponseParser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests parsing of WhatsApp Flow submissions (interactive nfm_reply).
 */
class FlowResponseParserTest extends CIUnitTestCase
{
    public function testParsesResponseAndExtractsToken(): void
    {
        $interactive = [
            'type' => 'nfm_reply',
            'nfm_reply' => [
                'name' => 'flow',
                'body' => 'Sent',
                'response_json' => json_encode([
                    'flow_token' => 'ft_1_5_abc',
                    'full_name'  => 'Asha Rao',
                    'email'      => 'asha@example.com',
                ]),
            ],
        ];

        $parsed = FlowResponseParser::parse($interactive);

        $this->assertNotNull($parsed);
        $this->assertSame('ft_1_5_abc', $parsed['flow_token']);
        $this->assertSame('Asha Rao', $parsed['response']['full_name']);
        $this->assertArrayNotHasKey('flow_token', $parsed['response'], 'token is stripped from answers');
    }

    public function testReturnsNullForNonFlowInteractive(): void
    {
        $this->assertNull(FlowResponseParser::parse(['type' => 'button_reply']));
    }

    public function testHandlesMissingResponseJson(): void
    {
        $parsed = FlowResponseParser::parse(['type' => 'nfm_reply', 'nfm_reply' => []]);
        $this->assertSame('', $parsed['flow_token']);
        $this->assertSame([], $parsed['response']);
    }
}
