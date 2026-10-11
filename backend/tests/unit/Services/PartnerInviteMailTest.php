<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Email\EmailService;
use App\Services\Partner\PartnerInviteMail;
use CodeIgniter\Test\CIUnitTestCase;

/** The partner invite / password-reset e-mail (no database or mail server needed). */
final class PartnerInviteMailTest extends CIUnitTestCase
{
    private const URL = 'https://partners.example.com/#/set-password?token=' . 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6';

    public function testInviteIsSentToThePartnerWithTheLinkAndCode(): void
    {
        $got = null;
        $r = PartnerInviteMail::send(['name' => 'Diksha <b>S</b>', 'email' => 'diksha@example.com', 'code' => 'DIKSHA1'], self::URL, false, function ($to, $subject, $html, $text) use (&$got) { $got = compact('to', 'subject', 'html', 'text'); return true; });
        $this->assertTrue($r['ok']); $this->assertNull($r['error']);
        $this->assertSame('diksha@example.com', $got['to']);
        $this->assertStringContainsString('invited', $got['subject']);
        $this->assertStringContainsString('DIKSHA1', $got['html']);
        $this->assertStringContainsString(self::URL, $got['text']);
        $this->assertStringNotContainsString('<b>S</b>', $got['html'], 'the name is escaped');
        $this->assertStringContainsString('expires in 7 days', $got['text']);
    }

    public function testResetWordingAndNoCode(): void
    {
        $got = null;
        PartnerInviteMail::send(['name' => 'Pat', 'email' => 'pat@example.com', 'code' => 'PAT'], self::URL, true, function ($to, $subject, $html, $text) use (&$got) { $got = compact('subject', 'html'); return true; });
        $this->assertStringContainsString('Reset', $got['subject']);
        $this->assertStringNotContainsString('referral code', $got['html']);
    }

    public function testAFailureIsReportedNotThrownAndABadAddressIsRefused(): void
    {
        EmailService::$lastError = 'Could not reach the mail server';
        $r = PartnerInviteMail::send(['name' => 'Pat', 'email' => 'pat@example.com'], self::URL, false, static function () { EmailService::$lastError = 'Could not reach the mail server'; return false; });
        $this->assertFalse($r['ok']); $this->assertStringContainsString('mail server', (string) $r['error']);
        $this->assertFalse(PartnerInviteMail::send(['name' => 'Pat', 'email' => 'not-an-email'], self::URL, false, static fn () => true)['ok']);
    }
}
