<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AI\AiScriptService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Phase L: AI call scripts + qualification questions.
 *  - Context brief is rendered from the lead fields (pure, no DB).
 *  - Unknown type is rejected.
 *  - Mock mode produces a successful generation without an API call.
 */
class AiScriptServiceTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_ENV['AI_MOCK_MODE'] = 'true';
    }

    protected function tearDown(): void
    {
        unset($_ENV['AI_MOCK_MODE']);
        parent::tearDown();
    }

    public function testUnknownTypeReturnsError(): void
    {
        $r = (new AiScriptService())->generate('bogus', []);
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('Unknown script type', (string) $r['error']);
    }

    public function testUserPromptRendersLeadContext(): void
    {
        $svc    = new AiScriptService();
        $prompt = $svc->userPrompt('call_script', [
            'name'            => 'Asha Rao',
            'job_title'       => 'CTO',
            'company'         => 'Acme',
            'lifecycle_stage' => 'opportunity',
            'deal_title'      => 'Annual plan',
        ]);

        $this->assertStringContainsString('Asha Rao', $prompt);
        $this->assertStringContainsString('CTO', $prompt);
        $this->assertStringContainsString('Annual plan', $prompt);
        $this->assertStringContainsString('call script', $prompt, 'call_script ask present');
    }

    public function testUserPromptRendersEnrichedContext(): void
    {
        // company, deal_stage and notes are now populated by the controller —
        // assert the service actually renders them into the brief.
        $prompt = (new AiScriptService())->userPrompt('call_script', [
            'name'       => 'Asha Rao',
            'company'    => 'Acme Corp',
            'deal_stage' => 'Proposal Sent',
            'notes'      => 'Prefers a call after 5pm; budget approved.',
        ]);

        $this->assertStringContainsString('Acme Corp', $prompt);
        $this->assertStringContainsString('Proposal Sent', $prompt);
        $this->assertStringContainsString('budget approved', $prompt);
    }

    public function testUserPromptHandlesEmptyContext(): void
    {
        $prompt = (new AiScriptService())->userPrompt('qualification', []);
        $this->assertStringContainsString('No specific lead details', $prompt);
        $this->assertStringContainsString('qualifying questions', $prompt, 'qualification ask present');
    }

    public function testGenerateInMockModeSucceeds(): void
    {
        $r = (new AiScriptService())->generate('qualification', ['name' => 'Asha Rao']);
        $this->assertTrue($r['success']);
        $this->assertNotSame('', trim($r['text']));
        $this->assertNull($r['error']);
    }
}
