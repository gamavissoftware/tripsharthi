<?php

declare(strict_types=1);

namespace App\Services\Flow;

use App\Exceptions\WindowClosedException;
use App\Models\ContactFieldValueModel;
use App\Models\ContactModel;
use App\Models\ConversationModel;
use App\Models\FlowRunLogModel;
use App\Models\FlowRunModel;
use App\Models\MessageModel;
use App\Models\PhoneNumberModel;
use App\Models\TemplateModel;
use App\Models\WabaAccountModel;
use App\Services\Leads\VariableResolver;
use App\Services\WhatsApp\BillableComputer;
use App\Services\WhatsApp\CloudApiClient;
use App\Services\WhatsApp\ProviderAdapter;
use App\Services\WhatsApp\TemplateComponentBuilder;
use App\Services\WhatsApp\WindowService;

/**
 * Flow execution engine.
 *
 * ── Dual loop guard ──────────────────────────────────────────────────
 *
 * GUARD 1 — per-advance() transitions counter (LOOP_GUARD_MAX = 100):
 *   Catches tight cycles within a single advance() call (A→B→A with no delays).
 *   Resets to 0 on every resume — so by itself it cannot stop a delay-spanning
 *   cycle.  When tripped it throws (job queue retries with backoff, then fails
 *   at max_attempts).
 *
 * GUARD 2 — persistent steps_executed counter (MAX_STEPS_PER_RUN = 1000):
 *   Incremented and saved to flow_runs.steps_executed on EVERY node execution,
 *   surviving restarts and delays.  Catches delay-spanning cycles (A→delay→B→
 *   delay→A) that reset GUARD 1 each resume.  When tripped: run.status=failed,
 *   no retry.
 *
 * The two guards together cover all cycle shapes.
 *
 * ── Window policy (CLAUDE.md §1) ─────────────────────────────────────
 *
 * send_freeform / send_media:
 *   1. assertFreeFormAllowedForConversation() — if closed: log blocked_window,
 *      route to 'window_closed' fallback edge if present, else stop the run.
 *      NEVER silently send.
 *
 * send_template: unrestricted by window — templates are always allowed.
 *   billable = BillableComputer::compute(category, windowOpen).
 *
 * send_template marketing + opt_in=false: skip the send, log skipped_opt_out,
 *   advance to next node (don't fail the run).
 *
 * No conversation (fresh lead, never messaged) → window treated as closed.
 *
 * send_interactive:
 *   Sends an interactive message (buttons / list) via CloudApiClient::sendInteractive().
 *   On success, parks the run in 'waiting' status for up to 24 hours waiting for a
 *   button reply or list selection. WebhookService resumes the run when the reply arrives.
 */
class FlowEngine
{
    /** Max node transitions in a single advance() call. Resets each resume. */
    public const LOOP_GUARD_MAX    = 100;
    /** Max total steps across ALL advance() calls for one run (persistent). */
    public const MAX_STEPS_PER_RUN = 1000;

    private const DELAY_UNIT_SECONDS = [
        'seconds' => 1,
        'minutes' => 60,
        'hours'   => 3600,
        'days'    => 86400,
    ];

    public function __construct(
        private readonly CloudApiClient|ProviderAdapter|null $apiClientOverride = null,
        private readonly ?WindowService  $windowSvcOverride  = null,
        private readonly ?int            $nowOverride        = null,
    ) {}

    private function now(): int
    {
        return $this->nowOverride ?? time();
    }

    // ------------------------------------------------------------------
    // Entry point
    // ------------------------------------------------------------------

    /**
     * Walk the flow graph from run.current_node_id until parking or terminating.
     *
     * @param  array $run       Flow run row from flow_runs (array).
     * @param  int   $tenantId  Tenant context.
     */
    /**
     * Read a node-data field that may be a JSON string or an already-decoded
     * array (seeded graphs, imported graphs, older builder versions).
     *
     * @return array<string|int, mixed>
     */
    private static function nodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    public function advance(array $run, int $tenantId): void
    {
        $graph         = json_decode($run['graph_snapshot'] ?? '{}', true) ?: [];
        $currentNodeId = $run['current_node_id'] ?? null;
        $runId         = (int) $run['id'];
        $contactId     = (int) $run['contact_id'];
        $state         = json_decode($run['state'] ?? '{}', true) ?: [];
        $stepsExecuted = (int) $run['steps_executed'];
        $transitions   = 0;

        // ── Models (created once, reused across iterations) ───────────
        $runModel     = new FlowRunModel();
        $logModel     = new FlowRunLogModel();
        $contactModel = new ContactModel();
        $convModel    = new ConversationModel();

        // Mark as running (fix #2: resume handler passes 'waiting' or 'running')
        $runModel->setTenant($tenantId)->update($runId, ['status' => 'running']);

        // ── Load contact ──────────────────────────────────────────────
        $contact = $contactModel->setTenant($tenantId)->find($contactId);
        if ($contact === null) {
            $this->failRun($runModel, $logModel, $runId, $tenantId,
                'n/a', 'n/a', "Contact #{$contactId} not found.");
            return;
        }

        // ── Interactive-reply TIMEOUT ─────────────────────────────────
        // A resume firing while still waiting_for=interactive_reply means the
        // 24h window elapsed with no tap (a real tap clears waiting_for and
        // advances current_node_id via WebhookService::resumeInteractiveFlowRuns).
        // Route to the button node's 'fallback' edge, or complete if none.
        if (($state['waiting_for'] ?? '') === 'interactive_reply') {
            $buttonNodeId = $state['button_node_id'] ?? $currentNodeId;
            unset($state['waiting_for'], $state['button_node_id']);
            $state['interactive_timed_out'] = true;

            $fallback      = $this->findEdge($graph, $buttonNodeId, 'fallback');
            $currentNodeId = $fallback['target'] ?? null;

            $this->writeLog($logModel, $runId, $buttonNodeId, 'wait_interactive',
                $currentNodeId !== null ? 'advance' : 'completed',
                'No interactive reply within 24h — ' . ($currentNodeId !== null ? 'took fallback edge.' : 'no fallback, run completed.'));

            if ($currentNodeId === null) {
                $runModel->setTenant($tenantId)->update($runId, [
                    'status'         => 'completed',
                    'completed_at'   => date('Y-m-d H:i:s', $this->now()),
                    'steps_executed' => $stepsExecuted,
                    'state'          => json_encode($state),
                ]);
                return;
            }
            // Persist cleared state so a queue retry doesn't re-detect the timeout.
            $runModel->setTenant($tenantId)->update($runId, ['state' => json_encode($state)]);
        }

        // ── Load conversation (null = no conversation yet) ────────────
        $convRow = $convModel->setTenant($tenantId)
                             ->where('wa_number', $contact['wa_number'])
                             ->first();
        // Fix #4: no conversation → empty array → treated as window-closed
        $convArr = $convRow ? (is_array($convRow) ? $convRow : (array) $convRow) : [];

        // ── Window service ────────────────────────────────────────────
        $windowSvc = $this->windowSvcOverride ?? new WindowService(new ConversationModel());

        // API client (lazy — only built on first send node)
        $apiClient = $this->apiClientOverride;

        // ── Test/dry-run mode — skip real sends, collapse delays ──────
        $isDryRun = (bool) ($run['is_test'] ?? false);

        // ── Main execution loop ───────────────────────────────────────
        while ($currentNodeId !== null) {

            // ── GUARD 1: per-advance transitions ─────────────────────
            if (++$transitions > self::LOOP_GUARD_MAX) {
                // Throw so the job queue retries (and eventually the persistent
                // guard fires if it's a delay-spanning cycle).
                throw new \RuntimeException(
                    "FlowEngine loop guard exceeded {$transitions} transitions "
                    . "in a single advance() call. Run #{$runId}, node '{$currentNodeId}'."
                );
            }

            // ── GUARD 2: persistent steps guard ──────────────────────
            $stepsExecuted++;
            if ($stepsExecuted > self::MAX_STEPS_PER_RUN) {
                $runModel->setTenant($tenantId)->update($runId, [
                    'status'         => 'failed',
                    'steps_executed' => $stepsExecuted,
                    'completed_at'   => date('Y-m-d H:i:s', $this->now()),
                ]);
                $this->writeLog($logModel, $runId, $currentNodeId, 'loop_guard',
                    'failed',
                    "MAX_STEPS_PER_RUN (" . self::MAX_STEPS_PER_RUN . ") exceeded. "
                    . "Possible delay-spanning cycle. Run permanently failed."
                );
                return;
            }

            // ── Find node ─────────────────────────────────────────────
            $node = $this->findNode($graph, $currentNodeId);
            if ($node === null) {
                $this->failRun($runModel, $logModel, $runId, $tenantId,
                    $currentNodeId, 'unknown',
                    "Node '{$currentNodeId}' not found in graph snapshot.");
                return;
            }

            // ── Execute node ──────────────────────────────────────────
            $ctx = [
                'tenantId'    => $tenantId,
                'contact'     => $contact,
                'conversation'=> $convArr,
                'graph'       => $graph,
                'state'       => $state,
                'runId'       => $runId,
                'windowSvc'   => $windowSvc,
                'apiClient'   => &$apiClient,
                'now'         => $this->now(),
                'dryRun'      => $isDryRun,
            ];

            $result = $this->executeNode($node, $ctx);

            // A node may hand facts to the nodes after it — book_slot records
            // the slot it just booked so an owner alert can name the time.
            // Persisted with the next run update below.
            if (! empty($result['state']) && is_array($result['state'])) {
                $state = array_merge($state, $result['state']);
            }

            // ── Write execution log ───────────────────────────────────
            $this->writeLog($logModel, $runId, $node['id'], $node['type'],
                $result['outcome'], $result['detail'] ?? null);

            // ── Handle result ─────────────────────────────────────────
            $terminal   = false;
            $termStatus = null;
            $nextNodeId = null;

            switch ($result['outcome']) {

                case 'wait_interactive':
                    // Park the run waiting for a button reply or list selection.
                    // next_run_at = 24 hours (max window for a reply)
                    $waitUntil = time() + 86400;
                    $runModel->setTenant($tenantId)->update($runId, [
                        'status'          => 'waiting',
                        'current_node_id' => $currentNodeId, // stay on same node
                        'next_run_at'     => date('Y-m-d H:i:s', $waitUntil),
                        'steps_executed'  => $stepsExecuted,
                        'state'           => json_encode(array_merge($state, [
                            'waiting_for'    => 'interactive_reply',
                            'button_node_id' => $currentNodeId,
                        ])),
                    ]);
                    // Durable timeout: if no tap arrives before the window closes,
                    // this resume fires and advance() routes to the node's 'fallback'
                    // edge. A real tap resumes earlier (WebhookService) and clears the
                    // waiting state, so by the time this fires the run is no longer
                    // waiting_for=interactive_reply and the timeout path is skipped.
                    JobDispatcher::dispatch(
                        $tenantId, 'flow_resume',
                        ['flow_run_id' => $runId],
                        $waitUntil
                    );
                    return; // Park — WebhookService resumes when button is tapped

                case 'delayed':
                    $runModel->setTenant($tenantId)->update($runId, [
                        'status'          => 'waiting',
                        'current_node_id' => $result['next_node_id'],
                        'next_run_at'     => date('Y-m-d H:i:s', $result['run_at']),
                        'steps_executed'  => $stepsExecuted,
                    ]);
                    JobDispatcher::dispatch(
                        $tenantId, 'flow_resume',
                        ['flow_run_id' => $runId],
                        $result['run_at']
                    );
                    return; // Park; cron resumes

                case 'blocked_window':
                    $fallback = $this->findEdge($graph, $node['id'], 'window_closed');
                    if ($fallback !== null) {
                        $nextNodeId = $fallback['target'];
                    } else {
                        $terminal   = true;
                        $termStatus = 'stopped';
                    }
                    break;

                case 'advance':
                case 'branched':
                    $handle   = $result['sourceHandle'] ?? 'next';
                    $nextEdge = $this->findEdge($graph, $node['id'], $handle);
                    if ($nextEdge === null) {
                        $terminal   = true;
                        $termStatus = 'completed';
                    } else {
                        $nextNodeId = $nextEdge['target'];
                    }
                    break;

                case 'completed':
                    $terminal   = true;
                    $termStatus = 'completed';
                    break;

                case 'stopped':
                    $terminal   = true;
                    $termStatus = 'stopped';
                    break;

                default:
                    $this->failRun($runModel, $logModel, $runId, $tenantId,
                        $node['id'], $node['type'],
                        "Unknown node outcome: '{$result['outcome']}'.");
                    return;
            }

            if ($terminal) {
                $runModel->setTenant($tenantId)->update($runId, [
                    'status'         => $termStatus,
                    'completed_at'   => date('Y-m-d H:i:s', $this->now()),
                    'steps_executed' => $stepsExecuted,
                ]);
                return;
            }

            // Advance to next node (persists current_node_id, step count, state)
            $currentNodeId = $nextNodeId;
            $runModel->setTenant($tenantId)->update($runId, [
                'current_node_id' => $currentNodeId,
                'steps_executed'  => $stepsExecuted,
                'state'           => json_encode($state),
            ]);
        }

        // currentNodeId became null — terminal node reached naturally
        $runModel->setTenant($tenantId)->update($runId, [
            'status'         => 'completed',
            'completed_at'   => date('Y-m-d H:i:s', $this->now()),
            'steps_executed' => $stepsExecuted,
        ]);
    }

    // ------------------------------------------------------------------
    // Node dispatch
    // ------------------------------------------------------------------

    private function executeNode(array $node, array &$ctx): array
    {
        // Trigger nodes: the engine starts AFTER the trigger; if one is somehow reached, skip it.
        if (in_array($node['type'] ?? '', FlowTriggers::all(), true)) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => 'Trigger node (entry) — skipped by engine'];
        }

        return match($node['type'] ?? '') {
            'send_template'     => $this->execSendTemplate($node, $ctx),
            'send_freeform'     => $this->execSendFreeform($node, $ctx),
            'send_media'        => $this->execSendMedia($node, $ctx),
            'send_interactive'  => $this->execSendInteractive($node, $ctx),
            'send_slots'        => $this->execSendSlots($node, $ctx),
            'book_slot'         => $this->execBookSlot($node, $ctx),
            'notify_number'     => $this->execNotifyNumber($node, $ctx),
            'add_tag'           => $this->execAddTag($node, $ctx),
            'remove_tag'        => $this->execRemoveTag($node, $ctx),
            'update_status'     => $this->execUpdateStatus($node, $ctx),
            'create_task'       => $this->execCreateTask($node, $ctx),
            'send_email'        => $this->execSendEmail($node, $ctx),
            'update_field'      => $this->execUpdateField($node, $ctx),
            'create_deal'       => $this->execCreateDeal($node, $ctx),
            'create_ticket'     => $this->execCreateTicket($node, $ctx),
            'assign_agent'      => $this->execAssignAgent($node, $ctx),
            'handoff'           => $this->execHandoff($node, $ctx),
            'send_payment'      => $this->execSendPayment($node, $ctx),
            'send_product'      => $this->execSendProduct($node, $ctx),
            'ai_reply'          => $this->execAiReply($node, $ctx),
            'send_flow'         => $this->execSendFlow($node, $ctx),
            'webhook_call'      => $this->execWebhookCall($node, $ctx),
            'delay'             => $this->execDelay($node, $ctx),
            'condition'         => $this->execCondition($node, $ctx),
            'window_check'      => $this->execWindowCheck($node, $ctx),
            default => throw new \RuntimeException(
                "Unknown node type: '{$node['type']}'"
            ),
        };
    }

    // ------------------------------------------------------------------
    // Action executors
    // ------------------------------------------------------------------

    private function execSendTemplate(array $node, array &$ctx): array
    {
        $data       = $node['data'] ?? [];
        $templateId = (int) ($data['template_id'] ?? 0);
        $tenantId   = $ctx['tenantId'];
        $contact    = $ctx['contact'];

        $template = (new TemplateModel())->setTenant($tenantId)->find($templateId);
        if ($template === null || ($template['meta_status'] ?? '') !== 'approved') {
            throw new \RuntimeException("Template #{$templateId} not found or not approved.");
        }

        // Multi-language: prefer the contact's language version when available.
        $template = (new \App\Services\Leads\TemplateResolver(new TemplateModel()))
            ->localize((int) $tenantId, $template, $contact['language'] ?? null);

        // Quality handbrake. Sits with the opt-in gate because both answer the
        // same question — may this marketing message go out at all — and both
        // must run before the API call, not after it.
        //
        // The run ADVANCES rather than stopping. A flow halted mid-graph would
        // strand every contact at this node, and when the rating recovered they
        // would all still be sitting there. Logging the skip and moving on keeps
        // the graph's own logic (tagging, handoff) intact.
        if ($template['category'] === 'marketing') {
            $blocked = (new \App\Services\WhatsApp\SendingGate())->blockedReason((int) $tenantId);
            if ($blocked !== null) {
                (new MessageModel())->withoutTenantScope()->insert([
                    'tenant_id'       => $tenantId,
                    'contact_id'      => (int) $contact['id'],
                    'conversation_id' => $this->getOrCreateConvId($ctx),
                    'direction'       => 'out',
                    'type'            => 'template',
                    'category'        => 'marketing',
                    'body'            => $template['body'] ?? '',
                    'status'          => 'failed',
                    'billable'        => 0,
                    'error'           => 'Skipped: ' . $blocked,
                ]);

                return ['outcome' => 'advance', 'sourceHandle' => 'next',
                        'detail' => 'Skipped: ' . $blocked];
            }
        }

        // Fix #3: Marketing opt-in gate
        if ($template['category'] === 'marketing' && ! (bool) ($contact['opt_in'] ?? 1)) {
            (new MessageModel())->withoutTenantScope()->insert([
                'tenant_id'       => $tenantId,
                'contact_id'      => (int) $contact['id'],
                'conversation_id' => $this->getOrCreateConvId($ctx),
                'direction'       => 'out',
                'type'            => 'template',
                'category'        => 'marketing',
                'body'            => $template['body'] ?? '',
                'status'          => 'failed',
                'billable'        => 0,
                'error'           => 'Skipped: contact has opt_in=false for marketing template.',
            ]);
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail' => 'Skipped: marketing template, contact opt_in=false'];
        }

        // Resolve variables. Node data holds these as a JSON string when written
        // by the builder, but seeded/imported graphs carry them as real arrays —
        // json_decode() on an array is a TypeError that killed the run outright.
        $varMap  = self::nodeJson($data['variable_mapping'] ?? $data['variable_map'] ?? null);
        $varDefs = self::nodeJson($data['variable_defaults'] ?? null);
        // Exclude restricted (owner/admin-only) custom fields from message
        // personalization — they must never be merged into an outbound body,
        // else an agent could exfiltrate a field they can't even view.
        $cfv     = (new ContactFieldValueModel())->getForContact((int) $contact['id'], false);
        // Run state is passed last so a mapping of "state:followup_hook" can
        // reach a value computed when the trigger fired. Contact and custom
        // fields resolve exactly as before.
        $res     = (new VariableResolver())->resolve(
            $varMap, $contact, $cfv, $varDefs, $ctx['state'] ?? []
        );

        // Build components
        $components = TemplateComponentBuilder::forSend($template, $res['body_params']);

        // Billable: window state is informational for templates (always allowed)
        $windowOpen = ! empty($ctx['conversation'])
            && $ctx['windowSvc']->isOpenForConversation($ctx['conversation']);
        $billable   = BillableComputer::compute($template['category'], $windowOpen);

        // API client
        if ($ctx['apiClient'] === null) {
            $ctx['apiClient'] = $this->buildApiClient($tenantId);
        }

        // ── Dry-run: skip actual send ────────────────────────────────
        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail' => "[TEST] Would send template '{$template['name']}' to {$contact['wa_number']}"];
        }

        $result = $ctx['apiClient']->sendTemplate(
            $contact['wa_number'],
            $template['name'],
            $template['language'],
            $components
        );

        (new MessageModel())->withoutTenantScope()->insert([
            'tenant_id'       => $tenantId,
            'contact_id'      => (int) $contact['id'],
            'conversation_id' => $this->getOrCreateConvId($ctx),
            'direction'       => 'out',
            'type'            => 'template',
            'template_id'     => (int) ($template['id'] ?? 0) ?: null,
            'category'        => $template['category'],
            'body'            => $template['body'] ?? '',
            'wa_message_id'   => $result['message_id'],
            'status'          => $result['success'] ? 'sent' : 'failed',
            // Meta rejected it — nothing was delivered, so nothing is billable.
            'billable'        => $result['success'] ? $billable : 0,
            'error'           => $result['error'],
            'sent_at'         => $result['success'] ? date('Y-m-d H:i:s', $ctx['now']) : null,
        ]);

        return ['outcome' => 'advance', 'sourceHandle' => 'next',
                'detail' => $result['success'] ? 'Template sent' : ('Send failed: ' . $result['error'])];
    }

    private function execSendFreeform(array $node, array &$ctx): array
    {
        return $this->execFreeformSend('text', $node, $ctx);
    }

    private function execSendMedia(array $node, array &$ctx): array
    {
        return $this->execFreeformSend('media', $node, $ctx);
    }

    /**
     * Shared free-form send path (text or media) with window gate.
     *
     * Fix #4: empty conversation array → treated as window closed.
     */
    private function execFreeformSend(string $sendType, array $node, array &$ctx): array
    {
        $data     = $node['data'] ?? [];
        $content  = $this->renderStateTokens((string) ($data['content'] ?? ''), $ctx);
        $contact  = $ctx['contact'];
        $tenantId = $ctx['tenantId'];
        $convArr  = $ctx['conversation']; // empty [] = no conversation = closed

        // ── Dry-run: skip actual send ────────────────────────────────
        if ($ctx['dryRun'] ?? false) {
            $preview = mb_substr($content, 0, 60) . (strlen($content) > 60 ? '…' : '');
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail' => "[TEST] Would send {$sendType} to {$contact['wa_number']}: '{$preview}'"];
        }

        // ── WINDOW GATE — must be first, before any API call ──────────
        try {
            // WindowClosedException when convArr is empty (fix #4)
            $ctx['windowSvc']->assertFreeFormAllowedForConversation($convArr);
        } catch (WindowClosedException $e) {
            // Log the blocked attempt — every block is auditable
            $convId = (int) ($convArr['id'] ?? 0);
            (new MessageModel())->logBlocked(
                $tenantId, $convId, (int) $contact['id'],
                $content,
                "Policy block: window closed in flow run #{$ctx['runId']} node '{$node['id']}'"
            );

            return ['outcome' => 'blocked_window',
                    'detail'  => 'Window closed: ' . $e->getMessage()];
        }

        // ── Window is open — proceed ───────────────────────────────────
        if ($ctx['apiClient'] === null) {
            $ctx['apiClient'] = $this->buildApiClient($tenantId);
        }

        if ($sendType === 'media') {
            $mediaType = $data['type'] ?? 'image';
            $mediaUrl  = $data['url']  ?? '';
            $caption   = $data['caption'] ?? '';
            $result    = $ctx['apiClient']->sendMedia(
                $contact['wa_number'], $mediaType, $mediaUrl, $caption
            );
        } else {
            $result = $ctx['apiClient']->sendText($contact['wa_number'], $content);
        }

        (new MessageModel())->withoutTenantScope()->insert([
            'tenant_id'       => $tenantId,
            'contact_id'      => (int) $contact['id'],
            'conversation_id' => $this->getOrCreateConvId($ctx),
            'direction'       => 'out',
            'type'            => 'text',
            'category'        => 'free_form',
            'body'            => $content,
            'wa_message_id'   => $result['message_id'],
            'status'          => $result['success'] ? 'sent' : 'failed',
            'billable'        => 0,
            'error'           => $result['error'],
            'sent_at'         => $result['success'] ? date('Y-m-d H:i:s', $ctx['now']) : null,
        ]);

        return ['outcome' => 'advance', 'sourceHandle' => 'next',
                'detail' => $result['success'] ? 'Message sent' : ('Failed: ' . $result['error'])];
    }

    /**
     * Merge run-state tokens into free-form copy.
     *
     * Deliberately narrow. Only the tokens listed here are recognised, and a
     * token is only substituted when the run actually carries a value for it —
     * anything else, including an unknown {{token}}, is left exactly as the
     * author typed it. A flow that never used tokens therefore behaves exactly
     * as it did before this existed, which matters because this is the hot path
     * every live drip already runs through.
     *
     * Leaving an unresolved token visible is intentional: a blank where a time
     * should be reads like a broken message to the customer, whereas the raw
     * token is obviously a flow that needs fixing.
     */
    private function renderStateTokens(string $content, array $ctx): string
    {
        if ($content === '' || ! str_contains($content, '{{')) {
            return $content;
        }

        $state   = $ctx['state'] ?? [];
        $contact = $ctx['contact'] ?? [];

        // 'there' matches VariableResolver's fallback, so a nameless contact
        // reads "Hi there" rather than "Hi {{contact.name}}".
        $name = trim((string) ($contact['name'] ?? ''));

        $map = [
            '{{contact.name}}'    => $name === '' ? 'there' : $name,
            '{{meeting.time}}'    => (string) ($state['meeting_time'] ?? ''),
            '{{meeting.minutes}}' => (string) ($state['meeting_minutes'] ?? ''),
            '{{meeting.title}}'   => (string) ($state['meeting_title'] ?? ''),
            '{{meeting.link}}'    => (string) ($state['meeting_link'] ?? ''),
        ];

        // Travel: {{trip.destination}} -> state['trip_destination'], {{quote.link}} -> state['quote_link'], …
        // Only whitelisted groups, and only when the run actually carries a value (same rule as above).
        $groups = implode('|', \App\Services\Travel\TravelFlowContext::TOKEN_GROUPS);
        if (preg_match_all('/\{\{\s*((?:' . $groups . ')\.[a-z_]+)\s*\}\}/', $content, $m)) {
            foreach (array_unique($m[1]) as $path) {
                $map['{{' . $path . '}}'] = (string) ($state[str_replace('.', '_', $path)] ?? '');
            }
            // also match the spaced form {{ trip.destination }}
            $content = preg_replace('/\{\{\s*((?:' . $groups . ')\.[a-z_]+)\s*\}\}/', '{{$1}}', $content) ?? $content;
        }

        foreach ($map as $token => $value) {
            if (trim($value) !== '') {
                $content = str_replace($token, $value, $content);
            }
        }

        return $content;
    }

    private function execSendInteractive(array $node, array &$ctx): array
    {
        $data     = $node['data'] ?? [];
        $contact  = $ctx['contact'];
        $tenantId = $ctx['tenantId'];

        // Dry-run: skip actual send
        if ($ctx['dryRun'] ?? false) {
            $type    = $data['interactive_type'] ?? 'button';
            $body    = $data['body'] ?? '[interactive message]';
            $preview = mb_substr($body, 0, 60);
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail' => "[TEST] Would send {$type} interactive to {$contact['wa_number']}: '{$preview}'"];
        }

        // Build adapter
        if ($ctx['apiClient'] === null) {
            $ctx['apiClient'] = $this->buildApiClient($tenantId);
        }

        $result = $ctx['apiClient']->sendInteractive($contact['wa_number'], $data);

        if (! $result['success']) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail' => 'Interactive send failed: ' . ($result['error'] ?? 'unknown')];
        }

        // Store the sent message
        $convId = $this->getOrCreateConvId($ctx);
        (new MessageModel())->withoutTenantScope()->insert([
            'tenant_id'       => $tenantId,
            'contact_id'      => (int) $contact['id'],
            'conversation_id' => $convId,
            'direction'       => 'out',
            'type'            => 'interactive',
            'category'        => 'free_form',
            'body'            => $data['body'] ?? '',
            'wa_message_id'   => $result['message_id'],
            'status'          => 'sent',
            'billable'        => 0,
            'sent_at'         => date('Y-m-d H:i:s', $ctx['now']),
        ]);

        // Park and wait for button reply
        return ['outcome' => 'wait_interactive',
                'detail'  => 'Interactive message sent — waiting for button reply'];
    }

    /**
     * Send an approved template to a FIXED number — the business's own phone —
     * rather than to the contact the run is about.
     *
     * Exists because a lead tapping "Book Free Demo" is worth nothing if nobody
     * notices it. Every other send node targets the contact; this one targets
     * the team, and carries who tapped and which card they tapped.
     *
     * Node data:
     *   to            "+919718991797"          the number to alert
     *   template_id   N                        an APPROVED template, normally utility
     *   params        ["{{contact.name}}", …]  ordered body variables
     *   label_map     {"card0_btn0": "CRM", …} payload -> human label for {{label}}
     *   label_default "Carousel"               when the payload is unmapped
     *
     * Deliberately writes no message row: the alert belongs to the business, not
     * to the customer's thread, and logging it there would make the Inbox read as
     * though the customer had been messaged.
     */
    private function execNotifyNumber(array $node, array &$ctx): array
    {
        $data       = $node['data'] ?? [];
        $to         = trim((string) ($data['to'] ?? ''));
        $templateId = (int) ($data['template_id'] ?? 0);
        $tenantId   = $ctx['tenantId'];

        if ($to === '') {
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail' => 'No alert number configured — nothing sent.'];
        }

        $template = (new TemplateModel())->setTenant($tenantId)->find($templateId);
        if ($template === null || ($template['meta_status'] ?? '') !== 'approved') {
            // An unapproved alert template must not abort the customer-facing
            // branch of the flow; the lead still gets their reply.
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail' => "Alert template #{$templateId} missing or not approved — nothing sent."];
        }

        $params = [];
        foreach (self::nodeJson($data['params'] ?? null) as $token) {
            $params[] = ['type' => 'text', 'text' => $this->resolveAlertToken((string) $token, $node, $ctx)];
        }

        $components = $params === [] ? [] : [['type' => 'body', 'parameters' => $params]];

        if ($ctx['dryRun'] ?? false) {
            $preview = implode(' | ', array_column($params, 'text'));
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail' => "[TEST] Would alert {$to} with '{$template['name']}': {$preview}"];
        }

        if ($ctx['apiClient'] === null) {
            $ctx['apiClient'] = $this->buildApiClient($tenantId);
        }

        $result = $ctx['apiClient']->sendTemplate($to, $template['name'], $template['language'], $components);

        if (! ($result['success'] ?? false)) {
            log_message('error', "notify_number: alert to {$to} failed — " . ($result['error'] ?? 'unknown'));

            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail' => 'Alert failed: ' . ($result['error'] ?? 'unknown')];
        }

        return ['outcome' => 'advance', 'sourceHandle' => 'next',
                'detail' => "Alert sent to {$to}"];
    }

    /**
     * Offer bookable demo slots as a WhatsApp list, then park for the choice.
     *
     * Slots are generated at send time, never stored on the node: a list built
     * when the flow was designed would be offering last week by now.
     *
     * With no availability the run advances down 'next' instead of parking, so
     * a fully-booked calendar cannot strand every contact mid-flow.
     */
    private function execSendSlots(array $node, array &$ctx): array
    {
        $data     = $node['data'] ?? [];
        $contact  = $ctx['contact'];
        $tenantId = (int) $ctx['tenantId'];

        $timezone = (string) ($data['timezone'] ?? 'Asia/Kolkata');
        $count    = (int) ($data['count'] ?? 6);
        $body     = (string) ($data['body'] ?? "Pick a time that suits you and I'll send the invite.");

        $slots = (new \App\Services\Crm\SlotService())
            ->available($tenantId, $count, $timezone, $ctx['now'] ?? null);

        if ($slots === []) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail'  => 'No slots available — skipped the picker'];
        }

        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail'  => '[TEST] Would offer ' . count($slots) . ' slots to ' . $contact['wa_number']];
        }

        if ($ctx['apiClient'] === null) {
            $ctx['apiClient'] = $this->buildApiClient($tenantId);
        }

        $rows = [];
        foreach ($slots as $slot) {
            $rows[] = ['id' => $slot['id'], 'title' => $slot['label'], 'description' => $slot['description']];
        }

        $result = $ctx['apiClient']->sendInteractive($contact['wa_number'], [
            'type'        => 'list',
            'body'        => $body,
            'footer'      => (string) ($data['footer'] ?? ''),
            'button_text' => (string) ($data['button_text'] ?? 'See times'),
            'sections'    => [[
                'title' => mb_substr((string) ($data['section_title'] ?? 'Available'), 0, 24),
                'rows'  => $rows,
            ]],
        ]);

        if (! $result['success']) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail'  => 'Slot list send failed: ' . ($result['error'] ?? 'unknown')];
        }

        $convId = $this->getOrCreateConvId($ctx);
        (new MessageModel())->withoutTenantScope()->insert([
            'tenant_id'       => $tenantId,
            'contact_id'      => (int) $contact['id'],
            'conversation_id' => $convId,
            'direction'       => 'out',
            'type'            => 'interactive',
            'category'        => 'free_form',
            'body'            => $body,
            'wa_message_id'   => $result['message_id'],
            'status'          => 'sent',
            'billable'        => 0,
            'sent_at'         => date('Y-m-d H:i:s', $ctx['now']),
        ]);

        return ['outcome' => 'wait_interactive',
                'detail'  => 'Offered ' . count($slots) . ' slots — waiting for a choice'];
    }

    /**
     * Book the slot the contact just tapped.
     *
     * Branches on 'booked' / 'failed' rather than always advancing, because
     * "that time just went" needs different handling from a confirmation —
     * the seeded flow loops 'failed' back to a fresh slot list.
     */
    private function execBookSlot(array $node, array &$ctx): array
    {
        $data     = $node['data'] ?? [];
        $contact  = $ctx['contact'];
        $tenantId = (int) $ctx['tenantId'];
        $slotId   = $this->tappedButton($ctx['state'] ?? []);

        if (! \App\Services\Crm\BookingService::isSlotSelection($slotId)) {
            // Say nothing. This path is reached both by a stray reply and by the
            // 24h interactive timeout, and "that time was taken" a day later —
            // to a contact who never picked a time, with the window now shut —
            // would be both wrong and unsendable.
            return ['outcome' => 'branched', 'sourceHandle' => 'failed',
                    'detail'  => 'No slot selected (stray reply or timeout) — stayed quiet'];
        }

        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'branched', 'sourceHandle' => 'booked',
                    'detail'  => "[TEST] Would book {$slotId}"];
        }

        $result = (new \App\Services\Crm\BookingService())->book(
            $tenantId,
            (int) $contact['id'],
            $slotId,
            (string) ($data['title'] ?? 'Product demo'),
            (string) ($data['timezone'] ?? 'Asia/Kolkata'),
            $ctx['now'] ?? null
        );

        $booked = (bool) ($result['ok'] ?? false);

        // The confirmation only promises a link when one actually exists.
        // It used to say "I'll send a video-call link before we start"
        // unconditionally, while nothing anywhere sent one — every prospect who
        // booked was told something untrue, which is a worse first impression
        // than saying nothing at all.
        $link = (new \App\Services\Crm\MeetingLinkService())->forTenant((int) $tenantId);

        $confirm = $link !== null
            ? "Done — you're booked for {{slot}}.\n\nHere's the link for the call: {{link}}\n\nIf you need to move it, just reply here."
            : "Done — you're booked for {{slot}}.\n\nIf you need to move it, just reply here.";

        $template = $booked
            ? (string) ($data['confirm_text'] ?? $confirm)
            : (string) ($data['failed_text'] ?? "Sorry — that time was taken a moment ago. Let me show you what's still free.");

        $message = str_replace(
            ['{{slot}}', '{{link}}'],
            [(string) ($result['label'] ?? ''), (string) $link],
            $template
        );
        $this->sendPlainText($ctx, $message);

        return ['outcome' => 'branched',
                'sourceHandle' => $booked ? 'booked' : 'failed',
                'state'  => $booked
                    ? ['booked_slot' => (string) ($result['label'] ?? ''), 'meeting_id' => (int) ($result['meeting_id'] ?? 0)]
                    : [],
                'detail' => $booked
                    ? "Booked meeting #{$result['meeting_id']} for {$result['label']}"
                    : 'Booking refused: ' . ($result['reason'] ?? 'unknown')];
    }

    /**
     * Send a plain free-form message from inside a node, recording it like any
     * other outbound. Used where the text depends on what the node just did and
     * so cannot live in a separate send_freeform node.
     */
    private function sendPlainText(array &$ctx, string $text): void
    {
        if ($text === '') {
            return;
        }

        $tenantId = (int) $ctx['tenantId'];
        $contact  = $ctx['contact'];

        if ($ctx['apiClient'] === null) {
            $ctx['apiClient'] = $this->buildApiClient($tenantId);
        }

        $result = $ctx['apiClient']->sendText($contact['wa_number'], $text);
        if (! ($result['success'] ?? false)) {
            log_message('error', '[Flow] slot message send failed: ' . ($result['error'] ?? 'unknown'));
            return;
        }

        (new MessageModel())->withoutTenantScope()->insert([
            'tenant_id'       => $tenantId,
            'contact_id'      => (int) $contact['id'],
            'conversation_id' => $this->getOrCreateConvId($ctx),
            'direction'       => 'out',
            'type'            => 'text',
            'category'        => 'free_form',
            'body'            => $text,
            'wa_message_id'   => $result['message_id'] ?? null,
            'status'          => 'sent',
            'billable'        => 0,
            'sent_at'         => date('Y-m-d H:i:s', $ctx['now']),
        ]);
    }

    /**
     * The button this alert is about: the one just tapped on a parked
     * send_interactive, else the one that started the run.
     *
     * @param array<string,mixed> $state
     */
    private function tappedButton(array $state): string
    {
        return (string) ($state['last_button_id'] ?? $state['button_id'] ?? '');
    }

    /**
     * Resolve one alert parameter.
     *
     * Meta rejects an empty body parameter outright, so every branch falls back
     * to something non-empty rather than risking the whole alert being dropped.
     */
    private function resolveAlertToken(string $token, array $node, array $ctx): string
    {
        $data    = $node['data'] ?? [];
        $contact = $ctx['contact'];
        $state   = $ctx['state'] ?? [];

        $token = trim($token);

        // A parameter may be a sentence with tokens inside it — "booked a call
        // for {{slot}}" — so an alert template with a free-text slot can still
        // read naturally. Each token resolves on its own; unknown ones stay.
        if (! preg_match('/^\{\{[^{}]+\}\}$/', $token) && str_contains($token, '{{')) {
            $out = preg_replace_callback('/\{\{[^{}]+\}\}/', function (array $m) use ($node, $ctx): string {
                $v = $this->resolveAlertToken($m[0], $node, $ctx);

                return $v === '-' ? '' : $v;
            }, $token);

            return trim((string) $out) === '' ? '-' : trim((string) $out);
        }

        // {{contact.<column>}}, {{contact.company}}, {{custom.<field_key>}} and
        // {{lead.answers}} — enough to put a whole lead-ad submission into an
        // owner alert without a node per field.
        if (preg_match('/^\{\{custom\.([a-z0-9_]+)\}\}$/i', $token, $m)) {
            $value = (string) ($this->contactCustomFields($ctx)[$m[1]] ?? '');

            return trim($value) === '' ? '-' : $value;
        }
        if ($token === '{{contact.company}}') {
            $value = $this->contactCompany($ctx);

            return trim($value) === '' ? '-' : $value;
        }
        if ($token === '{{lead.answers}}') {
            $value = $this->leadAnswers($ctx);

            return trim($value) === '' ? '-' : $value;
        }
        if ($token === '{{lead.source}}') {
            $value = ucwords(str_replace('_', ' ', (string) ($contact['source'] ?? '')));

            return trim($value) === '' ? '-' : $value;
        }
        if (preg_match('/^\{\{contact\.([a-z0-9_]+)\}\}$/i', $token, $m)
            && ! in_array($m[1], ['name', 'wa_number', 'email'], true)) {
            $value = (string) ($contact[$m[1]] ?? '');

            return trim($value) === '' ? '-' : $value;
        }

        $value = match ($token) {
            '{{contact.name}}'      => (string) ($contact['name'] ?? ''),
            '{{contact.wa_number}}' => (string) ($contact['wa_number'] ?? ''),
            '{{contact.email}}'     => (string) ($contact['email'] ?? ''),
            // The slot book_slot just confirmed, e.g. "Fri 26 Sep, 11:00 AM".
            '{{slot}}'              => (string) ($state['booked_slot'] ?? ''),
            // Which carousel card, or which button was just tapped. Every card's
            // button shows the same label, so the payload is the only thing that
            // distinguishes them.
            //
            // last_button_id wins: a run parked on send_interactive is resumed
            // with the button the contact just tapped, and that is what the
            // alert should describe — not the message that started the run.
            '{{label}}'             => (string) (self::nodeJson($data['label_map'] ?? null)[$this->tappedButton($state)]
                                        ?? $data['label_default'] ?? ''),
            '{{button_title}}'      => (string) ($state['button_title'] ?? ''),
            '{{button_id}}'         => $this->tappedButton($state),
            default                 => $token,
        };

        return trim($value) === '' ? '-' : $value;
    }

    /** @return array<string,string> field_key => value, owner-only fields excluded */
    private function contactCustomFields(array $ctx): array
    {
        return (new ContactFieldValueModel())->getForContact((int) $ctx['contact']['id'], false);
    }

    private function contactCompany(array $ctx): string
    {
        $accountId = (int) ($ctx['contact']['account_id'] ?? 0);
        if ($accountId <= 0) {
            return '';
        }
        $row = db_connect()->table('accounts')->select('name')->where('id', $accountId)->get()->getRowArray();

        return (string) ($row['name'] ?? '');
    }

    /**
     * Every custom-field answer on the contact as one line: "Label: value · …".
     * Lead-ad forms write each question as a custom field, so this is the whole
     * form. Labels come from custom_fields; keys are the fallback.
     */
    private function leadAnswers(array $ctx): string
    {
        $values = $this->contactCustomFields($ctx);
        if ($values === []) {
            return '';
        }
        $labels = [];
        foreach (db_connect()->table('custom_fields')->select('field_key, label')
            ->where('tenant_id', (int) $ctx['tenantId'])->whereIn('field_key', array_keys($values))
            ->get()->getResultArray() as $r) {
            $labels[$r['field_key']] = (string) $r['label'];
        }
        $parts = [];
        foreach ($values as $key => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $label   = rtrim($labels[$key] ?? ucfirst(str_replace('_', ' ', (string) $key)), '?.');
            $parts[] = $label . ': ' . str_replace(["\r", "\n"], ' ', $value);
        }

        // Meta caps a body parameter at 1024 characters.
        return mb_substr(implode(' · ', $parts), 0, 1000);
    }

    private function execAddTag(array $node, array &$ctx): array
    {
        $tagId     = (int) ($node['data']['tag_id'] ?? 0);
        $contactId = (int) $ctx['contact']['id'];

        // Dry-run (flow test): never mutate CRM data or fire downstream triggers.
        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail' => "[TEST] Would add tag #{$tagId}"];
        }

        if ($tagId > 0) {
            // Use raw SQL via db_connect() to guarantee the DBPrefix is applied
            // correctly in all driver contexts (MySQL + SQLite test).
            $db     = db_connect();
            $p      = $db->DBPrefix;
            $exists = $db->query(
                "SELECT 1 FROM {$p}contact_tags WHERE contact_id=? AND tag_id=? LIMIT 1",
                [$contactId, $tagId]
            )->getRow();
            if (! $exists) {
                $db->query(
                    "INSERT INTO {$p}contact_tags (contact_id, tag_id, created_at) VALUES (?,?,?)",
                    [$contactId, $tagId, date('Y-m-d H:i:s')]
                );
            }
        }
        // Fire tag_added trigger after the INSERT is auto-committed.
        // This enables flow chaining: a flow can tag a contact which starts another flow.
        // KNOWN: inter-flow loop detection across distinct run trees is deferred.
        //        reentry_policy='once' is the intended mitigation.
        if ($tagId > 0) {
            FlowTriggerService::fire('tag_added', $ctx['tenantId'], $contactId, [
                'tag_id' => $tagId,
            ]);
        }

        return ['outcome' => 'advance', 'sourceHandle' => 'next',
                'detail' => "Tag #{$tagId} added"];
    }

    private function execRemoveTag(array $node, array &$ctx): array
    {
        $tagId     = (int) ($node['data']['tag_id'] ?? 0);
        $contactId = (int) $ctx['contact']['id'];

        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail' => "[TEST] Would remove tag #{$tagId}"];
        }

        if ($tagId > 0) {
            $db = db_connect();
            $p  = $db->DBPrefix;
            $db->query(
                "DELETE FROM {$p}contact_tags WHERE contact_id=? AND tag_id=?",
                [$contactId, $tagId]
            );
        }
        return ['outcome' => 'advance', 'sourceHandle' => 'next',
                'detail' => "Tag #{$tagId} removed"];
    }

    private function execUpdateStatus(array $node, array &$ctx): array
    {
        $status    = $node['data']['status'] ?? 'contacted';
        $contactId = (int) $ctx['contact']['id'];

        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail' => "[TEST] Would set status → {$status}"];
        }

        $db        = db_connect();
        $p         = $db->DBPrefix;
        $db->query(
            "UPDATE {$p}contacts SET status=?, updated_at=? WHERE id=?",
            [$status, date('Y-m-d H:i:s'), $contactId]
        );
        return ['outcome' => 'advance', 'sourceHandle' => 'next',
                'detail' => "Status → {$status}"];
    }

    // ── CRM actions (Phase C) ───────────────────────────────────────────

    /**
     * send_email: a marketing email to the flow's contact, from a saved email
     * template or an inline subject + body. Email is not WhatsApp, so the 24h
     * window does not apply — the suppression list does. A contact without an
     * address, or one who unsubscribed, is skipped and the flow carries on.
     */
    private function execSendEmail(array $node, array &$ctx): array
    {
        $d          = $node['data'] ?? [];
        $tenantId   = (int) $ctx['tenantId'];
        $contact    = (array) $ctx['contact'];
        $templateId = (int) ($d['email_template_id'] ?? 0);

        $subject   = (string) ($d['subject'] ?? '');
        $html      = (string) ($d['html_body'] ?? '');
        $preheader = '';
        if ($templateId > 0) {
            $tpl = (new \App\Models\EmailTemplateModel())->setTenant($tenantId)->find($templateId);
            if ($tpl === null) {
                return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "Email skipped: template #{$templateId} not found"];
            }
            $subject   = trim($subject) !== '' ? $subject : (string) $tpl['subject'];
            $html      = (string) $tpl['html_body'];
            $preheader = (string) ($tpl['preheader'] ?? '');
        }
        if (trim($subject) === '' || trim($html) === '') {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => 'Email skipped: no subject or body configured'];
        }

        $address = \App\Services\Email\Marketing\SuppressionService::normalize((string) ($contact['email'] ?? ''));
        if ($address === '' || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => 'Email skipped: contact has no email address'];
        }
        if ((new \App\Services\Email\Marketing\SuppressionService())->isSuppressed($tenantId, $address)) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "Email skipped: {$address} is unsubscribed/suppressed"];
        }

        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "[TEST] Would email {$address}: {$subject}"];
        }

        $composer = new \App\Services\Email\Marketing\EmailComposer();
        $settings = $composer->settings($tenantId);
        if (! $settings['ready']) {
            log_message('warning', "[flow] send_email blocked for tenant #{$tenantId}: {$settings['reason']}");

            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => 'Email blocked: ' . $settings['reason']];
        }

        $token = \App\Services\Email\Marketing\EmailTracking::newToken();
        $mail  = $composer->compose($contact, $subject, $html, $preheader, $token, $settings['footer_text']);

        [$ok, $error] = \App\Services\Email\Marketing\EmailComposer::defaultTransport()->send(
            $settings['smtp'], $address, $mail['subject'], $mail['html'], $mail['text'], $mail['headers'],
            trim((string) ($d['reply_to'] ?? '')) ?: null,
            trim((string) ($d['from_name'] ?? '')) ?: null,
        );

        $emailId = (int) (new \App\Models\EmailModel())->setTenant($tenantId)->insert([
            'contact_id'     => (int) $contact['id'],
            'tracking_token' => $token,
            'direction'      => 'out',
            'from_email'     => $settings['smtp']['from_email'],
            'to_email'       => $address,
            'subject'        => mb_substr($mail['subject'], 0, 255),
            'status'         => $ok ? 'sent' : 'failed',
            'error'          => $ok ? null : mb_substr((string) $error, 0, 500),
            'sent_at'        => $ok ? date('Y-m-d H:i:s') : null,
        ], true);

        if (! $ok) {
            log_message('error', "[flow] send_email to {$address} failed: {$error}");

            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "Email to {$address} failed: {$error}"];
        }

        $composer->sendCopy(
            \App\Services\Email\Marketing\EmailComposer::defaultTransport(), $settings, $contact, $address,
            $subject, $html, $preheader, trim((string) ($d['from_name'] ?? '')) ?: null,
        );

        (new \App\Models\ActivityModel())->log($tenantId, 'email', 'contact', (int) $contact['id'], [
            'subject' => $mail['subject'],
            'body'    => 'Sent by an automation flow',
            'meta'    => ['email_id' => $emailId, 'to' => $address],
        ]);

        return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "Email sent to {$address}: {$mail['subject']}"];
    }

    /** create_task: open a CRM task on the flow's contact. */
    private function execCreateTask(array $node, array &$ctx): array
    {
        $d     = $node['data'] ?? [];
        $title = trim((string) ($d['title'] ?? 'Follow up'));

        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "[TEST] Would create task: {$title}"];
        }

        $tenantId  = (int) $ctx['tenantId'];
        $contactId = (int) $ctx['contact']['id'];
        $dueDays   = (int) ($d['due_in_days'] ?? 0);

        (new \App\Models\TaskModel())->setTenant($tenantId)->insert([
            'title'            => $title,
            'type'             => $d['type'] ?? 'todo',
            'priority'         => $d['priority'] ?? 'medium',
            'status'           => 'open',
            'due_at'           => $dueDays > 0 ? date('Y-m-d H:i:s', strtotime("+{$dueDays} days")) : null,
            'assigned_user_id' => ((int) ($d['assigned_user_id'] ?? 0)) ?: null,
            'related_type'     => 'contact',
            'related_id'       => $contactId,
        ]);

        return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "Task created: {$title}"];
    }

    /** update_field: set a whitelisted CRM field on the flow's contact. */
    private function execUpdateField(array $node, array &$ctx): array
    {
        $d     = $node['data'] ?? [];
        $field = (string) ($d['field'] ?? '');
        $value = $d['value'] ?? null;

        // Whitelist guards the column name (it is interpolated into SQL).
        $allowed = ['lifecycle_stage', 'status', 'owner_id', 'lead_score'];
        if (! in_array($field, $allowed, true)) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "Unknown field '{$field}' — skipped"];
        }
        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "[TEST] Would set {$field} → {$value}"];
        }

        $tenantId  = (int) $ctx['tenantId'];
        $contactId = (int) $ctx['contact']['id'];
        $db        = db_connect();
        $p         = $db->DBPrefix;
        $db->query(
            "UPDATE {$p}contacts SET {$field}=?, updated_at=? WHERE id=? AND tenant_id=?",
            [$value, date('Y-m-d H:i:s'), $contactId, $tenantId]
        );

        return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "{$field} → {$value}"];
    }

    /** create_deal: open a deal for the flow's contact in the default pipeline. */
    private function execCreateDeal(array $node, array &$ctx): array
    {
        $d     = $node['data'] ?? [];
        $title = trim((string) ($d['title'] ?? '')) ?: ('Deal — ' . ($ctx['contact']['name'] ?? 'New lead'));

        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "[TEST] Would create deal: {$title}"];
        }

        $tenantId  = (int) $ctx['tenantId'];
        $contactId = (int) $ctx['contact']['id'];
        $pipeline  = (new \App\Models\PipelineModel())->ensureDefault($tenantId);
        $stages    = (new \App\Models\PipelineStageModel())->forPipeline($tenantId, (int) $pipeline['id']);
        if (empty($stages)) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => 'No pipeline stages — skipped'];
        }

        (new \App\Models\DealModel())->setTenant($tenantId)->insert([
            'title'              => $title,
            'pipeline_id'        => (int) $pipeline['id'],
            'stage_id'           => (int) $stages[0]['id'],
            'primary_contact_id' => $contactId,
            'value_amount'       => (int) ($d['value_amount'] ?? 0),
            'status'             => 'open',
            'last_activity_at'   => date('Y-m-d H:i:s'),
        ]);

        return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "Deal created: {$title}"];
    }

    /** create_ticket: open a support ticket for the flow's contact. */
    private function execCreateTicket(array $node, array &$ctx): array
    {
        $d        = $node['data'] ?? [];
        $subject  = trim((string) ($d['subject'] ?? '')) ?: 'Support request';
        $priority = (string) ($d['priority'] ?? 'medium');

        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "[TEST] Would open ticket: {$subject}"];
        }

        $tenantId  = (int) $ctx['tenantId'];
        $contactId = (int) $ctx['contact']['id'];

        (new \App\Models\TicketModel())->setTenant($tenantId)->insert([
            'subject'    => $subject,
            'contact_id' => $contactId,
            'priority'   => $priority,
            'status'     => 'open',
            'source'     => 'whatsapp',
            'category'   => $d['category'] ?? null,
            'sla_due_at' => \App\Models\TicketModel::slaDueAt($priority),
        ]);

        return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "Ticket opened: {$subject}"];
    }

    private function execAssignAgent(array $node, array &$ctx): array
    {
        $userId = (int) ($node['data']['user_id'] ?? 0);

        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail' => "[TEST] Would assign to user #{$userId}"];
        }

        $convId = $this->getOrCreateConvId($ctx);

        if ($convId > 0 && $userId > 0) {
            $db = db_connect();
            $p  = $db->DBPrefix;
            $db->query(
                "UPDATE {$p}conversations SET assigned_user_id=?, updated_at=? WHERE id=?",
                [$userId, date('Y-m-d H:i:s'), $convId]
            );
        }
        return ['outcome' => 'advance', 'sourceHandle' => 'next',
                'detail' => "Assigned to user #{$userId}"];
    }

    /**
     * Hand the conversation off to a human agent.
     *
     * Picks the agent from node config (specific user) or, when none is set,
     * delegates to AgentRouter (tenant routing rules / least-loaded). Stamps
     * handoff_at so the inbox can surface "needs a human", and optionally stops
     * the flow (node.data.stop = true) so automation yields to the agent.
     */
    private function execHandoff(array $node, array &$ctx): array
    {
        $data     = $node['data'] ?? [];
        $tenantId = (int) $ctx['tenantId'];
        $userId   = (int) ($data['user_id'] ?? 0);

        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail' => '[TEST] Would hand off to a human agent'];
        }

        $convId   = $this->getOrCreateConvId($ctx);

        if ($convId <= 0) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail' => 'Handoff skipped — no conversation.'];
        }

        // No explicit agent → let the router choose via rules / least-loaded.
        if ($userId <= 0) {
            $tagIds    = [];
            $contactId = (int) ($ctx['contact']['id'] ?? 0);
            if ($contactId > 0) {
                foreach ((new ContactModel())->setTenant($tenantId)->getTagsFor($contactId) as $t) {
                    $tagIds[] = (int) ($t['id'] ?? 0);
                }
            }
            $routed = (new \App\Services\Inbox\AgentRouter())->route($tenantId, $convId, [
                'tag_ids' => array_filter($tagIds),
                'body'    => '',
            ]);
            $userId = (int) ($routed ?? 0);
        }

        $db = db_connect();
        $p  = $db->DBPrefix;
        $now = date('Y-m-d H:i:s', $this->now());

        // Assign (if resolved) + flag handoff + ensure the thread is open.
        if ($userId > 0) {
            $db->query(
                "UPDATE {$p}conversations SET assigned_user_id=?, handoff_at=?, status='open', updated_at=? WHERE id=?",
                [$userId, $now, $now, $convId]
            );
        } else {
            $db->query(
                "UPDATE {$p}conversations SET handoff_at=?, status='open', updated_at=? WHERE id=?",
                [$now, $now, $convId]
            );
        }

        $detail = $userId > 0
            ? "Handed off to user #{$userId}"
            : 'Handed off to inbox (no agent matched)';

        if (! empty($data['stop'])) {
            return ['outcome' => 'stopped', 'detail' => $detail . ' — flow stopped for human.'];
        }

        return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => $detail];
    }

    /**
     * Create a Razorpay payment link and send it to the contact over WhatsApp.
     *
     * Delivery is free-form text, so this node is only effective inside an open
     * 24h window — precede it with a window_check for strict policy compliance.
     */
    private function execSendPayment(array $node, array &$ctx): array
    {
        $data     = $node['data'] ?? [];
        $tenantId = (int) $ctx['tenantId'];
        $contact  = $ctx['contact'];
        $amount   = (float) ($data['amount'] ?? 0);
        $desc     = (string) ($data['description'] ?? 'Payment request');

        if ($amount <= 0) {
            throw new \RuntimeException('send_payment node has no amount configured.');
        }

        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "[TEST] Would request payment of ₹{$amount}"];
        }

        $convId  = $this->getOrCreateConvId($ctx);
        $service = new \App\Services\Commerce\PaymentLinkService();

        $link = $service->createLink($tenantId, $amount, [
            'contact_id'      => (int) ($contact['id'] ?? 0) ?: null,
            'conversation_id' => $convId ?: null,
            'description'     => $desc,
        ]);

        if ($ctx['apiClient'] === null) {
            $ctx['apiClient'] = $this->buildApiClient($tenantId);
        }
        $service->deliver($tenantId, $link, $contact['wa_number'], $ctx['apiClient'], $convId ?: null);

        return ['outcome' => 'advance', 'sourceHandle' => 'next',
                'detail' => "Sent payment link for ₹{$amount}"];
    }

    /**
     * Send a catalog product to the contact. Needs an open window + a connected
     * Meta catalog (integrations.type = 'whatsapp_catalog').
     */
    private function execSendProduct(array $node, array &$ctx): array
    {
        $data       = $node['data'] ?? [];
        $tenantId   = (int) $ctx['tenantId'];
        $contact    = $ctx['contact'];
        $retailerId = (string) ($data['retailer_id'] ?? '');

        if ($retailerId === '') {
            throw new \RuntimeException('send_product node has no product (retailer_id) configured.');
        }

        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "[TEST] Would send product {$retailerId}"];
        }

        $integration = (new \App\Models\IntegrationModel())->findActiveByType($tenantId, 'whatsapp_catalog');
        $catalogId   = $integration
            ? (string) ((json_decode($integration['config'] ?? '{}', true) ?: [])['catalog_id'] ?? '')
            : '';
        if ($catalogId === '') {
            throw new \RuntimeException('No Meta catalog connected for this tenant.');
        }

        if ($ctx['apiClient'] === null) {
            $ctx['apiClient'] = $this->buildApiClient($tenantId);
        }
        $body = (string) ($data['body'] ?? '');
        $ctx['apiClient']->sendProduct($contact['wa_number'], $catalogId, $retailerId, $body);

        return ['outcome' => 'advance', 'sourceHandle' => 'next',
                'detail' => "Sent product {$retailerId}"];
    }

    /**
     * Generate an AI reply from the conversation history and send it (free-form).
     * Needs an open 24h window; no-ops (advances) gracefully if AI is unavailable
     * or there's nothing to answer.
     */
    private function execAiReply(array $node, array &$ctx): array
    {
        $data         = $node['data'] ?? [];
        $tenantId     = (int) $ctx['tenantId'];
        $contact      = $ctx['contact'];
        $systemPrompt = (string) ($data['system_prompt'] ?? '');
        $maxTokens    = (int) ($data['max_tokens'] ?? 400);

        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => '[TEST] Would generate & send an AI reply'];
        }

        $convId = $this->getOrCreateConvId($ctx);
        if ($convId <= 0) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => 'AI reply skipped — no conversation.'];
        }

        // Plan metering: skip (don't fail the flow) when the monthly cap is hit.
        $usage = new \App\Services\AI\AiUsageService();
        if (! $usage->canUse($tenantId)) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => 'AI reply skipped — monthly AI limit reached.'];
        }

        $service  = new \App\Services\AI\AiReplyService($tenantId);
        $messages = (new MessageModel())->forConversation($tenantId, $convId, null, 20);
        $result   = $service->generate($systemPrompt, \App\Services\AI\AiReplyService::buildHistory($messages), $maxTokens);

        if (! ($result['success'] ?? false)) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => 'AI reply skipped: ' . ($result['error'] ?? 'unavailable')];
        }
        $usage->record($tenantId);

        if ($ctx['apiClient'] === null) {
            $ctx['apiClient'] = $this->buildApiClient($tenantId);
        }
        $send = $ctx['apiClient']->sendText($contact['wa_number'], $result['text']);

        (new MessageModel())->withoutTenantScope()->insert([
            'tenant_id'       => $tenantId,
            'contact_id'      => (int) ($contact['id'] ?? 0) ?: null,
            'conversation_id' => $convId,
            'direction'       => 'out',
            'type'            => 'text',
            'category'        => 'free_form',
            'body'            => $result['text'],
            'wa_message_id'   => $send['message_id'] ?? null,
            'status'          => ($send['success'] ?? false) ? 'sent' : 'failed',
            'billable'        => 0,
            'error'           => $send['error'] ?? null,
            'sent_at'         => ($send['success'] ?? false) ? date('Y-m-d H:i:s', $this->now()) : null,
        ]);

        return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => 'AI reply sent'];
    }

    /**
     * Send a published Meta WhatsApp Flow (native in-chat form). Needs an open
     * 24h window. The submission arrives later as an inbound nfm_reply and fires
     * the 'flow_response' trigger.
     */
    private function execSendFlow(array $node, array &$ctx): array
    {
        $data     = $node['data'] ?? [];
        $tenantId = (int) $ctx['tenantId'];
        $contact  = $ctx['contact'];
        $flowId   = (string) ($data['flow_id'] ?? '');

        if ($flowId === '') {
            throw new \RuntimeException('send_flow node has no flow_id configured.');
        }

        if ($ctx['dryRun'] ?? false) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "[TEST] Would send flow {$flowId}"];
        }

        if ($ctx['apiClient'] === null) {
            $ctx['apiClient'] = $this->buildApiClient($tenantId);
        }

        // Token correlates the later submission back to this contact/flow run.
        $flowToken = 'ft_' . $tenantId . '_' . (int) ($contact['id'] ?? 0) . '_' . substr(md5((string) $this->now() . $flowId), 0, 10);

        $ctx['apiClient']->sendFlow(
            $contact['wa_number'],
            $flowId,
            $flowToken,
            (string) ($data['cta_text'] ?? 'Open'),
            (string) ($data['body'] ?? ''),
            (string) ($data['screen'] ?? ''),
        );

        return ['outcome' => 'advance', 'sourceHandle' => 'next', 'detail' => "Sent flow {$flowId}"];
    }

    private function execWebhookCall(array $node, array &$ctx): array
    {
        $url     = $node['data']['url'] ?? '';
        $contact = $ctx['contact'];

        if (empty($url)) {
            throw new \RuntimeException("webhook_call node has no URL configured.");
        }

        // SSRF guard: never call internal/reserved addresses from the worker.
        if (! \App\Services\Security\UrlGuard::isSafePublicUrl($url)) {
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'detail' => 'Webhook skipped — URL is not a public http(s) endpoint.'];
        }

        $payload = [
            'contact'     => ['id' => $contact['id'], 'wa_number' => $contact['wa_number'],
                              'name' => $contact['name'] ?? null, 'email' => $contact['email'] ?? null],
            'flow_run_id' => $ctx['runId'],
            'state'       => $ctx['state'],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => json_encode($payload),
            CURLOPT_HTTPHEADER        => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT_MS        => 10000,
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_SSL_VERIFYPEER    => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err || $code >= 300) {
            throw new \RuntimeException("Webhook to {$url} failed: " . ($err ?: "HTTP {$code}"));
        }

        return ['outcome' => 'advance', 'sourceHandle' => 'next',
                'detail' => "Webhook OK (HTTP {$code})"];
    }

    // ------------------------------------------------------------------
    // Control node executors
    // ------------------------------------------------------------------

    private function execDelay(array $node, array &$ctx): array
    {
        $data  = $node['data'] ?? [];
        $value = max(0, (int) ($data['value'] ?? 0));
        $unit  = $data['unit']  ?? 'seconds';
        $secs  = $value * (self::DELAY_UNIT_SECONDS[$unit] ?? 1);

        // ── Dry-run: collapse delay to immediate advance ─────────────
        if ($ctx['dryRun'] ?? false) {
            $nextEdge   = $this->findEdge($ctx['graph'], $node['id'], 'next');
            $nextNodeId = $nextEdge['target'] ?? null;
            return ['outcome' => 'advance', 'sourceHandle' => 'next',
                    'next_node_id' => $nextNodeId,
                    'detail' => "[TEST] Delay {$value}{$unit} — skipped in test mode"]
                + ($nextNodeId !== null ? [] : ['outcome' => 'completed']);
        }

        // Find the node AFTER this delay (the engine resumes there)
        $nextEdge   = $this->findEdge($ctx['graph'], $node['id'], 'next');
        $nextNodeId = $nextEdge['target'] ?? null;
        $runAt      = $ctx['now'] + $secs;

        return [
            'outcome'      => 'delayed',
            'run_at'       => $runAt,
            'next_node_id' => $nextNodeId,
            'detail'       => "Delay {$value}{$unit} — resume at " . date('Y-m-d H:i:s', $runAt),
        ];
    }

    private function execCondition(array $node, array &$ctx): array
    {
        $data     = $node['data'] ?? [];
        $type     = $data['type']     ?? 'contact_field';
        $field    = $data['field']    ?? '';
        $operator = $data['operator'] ?? 'equals';
        $expected = $data['value']    ?? '';
        $contact  = $ctx['contact'];

        $actual = match($type) {
            'contact_field' => (string) ($contact[$field] ?? ''),
            'custom_field'  => (string) ((new ContactFieldValueModel())
                ->getForContact((int) $contact['id'])[$field] ?? ''),
            'state'         => (string) ($ctx['state'][$field] ?? ''),
            'tag'           => $this->contactHasTag((int) $contact['id'], (int) ($data['tag_id'] ?? 0)),
            default         => '',
        };

        $result = match($operator) {
            'equals'       => (string) $actual === (string) $expected,
            'not_equals'   => (string) $actual !== (string) $expected,
            'contains'     => str_contains((string) $actual, (string) $expected),
            'starts_with'  => str_starts_with((string) $actual, (string) $expected),
            'ends_with'    => str_ends_with((string) $actual, (string) $expected),
            'is_empty'     => empty($actual),
            'is_not_empty' => ! empty($actual),
            'has_tag'      => (bool) $actual,
            'not_has_tag'  => ! (bool) $actual,
            default        => false,
        };

        $handle = $result ? 'true' : 'false';
        return ['outcome' => 'branched', 'sourceHandle' => $handle,
                'detail' => "{$type}:{$field} {$operator} '{$expected}' → {$handle}"];
    }

    /**
     * Window check node — pure read.
     * Fix #4: empty conversation (no messages ever) → closed.
     */
    private function execWindowCheck(array $node, array &$ctx): array
    {
        $convArr = $ctx['conversation'];
        // Fix #4: no conversation array → window is closed
        $isOpen  = ! empty($convArr)
            && $ctx['windowSvc']->isOpenForConversation($convArr);

        $handle = $isOpen ? 'open' : 'closed';
        return ['outcome' => 'branched', 'sourceHandle' => $handle,
                'detail' => $isOpen ? 'Window open' : 'Window closed (or no conversation)'];
    }

    // ------------------------------------------------------------------
    // Graph helpers
    // ------------------------------------------------------------------

    public function findNode(array $graph, string $nodeId): ?array
    {
        foreach ($graph['nodes'] ?? [] as $node) {
            if (($node['id'] ?? '') === $nodeId) {
                return $node;
            }
        }
        return null;
    }

    public function findEdge(array $graph, string $fromNodeId, string $sourceHandle = 'next'): ?array
    {
        foreach ($graph['edges'] ?? [] as $edge) {
            if ($edge['source'] === $fromNodeId
                && ($edge['sourceHandle'] ?? 'next') === $sourceHandle) {
                return $edge;
            }
        }
        return null;
    }

    public function findTriggerNode(array $graph): ?array
    {
        foreach ($graph['nodes'] ?? [] as $node) {
            if (in_array($node['type'] ?? '', FlowTriggers::all(), true)) {
                return $node;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    private function buildApiClient(int $tenantId): ProviderAdapter
    {
        $wabaModel = new WabaAccountModel();
        $account   = $wabaModel->findActive($tenantId);

        if (! $account) {
            throw new \RuntimeException("No WABA configured for tenant #{$tenantId}.");
        }

        return $wabaModel->buildAdapter(is_array($account) ? $account : (array) $account);
    }

    /** Find or create the conversation row, cache it in $ctx. */
    private function getOrCreateConvId(array &$ctx): int
    {
        if (! empty($ctx['conversation']['id'])) {
            return (int) $ctx['conversation']['id'];
        }
        $convRow            = (new ConversationModel())
            ->findOrCreate($ctx['tenantId'], $ctx['contact']['wa_number']);
        $ctx['conversation'] = $convRow;
        return (int) $convRow['id'];
    }

    private function contactHasTag(int $contactId, int $tagId): bool
    {
        if ($tagId === 0) return false;
        $p = db_connect()->DBPrefix;
        return db_connect()->table('contact_tags')
            ->where('contact_id', $contactId)
            ->where('tag_id', $tagId)
            ->countAllResults() > 0;
    }

    private function writeLog(
        FlowRunLogModel $logModel,
        int $runId,
        string $nodeId,
        string $nodeType,
        string $result,
        ?string $detail
    ): void {
        $logModel->insert([
            'flow_run_id' => $runId,
            'node_id'     => $nodeId,
            'node_type'   => $nodeType,
            'result'      => $result,
            'detail'      => $detail,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    private function failRun(
        FlowRunModel $runModel,
        FlowRunLogModel $logModel,
        int $runId,
        int $tenantId,
        string $nodeId,
        string $nodeType,
        string $reason
    ): void {
        $runModel->setTenant($tenantId)->update($runId, [
            'status'       => 'failed',
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
        $this->writeLog($logModel, $runId, $nodeId, $nodeType, 'failed', $reason);
        log_message('error', "FlowEngine: run #{$runId} failed — {$reason}");
    }
}
