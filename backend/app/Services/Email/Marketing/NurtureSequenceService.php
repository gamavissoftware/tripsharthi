<?php

declare(strict_types=1);

namespace App\Services\Email\Marketing;

use App\Models\EmailCampaignModel;
use App\Models\EmailTemplateModel;
use App\Services\Leads\ScheduledCampaignDispatcher;

/**
 * Creates a nurture sequence: one scheduled follow-up campaign per step, each
 * to the people the source campaign reached, filtered by engagement across
 * the sequence (EmailCampaignSender::applyFollowup). days_after counts from
 * the source's send date at send_time in the given zone; each step waits for
 * the one before it (EmailCampaignScheduler).
 *
 * Shared by the "Add follow-up sequence" dialog and `spark email:schedule-nurture`.
 * Everything is validated before anything is created.
 */
final class NurtureSequenceService
{
    public const STATUSES = ['new', 'contacted', 'qualified', 'won', 'lost'];

    /**
     * @param  list<array{email_template_id:int|string, days_after:int|string}> $steps
     * @param  list<string> $excludeStatuses
     * @return list<array{id:int, days_after:int, scheduled_at:string, template:string}>
     * @throws \InvalidArgumentException with a user-facing message
     */
    public function create(
        int $tenantId,
        array $source,
        array $steps,
        string $engagement = 'not_clicked',
        array $excludeStatuses = ['qualified', 'won', 'lost'],
        string $sendTime = '10:30',
        string $timezone = 'Asia/Kolkata',
        ?int $userId = null,
        ?int $nowTs = null,
    ): array {
        $nowTs ??= time();

        if (! in_array($source['status'] ?? '', ['scheduled', 'processing', 'paused', 'done'], true)) {
            throw new \InvalidArgumentException('Send or schedule this campaign first — follow-ups are timed from its send date.');
        }
        $srcSeg = json_decode((string) ($source['segment'] ?? ''), true) ?: [];
        if (! empty($srcSeg['followup_of'])) {
            throw new \InvalidArgumentException('Add the sequence to the first email of the series, not to a follow-up.');
        }
        if ($steps === [] || count($steps) > 8) {
            throw new \InvalidArgumentException('Add between 1 and 8 follow-up emails.');
        }
        if (! in_array($engagement, EmailCampaignSender::ENGAGEMENT, true)) {
            throw new \InvalidArgumentException('Unknown engagement filter.');
        }
        $excludeStatuses = array_values(array_intersect($excludeStatuses, self::STATUSES));
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $sendTime)) {
            throw new \InvalidArgumentException('Use a 24-hour time like 10:30.');
        }

        // Anchor day = when the source goes (or went) out, in the user's zone.
        $anchorUtc = $source['scheduled_at'] ?: ($source['started_at'] ?: gmdate('Y-m-d H:i:s', $nowTs));
        try {
            $anchorDay = (new \DateTimeImmutable($anchorUtc, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d');
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Unknown timezone.');
        }

        $templates = (new EmailTemplateModel())->setTenant($tenantId);
        $plan      = [];
        $lastDay   = 0;
        foreach (array_values($steps) as $i => $step) {
            $n    = $i + 1;
            $days = (int) ($step['days_after'] ?? 0);
            $tpl  = $templates->find((int) ($step['email_template_id'] ?? 0));
            if (! $tpl) {
                throw new \InvalidArgumentException("Follow-up {$n}: choose a template.");
            }
            if ($days < 1 || $days > 120 || $days <= $lastDay) {
                throw new \InvalidArgumentException("Follow-up {$n}: days must be 1–120 and later than the previous step.");
            }
            $lastDay = $days;
            $local   = date('Y-m-d', strtotime("{$anchorDay} +{$days} days")) . ' ' . $sendTime;
            $utc     = ScheduledCampaignDispatcher::toUtc($local, $timezone);
            if (strtotime($utc . ' UTC') <= $nowTs) {
                throw new \InvalidArgumentException("Follow-up {$n} would be in the past ({$local}). Use a later day.");
            }
            $plan[] = ['tpl' => $tpl, 'days' => $days, 'utc' => $utc];
        }

        $campaigns = (new EmailCampaignModel())->setTenant($tenantId);
        $created   = [];
        $prevId    = (int) $source['id'];
        foreach ($plan as $i => $p) {
            $newId = (int) $campaigns->insert([
                'email_template_id' => (int) $p['tpl']['id'],
                'name'              => mb_substr("{$source['name']} · Follow-up " . ($i + 1) . " (day {$p['days']})", 0, 150),
                'subject'           => $p['tpl']['subject'],
                'preheader'         => $p['tpl']['preheader'],
                'from_name'         => $source['from_name'],
                'reply_to'          => $source['reply_to'],
                'html_body'         => $p['tpl']['html_body'],
                'segment'           => json_encode([
                    'followup_of'       => (int) $source['id'],
                    'after_campaign_id' => $prevId,
                    'engagement'        => $engagement,
                    'exclude_statuses'  => $excludeStatuses,
                    'name'              => $source['name'],
                ]),
                'status'            => 'scheduled',
                'scheduled_at'      => $p['utc'],
                'schedule_timezone' => $timezone,
                'stats'             => json_encode(EmailCampaignModel::emptyStats()),
                'created_by'        => $userId,
            ], true);
            $created[] = ['id' => $newId, 'days_after' => $p['days'], 'scheduled_at' => $p['utc'], 'template' => (string) $p['tpl']['name']];
            $prevId    = $newId;
        }

        return $created;
    }
}
