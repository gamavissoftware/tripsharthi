<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\ActivityModel;
use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Models\EmailModel;
use App\Services\Leads\ContactDedupeService;

/**
 * Inbound email ingestion (Phase L, v1 — webhook-based, no IMAP).
 *
 * A mail provider's inbound-parse webhook posts a normalized message; we match
 * the sender to a contact and log it as an inbound `emails` row + a timeline
 * `email` activity, giving 2-way threading alongside outbound sends. An unknown
 * sender now auto-creates an email-only contact (NULL wa_number, source
 * `email_inbound`) so the message is captured and the contact enrolls in
 * lead_created flows. Such a contact stays separate from any WhatsApp contact;
 * merge later via duplicate detection if they turn out to be the same person.
 *
 * Pure-ish: takes already-parsed fields, so it is unit-testable without HTTP.
 */
final class InboundEmailService
{
    /**
     * @return array{matched:bool, email_id:?int, contact_id?:int, created?:bool}
     */
    public function ingest(int $tenantId, string $fromRaw, string $toEmail, string $subject, string $body): array
    {
        $fromEmail = self::parseAddress($fromRaw);
        if ($fromEmail === '') {
            return ['matched' => false, 'email_id' => null];
        }

        // A travel portal's notification email (TravelTriangle, Justdial…) is a LEAD about someone else, not a message from the sender:
        // route it through the portal-lead pipeline instead of creating a contact for the portal's own address.
        try {
            $svc = new \App\Services\Leads\PortalLeadService();
            $portal = $svc->matchEmailSource($tenantId, $fromEmail);
            if ($portal !== null) {
                $r = $svc->ingestEmail($portal, $subject, $body);
                return ['matched' => true, 'email_id' => null, 'contact_id' => $r['contact_id'] ?? null, 'created' => ($r['status'] ?? '') === 'created', 'portal_lead' => $r['status']];
            }
        } catch (\Throwable $e) {
            log_message('error', 'portal email lead routing failed: ' . $e->getMessage());   // never lose the email: fall through to the normal path
        }

        $contact = (new ContactModel())->setTenant($tenantId)
            ->where('email', $fromEmail)
            ->first();

        $created = false;
        if (! $contact) {
            // Auto-create an email-only contact so the message isn't lost and the
            // sender enrolls in lead_created flows.
            $res = (new ContactDedupeService(new ContactModel(), new ContactFieldValueModel()))
                ->upsert($tenantId, [
                    'email'  => $fromEmail,
                    'name'   => self::nameFromAddress($fromRaw) ?: null,
                    'source' => 'email_inbound',
                ]);
            $contact = (new ContactModel())->setTenant($tenantId)->find($res['contact_id']);
            $created = true;
        }

        $contactId = (int) $contact['id'];
        $subject   = trim($subject) !== '' ? mb_substr(trim($subject), 0, 255) : '(no subject)';

        $emailId = (int) (new EmailModel())->setTenant($tenantId)->insert([
            'contact_id' => $contactId,
            'direction'  => 'in',
            'from_email' => $fromEmail,
            'to_email'   => self::parseAddress($toEmail) ?: $fromEmail,
            'subject'    => $subject,
            'body'       => $body,
            'status'     => 'sent', // direction='in' already marks it received; status reused as "recorded"
        ], true);

        (new ActivityModel())->log($tenantId, 'email', 'contact', $contactId, [
            'subject' => "↩ {$subject}",
            'body'    => mb_substr(strip_tags($body), 0, 2000),
            'meta'    => ['email_id' => $emailId, 'direction' => 'in', 'from' => $fromEmail],
        ]);

        return ['matched' => true, 'email_id' => $emailId, 'contact_id' => $contactId, 'created' => $created];
    }

    /** Extract a display name from "Name <a@b.com>"; '' when none present. */
    public static function nameFromAddress(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^([^<]+)<[^>]+>/', $raw, $m)) {
            return trim($m[1], " \t\"'");
        }
        return '';
    }

    /** Extract a bare address from "Name <a@b.com>" / "<a@b.com>" / "a@b.com". */
    public static function parseAddress(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/<([^>]+)>/', $raw, $m)) {
            $raw = $m[1];
        }
        $raw = trim($raw, " \t\"'");
        return filter_var($raw, FILTER_VALIDATE_EMAIL) ? strtolower($raw) : '';
    }
}
