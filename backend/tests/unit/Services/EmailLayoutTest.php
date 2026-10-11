<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Email\EmailLayout;
use App\Services\Email\EmailService;
use App\Services\Marketing\EmailBody;
use CodeIgniter\Test\CIUnitTestCase;

/** The shared branded e-mail design: safe (escaped), self-contained (no remote images), with a plain-text twin. */
final class EmailLayoutTest extends CIUnitTestCase
{
    public function testEverythingIsEscapedAndThereAreNoRemoteImages(): void
    {
        $m = EmailLayout::build(['subject' => 'S <i>', 'title' => 'T <script>x</script>', 'preheader' => 'Pre <b>', 'blocks' => [
            ['p', '<img src=x onerror=alert(1)> see https://example.com/a?b=1&c=2'], ['facts', [['Label <b>', 'Val "q"']]], ['button', 'Go <b>', 'https://example.com/?a=1&b=2'], ['callout', '<script>alert(1)</script>', 'warn'],
            ['list', ['<u>one</u>']], ['steps', [['<s>t</s>', 'd']]],
        ]]);
        foreach (['<script', '<img', '<u>', '<s>'] as $bad) { $this->assertStringNotContainsString($bad, $m['html'], "raw '$bad' must not appear"); }
        $this->assertStringContainsString('href="https://example.com/a?b=1&amp;c=2"', $m['html']);
        $this->assertStringContainsString('href="https://example.com/?a=1&amp;b=2"', $m['html']);
        $this->assertStringNotContainsString('<img', $m['html']);
    }

    public function testHasPreheaderFooterAndOptionalUnsubscribe(): void
    {
        $plainMail = EmailLayout::build(['title' => 'Hello', 'preheader' => 'Preview line', 'blocks' => [['p', 'Hi']]]);
        $this->assertStringContainsString('Preview line', $plainMail['html']);
        $this->assertStringContainsString('Gamavis Software Solutions', $plainMail['html']);
        $this->assertStringNotContainsString('Unsubscribe', $plainMail['html'], 'account e-mails carry no unsubscribe link');

        $mk = EmailLayout::build(['title' => 'Offer', 'blocks' => [['p', 'Hi']], 'unsubscribe_url' => 'https://app.example/u?e=x&t=y']);
        $this->assertStringContainsString('Unsubscribe', $mk['html']);
        $this->assertStringContainsString('Unsubscribe: https://app.example/u?e=x&t=y', $mk['text']);
    }

    public function testTextTwinCarriesTheContentAndLinks(): void
    {
        $m = EmailLayout::build(['title' => 'Welcome', 'blocks' => [['p', 'Line one'], ['button', 'Open', 'https://app.example/x'], ['facts', [['Plan', 'Starter']]], ['steps', [['First', 'do this']]], ['list', ['a', 'b']]]]);
        foreach (['Welcome', 'Line one', 'Open: https://app.example/x', 'Plan: Starter', '1. First - do this', '- a', 'tripsarthi.com'] as $needle) { $this->assertStringContainsString($needle, $m['text']); }
    }

    public function testCampaignWritingRulesBecomeBlocks(): void
    {
        $r = EmailBody::render("# Big title\n\nHi {{first_name}},\n\n- one\n- two\n\n> Note here\n\n[[button: Click me | https://x.example/go?a=1&b=2]]\n\n[[button: Bad | javascript:alert(1)]]\n\n# Small headline", 'Priya', 'https://u.example/unsub', 'Subj');
        $this->assertStringContainsString('<h1', $r['html']); $this->assertStringContainsString('Big title', $r['html']);
        $this->assertStringContainsString('Hi Priya,', $r['html']);
        $this->assertStringContainsString('<li', $r['html']);
        $this->assertStringContainsString('Click me', $r['html']); $this->assertStringContainsString('href="https://x.example/go?a=1&amp;b=2"', $r['html']);
        $this->assertStringNotContainsString('href="javascript', $r['html'], 'only http(s) buttons are accepted');
        $this->assertStringContainsString('<h2', $r['html']);
    }

    public function testEveryRealEmailRendersThroughTheCaptureSeam(): void
    {
        $got = [];
        EmailService::$capture = static function ($to, $subject, $html, $text) use (&$got) { $got[$subject] = [$html, $text]; };
        try {
            EmailService::sendWelcome('a@example.com', 'Priya Sharma', 'https://app.example');
            EmailService::sendPasswordReset('a@example.com', 'Priya Sharma', 'https://app.example/reset?t=1');
            EmailService::sendCampaignComplete('a@example.com', 'Priya', 'Diwali', 10, 1);
            EmailService::sendWabaFlagged('a@example.com', 'Priya', '+91 98765 43210');
            EmailService::sendTest('a@example.com');
        } finally { EmailService::$capture = null; }
        $this->assertCount(5, $got);
        foreach ($got as $subject => [$html, $text]) {
            $this->assertStringContainsString('TripSarthi', $html, $subject); $this->assertStringNotContainsString('TravelPilot', $html . $text, "$subject still says TravelPilot");
            $this->assertStringNotContainsString('<img', $html);
        }
    }
}
