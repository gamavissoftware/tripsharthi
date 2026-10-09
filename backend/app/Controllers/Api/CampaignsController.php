<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Models\CampaignModel;
use App\Models\TemplateModel;
use App\Services\Auth\CurrentUser;
use App\Services\Flow\JobDispatcher;
use App\Services\Leads\CampaignRetargeter;
use App\Services\Leads\ScheduledCampaignDispatcher;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * Campaign management + chunked send.
 *
 * GET    /api/v1/campaigns              — list
 * POST   /api/v1/campaigns              — create (draft)
 * GET    /api/v1/campaigns/:id          — show + stats
 * PUT    /api/v1/campaigns/:id          — update (draft only)
 * DELETE /api/v1/campaigns/:id          — soft-delete
 * POST   /api/v1/campaigns/:id/send     — start OR continue batch send
 */
class CampaignsController extends ResourceController
{
    protected $format = 'json';

    private function model(): CampaignModel
    {
        return (new CampaignModel())->setTenant(CurrentUser::tenantId());
    }

    // GET /api/v1/campaigns
    public function index(): ResponseInterface
    {
        $campaigns = $this->model()->orderBy('created_at', 'DESC')->findAll(50);

        // Decode stats (show/ does the same — the list must not hand the client a
        // raw JSON string) and attach real delivery counts derived from the
        // messages table so the UI never has to guess at delivered/read.
        $delivery = (new \App\Models\MessageModel())->deliveryCountsByCampaign(
            CurrentUser::tenantId(),
            array_map(static fn ($c) => (int) $c['id'], $campaigns)
        );

        foreach ($campaigns as &$c) {
            $c['stats'] = json_decode($c['stats'] ?? '{}', true) ?: CampaignModel::emptyStats();
            $counts     = $delivery[(int) $c['id']] ?? null;

            // Campaigns sent before messages.campaign_id existed have no message
            // rows to count — fall back to the campaign's own progress columns.
            $c['sent_count']      = $counts !== null ? $counts['sent']      : (int) ($c['sent_count'] ?? 0);
            $c['delivered_count'] = $counts !== null ? $counts['delivered'] : 0;
            $c['read_count']      = $counts !== null ? $counts['read']      : 0;
            $c['failed_count']    = $counts !== null ? $counts['failed']    : (int) ($c['failed_count'] ?? 0);
        }
        unset($c);

        return $this->respond(['success' => true, 'data' => $campaigns]);
    }


    // GET /api/v1/campaigns/:id
    public function show($id = null): ResponseInterface
    {
        $c = $this->model()->find((int) $id);
        if (! $c) return $this->failNotFound("Campaign #{$id} not found.");
        $c['stats'] = json_decode($c['stats'] ?? '{}', true) ?: CampaignModel::emptyStats();
        return $this->respond(['success' => true, 'data' => $c]);
    }

    // POST /api/v1/campaigns
    public function create(): ResponseInterface
    {
        $rules = [
            'name'        => 'required|max_length[255]',
            'template_id' => 'required|is_natural_no_zero',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $tenantId   = CurrentUser::tenantId();
        $templateId = (int) $this->request->getJsonVar('template_id');

        // Validate the template is approved
        $template = (new TemplateModel())->setTenant($tenantId)->find($templateId);
        if (! $template) {
            return $this->fail(['template_id' => 'Template not found.'], 422);
        }
        if ($template['meta_status'] !== 'approved') {
            return $this->fail(['template_id' => "Template is not approved (status: {$template['meta_status']})."], 422);
        }

        $segment  = $this->request->getJsonVar('segment');
        $varMap   = $this->request->getJsonVar('variable_mapping');
        $varDefs  = $this->request->getJsonVar('variable_defaults');

        // Optional A/B variant
        [$variantId, $abSplit] = $this->resolveVariant($tenantId);

        $id = $this->model()->insert([
            'name'                => $this->request->getJsonVar('name'),
            'template_id'         => $templateId,
            'variant_template_id' => $variantId,
            'ab_split'            => $abSplit,
            'segment'             => $segment ? json_encode($segment) : json_encode(['all' => true]),
            'variable_mapping'    => $varMap  ? json_encode($varMap)  : null,
            'variable_defaults'   => $varDefs ? json_encode($varDefs) : null,
        ], true);

        return $this->respondCreated(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    // PUT /api/v1/campaigns/:id
    public function update($id = null): ResponseInterface
    {
        $c = $this->model()->find((int) $id);
        if (! $c) return $this->failNotFound("Campaign #{$id} not found.");

        if ($c['status'] !== 'draft') {
            return $this->fail("Only draft campaigns can be edited (current: {$c['status']}).", 422);
        }

        $segment = $this->request->getJsonVar('segment');
        $varMap  = $this->request->getJsonVar('variable_mapping');
        $varDefs = $this->request->getJsonVar('variable_defaults');

        $payload = array_filter([
            'name'              => $this->request->getJsonVar('name'),
            'segment'           => $segment ? json_encode($segment) : null,
            'variable_mapping'  => $varMap  ? json_encode($varMap)  : null,
            'variable_defaults' => $varDefs ? json_encode($varDefs) : null,
        ], static fn ($v) => $v !== null);

        // A/B variant can be (re)set on a draft.
        if ($this->request->getJsonVar('variant_template_id') !== null) {
            [$variantId, $abSplit]          = $this->resolveVariant(CurrentUser::tenantId());
            $payload['variant_template_id'] = $variantId;
            $payload['ab_split']            = $abSplit;
        }

        $this->model()->update((int) $id, $payload);
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    // DELETE /api/v1/campaigns/:id
    public function delete($id = null): ResponseInterface
    {
        $c = $this->model()->find((int) $id);
        if (! $c) return $this->failNotFound("Campaign #{$id} not found.");
        $this->model()->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    // POST /api/v1/campaigns/:id/send
    // Phase 5: dispatches a campaign_send job instead of calling processBatch() directly.
    // Poll GET /api/v1/campaigns/{id} for progress (sent_count, failed_count, stats, status).
    public function send($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();

        $campaign = $this->model()->find((int) $id);
        if (! $campaign) return $this->failNotFound("Campaign #{$id} not found.");

        if (! in_array($campaign['status'], ['draft', 'scheduled', 'processing', 'paused'], true)) {
            return $this->fail("Campaign cannot be sent in status '{$campaign['status']}'.", 422);
        }

        $template = (new TemplateModel())->setTenant($tenantId)->find((int) $campaign['template_id']);
        if (! $template || ($template['meta_status'] ?? '') !== 'approved') {
            return $this->fail('Template is not approved.', 422);
        }

        // Block sends that would hit Meta error #132000 (param count mismatch).
        if ($err = $this->templateParamError($campaign, $template)) {
            return $this->fail($err, 422);
        }

        // "Send now" on a scheduled campaign cancels the schedule and sends immediately.
        if ($campaign['status'] === 'scheduled') {
            $this->model()->update((int) $id, ['status' => 'draft', 'scheduled_at' => null]);
        }

        JobDispatcher::dispatch(
            tenantId:   $tenantId,
            type:       'campaign_send',
            payload:    ['campaign_id' => (int) $id],
            sourceType: 'campaigns',
            sourceId:   (int) $id,
        );

        return $this->respond([
            'success' => true,
            'status'  => 'queued',
            'message' => 'Campaign send queued. Poll GET /api/v1/campaigns/{id} for progress.',
        ]);
    }

    // POST /api/v1/campaigns/:id/schedule
    // Body: { "scheduled_at": "2026-06-10 10:00", "timezone": "Asia/Kolkata" }
    // scheduled_at is wall-clock time in the given timezone; stored as UTC.
    public function schedule($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();

        $campaign = $this->model()->find((int) $id);
        if (! $campaign) return $this->failNotFound("Campaign #{$id} not found.");

        if (! in_array($campaign['status'], ['draft', 'scheduled'], true)) {
            return $this->fail("Only draft campaigns can be scheduled (current: {$campaign['status']}).", 422);
        }

        $template = (new TemplateModel())->setTenant($tenantId)->find((int) $campaign['template_id']);
        if (! $template || ($template['meta_status'] ?? '') !== 'approved') {
            return $this->fail('Template is not approved.', 422);
        }
        if ($err = $this->templateParamError($campaign, $template)) {
            return $this->fail($err, 422);
        }

        $rules = [
            'scheduled_at' => 'required|string',
            'timezone'     => 'required|string',
        ];
        if (! $this->validate($rules)) {
            return $this->fail($this->validator->getErrors(), 422);
        }

        $localAt  = (string) $this->request->getJsonVar('scheduled_at');
        $timezone = (string) $this->request->getJsonVar('timezone');

        try {
            $utcAt = ScheduledCampaignDispatcher::toUtc($localAt, $timezone);
        } catch (\InvalidArgumentException $e) {
            return $this->fail(['scheduled_at' => $e->getMessage()], 422);
        }

        // Must be in the future (compare in UTC).
        if (strtotime($utcAt . ' UTC') <= time()) {
            return $this->fail(['scheduled_at' => 'Scheduled time must be in the future.'], 422);
        }

        $this->model()->update((int) $id, [
            'status'            => 'scheduled',
            'scheduled_at'      => $utcAt,
            'schedule_timezone' => $timezone,
        ]);

        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    /**
     * Guard against Meta error #132000 (parameter count mismatch): a template
     * with N body variables must have all N mapped in the campaign. The engine
     * emits one body param per variable_mapping entry, so the mapping must
     * cover indices 1..N (values may be blank — defaults/fallback fill them).
     *
     * @return array|null  Error body for a 422, or null when OK.
     */
    private function templateParamError(array $campaign, array $template): ?array
    {
        $vars = json_decode($template['variables'] ?? '[]', true);
        $n    = is_array($vars) ? count($vars) : 0;
        if ($n === 0) {
            return null; // template has no variables
        }

        $map = json_decode($campaign['variable_mapping'] ?? '{}', true) ?: [];
        for ($i = 1; $i <= $n; $i++) {
            if (! array_key_exists((string) $i, $map)) {
                return [
                    'variable_mapping' => "This template needs {$n} variable(s); the campaign maps " . count($map)
                        . ". Map all {$n} (with defaults for any that aren't contact fields) before sending.",
                    'template_variables' => $vars,
                    'required'           => $n,
                    'mapped'             => count($map),
                ];
            }
        }
        return null;
    }

    /**
     * Resolve the optional A/B variant from the request.
     * Returns [variant_template_id|null, ab_split]. Invalid/unapproved → [null, 0].
     */
    private function resolveVariant(int $tenantId): array
    {
        $vid   = (int) ($this->request->getJsonVar('variant_template_id') ?? 0);
        $split = max(0, min(100, (int) ($this->request->getJsonVar('ab_split') ?? 0)));
        if ($vid <= 0 || $split <= 0) {
            return [null, 0];
        }
        $t = (new TemplateModel())->setTenant($tenantId)->find($vid);
        if (! $t || ($t['meta_status'] ?? '') !== 'approved') {
            return [null, 0];
        }
        return [$vid, $split];
    }

    // GET /api/v1/campaigns/:id/retarget-preview — recipient counts per outcome.
    public function retargetPreview($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();

        $campaign = $this->model()->find((int) $id);
        if (! $campaign) return $this->failNotFound("Campaign #{$id} not found.");

        $retargeter = new CampaignRetargeter();
        $counts     = [];
        foreach (CampaignRetargeter::OUTCOMES as $outcome) {
            try {
                $counts[$outcome] = count($retargeter->resolveContactIds($tenantId, (int) $id, $outcome));
            } catch (\Throwable $e) {
                // e.g. 'clicked' before the click_events table exists — report null, not 500.
                $counts[$outcome] = null;
            }
        }

        return $this->respond(['success' => true, 'data' => $counts]);
    }

    // POST /api/v1/campaigns/:id/retarget
    // Body: { "outcome": "not_read", "template_id"?: int, "name"?: string }
    // Creates a NEW draft campaign frozen to the matching recipients.
    public function retarget($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();

        $campaign = $this->model()->find((int) $id);
        if (! $campaign) return $this->failNotFound("Campaign #{$id} not found.");

        $outcome = (string) $this->request->getJsonVar('outcome');
        if (! in_array($outcome, CampaignRetargeter::OUTCOMES, true)) {
            return $this->fail(['outcome' => 'Invalid outcome. Use one of: ' . implode(', ', CampaignRetargeter::OUTCOMES)], 422);
        }

        // Resolve which template the retarget will use, and confirm it's approved.
        $templateId = $this->request->getJsonVar('template_id')
            ? (int) $this->request->getJsonVar('template_id')
            : (int) $campaign['template_id'];

        $template = (new TemplateModel())->setTenant($tenantId)->find($templateId);
        if (! $template) {
            return $this->fail(['template_id' => 'Template not found.'], 422);
        }
        if (($template['meta_status'] ?? '') !== 'approved') {
            return $this->fail(['template_id' => "Template is not approved (status: {$template['meta_status']})."], 422);
        }

        $retargeter = new CampaignRetargeter();
        try {
            $contactIds = $retargeter->resolveContactIds($tenantId, (int) $id, $outcome);
        } catch (\Throwable $e) {
            return $this->fail(['outcome' => 'This outcome is not available yet.'], 422);
        }

        if ($contactIds === []) {
            return $this->fail(['outcome' => 'No recipients match this outcome — nothing to retarget.'], 422);
        }

        $newId = $retargeter->createRetargetCampaign(
            tenantId:         $tenantId,
            sourceCampaignId: (int) $id,
            contactIds:       $contactIds,
            templateId:       $templateId,
            name:             $this->request->getJsonVar('name') ?: null,
        );

        return $this->respondCreated([
            'success'         => true,
            'data'            => $this->model()->find($newId),
            'recipient_count' => count($contactIds),
        ]);
    }

    // POST /api/v1/campaigns/:id/unschedule — revert a scheduled campaign to draft.
    public function unschedule($id = null): ResponseInterface
    {
        $campaign = $this->model()->find((int) $id);
        if (! $campaign) return $this->failNotFound("Campaign #{$id} not found.");

        if ($campaign['status'] !== 'scheduled') {
            return $this->fail("Campaign is not scheduled (current: {$campaign['status']}).", 422);
        }

        $this->model()->update((int) $id, [
            'status'            => 'draft',
            'scheduled_at'      => null,
            'schedule_timezone' => null,
        ]);

        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }
}
