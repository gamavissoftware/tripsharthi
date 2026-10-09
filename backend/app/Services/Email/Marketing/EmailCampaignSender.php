<?php

declare(strict_types=1);

namespace App\Services\Email\Marketing;

use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Models\EmailCampaignModel;
use App\Services\Email\Marketing\Transport\MailTransport;
use App\Services\Leads\SegmentResolver;

/**
 * Sends one batch of an email campaign. Called by the `email_campaign_send`
 * job; the job re-enqueues itself until the audience is exhausted, so the
 * batch size is the send rate (one batch per worker minute).
 *
 * Mirrors the WhatsApp CampaignSender's guarantees:
 *  - keyset cursor on contacts.id, committed after the batch
 *  - each recipient is RESERVED (INSERT IGNORE on UNIQUE(email_campaign_id,
 *    contact_id)) before the SMTP call, so a retried or concurrent batch can
 *    never email the same contact twice
 *  - the audience is re-resolved from the segment per batch, so a contact who
 *    unsubscribes mid-campaign is skipped from then on
 *
 * Specific to email:
 *  - contacts without a valid address, suppressed addresses, and a second
 *    contact sharing an address already emailed by this campaign are skipped
 *  - WhatsApp opt_in is NOT an email gate: the suppression list is
 *  - a follow-up campaign (segment `followup_of`) targets the people a
 *    previous campaign reached — see applyFollowup()
 *  - the tenant's daily_limit is enforced over a rolling 24h window: at the
 *    cap the batch is skipped (result `throttled`) and the job retries later
 *  - an SMTP server that rejects the first few messages of a batch outright
 *    (bad password, sending disabled) pauses the campaign instead of burning
 *    through the whole list marking everyone failed; those reservations are
 *    released so a resume retries the same contacts
 */
class EmailCampaignSender
{
    /** Consecutive failures, before any success in a batch, that pause the campaign. */
    public const SYSTEMIC_FAILURES = 3;

    public function __construct(
        private readonly MailTransport $transport,
        private readonly EmailComposer $composer = new EmailComposer(),
        private readonly SegmentResolver $segments = new SegmentResolver(),
        private readonly SuppressionService $suppressions = new SuppressionService(),
        private readonly ?int $batchSizeOverride = null,
    ) {}

    /** Engagement filters a follow-up can apply to the previous campaign's recipients. */
    public const ENGAGEMENT = ['all', 'not_clicked', 'not_opened'];

    /**
     * @return array{status:string, sent:int, failed:int, total:int, cursor:int, throttled?:bool}
     */
    public function processBatch(int $campaignId, int $tenantId): array
    {
        $model    = (new EmailCampaignModel())->setTenant($tenantId);
        $campaign = $model->find($campaignId);
        if ($campaign === null) {
            throw new \RuntimeException("Email campaign #{$campaignId} not found for tenant #{$tenantId}.");
        }
        if (($campaign['status'] ?? '') !== 'processing') {
            return $this->result($campaign, (string) $campaign['status']);
        }

        $settings = $this->composer->settings($tenantId);
        if (! $settings['ready']) {
            $model->update($campaignId, ['status' => 'paused', 'last_error' => $settings['reason']]);

            return $this->result($campaign, 'paused');
        }

        $segment   = json_decode((string) ($campaign['segment'] ?? ''), true) ?: ['all' => true];
        $cursor    = (int) ($campaign['cursor'] ?? 0);
        $batchSize = $this->batchSizeOverride ?? $settings['rate_per_minute'];
        $stats     = (json_decode((string) ($campaign['stats'] ?? ''), true) ?: []) + EmailCampaignModel::emptyStats();

        if ($cursor === 0 && empty($campaign['started_at'])) {
            $model->update($campaignId, [
                'started_at'     => date('Y-m-d H:i:s'),
                'total_contacts' => $this->countAudience($tenantId, $segment),
            ]);
        }

        if ($settings['daily_limit'] > 0) {
            // Copies to the owner are SMTP messages too: each recipient costs two.
            $perRecipient = $settings['copy_to'] !== '' ? 2 : 1;
            $remaining    = intdiv(max(0, $settings['daily_limit'] - EmailComposer::usedInLast24h($tenantId, $settings)), $perRecipient);
            if ($remaining <= 0) {
                $model->update($campaignId, [
                    'last_error' => "Daily limit of {$settings['daily_limit']} emails reached — sending resumes automatically as the 24-hour window rolls.",
                ]);

                return $this->result($model->find($campaignId) ?? $campaign, 'processing') + ['throttled' => true];
            }
            $batchSize = min($batchSize, $remaining);
        }

        $contacts = $this->audience($tenantId, $segment)
            ->where('contacts.id >', $cursor)
            ->orderBy('contacts.id', 'ASC')
            ->findAll($batchSize);

        $suppressed = $this->suppressions->suppressedAmong(
            $tenantId,
            array_map(static fn ($c) => (string) ($c['email'] ?? ''), $contacts),
        );

        $db          = db_connect();
        $newCursor   = $cursor;
        $sentInBatch = 0;
        $failStreak  = [];

        foreach ($contacts as $contact) {
            $contactId = (int) $contact['id'];
            $address   = SuppressionService::normalize((string) ($contact['email'] ?? ''));

            if ($address === '' || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                $stats['skipped_no_email']++;
                $newCursor = $contactId;
                continue;
            }
            if (isset($suppressed[$address])) {
                $stats['skipped_suppressed']++;
                $newCursor = $contactId;
                continue;
            }
            // Two contacts sharing one inbox get the campaign once.
            $already = $db->table('emails')
                ->where('tenant_id', $tenantId)
                ->where('email_campaign_id', $campaignId)
                ->where('to_email', $address)
                ->countAllResults();
            if ($already > 0) {
                $stats['skipped_duplicate']++;
                $newCursor = $contactId;
                continue;
            }

            // ── Reserve before sending ────────────────────────────────
            $token = EmailTracking::newToken();
            $now   = date('Y-m-d H:i:s');
            $db->table('emails')->ignore(true)->insert([
                'tenant_id'         => $tenantId,
                'contact_id'        => $contactId,
                'email_campaign_id' => $campaignId,
                'tracking_token'    => $token,
                'direction'         => 'out',
                'from_email'        => $settings['smtp']['from_email'],
                'to_email'          => $address,
                'subject'           => mb_substr((string) $campaign['subject'], 0, 255),
                'status'            => 'queued',
                'sent_by'           => $campaign['created_by'] ?? null,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            if ($db->affectedRows() === 0) {
                $stats['skipped_duplicate']++;
                $newCursor = $contactId;
                continue;
            }
            $emailId = (int) $db->insertID();

            $mail = $this->composer->compose(
                $contact,
                (string) $campaign['subject'],
                (string) $campaign['html_body'],
                (string) ($campaign['preheader'] ?? ''),
                $token,
                $settings['footer_text'],
            );

            [$ok, $error] = $this->transport->send(
                $settings['smtp'],
                $address,
                $mail['subject'] !== '' ? $mail['subject'] : (string) $campaign['subject'],
                $mail['html'],
                $mail['text'],
                $mail['headers'],
                $campaign['reply_to'] ?? null,
                $campaign['from_name'] ?? null,
            );

            if (! $ok && $sentInBatch === 0) {
                $failStreak[$emailId] = (string) $error;
                if (count($failStreak) >= self::SYSTEMIC_FAILURES) {
                    // The server is refusing everything — stop, release these
                    // reservations and keep the cursor before them.
                    $db->table('emails')->whereIn('id', array_keys($failStreak))->delete();
                    $model->update($campaignId, [
                        'status'     => 'paused',
                        // Cursor and stats as they were: the resume re-walks
                        // this batch and must not count its skips twice.
                        'cursor'     => $cursor,
                        'last_error' => 'Paused: your SMTP server rejected every message — ' . mb_substr((string) $error, 0, 400),
                    ]);
                    log_message('error', "[email-campaign #{$campaignId}] paused, SMTP refusing: {$error}");

                    return $this->result($model->find($campaignId) ?? $campaign, 'paused');
                }
                // Hold the cursor until we know whether this is systemic.
                continue;
            }

            if ($ok) {
                $sentInBatch++;
            }
            // Past the systemic check: failures that were held are final now.
            $this->finalizeHeld($failStreak);
            $failStreak = [];

            $db->table('emails')->where('id', $emailId)->update([
                'status'     => $ok ? 'sent' : 'failed',
                'error'      => $ok ? null : mb_substr((string) $error, 0, 500),
                'sent_at'    => $ok ? date('Y-m-d H:i:s') : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if ($ok) {
                $this->composer->sendCopy(
                    $this->transport, $settings, $contact, $address,
                    (string) $campaign['subject'], (string) $campaign['html_body'],
                    (string) ($campaign['preheader'] ?? ''), $campaign['from_name'] ?? null,
                );
                (new ActivityModel())->log($tenantId, 'email', 'contact', $contactId, [
                    'subject' => $mail['subject'],
                    'body'    => 'Email campaign: ' . (string) $campaign['name'],
                    'meta'    => ['email_id' => $emailId, 'email_campaign_id' => $campaignId, 'to' => $address],
                ]);
            } else {
                log_message('error', "[email-campaign #{$campaignId}] send to {$address} failed: {$error}");
            }

            $newCursor = $contactId;
        }

        // A batch that ended while still holding unconfirmed failures (fewer
        // than SYSTEMIC_FAILURES, no success) records them as failed.
        if ($failStreak !== []) {
            $this->finalizeHeld($failStreak);
            $newCursor = max($newCursor, (int) end($contacts)['id']);
        }

        $isDone = count($contacts) < $batchSize;
        [$sent, $failed] = $this->counts($tenantId, $campaignId);

        $status = match (true) {
            ! $isDone                     => 'processing',
            $sent === 0 && $failed > 0    => 'failed',
            default                       => 'done',
        };

        $model->update($campaignId, [
            'cursor'       => $newCursor,
            'sent_count'   => $sent,
            'failed_count' => $failed,
            'stats'        => json_encode($stats),
            'status'       => $status,
            'completed_at' => $isDone ? date('Y-m-d H:i:s') : null,
            'last_error'   => null,
        ]);

        return ['status' => $status, 'sent' => $sent, 'failed' => $failed,
                'total' => (int) ($campaign['total_contacts'] ?? 0), 'cursor' => $newCursor];
    }

    /** Contacts in the segment that have an email address. */
    public function audience(int $tenantId, array $segment, string $select = 'contacts.*'): ContactModel
    {
        $model = (new ContactModel())->setTenant($tenantId)->select($select);
        $this->applySegment($model, $tenantId, $segment);

        return $model->where('contacts.email !=', '');
    }

    public function countAudience(int $tenantId, array $segment): int
    {
        $model = (new ContactModel())->setTenant($tenantId)->select('contacts.id');
        $this->applySegment($model, $tenantId, $segment);

        return $model->where('contacts.tenant_id', $tenantId)
            ->where('contacts.email !=', '')
            ->countAllResults();
    }

    private function applySegment(ContactModel $model, int $tenantId, array $segment): void
    {
        if (! empty($segment['followup_of'])) {
            $this->applyFollowup($model, $tenantId, $segment);

            return;
        }
        $this->segments->apply($model, $segment);
    }

    /**
     * Follow-up audience: everyone campaign `followup_of` actually reached
     * (status sent — not failed, not merely reserved), narrowed by:
     *
     *  - engagement  not_clicked | not_opened | all. Measured across the WHOLE
     *                sequence (the source and every other follow-up of it), so
     *                someone who clicked email 2 is not chased by email 3.
     *  - exclude_statuses  contacts a salesperson has moved on (e.g. qualified,
     *                won, lost) drop out of the remaining steps.
     *  - replies     anyone who has emailed back since the source started
     *                (ReplyTracker records it) is always excluded.
     *
     * Suppressed addresses are removed later by the sender, as for any campaign.
     * An unknown engagement value fails closed to the strictest filter.
     */
    private function applyFollowup(ContactModel $model, int $tenantId, array $segment): void
    {
        $source     = (int) $segment['followup_of'];
        $engagement = in_array($segment['engagement'] ?? '', self::ENGAGEMENT, true) ? $segment['engagement'] : 'not_clicked';
        $sequence   = self::sequenceIds($tenantId, $source);

        $model->whereIn('contacts.id', static function ($b) use ($tenantId, $source) {
            return $b->select('contact_id')->from('emails')
                ->where('tenant_id', $tenantId)
                ->where('email_campaign_id', $source)
                ->where('status', 'sent');
        });

        if ($engagement !== 'all') {
            $column = $engagement === 'not_opened' ? 'opened_at' : 'clicked_at';
            $model->whereNotIn('contacts.id', static function ($b) use ($tenantId, $sequence, $column) {
                return $b->select('contact_id')->from('emails')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('email_campaign_id', $sequence)
                    ->where("{$column} IS NOT NULL");
            });
        }

        // Anyone who has written back since the series began is a conversation
        // now, not a nurture target — whatever the engagement setting.
        $since = db_connect()->table('email_campaigns')->select('started_at, scheduled_at, created_at')
            ->where('id', $source)->where('tenant_id', $tenantId)->get()->getRowArray();
        $sinceAt = $since['started_at'] ?? $since['scheduled_at'] ?? $since['created_at'] ?? '1970-01-01 00:00:00';
        $model->whereNotIn('contacts.id', static function ($b) use ($tenantId, $sinceAt) {
            return $b->select('contact_id')->from('emails')
                ->where('tenant_id', $tenantId)
                ->where('direction', 'in')
                ->where('is_auto_reply', 0)
                ->where('created_at >=', $sinceAt);
        });

        $statuses = array_values(array_filter(array_map('strval', (array) ($segment['exclude_statuses'] ?? []))));
        if ($statuses !== []) {
            $model->whereNotIn('contacts.status', $statuses);
        }
    }

    /** The source campaign plus every campaign that is a follow-up of it. @return list<int> */
    public static function sequenceIds(int $tenantId, int $source): array
    {
        $ids  = [$source];
        $rows = db_connect()->table('email_campaigns')
            ->select('id, segment')
            ->where('tenant_id', $tenantId)
            ->like('segment', 'followup_of')
            ->get()->getResultArray();
        foreach ($rows as $r) {
            $seg = json_decode((string) $r['segment'], true) ?: [];
            if ((int) ($seg['followup_of'] ?? 0) === $source) {
                $ids[] = (int) $r['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param array<int,string> $held email id => SMTP error */
    private function finalizeHeld(array $held): void
    {
        foreach ($held as $emailId => $error) {
            db_connect()->table('emails')->where('id', $emailId)->update([
                'status'     => 'failed',
                'error'      => mb_substr($error, 0, 500),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /** @return array{0:int,1:int} [sent, failed] from the recipient rows themselves */
    private function counts(int $tenantId, int $campaignId): array
    {
        $rows = db_connect()->table('emails')
            ->select('status, COUNT(*) AS n')
            ->where('tenant_id', $tenantId)
            ->where('email_campaign_id', $campaignId)
            ->groupBy('status')
            ->get()->getResultArray();

        $by = array_column($rows, 'n', 'status');

        return [(int) ($by['sent'] ?? 0), (int) ($by['failed'] ?? 0)];
    }

    private function result(array $campaign, string $status): array
    {
        return [
            'status' => $status,
            'sent'   => (int) ($campaign['sent_count'] ?? 0),
            'failed' => (int) ($campaign['failed_count'] ?? 0),
            'total'  => (int) ($campaign['total_contacts'] ?? 0),
            'cursor' => (int) ($campaign['cursor'] ?? 0),
        ];
    }
}
