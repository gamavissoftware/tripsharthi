<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Exceptions\WindowClosedException;
use App\Models\ConversationModel;

/**
 * 24-hour customer service window service.
 *
 * CLOCK RULE: all comparisons use PHP's time() / date() against UTC.
 * Never use MySQL NOW() or DATE_ADD() — that would split the time source.
 * All datetime strings stored in DB are UTC.
 *
 * WINDOW_SECONDS is a named constant so a future 72-hour "free entry" tier
 * can be added without changing call sites.
 *
 * When the window is OPEN:  free-form text, image, document, video are allowed.
 * When CLOSED or NULL:      only approved template messages may be sent.
 *
 * Any free-form attempt outside the window MUST call assertFreeFormAllowed()
 * which throws WindowClosedException. The caller catches it, calls
 * MessageModel::logBlocked(), and returns a 422 — never silently discards.
 */
class WindowService
{
    /** Duration of the customer service window in seconds (24 hours). */
    public const WINDOW_SECONDS = 86400;

    /**
     * @param  ConversationModel $conversationModel
     * @param  int|null          $nowOverride  Inject a fixed timestamp for tests.
     *                                         Pass null (default) to use real time().
     */
    public function __construct(
        private readonly ConversationModel $conversationModel,
        private readonly ?int $nowOverride = null,
    ) {}

    private function now(): int
    {
        return $this->nowOverride ?? time();
    }

    // ------------------------------------------------------------------
    // Window state queries
    // ------------------------------------------------------------------

    /**
     * Is the 24-hour window currently open for this conversation?
     */
    public function isOpen(int $conversationId): bool
    {
        $conv = $this->conversationModel->withoutTenantScope()->find($conversationId);
        return $this->isOpenForConversation(
            is_array($conv) ? $conv : (array) ($conv ?? [])
        );
    }

    /**
     * Window check from an already-fetched conversation row (no extra DB query).
     */
    public function isOpenForConversation(array $conversation): bool
    {
        $exp = $conversation['window_expires_at'] ?? null;
        if (empty($exp)) {
            return false;
        }
        return strtotime($exp) > $this->now();
    }

    /**
     * Returns 'free_form' when the window is open, 'template_only' when closed.
     * Use this in the inbox UI to decide which controls to show.
     */
    public function getSendMode(int $conversationId): string
    {
        return $this->isOpen($conversationId) ? 'free_form' : 'template_only';
    }

    /**
     * How many seconds remain in the window (0 if closed or never opened).
     */
    public function secondsRemaining(int $conversationId): int
    {
        $conv = $this->conversationModel->withoutTenantScope()->find($conversationId);
        return $this->secondsRemainingForConversation(
            is_array($conv) ? $conv : (array) ($conv ?? [])
        );
    }

    /**
     * Seconds remaining from an already-fetched conversation row.
     */
    public function secondsRemainingForConversation(array $conversation): int
    {
        $exp = $conversation['window_expires_at'] ?? null;
        if (empty($exp)) return 0;

        $remaining = strtotime($exp) - $this->now();
        return max(0, $remaining);
    }

    /**
     * The formatted window_expires_at for a conversation (null if not open).
     */
    public function expiresAt(int $conversationId): ?string
    {
        $conv = $this->conversationModel->withoutTenantScope()->find($conversationId);
        $exp  = is_array($conv) ? ($conv['window_expires_at'] ?? null) : null;
        return (empty($exp) || strtotime($exp) <= $this->now()) ? null : $exp;
    }

    // ------------------------------------------------------------------
    // Window mutation
    // ------------------------------------------------------------------

    /**
     * Open or refresh the 24-hour window.
     *
     * Called on every inbound message. Sets window_expires_at = now + WINDOW_SECONDS.
     * Subsequent inbound messages push the window forward (each extends by 24h from now).
     *
     * @return string The new window_expires_at value (UTC datetime string).
     */
    public function refreshWindow(int $conversationId): string
    {
        $newExpiry = date('Y-m-d H:i:s', $this->now() + self::WINDOW_SECONDS);

        $this->conversationModel->withoutTenantScope()->update($conversationId, [
            'window_expires_at' => $newExpiry,
            'last_inbound_at'   => date('Y-m-d H:i:s', $this->now()),
        ]);

        return $newExpiry;
    }

    // ------------------------------------------------------------------
    // Send gate
    // ------------------------------------------------------------------

    /**
     * Assert that a free-form (non-template) outbound send is permitted.
     *
     * Throws WindowClosedException if the window is closed.
     *
     * EVERY free-form send path MUST call this before transmitting.
     * Catching the exception without logging is forbidden — see
     * MessageModel::logBlocked() for the required audit step.
     */
    public function assertFreeFormAllowed(int $conversationId): void
    {
        if (! $this->isOpen($conversationId)) {
            $conv = $this->conversationModel->withoutTenantScope()->find($conversationId);
            $exp  = is_array($conv) ? ($conv['window_expires_at'] ?? null) : null;

            throw new WindowClosedException(
                'The 24-hour customer service window is closed. '
                . 'Only approved template messages may be sent.',
                $conversationId,
                $exp,
            );
        }
    }

    /**
     * Assert from an already-fetched conversation row (avoids redundant DB query
     * when the caller has the row in hand, e.g. InboxController).
     */
    public function assertFreeFormAllowedForConversation(array $conversation): void
    {
        if (! $this->isOpenForConversation($conversation)) {
            $id  = (int) ($conversation['id'] ?? 0);
            $exp = $conversation['window_expires_at'] ?? null;

            throw new WindowClosedException(
                'The 24-hour customer service window is closed. '
                . 'Only approved template messages may be sent.',
                $id,
                $exp,
            );
        }
    }
}
