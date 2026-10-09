<?php

declare(strict_types=1);

namespace App\Services\Email\Marketing\Transport;

/**
 * One outbound email. Implementations must never throw — a transport failure
 * is a result, so a batch can record it and move on.
 */
interface MailTransport
{
    /**
     * @param array{host:string,port:int,user:string,pass:string,crypto:string,from_email:string,from_name:string} $smtp
     * @param array<string,string> $headers
     * @return array{0:bool,1:?string} [ok, error]
     */
    public function send(array $smtp, string $to, string $subject, string $html, string $text, array $headers = [], ?string $replyTo = null, ?string $fromName = null): array;
}
