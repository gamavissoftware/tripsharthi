<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Marketing\ContactEnquiryService;
use CodeIgniter\Test\CIUnitTestCase;

final class ContactEnquiryTest extends CIUnitTestCase
{
    public function testGoodSubmissionIsCleaned(): void
    {
        $v = ContactEnquiryService::validate(['name' => "  Asha\r\nBcc: x@evil.test ", 'email' => ' Asha@Example.COM ', 'phone' => '+91 98765-43210', 'company' => 'Asha Tours', 'topic' => 'pricing', 'message' => "Hi\r\nthere", 'source' => 'contact.html']);
        $this->assertSame([], $v['errors']);
        $this->assertSame('Asha Bcc: x@evil.test', $v['clean']['name']);            // newlines become spaces: no header injection possible
        $this->assertSame('asha@example.com', $v['clean']['email']);
        $this->assertSame("Hi\nthere", $v['clean']['message']);
        $this->assertSame('pricing', $v['clean']['topic']);
    }

    public function testEveryProblemIsReported(): void
    {
        $e = ContactEnquiryService::validate(['name' => 'A', 'email' => 'nope', 'phone' => 'abc', 'message' => str_repeat('x', 3001)])['errors'];
        $this->assertSame(['name', 'email', 'phone', 'message'], array_keys($e));
    }

    public function testUnknownTopicFallsBackAndPhoneIsOptional(): void
    {
        $v = ContactEnquiryService::validate(['name' => 'Asha', 'email' => 'a@b.co', 'topic' => "demo\nBcc: x"]);
        $this->assertSame([], $v['errors']);
        $this->assertSame('other', $v['clean']['topic']);                            // the topic is a whitelist: it is the only thing that reaches the email subject
    }

    public function testLinkStuffingIsSpam(): void
    {
        $this->assertFalse(ContactEnquiryService::looksLikeSpam('See https://example.com for details'));
        $this->assertTrue(ContactEnquiryService::looksLikeSpam('http://a.example http://b.example www.c.example'));
    }
}
