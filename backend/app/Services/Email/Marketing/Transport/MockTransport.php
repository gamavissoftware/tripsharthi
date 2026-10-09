<?php

declare(strict_types=1);

namespace App\Services\Email\Marketing\Transport;

/**
 * EMAIL_MOCK_MODE and tests: records what would have been sent. A recipient
 * listed in $failFor fails with $failError, to exercise the failure paths.
 */
final class MockTransport implements MailTransport
{
    /** @var list<array{to:string,subject:string,html:string,text:string,headers:array,reply_to:?string,from_name:?string}> */
    public array $sent = [];

    /** @param list<string> $failFor */
    public function __construct(public array $failFor = [], public string $failError = 'Mock SMTP rejected the message.') {}

    public function send(array $smtp, string $to, string $subject, string $html, string $text, array $headers = [], ?string $replyTo = null, ?string $fromName = null): array
    {
        if (in_array($to, $this->failFor, true) || in_array('*', $this->failFor, true)) {
            return [false, $this->failError];
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text, 'headers' => $headers, 'reply_to' => $replyTo, 'from_name' => $fromName];

        return [true, null];
    }
}
