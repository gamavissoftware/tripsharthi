<?php

declare(strict_types=1);

namespace App\Services\Email\Marketing;

use App\Models\ActivityModel;
use App\Services\Email\Mailbox\ReplyMailbox;

/**
 * Finds replies to email campaigns in a mailbox and acts on them.
 *
 * A message counts as a reply when its sender is someone a campaign actually
 * emailed (an outbound `emails` row to that address, sent before the message
 * arrived). Everything else in the mailbox is ignored — never recorded, never
 * turned into a contact — because this is usually a person's real inbox.
 *
 * For each new reply:
 *   - an inbound `emails` row (Message-ID makes a re-scan a no-op)
 *   - replied_at on the campaign email that was answered
 *   - a timeline activity on the contact
 *   - an alert to the tenant's notify_to address (ReplyNotifier)
 * The contact then drops out of every remaining follow-up: applyFollowup()
 * excludes anyone with a non-automatic inbound email since the sequence began.
 *
 * Out-of-office and other autoresponders are recorded (so they are not
 * re-read) but are not replies: no alert, and the nurture continues.
 */
final class ReplyTracker
{
    public function __construct(
        private readonly ?ReplyNotifier $notifier = new ReplyNotifier(),
    ) {}

    /**
     * @return array{checked:int, replies:int, auto_replies:int, list:list<array{from:string, subject:string, auto:bool}>}
     */
    public function sync(int $tenantId, ReplyMailbox $mailbox, int $sinceTs, bool $dryRun = false): array
    {
        $db      = db_connect();
        $result  = ['checked' => 0, 'replies' => 0, 'auto_replies' => 0, 'list' => []];
        $ownFrom = $this->ownAddresses($tenantId);

        foreach ($mailbox->listSince($sinceTs) as $m) {
            $result['checked']++;
            $from = strtolower(trim($m['from']));
            if ($from === '' || isset($ownFrom[$from]) || preg_match('/^(mailer-daemon|postmaster|no-?reply)@/i', $from)) {
                continue;
            }

            $messageId = $m['message_id'] !== '' ? mb_substr($m['message_id'], 0, 255)
                : '<lp-' . sha1($from . '|' . $m['date_ts'] . '|' . $m['subject']) . '>';
            $seen = $db->table('emails')->where('tenant_id', $tenantId)->where('message_id', $messageId)->countAllResults();
            if ($seen > 0) {
                continue;
            }

            // The campaign email this answers: the latest one sent to this address before it arrived.
            $answered = $db->table('emails')
                ->where('tenant_id', $tenantId)
                ->where('direction', 'out')
                ->where('status', 'sent')
                ->where('email_campaign_id IS NOT NULL')
                ->where('to_email', $from)
                ->where('sent_at <=', date('Y-m-d H:i:s', $m['date_ts'] + 600))
                ->where('deleted_at', null)
                ->orderBy('sent_at', 'DESC')
                ->get(1)->getRowArray();
            if ($answered === null) {
                continue;
            }

            $detail = $mailbox->read($m['uid']);
            $auto   = $detail['auto'] || (bool) preg_match('/^\s*(automatic reply|auto(matic)?[- ]?reply|autoreply|out of (the )?office|ooo\b|auto:)/i', $m['subject']);
            $text   = self::stripQuoted($detail['text']);

            $result['list'][] = ['from' => $from, 'subject' => $m['subject'], 'auto' => $auto];
            $auto ? $result['auto_replies']++ : $result['replies']++;
            if ($dryRun) {
                continue;
            }

            $received = date('Y-m-d H:i:s', $m['date_ts']);
            $db->table('emails')->insert([
                'tenant_id'     => $tenantId,
                'contact_id'    => (int) $answered['contact_id'],
                'direction'     => 'in',
                'is_auto_reply' => $auto ? 1 : 0,
                'message_id'    => $messageId,
                'from_email'    => $from,
                'to_email'      => (string) $answered['from_email'],
                'subject'       => mb_substr($m['subject'] !== '' ? $m['subject'] : '(no subject)', 0, 255),
                'body'          => mb_substr($text, 0, 20000),
                'status'        => 'sent',
                'sent_at'       => $received,
                'created_at'    => $received,
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            if ($auto) {
                continue;
            }

            $db->table('emails')->where('id', $answered['id'])->where('replied_at', null)
                ->update(['replied_at' => $received, 'updated_at' => date('Y-m-d H:i:s')]);

            (new ActivityModel())->log($tenantId, 'email', 'contact', (int) $answered['contact_id'], [
                'subject'     => '↩ Replied: ' . mb_substr($m['subject'], 0, 200),
                'body'        => mb_substr($text, 0, 2000),
                'occurred_at' => $received,
                'meta'        => ['direction' => 'in', 'from' => $from, 'email_campaign_id' => (int) $answered['email_campaign_id']],
            ]);

            $this->notifier?->notify($tenantId, (int) $answered['contact_id'], (int) $answered['email_campaign_id'], $from, $m['from_name'], $m['subject'], $text, $received);
        }

        return $result;
    }

    /**
     * The new part of a reply, without the quoted original underneath.
     * Handles Gmail/Outlook/Zoho/Apple markers; falls back to the whole text.
     */
    public static function stripQuoted(string $text): string
    {
        $text  = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $keep  = [];
        foreach ($lines as $i => $line) {
            $t = trim($line);
            if (preg_match('/^On .{5,200}wrote:?$/i', $t)
                || (preg_match('/^On .{5,120}$/i', $t) && preg_match('/wrote:?$/i', trim($lines[$i + 1] ?? '')))
                || preg_match('/^-{2,}\s*(Original Message|Forwarded message)/i', $t)
                || preg_match('/^_{5,}$/', $t)
                || preg_match('/^(From|Sent|De|Von):\s.+/i', $t) && preg_match('/^(Sent|Date|To|Subject|Gesendet|Envoyé):/i', trim($lines[$i + 1] ?? ''))
                || preg_match('/^={4,}\s*Reply above this line/i', $t)) {
                break;
            }
            if (str_starts_with($t, '>')) {
                continue;
            }
            $keep[] = $line;
        }
        $out = trim(implode("\n", $keep));

        return $out !== '' ? (string) preg_replace("/\n{3,}/", "\n\n", $out) : trim($text);
    }

    /** Addresses the tenant sends from — their own copies/threads are never "replies". @return array<string,true> */
    private function ownAddresses(int $tenantId): array
    {
        $s   = (new EmailComposer())->settings($tenantId);
        $own = [];
        foreach ([$s['smtp']['from_email'] ?? '', $s['copy_to'], $s['notify_to']] as $a) {
            if ($a !== '') {
                $own[strtolower($a)] = true;
            }
        }

        return $own;
    }
}
