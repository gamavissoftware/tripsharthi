<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

/**
 * Computes whether an outbound WhatsApp message is billable.
 *
 * Rules (Meta Cloud API per-conversation pricing model):
 *
 * marketing      → always 1 (opens a paid marketing conversation)
 * authentication → always 1 (opens a paid authentication conversation)
 * utility        → 0 inside an open service window (covered by user-initiated
 *                  conversation); 1 outside window (opens a paid utility conversation)
 * free_form /    → 0 (only allowed inside an open window — always free)
 * service
 *
 * The billable flag is stored on every outbound messages row for analytics.
 * This class is intentionally pure (no I/O) so it can be unit-tested exhaustively.
 */
final class BillableComputer
{
    /**
     * @param  string $category   'marketing' | 'authentication' | 'utility' | 'free_form' | 'service'
     * @param  bool   $windowOpen Is the 24-hour customer service window currently open?
     * @return int    1 = billable, 0 = free
     */
    public static function compute(string $category, bool $windowOpen): int
    {
        return match($category) {
            'marketing'      => 1,                     // always billable regardless of window
            'authentication' => 1,                     // always billable regardless of window
            'utility'        => $windowOpen ? 0 : 1,  // free inside open window; paid outside
            'service',
            'free_form'      => 0,                     // only allowed inside window → always free
            default          => 1,                     // unknown category: assume billable (safe default)
        };
    }
}
