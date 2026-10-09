<?php

declare(strict_types=1);

namespace App\Services\Email\Marketing;

use App\Services\Email\Mailbox\ImapReplyMailbox;
use App\Services\WhatsApp\TokenCipher;

/**
 * Background housekeeping for email marketing, driven by the existing
 * every-minute `campaigns:dispatch-scheduled` cron so no new cron lines are
 * needed:
 *
 *   - every 5 minutes: look for campaign replies in the tenant's connected
 *     IMAP mailbox (ReplyTracker; read-only, 7-day look-back, Message-ID dedupe)
 *   - once a day at/after 09:00 IST: send the daily report to notify_to
 *
 * Each tenant and each job is isolated; a failure is logged and never stops
 * the WhatsApp campaign dispatch that shares the cron.
 */
final class EmailInboxRunner
{
    public const POLL_EVERY  = 300;
    public const LOOKBACK    = 7 * 86400;
    public const REPORT_HOUR = 9; // IST

    /** @return list<string> log lines */
    public function tick(int $nowTs): array
    {
        $log  = [];
        $rows = db_connect()->table('integrations')->select('id, tenant_id, config')
            ->where('type', 'email_smtp')->where('status', 'active')->where('deleted_at', null)
            ->get()->getResultArray();

        foreach ($rows as $row) {
            $tenantId = (int) $row['tenant_id'];
            $cfg      = json_decode((string) $row['config'], true) ?: [];

            try {
                if ($nowTs - (int) ($cfg['replies_polled_at'] ?? 0) >= self::POLL_EVERY) {
                    $this->saveCfg((int) $row['id'], ['replies_polled_at' => $nowTs]);
                    $log[] = $this->pollReplies($tenantId, $nowTs);
                }
            } catch (\Throwable $e) {
                $this->saveCfg((int) $row['id'], ['replies_last_error' => mb_substr($e->getMessage(), 0, 300)]);
                $log[] = "tenant {$tenantId}: reply check failed — {$e->getMessage()}";
            }

            try {
                $today = gmdate('Y-m-d', $nowTs + 19800);
                $hour  = (int) gmdate('G', $nowTs + 19800);
                if (($cfg['notify_to'] ?? '') !== '' && $hour >= self::REPORT_HOUR && ($cfg['report_sent_on'] ?? '') !== $today) {
                    // Claim first: a crash costs one report, never a flood of them.
                    $this->saveCfg((int) $row['id'], ['report_sent_on' => $today]);
                    [$ok, $err] = (new EmailDailyReport())->send($tenantId, $nowTs);
                    $log[] = "tenant {$tenantId}: daily report " . ($ok ? 'sent' : "failed — {$err}");
                }
            } catch (\Throwable $e) {
                $log[] = "tenant {$tenantId}: daily report failed — {$e->getMessage()}";
            }
        }

        return array_values(array_filter($log));
    }

    public function pollReplies(int $tenantId, int $nowTs, bool $dryRun = false): string
    {
        $imap = db_connect()->table('integrations')->select('config')
            ->where('tenant_id', $tenantId)->where('type', 'email_imap')->where('status', 'active')->where('deleted_at', null)
            ->get()->getRowArray();
        if ($imap === null) {
            return '';
        }
        $cfg = json_decode((string) $imap['config'], true) ?: [];
        if (empty($cfg['host']) || str_ends_with((string) $cfg['host'], 'example.com')) {
            return '';
        }
        if (! empty($cfg['password_enc'])) {
            $cfg['password'] = TokenCipher::decrypt($cfg['password_enc']);
        }

        $res = (new ReplyTracker())->sync($tenantId, new ImapReplyMailbox($cfg), $nowTs - self::LOOKBACK, $dryRun);
        $this->saveCfgForTenant($tenantId, ['replies_last_error' => null, 'replies_last_ok' => $nowTs]);

        return "tenant {$tenantId}: checked {$res['checked']} messages — {$res['replies']} new replies, {$res['auto_replies']} auto-replies";
    }

    private function saveCfg(int $integrationId, array $patch): void
    {
        $db  = db_connect();
        $row = $db->table('integrations')->select('config')->where('id', $integrationId)->get()->getRowArray();
        $cfg = array_merge(json_decode((string) ($row['config'] ?? ''), true) ?: [], $patch);
        $db->table('integrations')->where('id', $integrationId)->update(['config' => json_encode($cfg)]);
    }

    private function saveCfgForTenant(int $tenantId, array $patch): void
    {
        $row = db_connect()->table('integrations')->select('id')->where('tenant_id', $tenantId)
            ->where('type', 'email_smtp')->where('status', 'active')->get()->getRowArray();
        if ($row) {
            $this->saveCfg((int) $row['id'], $patch);
        }
    }
}
