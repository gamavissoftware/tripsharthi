<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\WhatsApp\TemplateMediaUploader;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Resumable-upload uploader: mock mode returns a deterministic handle (no network);
 * real mode with no App ID fails clearly instead of calling Meta blind.
 */
class TemplateMediaUploaderTest extends CIUnitTestCase
{
    public function testMockModeReturnsHandleWithoutNetwork(): void
    {
        putenv('WHATSAPP_MOCK_MODE=true');
        $_ENV['WHATSAPP_MOCK_MODE'] = 'true';
        $_SERVER['WHATSAPP_MOCK_MODE'] = 'true';

        $r = (new TemplateMediaUploader('', 'tok'))->handleForUrl('https://x/a.png');
        $this->assertTrue($r['success']);
        $this->assertStringStartsWith('mock_handle_', $r['handle']);

        putenv('WHATSAPP_MOCK_MODE');
        unset($_ENV['WHATSAPP_MOCK_MODE'], $_SERVER['WHATSAPP_MOCK_MODE']);
    }

    public function testRealModeWithoutAppIdFailsClearly(): void
    {
        putenv('WHATSAPP_MOCK_MODE=false');
        $_ENV['WHATSAPP_MOCK_MODE'] = 'false';
        $_SERVER['WHATSAPP_MOCK_MODE'] = 'false';

        $r = (new TemplateMediaUploader('', 'tok'))->handleForUrl('https://x/a.png');
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('App ID', $r['error']);

        putenv('WHATSAPP_MOCK_MODE');
        unset($_ENV['WHATSAPP_MOCK_MODE'], $_SERVER['WHATSAPP_MOCK_MODE']);
    }
}
