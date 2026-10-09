# CLAUDE_FLOW.md — TravelPilot Sprint 4: Flow / Automation Engine

> Module spec. Read this in full AND re-read CLAUDE.md §1 (WhatsApp policy backbone) and §8 (flow engine design) before writing code.
> This is the most complex and highest-value module in TravelPilot. Build it in the phases below, in order. Do NOT start Sprint 5 (Meta Lead Ads ingestion) work.

---

## 0. What Sprint 4 delivers

A visual automation builder where a tenant designs a **flow** (a graph of trigger → actions → delays → conditions), activates it, and contacts automatically move through it — with **every message send obeying the 24-hour window rules from Sprint 2** and **every template send billed via the Sprint 3 BillableComputer**.

Three pillars:
1. **Durable job queue** — DB `jobs` table + a cron-driven `spark flow:work` worker. This is the engine that survives restarts and executes delayed steps.
2. **Flow execution engine** — `FlowEngine` walks the graph per contact, persisting state in `flow_runs`.
3. **Visual builder** — full `@xyflow/react` drag-drop canvas.

Reuse, do not re-implement: `WindowService`, `BillableComputer`, `CloudApiClient` (incl. `WHATSAPP_MOCK_MODE`), the template-send path from `CampaignSender`, `VariableResolver`, `ContactDedupeService`.

---

## 1. NON-NEGOTIABLE: window gating inside flows

This is the same backbone as CLAUDE.md §1, now applied to automated sends. Get it wrong and automated flows become a ban vector.

- **`send_freeform` / `send_media` nodes MUST call `WindowService::assertFreeFormAllowed()` before sending.** If the window is closed: log a `blocked_by_policy` message row (direction=out, status=failed, error set), and route to the node's optional `window_closed` fallback edge if one exists, else **stop the run cleanly**. NEVER silently send. NEVER crash.
- **`send_template` nodes** may run regardless of window (templates are always allowed), but only `meta_status='approved'` templates, and `billable` is computed via `BillableComputer::compute($template.category, $windowOpen)` — exactly as in Sprint 3.
- The builder MUST surface a **design-time warning** when a `send_freeform`/`send_media` node is not guaranteed to run inside an open window (i.e. not downstream of an inbound-type trigger or a `window_check` node on its open branch).

---

## 2. Database schema

All tables carry `tenant_id`, timestamps; flows/flow_runs soft-delete.

### `jobs` (the generic durable queue — also the Sprint 1/3 port target, see Phase 5)
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| tenant_id | INT FK | |
| type | VARCHAR(50) | `flow_start`, `flow_resume`, (later) `campaign_send`, `lead_import` |
| payload | JSON | e.g. `{flow_run_id}` or `{flow_id, contact_id, trigger_context}` |
| run_at | DATETIME | due time (PHP-computed, UTC — one clock, like WindowService) |
| status | ENUM(pending, processing, done, failed) | |
| attempts | INT default 0 | |
| max_attempts | INT default 5 | |
| locked_at | DATETIME nullable | |
| locked_by | VARCHAR(64) nullable | worker id |
| last_error | TEXT nullable | |
| INDEX (status, run_at) | | the worker's hot query |

### `flows`
| Column | Type | Notes |
|---|---|---|
| id, tenant_id | | |
| name | VARCHAR(255) | |
| status | ENUM(draft, active, paused) default draft | only `active` flows fire |
| trigger_type | ENUM(lead_created, tag_added, form_submitted, meta_lead_received, keyword_reply, inbound_message) | |
| trigger_config | JSON nullable | e.g. `{tag_id}` for tag_added; `{keywords:[],match:exact\|contains}` for keyword_reply; `{form_id}` for form_submitted |
| graph | JSON | `{nodes:[{id,type,data}], edges:[{id,source,target,sourceHandle}]}` |
| reentry_policy | ENUM(once, always) default once | `once` = a contact with an existing run for this flow is not re-enrolled |
| version | INT default 1 | bumped on each save |
| stats | JSON | `{started,completed,waiting,stopped,failed}` |

### `flow_runs` (per-contact journey state)
| Column | Type | Notes |
|---|---|---|
| id, tenant_id | | |
| flow_id | INT FK | |
| contact_id | INT FK | |
| current_node_id | VARCHAR(64) nullable | node the run is at / about to execute |
| status | ENUM(running, waiting, completed, stopped, failed) | `waiting` = parked on a delay |
| state | JSON | run-scoped variables / context (trigger payload, etc.) |
| graph_snapshot | JSON | **copy of flows.graph captured at start** — in-flight runs are immune to later edits |
| next_run_at | DATETIME nullable | when a waiting run resumes (mirrors the job's run_at) |
| entered_at, completed_at | DATETIME | |
| INDEX (flow_id, contact_id) | | reentry check |

### `flow_run_logs` (execution audit — invaluable for debugging)
| Column | Type | Notes |
|---|---|---|
| id, flow_run_id | | |
| node_id | VARCHAR(64) | |
| node_type | VARCHAR(50) | |
| result | VARCHAR(50) | executed / branched_true / blocked_window / sent / failed / delayed |
| detail | TEXT nullable | |
| created_at | | |

---

## 3. Node catalog

**Trigger nodes** (exactly one per flow; type must equal `flows.trigger_type`):
`lead_created`, `tag_added`, `form_submitted`, `meta_lead_received`, `keyword_reply`, `inbound_message`.

**Action nodes:**
- `send_template` — pick approved template + per-node variable mapping (reuse `VariableResolver` + fallback chain). Billable via `BillableComputer`.
- `send_freeform` — text; **window-gated** (§1).
- `send_media` — image/document/video; **window-gated** (§1).
- `add_tag` / `remove_tag` — tag id from node data.
- `update_status` — set contact status.
- `assign_agent` — set conversation `assigned_user_id`.
- `webhook_call` — POST to a configured URL with contact+run context; retryable via the jobs queue.

**Control nodes:**
- `delay` — wait N minutes/hours/days. Parks the run (`waiting`) and schedules a `flow_resume` job at `now + delay`. Keep delay duration as seconds internally (named, not magic).
- `condition` — branch on a field/tag/status/window state. Outgoing handles: `true` / `false`.
- `window_check` — explicit branch on window state. Outgoing handles: `open` / `closed`. (Use this upstream of `send_freeform` to design a compliant fallback to a template.)

Each node's outgoing edge(s) use `sourceHandle` to distinguish branches (`true`/`false`, `open`/`closed`, default `next`).

---

## 4. Execution engine

### Trigger firing (fast, non-blocking)
`FlowTriggerService::fire(string $triggerType, int $tenantId, int $contactId, array $context = [])`:
- Finds `active` flows for the tenant whose `trigger_type` matches AND whose `trigger_config` matches the context (tag id, keyword, form id).
- For each match, respects `reentry_policy` (`once` → skip if a non-terminal or completed run exists for (flow, contact)).
- **Enqueues a `flow_start` job** (does NOT execute inline). This keeps webhooks/requests fast.

Call `FlowTriggerService::fire()` from these existing code paths (lightweight additions):
| Trigger | Fired from |
|---|---|
| lead_created | ContactDedupeService / contact create path (all sources) |
| tag_added | tag assignment path |
| form_submitted | FormHandler (replaces the Sprint 1 no-op placeholder) |
| meta_lead_received | contact create where `source=meta_lead_ads` (real producer = Sprint 5 Lead Ads webhook; testable now via manual/import create with that source) |
| keyword_reply | WebhookService inbound text handler (match against active keyword_reply flows) |
| inbound_message | WebhookService inbound handler (any inbound) |

### The worker: `php spark flow:work`
Cron-driven (`* * * * * cd /path/backend && php spark flow:work`). Each invocation:
1. Claims up to `BATCH` due jobs atomically:
   `UPDATE jobs SET status='processing', locked_at=NOW(), locked_by=:wid WHERE status='pending' AND run_at <= NOW() ORDER BY run_at LIMIT :n` — then select the claimed rows by `locked_by`. (Atomic claim = no double-processing across overlapping cron runs.)
2. For each job: dispatch by `type` to a handler.
   - `flow_start` → create `flow_run` (snapshot graph), set current node to the trigger's next node, call `FlowEngine::advance()`.
   - `flow_resume` → load `flow_run`, call `FlowEngine::advance()`.
3. On handler success → job `done`. On exception → `attempts++`; if `attempts < max_attempts` reschedule with **exponential backoff** (`run_at = now + base^attempts`), status back to `pending`; else `failed` with `last_error`.
4. Bounded: process at most `BATCH` jobs then exit (cron-friendly). Releases stale locks (locked_at older than a timeout) back to pending at start.

### `FlowEngine::advance(FlowRun $run)`
Walk the `graph_snapshot` from `current_node_id`:
- Loop, executing nodes, following the correct outgoing edge, until:
  - a `delay` node → set `status=waiting`, `current_node_id = delay's next`, `next_run_at = now+delay`, enqueue `flow_resume` job at that time, **return**.
  - no outgoing edge / explicit end → `status=completed`, **return**.
  - a `send_freeform`/`send_media` node with **closed window and no `window_closed` fallback** → log `blocked_window`, `status=stopped`, **return**.
  - a node throws → bubble to the worker (job retry).
- **Loop guard:** cap transitions per `advance()` call (e.g. 100). Exceeding it → `status=failed`, log — protects against a cyclic/misconfigured graph.
- Write a `flow_run_logs` row per node executed.
- Lock the `flow_run` (it's effectively single-owned via the job claim, but guard against concurrent resume).

---

## 5. Build phases (in order)

- **Phase 1 — Jobs queue:** `jobs` migration, `JobModel`, atomic claim, `JobDispatcher::dispatch($type,$payload,$runAt)`, `spark flow:work` with backoff + stale-lock recovery. Tests first (this is the durability core).
- **Phase 2 — Flow data model + engine (backend):** migrations, models, `FlowEngine`, node executors, `FlowTriggerService`, graph validation on save. All node types. Window gating wired to real `WindowService`. Reuse `CloudApiClient` mock mode for sends.
- **Phase 3 — Trigger wiring:** add `FlowTriggerService::fire()` calls to the existing code paths in the table above.
- **Phase 4 — Visual builder (frontend):** full `@xyflow/react` canvas — node palette, drag-drop, branch handles (`true/false`, `open/closed`), per-node config panels, save (POST graph), client + server graph validation with warnings (esp. the §1 free-form-window warning), activate/pause toggle, a runs list per flow.
- **Phase 5 — Port earlier sprints onto the queue (do last; may spill to a 4.5 if the sprint is heavy):** replace Sprint 1 import continuation and Sprint 3 `CampaignSender::processBatch()` controller calls with `JobDispatcher::dispatch('lead_import'|'campaign_send', …)`. The batch method bodies are unchanged — they become job handlers. This realizes the seams those sprints deliberately left. If time-constrained, defer Phase 5; do not rush it and risk regressing working features.

---

## 6. Graph validation rules (on save)

- Exactly one trigger node; its type == `flows.trigger_type`.
- Every non-trigger node reachable from the trigger (warn on orphans).
- `send_template` references a template that exists, is this tenant's, and is `approved` (warn if not approved — block activation).
- Branch nodes (`condition`, `window_check`) have both handles connected (warn if a branch dangles).
- **Free-form window warning** (§1): `send_freeform`/`send_media` not guaranteed inside an open window.
- A flow can only move `draft`/`paused` → `active` if it passes validation with no blocking errors.

---

## 7. PHPUnit tests

Must-haves marked ✅.

- `JobQueueTest` ✅ — due jobs claimed; not-yet-due skipped; atomic claim prevents double-pick (simulate two claims); failure increments attempts + backoff reschedule; max_attempts → failed; stale lock reclaimed.
- `FlowEngineTest` ✅ — trigger start creates run + snapshots graph; action advances; `delay` parks run as `waiting` + enqueues `flow_resume` at correct run_at; resume continues from saved node; end → `completed`; `condition` true/false branches; `window_check` open/closed branches.
- `FlowWindowGateTest` ✅ — `send_freeform` with **closed** window logs `blocked_window` and does NOT send; with `window_closed` fallback edge → routes there; with **open** window → sends (mock) and advances. `send_template` sends regardless of window with correct billable.
- `FlowReentryTest` — `once` policy blocks a second concurrent enrollment; `always` allows it.
- `FlowTriggerServiceTest` — only `active` flows enqueue; `paused`/`draft` don't; keyword `exact` vs `contains` matching; `tag_added` config id match; reentry respected.
- `FlowLoopGuardTest` — cyclic graph hits the transition cap and fails cleanly (no infinite loop).
- (Phase 5) `JobHandlerPortTest` — `campaign_send`/`lead_import` job handlers produce identical results to the Sprint 1/3 direct calls.

---

## 8. Local testing (with WHATSAPP_MOCK_MODE=true)

Prove the engine end-to-end without real Meta:
1. Build a flow: `lead_created` → `send_template` (approved, mock) → `delay 60s` → `add_tag` → `send_freeform`.
2. Create a contact → confirm a `flow_start` job appears, run `spark flow:work`, confirm the template "sent" (mock) + run parked `waiting` with `next_run_at ≈ now+60s` + a `flow_resume` job queued.
3. Run `flow:work` before the delay elapses → run untouched. After it elapses → run resumes, tag added.
4. Set the contact's conversation window closed, let the run reach `send_freeform` → confirm `blocked_window` logged, nothing sent, run stops (or follows fallback).
5. Re-trigger the same contact under `reentry_policy=once` → no second run.
6. Kill the worker mid-flow, restart → run resumes from saved state (durability proof).

Allow delay values in **seconds** for testing so you don't wait hours. The 24h window math stays as-is.

---

## 9. Out of scope (Sprint 4)

- Meta Lead Ads webhook ingestion (Sprint 5) — only the `meta_lead_received` trigger plumbing is built here.
- Campaign scheduling UI (the `scheduled_at` field) — wire the scheduler here only if Phase 5 is comfortably done; otherwise Sprint 5+.
- SSE/real-time inbox push (Sprint 7).
- A/B branches, goal/conversion tracking, per-node analytics dashboards — future.

---

## 10. Definition of done

On a clean local install with mock mode: a tenant can visually build a multi-node flow with a delay and a branch, activate it, have a new lead auto-enrolled, watch the worker advance it across a delay durably, see every send respect the window rules and bill correctly, and inspect the run via `flow_run_logs` — with `JobQueueTest`, `FlowEngineTest`, and `FlowWindowGateTest` green.
