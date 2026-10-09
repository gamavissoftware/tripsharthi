<?php

declare(strict_types=1);

namespace App\Services\Ads;

/** Raised when an action is refused by AdGuardrails. Carries every violation so the UI can show them all. */
final class AdsGuardrailException extends \RuntimeException
{
    /** @param list<string> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(implode(' ', $violations));
    }
}
