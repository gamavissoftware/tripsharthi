<?php

declare(strict_types=1);

namespace App\Services\Einvoice;

/** kind: invalid (the user can fix the data) | rejected (the IRP refused it, message is the IRP's) | transient (could not reach / try again) | auth | window (cancel window passed) */
final class EinvoiceException extends \RuntimeException
{
    public const INVALID = 'invalid', REJECTED = 'rejected', TRANSIENT = 'transient', AUTH = 'auth', WINDOW = 'window', DUPLICATE = 'duplicate';

    public function __construct(string $message, public readonly string $kind = self::REJECTED, public readonly ?string $irpCode = null, public readonly ?array $extra = null)
    {
        parent::__construct($message);
    }
}
