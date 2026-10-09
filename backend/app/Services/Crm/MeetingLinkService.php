<?php

declare(strict_types=1);

namespace App\Services\Crm;

/**
 * The video-call link a booked demo actually happens on.
 *
 * It exists because the booking confirmation has been telling every prospect
 * "I'll send a video-call link before we start" since the slot picker went
 * live, and nothing has ever sent one. A promise nobody keeps is worse than no
 * promise, so the wording is now conditional on this returning something.
 *
 * Resolution order, most specific first:
 *   1. tenants.settings JSON, key `meeting_link` — per tenant, set in the app.
 *   2. MEETING_VIDEO_LINK in .env — the single-tenant deployment's shortcut.
 *   3. null, meaning "we have no link", which callers must treat as "then do
 *      not mention one".
 *
 * Only http(s) URLs are returned. Anything else is treated as unconfigured
 * rather than pasted into a customer's WhatsApp.
 */
final class MeetingLinkService
{
    /** Memoised per tenant — the reminder path asks once per run. */
    private static array $cache = [];

    public function forTenant(int $tenantId): ?string
    {
        if (array_key_exists($tenantId, self::$cache)) {
            return self::$cache[$tenantId];
        }

        return self::$cache[$tenantId] = $this->resolve($tenantId);
    }

    /** Test seam — the cache would otherwise outlive a single test. */
    public static function flushCache(): void
    {
        self::$cache = [];
    }

    /**
     * The URL a prospect is actually given: our own /meet redirect, not the
     * Google Meet link itself.
     *
     * Two reasons it has to be indirect. The reminder template's URL button is
     * fixed the moment Meta approves it, and this account's Meet code is from a
     * free Google account, which expires after about ninety days unused — bake
     * that code into the button and fixing it later means a fresh template and
     * a fresh review. And a click on our own URL is a click we can see, which
     * is what makes "they are waiting in the lobby" possible at all.
     *
     * Falls back to the raw link when there is no base URL to build on, and to
     * null when no link is configured, because a reminder must never offer a
     * "join" that goes nowhere.
     *
     * @param int $meetingId 0 for the static button on the approved template,
     *                       which cannot carry a per-meeting value.
     */
    public function joinUrlFor(int $tenantId, int $meetingId = 0): ?string
    {
        if ($this->forTenant($tenantId) === null) {
            return null;
        }

        $base = rtrim((string) (config('App')->baseURL ?? ''), '/');
        if ($base === '') {
            return $this->forTenant($tenantId);
        }

        return $meetingId > 0 ? "{$base}/meet/{$meetingId}" : "{$base}/meet";
    }

    private function resolve(int $tenantId): ?string
    {
        if ($tenantId > 0) {
            try {
                // Queried directly rather than through TenantModel: that model
                // extends CI4's Model, not this project's tenant-scoped
                // BaseModel, so it has no withoutTenantScope() — and calling it
                // threw a BadMethodCallException this catch block then swallowed,
                // silently demoting every tenant to the env fallback.
                $row = db_connect()->table('tenants')
                    ->select('settings')
                    ->where('id', $tenantId)
                    ->where('deleted_at', null)
                    ->get()->getRowArray();

                $settings = json_decode((string) ($row['settings'] ?? ''), true);
                if (is_array($settings)) {
                    $link = $this->clean($settings['meeting_link'] ?? null);
                    if ($link !== null) {
                        return $link;
                    }
                }
            } catch (\Throwable $e) {
                log_message('error', '[MeetingLink] tenant settings unreadable: ' . $e->getMessage());
            }
        }

        return $this->clean(env('MEETING_VIDEO_LINK'));
    }

    private function clean(mixed $value): ?string
    {
        $link = trim((string) ($value ?? ''));
        if ($link === '') {
            return null;
        }

        // Never paste something unvalidated into a customer's chat.
        if (! preg_match('#^https?://#i', $link) || filter_var($link, FILTER_VALIDATE_URL) === false) {
            log_message('warning', '[MeetingLink] ignoring a meeting_link that is not an http(s) URL.');

            return null;
        }

        return $link;
    }
}
