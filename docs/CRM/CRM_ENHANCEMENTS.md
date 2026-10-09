# TravelPilot CRM — Enhancement Spec (Phase G →)

> Build spec for Claude Code. Continues the A–F phase convention. This is **gap-closing**, not a
> rebuild — the CRM core (accounts, contacts, deals, pipelines, tickets, custom objects, timeline,
> CPQ, flows) is already shipped. We are filling the gaps documented in `CRM_CAPABILITIES.md §8`,
> prioritized per §9, plus a few high-value items from the sales-CRM reference.
>
> **Companion docs (authoritative, read first):** `CRM_ARCHITECTURE.md`, `CRM_CAPABILITIES.md`.
> **Method:** plan → approve → build → tests green → commit. One phase = one checkpoint.

---

## ⛔ NON-NEGOTIABLE GUARDRAIL — DO NOT TOUCH THE WHATSAPP MODULE

Every change below is **additive to the CRM side only**. The WhatsApp module must remain byte-for-byte
unaffected.

**Do NOT modify:**
- The WhatsApp **send pipeline** or the **24-hour window check**.
- The **shared inbox** and conversation UI.
- Tables `conversations`, `messages`, `templates`, `campaigns`, `waba_accounts` — treat as **READ-ONLY** from all enhancement code.
- The Meta WhatsApp Cloud API client and webhook handlers.
- **Existing** flow triggers/actions and their node-execution logic.

**Allowed (additive only):**
- Register **new** flow triggers (e.g. `task_due`) and **new** CRM actions **without** altering existing node handlers.
- **Read** from the `activities` timeline (which already mirrors WhatsApp messages) for scoring/engagement — never write to messaging tables.
- For any outbound over WhatsApp (quote send, task reminders), **reuse existing actions** (`send_template` / `send_media` / `send_freeform`) via the flow engine — **zero new WhatsApp code**.
- Add new CRM tables, services, API routes, and React screens.

**Regression gate:** after every phase, run the full backend suite (currently **413 green**). All
existing WhatsApp/flow tests must stay green — that is the proof the WhatsApp module is untouched.

---

## Priority order (per `CRM_CAPABILITIES.md §9`)

1. **Phase G — Productivity Layer** (saved views, filters, bulk actions, global search, import/export, dedupe/merge, recycle bin)
2. **Phase H — Intelligence & Routing** (lead scoring engine + tiers, assignment rules, rotting/stale auto-reset, task_due reminders)
3. **Phase I — Reporting & Forecasting** (configurable dashboards, report widgets, forecasting/targets, leaderboard, export)
4. **Phase J — Close the Sales Loop** (quote PDF + send, product picker, price books, multi-currency display)
5. **Phase K — Governance** (audit log, plan-limit enforcement, teams/permissions)
6. **Phase L — Deferred / decide first** (email channel, calendar, @mentions, AI call scripts, web-form builder)

Build top-down. Don't start a phase until the previous one is green and committed.

---

## Phase G — Productivity Layer

**Goal:** make the CRM a fast daily driver — the single biggest leverage per §9.

| # | Item | What to build |
|---|---|---|
| G1 | **Saved Views + Filters** | Implement `saved_views` (schema in ARCH §4.16): per `entity_type` (contact/account/deal/ticket/custom_object) with `filters(json)`, `columns(json)`, `sort`, `is_shared`. Generic filter engine (field op value, AND/OR). UI: filter bar + "Save view" + shared/personal view switcher on every list. |
| G2 | **Bulk Actions** | Multi-select rows → bulk assign owner, change status/stage/lifecycle, add/remove tag, delete (soft). One bulk endpoint per entity, tenant-scoped, audited. |
| G3 | **Global Search** | Search service + `GET /crm/search?q=` across contacts, accounts, deals, tickets, custom records (name/phone/email/company/number). Header search box, grouped results. **Read-only** over messaging. |
| G4 | **CSV Import/Export (all objects)** | Generalize the existing contacts importer to accounts, deals, custom-object records. Column-mapping step, validation report, dedupe-on-import. Export current (filtered) view to CSV. |
| G5 | **Duplicate Detect + Merge** | Dedupe for contacts/accounts on phone/email/domain. "Find duplicates" screen; merge keeps primary, re-points associations/activities/deals, soft-deletes the loser. |
| G6 | **Recycle Bin** | UI + restore endpoint over existing `deleted_at` soft-deletes (contacts/accounts/deals/tickets/records). Restore re-links; permanent purge is admin-only via a job. |

**Tests:** filter engine, bulk ops authorization + tenant isolation, merge re-pointing, import validation, restore integrity.

---

## Phase H — Intelligence & Routing

**Goal:** make the pipeline run itself.

| # | Item | What to build |
|---|---|---|
| H1 | **Lead Scoring Engine** | Configurable, per-tenant scoring rules → write to existing `contacts.lead_score` (0–100). Signals: lifecycle_stage, source quality, has-email/has-account, linked-deal value band, **engagement** (count of `whatsapp_in` from the `activities` timeline — read-only), task-completion rate, **recency decay**. Derive `score_tier`: 🔥 Hot ≥65, ☀️ Warm 40–64, ❄️ Cold <40. Show badge on lists, contact page, Kanban cards. Store the breakdown so the contact page can show **why** a lead is Hot. Recalc on activity insert / stage change / nightly decay job (idempotent, tenant-aware). Weights editable in Settings (gate: Pro). |
| H2 | **Assignment Rules / Round-Robin** | Generalize the inbox routing pattern to **deals & tickets**. Strategies: round-robin, team-wise, location-wise, product/campaign-wise, priority-wise, manual. Rep **capacity threshold** (default 50 open). Auto-assign on create/capture; log to timeline. |
| H3 | **Rotting / Stale Auto-Reset** | Enforce `pipeline_stages.rotting_days`: nightly job flags idle deals (visual "rotting" tint on Kanban) and reroutes contacts stuck in early lifecycle (lead/MQL) > N days back to a queue/owner. Idempotent; can fire a flow (WhatsApp via **existing** action). |
| H4 | **`task_due` Trigger (reminders)** | The deferred trigger. Cron scans tasks with `reminder_at`/`due_at` matured → fires `task_due` flow trigger. Idempotent (`reminder_sent` flag, no double-fire). Reminder send **reuses existing** `send_template` — no new WhatsApp code. Registers as a NEW trigger; existing flow logic untouched. |
| H5 | **SLA escalation (tickets)** | Business-hours-aware SLA + escalation step on breach (reassign / notify) building on existing `sla_due_at`. Reuse working-hours config. |

**Tests:** score math + tier thresholds, decay job idempotency, round-robin fairness + capacity cap, rotting detection, `task_due` no-double-fire, SLA breach/escalation.

---

## Phase I — Reporting & Forecasting

**Goal:** management visibility beyond the single fixed dashboard.

| # | Item | What to build |
|---|---|---|
| I1 | **Configurable Dashboards** | `dashboards` + `dashboard_widgets` (ARCH §4.18). Multiple dashboards per tenant, date-range filter, optional 30s auto-refresh. |
| I2 | **Report Builder Widgets** | Widget types: **KPI card, bar, line, pie, table, funnel**. Pick metric + dimension + date range + chart type → save as widget. |
| I3 | **Forecasting + Targets/Quotas** | Weighted pipeline forecast (Σ deal value × stage.probability). `sales_targets` per user per period; attainment % (actual / target / weighted forecast). Conversion-funnel + sales-velocity reports. |
| I4 | **Leaderboard** | Ranked composite: conversions + qualified + won revenue + follow-up discipline; date-ranged. |
| I5 | **Export** | CSV + **PDF** export of any report/dashboard view. |

**Tests:** widget aggregation correctness, forecast weighting, target attainment math, export integrity.

---

## Phase J — Close the Sales Loop

**Goal:** finish the quote-to-cash path. Quotes/CPQ already exist (draft→sent→accepted) — this adds the missing output + tooling.

| # | Item | What to build |
|---|---|---|
| J1 | **Quote PDF + Send** | Server-side render the existing quote snapshot to a branded PDF; store it. **Send over WhatsApp** via existing `send_media` action and over email (Phase L channel if/when built). No quote-system rebuild — just PDF + delivery. |
| J2 | **Product Catalog Picker** | Replace manual line-item entry with a product picker on the deal/quote line items (table already supports `product_id`). Pulls price, tax, unit from `products`. |
| J3 | **Price Books** | Per-tenant price lists (product → price by book/currency); deals select a price book. |
| J4 | **Multi-currency display** | Per-tenant default currency + per-deal override (currency code already stored; money in paise). Display formatting only — **no live FX**. Never mix currencies on one screen. |

**Tests:** PDF render snapshot, send reuses existing action (mock), picker pricing math, price-book resolution.

---

## Phase K — Governance

**Goal:** trust, limits, and team structure.

| # | Item | What to build |
|---|---|---|
| K1 | **Audit Log** | Record CRM mutations (create/update/delete/status/assignment/export) with actor, entity, before/after, IP, timestamp. Filterable, paginated. |
| K2 | **Plan-limit Enforcement** | Wire the **defined-but-not-enforced** CRM limits (deals, pipelines, custom_objects, seats, dashboards) to Razorpay plan tiers (ARCH §7). Gate, don't break, on limit hit. |
| K3 | **Teams + Permissions** | `teams` + `team_members` (ARCH §4.17). Field-/record-level visibility beyond role+owner. |

**Tests:** audit completeness on each mutation type, limit gating per tier, team visibility scoping.

---

## Phase L — Deferred (confirm scope before building)

These are larger surfaces or product decisions — **do not auto-build**. Flagged for a decision.

- **Email as a 2-way channel** + email logging (today WhatsApp-only). Net-new channel; sizeable. Email is separate from WhatsApp, so it won't affect the WhatsApp module — but confirm priority.
- **Calendar / meeting scheduling** (native calendar).
- **@mentions / internal collaboration** on records.
- **AI Call Scripts + Qualification Questions** (reference "coming soon"). Separate AI surface; needs product decision before scoping.
- **Web-form drag-and-drop builder** (beyond current default fields).

---

## Decisions to confirm before kickoff

1. **Build order** — recommend exactly as listed: **G → H → I → J → K**, L deferred. (Matches your §9.) ✅ default unless you say otherwise.
2. **Multi-currency** — recommend per-tenant default + per-deal override, **display-only, no FX**. ✅ default.
3. **Lead-score tiers** — recommend Hot ≥65 / Warm 40–64 / Cold <40 with 🔥☀️❄️ badges (mirrors the sales-CRM reference). ✅ default.
4. **Email channel & AI call scripts** — recommend **defer** (Phase L). Confirm.

---

## Definition of Done (every phase)

- [ ] Works in **both** SaaS (multi-tenant) and self-hosted (license) modes.
- [ ] Tenant-isolated via `BaseModel` (fail-closed); no cross-tenant access.
- [ ] Migrations + seeders included and reversible.
- [ ] API authorized server-side by role + tenant.
- [ ] React UI matches the existing dark theme; loading/empty/error states handled.
- [ ] PHPUnit added/updated; **full suite green (≥413)** — proving WhatsApp module untouched.
- [ ] Tier-gated where it should be a paid upgrade.
- [ ] CRM mutation logged to audit (once K1 exists).
- [ ] Conventional Commit + one-line phase summary.
