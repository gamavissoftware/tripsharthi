<?php

declare(strict_types=1);

namespace App\Controllers\Public;

use App\Services\Crm\MeetingLinkService;
use App\Services\Crm\OwnerAlertService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The stable "join the call" URL.
 *
 * Prospects are never given the Google Meet code directly. They are given this,
 * and this looks up wherever the business currently runs its demos and forwards
 * them. Three things fall out of that indirection:
 *
 *  1. The reminder template's URL button is fixed forever the moment Meta
 *     approves it. This account's Meet code belongs to a free Google account
 *     and expires after roughly ninety days unused — pointing the button here
 *     means a rotated code is a settings change, not a new template and a new
 *     review.
 *  2. A click on our own URL is a click we can see. With a free Google account
 *     nobody can enter the call until the host admits them, so "somebody is
 *     waiting in the lobby" is the single most useful thing to know, and it is
 *     only knowable here.
 *  3. Switching to Zoom later changes one setting and nothing else.
 *
 * Public and unauthenticated by necessity — the person clicking is a prospect,
 * not a user. It therefore does nothing destructive, takes no input beyond a
 * numeric id, and reveals nothing: an unknown id redirects exactly like a known
 * one, so the URL cannot be used to probe which meeting ids exist.
 */
class MeetRedirectController extends Controller
{
    public function go(?string $meetingId = null): ResponseInterface
    {
        $links     = new MeetingLinkService();
        $tenantId  = $this->resolveTenant((int) $meetingId);
        $link      = $links->forTenant($tenantId);

        if ($link === null) {
            // Nothing configured. Say so plainly rather than redirecting
            // somewhere arbitrary or showing a framework error.
            return $this->response->setStatusCode(503)
                ->setContentType('text/plain')
                ->setBody("The meeting link has not been set up yet. Please contact us and we'll send it to you.");
        }

        // Recorded and alerted before redirecting, because once we send the 302
        // this request is over. Both are wrapped so that a logging or WhatsApp
        // failure can never stop somebody reaching their own demo.
        try {
            $this->recordAndAlert($tenantId, (int) $meetingId);
        } catch (\Throwable $e) {
            log_message('error', '[MeetRedirect] tracking failed: ' . $e->getMessage());
        }

        return $this->response->redirect($link, 'auto', 302);
    }

    // ------------------------------------------------------------------

    /**
     * A meeting id identifies its tenant. Without one — the static button on the
     * approved template cannot carry a per-meeting value — fall back to the only
     * tenant this deployment has.
     */
    private function resolveTenant(int $meetingId): int
    {
        if ($meetingId > 0) {
            $row = db_connect()->table('meetings')->select('tenant_id')
                ->where('id', $meetingId)->where('deleted_at', null)
                ->get()->getRowArray();

            if ($row !== null) {
                return (int) $row['tenant_id'];
            }
        }

        return (int) (env('DEFAULT_TENANT_ID') ?: 1);
    }

    private function recordAndAlert(int $tenantId, int $meetingId): void
    {
        $meeting = null;
        if ($meetingId > 0) {
            $meeting = db_connect()->table('meetings')
                ->select('id, contact_id, start_at')
                ->where('id', $meetingId)->where('deleted_at', null)
                ->get()->getRowArray();
        }

        $contactId = (int) ($meeting['contact_id'] ?? 0);

        // Recorded through ClickTracker rather than a raw insert, so these land
        // alongside every other CTA click with the same shape and the same
        // idempotency, and so the column list stays in one place.
        $clickId = (new \App\Services\WhatsApp\ClickTracker())->record([
            'tenant_id'    => $tenantId,
            'contact_id'   => $contactId,
            'button_id'    => 'meet_join',
            'button_title' => 'Join the call',
            'source'       => 'button',
        ]);

        // DBDebug is off in production, so a rejected insert returns quietly.
        // Say so here or the first sign of trouble is an empty analytics page.
        if ($clickId === null) {
            log_message('warning', '[MeetRedirect] click not recorded for meeting #' . $meetingId);
        }

        // Only alert when we know who it was. A click on the un-attributed
        // static link would produce "Heads up — someone, somewhere", which is
        // noise, and noise is what makes people stop reading alerts.
        if ($contactId <= 0) {
            return;
        }

        $contact = db_connect()->table('contacts')->select('name, wa_number')
            ->where('id', $contactId)->get()->getRowArray();

        (new OwnerAlertService())->notify(
            $tenantId,
            (string) ($contact['name'] ?? 'A contact'),
            'just tapped the join link for their demo — they may be waiting in the lobby',
            (string) ($contact['wa_number'] ?? '')
        );
    }
}
