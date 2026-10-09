<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\ContactModel;
use App\Models\EmailCampaignModel;
use App\Models\EmailSuppressionModel;
use App\Models\EmailTemplateModel;
use App\Services\Auth\CurrentUser;
use App\Services\Email\Marketing\EmailCampaignReport;
use App\Services\Email\Marketing\EmailCampaignScheduler;
use App\Services\Email\Marketing\EmailCampaignSender;
use App\Services\Email\Marketing\EmailComposer;
use App\Services\Email\Marketing\EmailPersonalizer;
use App\Services\Email\Marketing\NurtureSequenceService;
use App\Services\Email\Marketing\SuppressionService;
use App\Services\Leads\ScheduledCampaignDispatcher;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Email marketing: templates, bulk campaigns to a segment, reports and the
 * suppression list. Sending itself happens in the `email_campaign_send` job.
 *
 * All routes under /api/v1/email-marketing.
 */
class EmailMarketingController extends ResourceController
{
    protected $format = 'json';

    private const MAX_HTML = 512000;

    // ── Overview ───────────────────────────────────────────────────────

    // GET /email-marketing/overview
    public function overview(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $settings = (new EmailComposer())->settings($tenantId);
        $report   = new EmailCampaignReport();

        $withEmail = (new ContactModel())->setTenant($tenantId)
            ->where('contacts.tenant_id', $tenantId)->where('email !=', '')->countAllResults();
        $suppressed = db_connect()->table('email_suppressions')->where('tenant_id', $tenantId)->countAllResults();

        return $this->respond(['success' => true, 'data' => [
            'ready'               => $settings['ready'],
            'reason'              => $settings['reason'],
            'mock_mode'           => EmailComposer::isMockMode(),
            'from_email'          => $settings['smtp']['from_email'],
            'rate_per_minute'     => $settings['rate_per_minute'],
            'daily_limit'         => $settings['daily_limit'],
            'sent_last_24h'       => EmailComposer::usedInLast24h($tenantId, $settings),
            'copy_to'             => $settings['copy_to'],
            'contacts_with_email' => $withEmail,
            'suppressed'          => $suppressed,
            'last_30_days'        => $report->funnel($tenantId, null, date('Y-m-d H:i:s', strtotime('-30 days'))),
            'all_time'            => $report->funnel($tenantId),
        ]]);
    }

    // ── Templates ──────────────────────────────────────────────────────

    public function templates(): ResponseInterface
    {
        $rows = $this->templateModel()
            ->select('id, name, subject, preheader, created_at, updated_at')
            ->orderBy('updated_at', 'DESC')->findAll(200);

        return $this->respond(['success' => true, 'data' => $rows]);
    }

    public function showTemplate($id = null): ResponseInterface
    {
        $row = $this->templateModel()->find((int) $id);

        return $row ? $this->respond(['success' => true, 'data' => $row]) : $this->failNotFound('Template not found.');
    }

    public function createTemplate(): ResponseInterface
    {
        $data = $this->templateInput();
        if (is_string($data)) {
            return $this->fail($data, 422);
        }
        $id = $this->templateModel()->insert($data, true);
        if (! $id) {
            return $this->fail($this->templateModel()->errors() ?: 'Could not save template.', 422);
        }

        return $this->respondCreated(['success' => true, 'data' => $this->templateModel()->find((int) $id)]);
    }

    public function updateTemplate($id = null): ResponseInterface
    {
        if (! $this->templateModel()->find((int) $id)) {
            return $this->failNotFound('Template not found.');
        }
        $data = $this->templateInput();
        if (is_string($data)) {
            return $this->fail($data, 422);
        }
        $this->templateModel()->update((int) $id, $data);

        return $this->respond(['success' => true, 'data' => $this->templateModel()->find((int) $id)]);
    }

    public function deleteTemplate($id = null): ResponseInterface
    {
        if (! $this->templateModel()->find((int) $id)) {
            return $this->failNotFound('Template not found.');
        }
        $this->templateModel()->delete((int) $id);

        return $this->respondDeleted(['success' => true]);
    }

    // ── Campaigns ──────────────────────────────────────────────────────

    public function campaigns(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $rows     = $this->campaignModel()
            ->select('id, name, subject, status, segment, scheduled_at, schedule_timezone, total_contacts,
                      sent_count, failed_count, last_error, started_at, completed_at, created_at')
            ->orderBy('id', 'DESC')->findAll(100);

        $funnels = (new EmailCampaignReport())->funnelByCampaign($tenantId, array_map(static fn ($r) => (int) $r['id'], $rows));
        foreach ($rows as &$r) {
            $r['segment'] = json_decode((string) ($r['segment'] ?? ''), true) ?: ['all' => true];
            $r['funnel']  = $funnels[(int) $r['id']] ?? null;
        }
        unset($r);

        return $this->respond(['success' => true, 'data' => $rows]);
    }

    public function showCampaign($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $row      = $this->campaignModel()->find((int) $id);
        if (! $row) {
            return $this->failNotFound('Campaign not found.');
        }
        $report         = new EmailCampaignReport();
        $row['segment'] = json_decode((string) ($row['segment'] ?? ''), true) ?: ['all' => true];
        $row['stats']   = (json_decode((string) ($row['stats'] ?? ''), true) ?: []) + EmailCampaignModel::emptyStats();
        $row['funnel']  = $report->funnel($tenantId, [(int) $id]);
        $row['links']   = $report->topLinks($tenantId, (int) $id);

        return $this->respond(['success' => true, 'data' => $row]);
    }

    public function createCampaign(): ResponseInterface
    {
        $data = $this->campaignInput(null);
        if (is_string($data)) {
            return $this->fail($data, 422);
        }
        $data['status']     = 'draft';
        $data['created_by'] = CurrentUser::id() ?: null;
        $data['stats']      = json_encode(EmailCampaignModel::emptyStats());

        $id = $this->campaignModel()->insert($data, true);

        return $this->respondCreated(['success' => true, 'data' => $this->campaignModel()->find((int) $id)]);
    }

    public function updateCampaign($id = null): ResponseInterface
    {
        $row = $this->campaignModel()->find((int) $id);
        if (! $row) {
            return $this->failNotFound('Campaign not found.');
        }
        if (! in_array($row['status'], EmailCampaignModel::EDITABLE, true)) {
            return $this->fail("A {$row['status']} campaign can no longer be edited.", 422);
        }
        $data = $this->campaignInput($row);
        if (is_string($data)) {
            return $this->fail($data, 422);
        }
        $this->campaignModel()->update((int) $id, $data);

        return $this->respond(['success' => true, 'data' => $this->campaignModel()->find((int) $id)]);
    }

    public function deleteCampaign($id = null): ResponseInterface
    {
        $row = $this->campaignModel()->find((int) $id);
        if (! $row) {
            return $this->failNotFound('Campaign not found.');
        }
        if ($row['status'] === 'processing') {
            return $this->fail('Pause or cancel the campaign before deleting it.', 422);
        }
        $this->campaignModel()->delete((int) $id);

        return $this->respondDeleted(['success' => true]);
    }

    public function duplicateCampaign($id = null): ResponseInterface
    {
        $row = $this->campaignModel()->find((int) $id);
        if (! $row) {
            return $this->failNotFound('Campaign not found.');
        }
        $newId = $this->campaignModel()->insert([
            'email_template_id' => $row['email_template_id'],
            'name'              => mb_substr('Copy of ' . $row['name'], 0, 150),
            'subject'           => $row['subject'],
            'preheader'         => $row['preheader'],
            'from_name'         => $row['from_name'],
            'reply_to'          => $row['reply_to'],
            'html_body'         => $row['html_body'],
            'segment'           => $row['segment'],
            'status'            => 'draft',
            'stats'             => json_encode(EmailCampaignModel::emptyStats()),
            'created_by'        => CurrentUser::id() ?: null,
        ], true);

        return $this->respondCreated(['success' => true, 'data' => $this->campaignModel()->find((int) $newId)]);
    }

    // POST /email-marketing/audience-count  { segment }
    public function audienceCount(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $segment  = $this->segmentInput($this->request->getJsonVar('segment', true));
        $sender   = new EmailCampaignSender(EmailComposer::defaultTransport());

        $emails = array_column(
            $sender->audience($tenantId, $segment, 'contacts.email')->findAll(50000),
            'email',
        );
        $unique     = array_unique(array_map([SuppressionService::class, 'normalize'], $emails));
        $suppressed = count((new SuppressionService())->suppressedAmong($tenantId, $unique));

        return $this->respond(['success' => true, 'data' => [
            'with_email' => count($emails),
            'suppressed' => $suppressed,
            'sendable'   => max(0, count($unique) - $suppressed),
        ]]);
    }

    // POST /email-marketing/campaigns/:id/send
    public function send($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $row      = $this->campaignModel()->find((int) $id);
        if (! $row) {
            return $this->failNotFound('Campaign not found.');
        }
        if (! in_array($row['status'], ['draft', 'scheduled'], true)) {
            return $this->fail("Campaign is {$row['status']}; only a draft or scheduled campaign can be sent.", 422);
        }
        if ($err = $this->readyError($tenantId)) {
            return $this->fail($err, 422);
        }

        $this->campaignModel()->update((int) $id, ['status' => 'processing', 'last_error' => null, 'scheduled_at' => null]);
        EmailCampaignScheduler::enqueue($tenantId, (int) $id);

        return $this->respond(['success' => true, 'message' => 'Sending started — the first batch goes out within a minute.']);
    }

    // POST /email-marketing/campaigns/:id/schedule  { scheduled_at: 'Y-m-d H:i', timezone }
    public function schedule($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $row      = $this->campaignModel()->find((int) $id);
        if (! $row) {
            return $this->failNotFound('Campaign not found.');
        }
        if (! in_array($row['status'], ['draft', 'scheduled'], true)) {
            return $this->fail("Only a draft campaign can be scheduled (current: {$row['status']}).", 422);
        }
        if ($err = $this->readyError($tenantId)) {
            return $this->fail($err, 422);
        }

        try {
            $utc = ScheduledCampaignDispatcher::toUtc(
                (string) $this->request->getJsonVar('scheduled_at'),
                (string) ($this->request->getJsonVar('timezone') ?: 'Asia/Kolkata'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail(['scheduled_at' => $e->getMessage()], 422);
        }
        if (strtotime($utc . ' UTC') <= time()) {
            return $this->fail(['scheduled_at' => 'Scheduled time must be in the future.'], 422);
        }

        $this->campaignModel()->update((int) $id, [
            'status'            => 'scheduled',
            'scheduled_at'      => $utc,
            'schedule_timezone' => (string) ($this->request->getJsonVar('timezone') ?: 'Asia/Kolkata'),
        ]);

        return $this->respond(['success' => true, 'data' => $this->campaignModel()->find((int) $id)]);
    }

    public function unschedule($id = null): ResponseInterface
    {
        return $this->transition((int) $id, ['scheduled'], ['status' => 'draft', 'scheduled_at' => null]);
    }

    public function pause($id = null): ResponseInterface
    {
        return $this->transition((int) $id, ['processing'], ['status' => 'paused']);
    }

    public function cancel($id = null): ResponseInterface
    {
        return $this->transition((int) $id, ['processing', 'paused', 'scheduled'], [
            'status' => 'cancelled', 'completed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function resume($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        if ($err = $this->readyError($tenantId)) {
            return $this->fail($err, 422);
        }
        $res = $this->transition((int) $id, ['paused'], ['status' => 'processing', 'last_error' => null]);
        if ($res->getStatusCode() === 200) {
            EmailCampaignScheduler::enqueue($tenantId, (int) $id);
        }

        return $res;
    }

    /**
     * POST /email-marketing/campaigns/:id/sequence — schedule a nurture
     * sequence of follow-ups after this campaign.
     *
     * { steps: [{email_template_id, days_after}], engagement, exclude_statuses[],
     *   send_time: 'HH:MM', timezone }
     *
     * Each step is its own scheduled campaign to the people THIS campaign
     * reached, filtered by engagement across the whole sequence. days_after is
     * counted from this campaign's send date; each step also waits for the one
     * before it to finish (EmailCampaignScheduler), so a throttled large send is
     * never overtaken by its own follow-up.
     */
    public function sequence($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $source   = $this->campaignModel()->find((int) $id);
        if (! $source) {
            return $this->failNotFound('Campaign not found.');
        }
        if ($err = $this->readyError($tenantId)) {
            return $this->fail($err, 422);
        }
        $steps = $this->request->getJsonVar('steps', true);

        try {
            $created = (new NurtureSequenceService())->create(
                $tenantId,
                $source,
                is_array($steps) ? $steps : [],
                (string) ($this->request->getJsonVar('engagement') ?? 'not_clicked'),
                (array) ($this->request->getJsonVar('exclude_statuses', true) ?? []),
                (string) ($this->request->getJsonVar('send_time') ?: '10:30'),
                (string) ($this->request->getJsonVar('timezone') ?: 'Asia/Kolkata'),
                CurrentUser::id() ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            return $this->fail(['sequence' => $e->getMessage()], 422);
        }

        return $this->respondCreated(['success' => true, 'data' => $created]);
    }

    // GET /email-marketing/campaigns/:id/recipients?filter=&page=&search=
    public function recipients($id = null): ResponseInterface
    {
        if (! $this->campaignModel()->find((int) $id)) {
            return $this->failNotFound('Campaign not found.');
        }
        $data = (new EmailCampaignReport())->recipients(
            CurrentUser::tenantId(),
            (int) $id,
            (string) ($this->request->getGet('filter') ?? 'all'),
            (int) ($this->request->getGet('page') ?? 1),
            (int) ($this->request->getGet('per_page') ?? 50),
            (string) ($this->request->getGet('search') ?? ''),
        );

        return $this->respond(['success' => true, 'data' => $data]);
    }

    // GET /email-marketing/campaigns/:id/recipients/export?filter=&search= — CSV
    public function exportRecipients($id = null): ResponseInterface
    {
        $row = $this->campaignModel()->find((int) $id);
        if (! $row) {
            return $this->failNotFound('Campaign not found.');
        }
        $filter = (string) ($this->request->getGet('filter') ?? 'all');
        $csv    = (new EmailCampaignReport())->recipientsCsv(
            CurrentUser::tenantId(), (int) $id, $filter, (string) ($this->request->getGet('search') ?? ''),
        );
        $slug = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $row['name'])), '-') ?: 'campaign';

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', "attachment; filename=\"email-{$slug}-{$filter}.csv\"")
            ->setBody("\xEF\xBB\xBF" . $csv); // BOM so Excel reads UTF-8 names correctly
    }

    // POST /email-marketing/test  { to, subject, html_body, preheader?, from_name?, reply_to? }
    public function testSend(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $to       = trim((string) $this->request->getJsonVar('to'));
        $subject  = trim((string) $this->request->getJsonVar('subject'));
        $html     = (string) $this->request->getJsonVar('html_body');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->fail(['to' => 'Enter a valid email address to send the test to.'], 422);
        }
        if ($subject === '' || trim($html) === '') {
            return $this->fail('Subject and body are required.', 422);
        }
        if ($err = $this->readyError($tenantId)) {
            return $this->fail($err, 422);
        }

        // Personalise with a real contact so the test shows real merge values.
        $sample = (new ContactModel())->setTenant($tenantId)->where('email !=', '')->where('name !=', '')->first()
            ?? ['id' => 0, 'name' => 'Alex Sample', 'email' => $to];
        $sample = (array) $sample;

        $composer = new EmailComposer();
        $settings = $composer->settings($tenantId);
        $mail     = $composer->compose($sample, $subject, $html, (string) $this->request->getJsonVar('preheader'), null, $settings['footer_text']);

        [$ok, $error] = EmailComposer::defaultTransport()->send(
            $settings['smtp'],
            $to,
            '[Test] ' . $mail['subject'],
            $mail['html'],
            $mail['text'],
            [],
            trim((string) $this->request->getJsonVar('reply_to')) ?: null,
            trim((string) $this->request->getJsonVar('from_name')) ?: null,
        );

        if (! $ok) {
            return $this->fail(['smtp' => 'Test email failed: ' . $error], 422);
        }

        return $this->respond(['success' => true, 'message' => "Test sent to {$to} (merge tags filled from " . ($sample['name'] ?? 'a sample contact') . ').']);
    }

    // POST /email-marketing/preview  { html_body, subject, preheader }  → rendered HTML for one sample contact
    public function preview(): ResponseInterface
    {
        $tenantId  = CurrentUser::tenantId();
        $contactId = (int) ($this->request->getJsonVar('contact_id') ?? 0);
        $model     = (new ContactModel())->setTenant($tenantId);
        $sample    = $contactId > 0 ? $model->find($contactId) : $model->where('email !=', '')->where('name !=', '')->first();
        $sample    = $sample ? (array) $sample : ['id' => 0, 'name' => 'Alex Sample', 'email' => 'alex@example.com'];

        $composer = new EmailComposer();
        $mail     = $composer->compose(
            $sample,
            (string) $this->request->getJsonVar('subject'),
            (string) $this->request->getJsonVar('html_body'),
            (string) $this->request->getJsonVar('preheader'),
            null,
            $composer->settings($tenantId)['footer_text'],
        );

        return $this->respond(['success' => true, 'data' => [
            'subject' => $mail['subject'],
            'html'    => $mail['html'],
            'contact' => ['id' => (int) ($sample['id'] ?? 0), 'name' => $sample['name'] ?? '', 'email' => $sample['email'] ?? ''],
            'tokens'  => (new EmailPersonalizer())->tokens((string) $this->request->getJsonVar('html_body') . ' ' . (string) $this->request->getJsonVar('subject')),
        ]]);
    }

    // ── Suppressions ───────────────────────────────────────────────────

    public function suppressions(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $search   = trim((string) ($this->request->getGet('search') ?? ''));
        $page     = max(1, (int) ($this->request->getGet('page') ?? 1));
        $perPage  = 50;

        $build = function () use ($tenantId, $search) {
            $b = db_connect()->table('email_suppressions s')
                ->join('contacts c', 'c.id = s.contact_id', 'left')
                ->where('s.tenant_id', $tenantId);
            if ($search !== '') {
                $b->like('s.email', $search);
            }

            return $b;
        };

        $total = $build()->countAllResults();
        $rows  = $build()->select('s.id, s.email, s.reason, s.contact_id, c.name AS contact_name, s.created_at')
            ->orderBy('s.id', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return $this->respond(['success' => true, 'data' => ['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]]);
    }

    // POST /email-marketing/suppressions  { emails: "a@x.com, b@y.com\n…", reason? }
    public function addSuppressions(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $raw      = (string) ($this->request->getJsonVar('emails') ?? $this->request->getJsonVar('email') ?? '');
        $reason   = (string) ($this->request->getJsonVar('reason') ?? 'manual');
        $svc      = new SuppressionService();

        $added = 0;
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $addr) {
            $addr = SuppressionService::normalize($addr);
            if (! filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $invalid[] = $addr;
                continue;
            }
            $contact = (new ContactModel())->setTenant($tenantId)->where('email', $addr)->first();
            $svc->suppress($tenantId, $addr, $reason, $contact ? (int) ((array) $contact)['id'] : null);
            $added++;
        }

        if ($added === 0) {
            return $this->fail(['emails' => 'No valid email addresses found.'], 422);
        }

        return $this->respond(['success' => true, 'data' => ['added' => $added, 'invalid' => array_slice($invalid, 0, 20)]]);
    }

    public function removeSuppression($id = null): ResponseInterface
    {
        if (! (new SuppressionService())->remove(CurrentUser::tenantId(), (int) $id)) {
            return $this->failNotFound('Suppression not found.');
        }

        return $this->respondDeleted(['success' => true]);
    }

    // ── Internals ──────────────────────────────────────────────────────

    private function templateModel(): EmailTemplateModel
    {
        return (new EmailTemplateModel())->setTenant(CurrentUser::tenantId());
    }

    private function campaignModel(): EmailCampaignModel
    {
        return (new EmailCampaignModel())->setTenant(CurrentUser::tenantId());
    }

    private function readyError(int $tenantId): ?string
    {
        $s = (new EmailComposer())->settings($tenantId);

        return $s['ready'] ? null : $s['reason'];
    }

    private function transition(int $id, array $from, array $update): ResponseInterface
    {
        $row = $this->campaignModel()->find($id);
        if (! $row) {
            return $this->failNotFound('Campaign not found.');
        }
        if (! in_array($row['status'], $from, true)) {
            return $this->fail("Not possible while the campaign is {$row['status']}.", 422);
        }
        $this->campaignModel()->update($id, $update);

        return $this->respond(['success' => true, 'data' => $this->campaignModel()->find($id)]);
    }

    /** @return array|string data or an error message */
    private function templateInput(): array|string
    {
        $name    = trim((string) $this->request->getJsonVar('name'));
        $subject = trim((string) $this->request->getJsonVar('subject'));
        $html    = (string) $this->request->getJsonVar('html_body');

        if ($name === '' || $subject === '' || trim($html) === '') {
            return 'Name, subject and body are required.';
        }
        if (strlen($html) > self::MAX_HTML) {
            return 'The email body is too large (max 500 KB).';
        }

        return [
            'name'      => mb_substr($name, 0, 150),
            'subject'   => mb_substr($subject, 0, 255),
            'preheader' => mb_substr(trim((string) $this->request->getJsonVar('preheader')), 0, 255) ?: null,
            'html_body' => $html,
        ];
    }

    /** @return array|string */
    private function campaignInput(?array $existing): array|string
    {
        $tenantId = CurrentUser::tenantId();
        $get      = fn (string $k, $default = null) => $this->request->getJsonVar($k) ?? ($existing[$k] ?? $default);

        $templateId = (int) ($this->request->getJsonVar('email_template_id') ?? 0);
        $template   = $templateId > 0 ? $this->templateModel()->find($templateId) : null;
        if ($templateId > 0 && ! $template) {
            return 'Template not found.';
        }

        $name    = trim((string) $get('name', ''));
        $subject = trim((string) ($this->request->getJsonVar('subject') ?? $template['subject'] ?? $existing['subject'] ?? ''));
        $html    = (string) ($this->request->getJsonVar('html_body') ?? $template['html_body'] ?? $existing['html_body'] ?? '');
        $replyTo = trim((string) $get('reply_to', ''));

        if ($name === '') {
            return 'Campaign name is required.';
        }
        if ($subject === '' || trim($html) === '') {
            return 'Pick a template or write a subject and body.';
        }
        if (strlen($html) > self::MAX_HTML) {
            return 'The email body is too large (max 500 KB).';
        }
        if ($replyTo !== '' && ! filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            return 'Reply-to must be a valid email address.';
        }

        $segmentIn = $this->request->getJsonVar('segment', true);
        $segment   = $segmentIn !== null
            ? $this->segmentInput($segmentIn)
            : (json_decode((string) ($existing['segment'] ?? ''), true) ?: ['all' => true]);

        return [
            'tenant_id'         => $tenantId,
            'email_template_id' => $templateId ?: ($existing['email_template_id'] ?? null),
            'name'              => mb_substr($name, 0, 150),
            'subject'           => mb_substr($subject, 0, 255),
            'preheader'         => mb_substr(trim((string) ($this->request->getJsonVar('preheader') ?? $template['preheader'] ?? $existing['preheader'] ?? '')), 0, 255) ?: null,
            'from_name'         => mb_substr(trim((string) $get('from_name', '')), 0, 150) ?: null,
            'reply_to'          => $replyTo ?: null,
            'html_body'         => $html,
            'segment'           => json_encode($segment),
        ];
    }

    private function segmentInput(mixed $segment): array
    {
        if (is_object($segment)) {
            $segment = json_decode((string) json_encode($segment), true);
        }

        return is_array($segment) && $segment !== [] ? $segment : ['all' => true];
    }
}
