<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Security\UrlGuard;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Tests the SSRF guard. Uses IP-literal URLs so no DNS resolution is needed.
 */
class UrlGuardTest extends CIUnitTestCase
{
    public function testBlocksPrivateAndReservedIps(): void
    {
        foreach (['127.0.0.1', '10.0.0.5', '192.168.1.1', '172.16.0.1', '169.254.169.254', '::1', '0.0.0.0', 'not-an-ip'] as $ip) {
            $this->assertTrue(UrlGuard::isBlockedIp($ip), "{$ip} must be blocked");
        }
    }

    public function testAllowsPublicIps(): void
    {
        foreach (['8.8.8.8', '1.1.1.1', '93.184.216.34'] as $ip) {
            $this->assertFalse(UrlGuard::isBlockedIp($ip), "{$ip} must be allowed");
        }
    }

    public function testRejectsNonHttpSchemes(): void
    {
        $this->assertFalse(UrlGuard::isSafePublicUrl('ftp://8.8.8.8/x'));
        $this->assertFalse(UrlGuard::isSafePublicUrl('file:///etc/passwd'));
        $this->assertFalse(UrlGuard::isSafePublicUrl('gopher://8.8.8.8'));
        $this->assertFalse(UrlGuard::isSafePublicUrl(''));
    }

    public function testRejectsInternalHosts(): void
    {
        $this->assertFalse(UrlGuard::isSafePublicUrl('http://localhost/hook'));
        $this->assertFalse(UrlGuard::isSafePublicUrl('http://127.0.0.1:8080/hook'));
        $this->assertFalse(UrlGuard::isSafePublicUrl('http://169.254.169.254/latest/meta-data/'));
        $this->assertFalse(UrlGuard::isSafePublicUrl('https://10.0.0.1/internal'));
    }

    public function testAllowsPublicHttpsUrl(): void
    {
        // IP-literal public host → no DNS needed, deterministic.
        $this->assertTrue(UrlGuard::isSafePublicUrl('https://8.8.8.8/webhook'));
        $this->assertTrue(UrlGuard::isSafePublicUrl('http://1.1.1.1/hook?x=1'));
    }
}
