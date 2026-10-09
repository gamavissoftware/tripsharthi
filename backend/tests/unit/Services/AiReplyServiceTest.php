<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AI\AiReplyService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests AI history mapping (pure) + mock-mode generation. No real API calls.
 */
class AiReplyServiceTest extends CIUnitTestCase
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

    public function testBuildHistoryMapsDirectionsAndSkipsEmpty(): void
    {
        $turns = AiReplyService::buildHistory([
            ['direction' => 'in',  'body' => 'Hi there'],
            ['direction' => 'out', 'body' => 'Hello! How can I help?'],
            ['direction' => 'in',  'body' => ''],          // media-only → skipped
            ['direction' => 'in',  'body' => 'My order is late'],
        ]);

        $this->assertCount(3, $turns);
        $this->assertSame(['role' => 'user', 'content' => 'Hi there'], $turns[0]);
        $this->assertSame('assistant', $turns[1]['role']);
        $this->assertSame('My order is late', $turns[2]['content']);
    }

    public function testGenerateMockReturnsReply(): void
    {
        $svc = new AiReplyService();
        $res = $svc->generate('You are helpful.', [
            ['role' => 'user', 'content' => 'Do you ship to Mumbai?'],
        ]);

        $this->assertTrue($res['success']);
        $this->assertNotEmpty($res['text']);
        $this->assertStringContainsString('Mumbai', $res['text']);
    }

    public function testGenerateFailsWithNoUserTurn(): void
    {
        $svc = new AiReplyService();
        $res = $svc->generate('sys', [['role' => 'assistant', 'content' => 'hello']]);
        $this->assertFalse($res['success']);
        $this->assertNotNull($res['error']);
    }

    public function testLeadingAssistantTurnsAreDropped(): void
    {
        $svc = new AiReplyService();
        $res = $svc->generate('sys', [
            ['role' => 'assistant', 'content' => 'Welcome!'],
            ['role' => 'user', 'content' => 'Need help with returns'],
        ]);
        $this->assertTrue($res['success']);
        $this->assertStringContainsString('returns', $res['text']);
    }
}
