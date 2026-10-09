<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Libraries\OptionalId;

use App\Models\AccountModel;
use App\Models\ActivityModel;
use App\Models\ContactModel;
use App\Models\DealLineItemModel;
use App\Models\DealModel;
use App\Models\PipelineModel;
use App\Models\PipelineStageModel;
use App\Services\Auth\CurrentUser;
use App\Services\Flow\FlowTriggerService;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;
use App\Controllers\Api\Concerns\EnforcesEditAccess;

/**
 * Deals (opportunities) + the kanban board — CRM Phase B.
 * Stage moves drive status (won/lost) and log to the deal timeline.
 */
class DealsController extends ResourceController
{
    use EnforcesEditAccess;

    protected $format = 'json';

    private function model(): DealModel
    {
        return (new DealModel())->setTenant(CurrentUser::tenantId());
    }

    /**
     * GET /api/v1/deals/board?pipeline_id=
     * Returns the pipeline, its stages, and open deals grouped per stage with
     * per-column count + value totals — everything the kanban needs.
     */
    /**
     * Attach a compact lead summary to each board deal so the kanban cards can be
     * rich (linked contact name/phone/score/priority, company, owner, overdue
     * follow-up) without an N+1 — everything is batch-loaded.
     */
    private function enrichForBoard(int $tenantId, array $deals): array
    {
        if ($deals === []) {
            return $deals;
        }
        $db         = db_connect();
        $contactIds = array_values(array_unique(array_filter(array_map(static fn ($d) => (int) ($d['primary_contact_id'] ?? 0), $deals))));
        $ownerIds   = array_values(array_unique(array_filter(array_map(static fn ($d) => (int) ($d['owner_id'] ?? 0), $deals))));
        $dealIds    = array_map(static fn ($d) => (int) $d['id'], $deals);

        $contacts = [];
        if ($contactIds !== []) {
            foreach ($db->table('contacts')->select('id,name,wa_number,lead_score,score_tier,priority,account_id')
                ->where('tenant_id', $tenantId)->whereIn('id', $contactIds)->where('deleted_at', null)->get()->getResultArray() as $c) {
                $contacts[(int) $c['id']] = $c;
            }
        }
        $acctIds = array_values(array_unique(array_filter(array_merge(
            array_map(static fn ($d) => (int) ($d['account_id'] ?? 0), $deals),
            array_map(static fn ($c) => (int) ($c['account_id'] ?? 0), $contacts),
        ))));
        $accounts = [];
        if ($acctIds !== []) {
            foreach ($db->table('accounts')->select('id,name')->where('tenant_id', $tenantId)->whereIn('id', $acctIds)->where('deleted_at', null)->get()->getResultArray() as $a) {
                $accounts[(int) $a['id']] = $a['name'];
            }
        }
        $owners = [];
        if ($ownerIds !== []) {
            foreach ($db->table('users')->select('id,name')->where('tenant_id', $tenantId)->whereIn('id', $ownerIds)->get()->getResultArray() as $u) {
                $owners[(int) $u['id']] = $u['name'];
            }
        }
        // Earliest overdue, still-open follow-up task per deal.
        $overdue = [];
        foreach ($db->table('tasks')->select('related_id, MIN(due_at) as due')
            ->where('tenant_id', $tenantId)->where('related_type', 'deal')->whereIn('related_id', $dealIds)
            ->whereNotIn('status', ['done', 'completed', 'cancelled'])->where('deleted_at', null)
            ->where('due_at IS NOT NULL', null, false)->where('due_at <', date('Y-m-d H:i:s'))
            ->groupBy('related_id')->get()->getResultArray() as $t) {
            $overdue[(int) $t['related_id']] = $t['due'];
        }

        foreach ($deals as &$d) {
            $c    = $contacts[(int) ($d['primary_contact_id'] ?? 0)] ?? null;
            $acct = (int) ($d['account_id'] ?? 0) ?: (int) ($c['account_id'] ?? 0);
            $d['contact_name'] = $c['name'] ?? null;
            $d['contact_phone'] = $c['wa_number'] ?? null;
            $d['lead_score']   = $c['lead_score'] ?? null;
            $d['score_tier']   = $c['score_tier'] ?? null;
            $d['priority']     = $c['priority'] ?? null;
            $d['company']      = $accounts[$acct] ?? null;
            $d['owner_name']   = $owners[(int) ($d['owner_id'] ?? 0)] ?? null;
            $d['overdue_at']   = $overdue[(int) $d['id']] ?? null;
        }
        return $deals;
    }

    public function board(): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $pm       = new PipelineModel();

        $pipelineId = (int) $this->request->getGet('pipeline_id');
        $pipeline   = $pipelineId > 0 ? $pm->setTenant($tenantId)->find($pipelineId) : $pm->ensureDefault($tenantId);
        if (! $pipeline) {
            return $this->failNotFound('Pipeline not found.');
        }
        $pipelineId = (int) $pipeline['id'];

        $stages = (new PipelineStageModel())->forPipeline($tenantId, $pipelineId);
        $deals  = $this->enrichForBoard($tenantId, $this->model()->forPipeline($tenantId, $pipelineId));

        // Flag rotting deals (idle past their stage's rotting_days) for the board.
        $rotting = array_flip((new \App\Services\Crm\RottingService())->rottingDealIds($tenantId));

        // Group deals by stage_id.
        $byStage = [];
        foreach ($deals as $d) {
            $d['is_rotting'] = isset($rotting[(int) $d['id']]);
            $byStage[(int) $d['stage_id']][] = $d;
        }

        $columns = array_map(static function (array $s) use (&$byStage): array {
            $list = $byStage[(int) $s['id']] ?? [];
            return [
                'stage'       => $s,
                'deals'       => $list,
                'count'       => count($list),
                'total_value' => array_sum(array_map(static fn ($d) => (int) $d['value_amount'], $list)),
            ];
        }, $stages);

        return $this->respond(['success' => true, 'data' => ['pipeline' => $pipeline, 'columns' => $columns]]);
    }

    public function show($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $deal     = $this->model()->find((int) $id);
        if (! $deal) {
            return $this->failNotFound("Deal #{$id} not found.");
        }

        $deal['stage']     = (new PipelineStageModel())->setTenant($tenantId)->find((int) $deal['stage_id']);
        $deal['pipeline']  = (new PipelineModel())->setTenant($tenantId)->find((int) $deal['pipeline_id']);
        $deal['account']   = $deal['account_id'] ? (new AccountModel())->setTenant($tenantId)->find((int) $deal['account_id']) : null;
        $deal['contact']   = $deal['primary_contact_id'] ? (new ContactModel())->setTenant($tenantId)->find((int) $deal['primary_contact_id']) : null;
        $deal['line_items'] = (new DealLineItemModel())->forDeal($tenantId, (int) $id);

        return $this->respond(['success' => true, 'data' => $deal]);
    }

    public function create(): ResponseInterface
    {
        if (! $this->validate(['title' => 'required|max_length[255]'])) {
            return $this->fail($this->validator->getErrors(), 422);
        }
        $tenantId = CurrentUser::tenantId();

        // Phase K2: gate on the plan's deal limit (SaaS only; self-hosted unlimited).
        if (\App\Services\Tenancy\FeatureGate::isSaas()) {
            $chk = (new \App\Services\Billing\PlanLimitChecker())->check($tenantId, 'deals');
            if (! $chk['allowed']) {
                return $this->fail((new \App\Services\Billing\PlanLimitChecker())->limitExceededResponse('deals', $chk['current'], $chk['limit']), 422);
            }
        }

        $pm         = new PipelineModel();
        $pipeline   = $pm->ensureDefault($tenantId);
        $pipelineId = (int) ($this->request->getJsonVar('pipeline_id') ?: $pipeline['id']);
        $stages     = (new PipelineStageModel())->forPipeline($tenantId, $pipelineId);
        if (empty($stages)) {
            return $this->fail('Pipeline has no stages.', 422);
        }
        $stageId = (int) ($this->request->getJsonVar('stage_id') ?: $stages[0]['id']);

        // Auto-assign when no explicit owner is given (Phase H2), else current user.
        $base          = $this->payload();
        // See TicketsController: '' from an "Unassigned" select is not null.
        $explicitOwner = OptionalId::from($this->request->getJsonVar('owner_id'));
        $autoOwner     = $explicitOwner === null
            ? (new \App\Services\Crm\AssignmentService())->assignee('deal', $tenantId, array_merge($base, ['status' => 'open']))
            : null;

        $payload = array_merge($base, [
            'pipeline_id'      => $pipelineId,
            'stage_id'         => $stageId,
            'owner_id'         => $explicitOwner ?? $autoOwner ?? CurrentUser::id(),
            'currency'         => $base['currency'] ?? \App\Services\Crm\CurrencyService::tenantDefault($tenantId),
            'status'           => 'open',
            'last_activity_at' => date('Y-m-d H:i:s'),
        ]);

        $model = $this->model();
        $id    = $model->insert($payload, true);
        if (! $id) {
            return $this->fail($model->errors() ?: 'Could not create deal.', 422);
        }

        (new ActivityModel())->log($tenantId, 'system', 'deal', (int) $id, [
            'subject'       => 'Deal created',
            'actor_user_id' => CurrentUser::id(),
        ]);
        if ($autoOwner !== null) {
            (new ActivityModel())->log($tenantId, 'system', 'deal', (int) $id, ['subject' => "Auto-assigned to user #{$autoOwner}"]);
        }

        $created = $model->find((int) $id);
        if ($created['primary_contact_id'] ?? null) {
            \App\Services\Crm\LeadScoringService::recalcQuietly((int) $created['primary_contact_id'], $tenantId);
        }
        // Fire the deal_created flow trigger against the deal's primary contact.
        FlowTriggerService::fire('deal_created', $tenantId, (int) ($created['primary_contact_id'] ?? 0), [
            'deal_id'      => (int) $id,
            'stage_id'     => $stageId,
            'value_amount' => (int) ($created['value_amount'] ?? 0),
        ]);

        return $this->respondCreated(['success' => true, 'data' => $created]);
    }

    public function update($id = null): ResponseInterface
    {
        $model = $this->model();
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Deal #{$id} not found.");
        }
        if ($deny = $this->denyIfReadOnly($model, (int) $id)) return $deny;
        $model->update((int) $id, array_merge($this->payload(), ['last_activity_at' => date('Y-m-d H:i:s')]));
        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    /** POST /api/v1/deals/:id/move  { stage_id, lost_reason? } */
    public function move($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $deal     = $this->model()->find((int) $id);
        if (! $deal) {
            return $this->failNotFound("Deal #{$id} not found.");
        }
        if ($deny = $this->denyIfReadOnly($this->model(), (int) $id)) return $deny;

        $newStageId = (int) $this->request->getJsonVar('stage_id');
        $newStage   = (new PipelineStageModel())->setTenant($tenantId)->find($newStageId);
        if (! $newStage || (int) $newStage['pipeline_id'] !== (int) $deal['pipeline_id']) {
            return $this->fail('Invalid stage for this deal\'s pipeline.', 422);
        }

        $fromStage = (new PipelineStageModel())->setTenant($tenantId)->find((int) $deal['stage_id']);
        $now       = date('Y-m-d H:i:s');

        $update = ['stage_id' => $newStageId, 'last_activity_at' => $now];
        if ((int) $newStage['is_won'] === 1) {
            $update['status'] = 'won';
            $update['won_at'] = $now;
            // Recurring deal won → schedule its first renewal one interval out.
            if ((int) ($deal['is_recurring'] ?? 0) === 1) {
                $update['next_renewal_at'] = \App\Models\DealModel::advanceRenewal(date('Y-m-d'), $deal['recurring_interval'] ?? null);
            }
        } elseif ((int) $newStage['is_lost'] === 1) {
            $update['status']      = 'lost';
            $update['lost_reason'] = $this->request->getJsonVar('lost_reason') ?: $deal['lost_reason'];
        } else {
            $update['status'] = 'open';
            $update['won_at'] = null;
        }

        $this->model()->update((int) $id, $update);
        \App\Services\Crm\AuditLogger::log('status', 'deal', (int) $id, ['stage_id' => (int) ($deal['stage_id'] ?? 0)], ['stage_id' => $newStageId, 'status' => $update['status'] ?? $deal['status']]);

        // Timeline: stage change + a won/lost marker when terminal.
        $am = new ActivityModel();
        $am->log($tenantId, 'stage_change', 'deal', (int) $id, [
            'subject'       => "Stage: {$fromStage['name']} → {$newStage['name']}",
            'actor_user_id' => CurrentUser::id(),
            'meta'          => ['from_stage' => $fromStage['name'] ?? null, 'to_stage' => $newStage['name']],
        ]);
        if (($update['status'] ?? '') === 'won') {
            $am->log($tenantId, 'deal_won', 'deal', (int) $id, ['subject' => 'Deal won 🏆', 'actor_user_id' => CurrentUser::id()]);
        } elseif (($update['status'] ?? '') === 'lost') {
            $am->log($tenantId, 'deal_lost', 'deal', (int) $id, ['subject' => 'Deal lost', 'body' => $update['lost_reason'] ?? null, 'actor_user_id' => CurrentUser::id()]);
        }

        // Fire CRM flow triggers against the deal's primary contact (no-op if none).
        $cid    = (int) ($deal['primary_contact_id'] ?? 0);
        if ($cid > 0) {
            \App\Services\Crm\LeadScoringService::recalcQuietly($cid, $tenantId);
        }
        $trigCtx = ['deal_id' => (int) $id, 'stage_id' => $newStageId, 'stage_name' => $newStage['name'], 'value_amount' => (int) $deal['value_amount']];
        FlowTriggerService::fire('deal_stage_changed', $tenantId, $cid, $trigCtx);
        if (($update['status'] ?? '') === 'won') {
            FlowTriggerService::fire('deal_won', $tenantId, $cid, $trigCtx);
        } elseif (($update['status'] ?? '') === 'lost') {
            FlowTriggerService::fire('deal_lost', $tenantId, $cid, $trigCtx);
        }

        return $this->respond(['success' => true, 'data' => $this->model()->find((int) $id)]);
    }

    public function delete($id = null): ResponseInterface
    {
        $model = $this->model();
        if (! $model->find((int) $id)) {
            return $this->failNotFound("Deal #{$id} not found.");
        }
        if ($deny = $this->denyIfReadOnly($model, (int) $id)) return $deny;
        $model->delete((int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    // ── Line items ──────────────────────────────────────────────────────

    /** POST /api/v1/deals/:id/line-items */
    public function addLineItem($id = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $deal     = $this->model()->find((int) $id);
        if (! $deal) {
            return $this->failNotFound("Deal #{$id} not found.");
        }
        // A product (Phase J2) fills name/price; explicit input wins. Name is then
        // required either from input or the chosen product.
        $productId = (int) ($this->request->getJsonVar('product_id') ?: 0);
        $product   = $productId > 0 ? (new \App\Models\ProductModel())->setTenant($tenantId)->find($productId) : null;
        if ($product) {
            // Phase J3: the deal's price book sets the product's effective price.
            $product['price_paise'] = (new \App\Services\Crm\PriceBookService())
                ->priceFor($tenantId, $productId, ! empty($deal['price_book_id']) ? (int) $deal['price_book_id'] : null);
        }

        $line = (new \App\Services\Crm\LineItemPricing())->resolve([
            'name'         => $this->request->getJsonVar('name'),
            'quantity'     => $this->request->getJsonVar('quantity'),
            'unit_price'   => $this->request->getJsonVar('unit_price'),
            'discount_pct' => $this->request->getJsonVar('discount_pct'),
            'tax_pct'      => $this->request->getJsonVar('tax_pct'),
        ], $product);

        if ($line['name'] === '') {
            return $this->fail(['name' => 'A name or product is required.'], 422);
        }
        if (mb_strlen($line['name']) > 255) {
            return $this->fail(['name' => 'Name must be 255 characters or fewer.'], 422);
        }

        $lim = new DealLineItemModel();
        $lim->setTenant($tenantId)->insert(array_merge(['deal_id' => (int) $id, 'product_id' => $productId ?: null], $line));

        $this->recalcDealValue($tenantId, (int) $id);
        return $this->respondCreated(['success' => true, 'data' => $lim->forDeal($tenantId, (int) $id)]);
    }

    /** DELETE /api/v1/deals/:id/line-items/:itemId */
    public function deleteLineItem($id = null, $itemId = null): ResponseInterface
    {
        $tenantId = CurrentUser::tenantId();
        $lim      = new DealLineItemModel();
        $item     = $lim->setTenant($tenantId)->find((int) $itemId);
        if (! $item || (int) $item['deal_id'] !== (int) $id) {
            return $this->failNotFound('Line item not found.');
        }
        $lim->setTenant($tenantId)->delete((int) $itemId);
        $this->recalcDealValue($tenantId, (int) $id);
        return $this->respondDeleted(['success' => true]);
    }

    /** Keep deal.value_amount in sync with the sum of its line items. */
    private function recalcDealValue(int $tenantId, int $dealId): void
    {
        $items = (new DealLineItemModel())->forDeal($tenantId, $dealId);
        $sum   = array_sum(array_map(static fn ($i) => (int) $i['total'], $items));
        $this->model()->update($dealId, ['value_amount' => $sum]);
    }

    private function payload(): array
    {
        $allowed = [
            'title', 'account_id', 'primary_contact_id', 'owner_id', 'value_amount',
            'currency', 'price_book_id', 'expected_close_date', 'source', 'pipeline_id',
            'is_recurring', 'recurring_interval',
        ];
        $body = $this->request->getJSON(true) ?: [];
        $out  = array_intersect_key($body, array_flip($allowed));

        // Normalize recurring fields: a valid interval implies recurring; an
        // invalid/absent interval clears it so MRR math never sees junk.
        if (array_key_exists('is_recurring', $out) || array_key_exists('recurring_interval', $out)) {
            $interval = $out['recurring_interval'] ?? null;
            $valid    = isset(\App\Models\DealModel::INTERVALS[$interval]);
            $out['is_recurring']       = ($valid && ! empty($out['is_recurring'] ?? 1)) ? 1 : 0;
            $out['recurring_interval'] = $out['is_recurring'] ? $interval : null;
        }
        return $out;
    }
}
