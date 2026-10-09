<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Email\EmailService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Links inside outbound email (password reset, teammate invite, welcome) must
 * point at the customer-facing app. The previous hardcoded
 * 'http://localhost:5173' fallback meant an install that never set APP_URL —
 * which the shipped .env did not — mailed real users a reset link they could
 * not open. No DB required.
 */
class EmailAppUrlTest extends CIUnitTestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['APP_URL', 'CORS_ORIGIN'] as $k) {
            $this->saved[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k], $_SERVER[$k]);
                putenv($k);
            } else {
                $_ENV[$k] = $v;
            }
        }
        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    public function testPrefersExplicitAppUrl(): void
    {
        $this->setEnv('APP_URL', 'https://app.travelpilot.example');
        $this->setEnv('CORS_ORIGIN', 'https://ignored.example');

        $this->assertSame('https://app.travelpilot.example', EmailService::appUrl());
    }

    public function testTrailingSlashIsTrimmedSoLinksDoNotDoubleUp(): void
    {
        $this->setEnv('APP_URL', 'https://app.travelpilot.example/');

        $this->assertSame('https://app.travelpilot.example', EmailService::appUrl());
    }

    public function testFallsBackToCorsOriginWhenAppUrlIsUnset(): void
    {
        $this->setEnv('CORS_ORIGIN', 'https://spa.travelpilot.example');

        $this->assertSame('https://spa.travelpilot.example', EmailService::appUrl());
    }

    public function testTakesFirstEntryOfAMultiOriginCorsList(): void
    {
        $this->setEnv('CORS_ORIGIN', 'https://a.example, https://b.example');

        $this->assertSame('https://a.example', EmailService::appUrl());
    }

    public function testNeverFallsBackToAHardcodedLocalhostPort(): void
    {
        // Nothing configured — must land on the framework base URL, never on
        // the old 'http://localhost:5173' literal.
        $url = EmailService::appUrl();

        $this->assertStringStartsWith('http', $url);
        $this->assertStringNotContainsString('localhost:5173', $url);
    }
}
