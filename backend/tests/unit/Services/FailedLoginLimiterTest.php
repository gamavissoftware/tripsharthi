<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Auth\FailedLoginLimiter;
use CodeIgniter\Test\CIUnitTestCase;

final class FailedLoginLimiterTest extends CIUnitTestCase
{
    public function testOnlyFailuresCountAndASuccessResetsTheCount(): void
    {
        $l = new FailedLoginLimiter(5, 60); $key = 'test|' . uniqid('', true);
        for ($i = 0; $i < 4; $i++) { $this->assertFalse($l->blocked($key)); $l->fail($key); }
        $this->assertFalse($l->blocked($key), 'four failures: still allowed');
        $l->clear($key);                                                    // a successful sign-in
        for ($i = 0; $i < 4; $i++) { $l->fail($key); }
        $this->assertFalse($l->blocked($key), 'the count started again after the success');
        $l->fail($key);
        $this->assertTrue($l->blocked($key), 'the fifth failure blocks');
        $l->clear($key);
    }

    public function testManySuccessfulSignInsNeverLockAnyoneOut(): void
    {
        $l = new FailedLoginLimiter(5, 60); $key = 'ok|' . uniqid('', true);
        for ($i = 0; $i < 30; $i++) { $this->assertFalse($l->blocked($key)); $l->clear($key); }
        $this->assertFalse($l->blocked($key));
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $l = new FailedLoginLimiter(2, 60); $a = 'a|' . uniqid('', true); $b = 'b|' . uniqid('', true);
        $l->fail($a); $l->fail($a);
        $this->assertTrue($l->blocked($a));
        $this->assertFalse($l->blocked($b));
        $l->clear($a);
    }
}
