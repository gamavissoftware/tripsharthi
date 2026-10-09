# TravelPilot CRM — Capabilities (As-Built)

> A complete inventory of what the CRM module **currently does** — data model, features, APIs, screens, automation, and WhatsApp integration. Use this to compare against your requirements and scope enhancements.
> Status: all 6 build phases shipped. 413 backend tests green. Branch `feat/aisensy-parity-p1-p4`.
> Companion docs: `CRM_ARCHITECTURE.md` (design/intent).

---

## 1. What the CRM is

A **WhatsApp-native, multi-industry CRM** built on the existing multi-tenant platform. It does three jobs at once, all threaded with the WhatsApp conversation:

- **Sell** — leads → contacts → accounts → deals on a kanban pipeline → quotes.
- **Support** — tickets with SLA, resolved over WhatsApp (CSAT).
- **Model anything** — custom objects per industry (Properties, Patients, Policies…), one-click templates.

Everything is **multi-tenant** (every row carries `tenant_id`, scoped fail-closed), soft-deleted, and automatable through the existing durable **flow engine**.

---

## 2. Data model (CRM tables)

| Table | Purpose | Key columns |
|---|---|---|
| `accounts` | Companies / organizations | name, domain, industry, type, owner_id, address, annual_revenue, employee_count, parent_account_id |
| `contacts` *(extended)* | People (lead = contact from msg #1) | + account_id, owner_id, job_title, **lifecycle_stage**, lead_score, phone_secondary (on top of wa_number, name, email, status, source, tags) |
| `activities` | Immutable, polymorphic **timeline** | type, subject, body, related_type/id, actor_user_id, meta(json), occurred_at |
| `notes` | Editable, pinnable annotations | body, related_type/id, is_pinned, created_by |
| `tasks` | Assignable work items | title, type, status, priority, due_at, reminder_at, assigned_user_id, related_type/id |
| `pipelines` / `pipeline_stages` | Configurable deal workflows | name, is_default; stage: name, position, probability, is_won, is_lost, rotting_days |
| `deals` | Opportunities | title, pipeline_id, stage_id, account_id, primary_contact_id, owner_id, value_amount, currency, expected_close_date, status, won_at, lost_reason |
| `deal_contacts` | Buying group (M:N + role) | deal_id, contact_id, role |
| `deal_line_items` | CPQ line items | name, product_id, quantity, unit_price, discount_pct, tax_pct, total |
| `tickets` | Support cases | subject, description, contact_id, account_id, status, priority, owner_id, source, category, **sla_due_at**, resolved_at, conversation_id |
| `custom_objects` | Tenant-defined record types | label_singular/plural, api_name, icon, color |
| `custom_object_fields` | The object's schema | field_key, label, type (text/textarea/number/date/select/boolean/email/phone), options, required, position |
| `custom_object_records` | Instances (JSON values) | name, owner_id, data(json) |
| `associations` | Polymorphic any↔any links | from_type/id, to_type/id, label |
| `quotes` | Priced proposals (CPQ) | deal_id, number (Q-####), status, valid_until, subtotal, total, items(json snapshot) |
| `meetings` | Native calendar entries | title, contact_id, deal_id, owner_id, start_at, end_at, location, notes, status (scheduled/completed/canceled) |
| `emails` | Outbound email log (CRM channel) | contact_id, deal_id, direction, from_email, to_email, subject, body, status (queued/sent/failed), error, sent_by |

> **Custom fields** gained a `restricted` flag (Phase L): restricted field values are returned only to owner/admin viewers and hidden from agents.

**Reused from the WhatsApp platform:** `conversations`, `messages`, `templates`, `campaigns`, `flows`/`flow_runs`/`jobs`, `segments`, `tags`, `custom_fields`/`contact_field_values`, `products`, `users` (owner/admin/agent), `waba_accounts`, `integrations`.

---

## 3. Capabilities by module

### 3.1 Contacts & Accounts (CRM-lite)
- Contacts with **lifecycle stage** (subscriber → lead → MQL → SQL → opportunity → customer → evangelist), lead score, owner, job title, account membership, tags, custom fields, status/source.
- **Accounts** (companies): create, list, detail drawer with linked contacts; type (prospect/customer/partner); industry, website, address, revenue, employees, parent account.
- Contact record page shows: edit form (incl. lifecycle/job), tags, **unified timeline**, notes, tasks, **linked records (associations)**, flow activity.
- Ingestion (existing): WhatsApp inbound, web form, Meta Lead Ads, **Google Ads Lead Forms**, CSV import, manual, API — all set `source` and can fire flow triggers.

### 3.2 Sales pipeline & Deals
- **Multiple configurable pipelines**; auto-provisions a default Generic-B2B pipeline (Lead → Qualified → Proposal → Negotiation → Won/Lost) on first use.
- **Kanban deal board**: drag-and-drop between stages (optimistic), per-column count + value totals, live pipeline-value header, won/lost tinting.
- Deal record page: editable title/value/close-date, **stage mover** (drives won/lost + stamps won_at), **line items** with CPQ math (qty × price − discount + tax), the shared Timeline/Notes/Tasks panels, **Quotes** panel.
- Stage moves log to the deal timeline (`stage_change`, `deal_won`, `deal_lost`) and fire flow triggers.
- Buying group (deal_contacts) and product line items (table-level; product picker UI is minimal).

### 3.3 Tasks
- Personal **"My Tasks"** queue (open, soonest-due-first), plus record-scoped tasks on any contact/deal/ticket/custom record.
- Type (call/whatsapp/email/meeting/todo), priority, due date, complete (stamps completed_at), delete.
- Created by flows via the `create_task` action.

### 3.4 Activities — the unified timeline
- One **immutable, polymorphic** stream per record. The `/timeline` endpoint **merges logged activities with the contact's WhatsApp message thread** (rendered as chat bubbles, newest-first) plus notes/calls/system events.
- A "log a call/meeting/note" composer on every record.
- Auto-logged: record created, stage changes, status changes, deal won/lost, ticket events, quote created.

### 3.5 Notes
- Editable, **pinnable** notes on any record (contact/deal/ticket/custom record). Pinned-first ordering.

### 3.6 Service & Tickets
- **Tickets** with status workflow (open → pending → resolved → closed), priority (low→urgent), source (whatsapp/email/web/manual), category, owner, optional conversation link.
- **SLA**: `sla_due_at` computed from priority (urgent 2h / high 4h / medium 24h / low 72h); **breach indicator** on the desk and detail.
- Tickets desk with status filter pills + SLA-breach badges; detail page with the status workflow + Timeline/Notes/Tasks panels.
- Resolving a ticket fires `ticket_resolved` → enables **CSAT survey over WhatsApp** via a flow.

### 3.7 Custom objects & associations (multi-industry)
- Define **new record types** (object) with typed fields — no code. Field types: text, textarea, number, date, select (with options), boolean, email, phone; required flag.
- **Dynamic record pages**: the create/edit form is generated from the field schema; record detail shows field values + the shared panels.
- **Associations**: link any record to any record (contact↔property, deal↔policy, etc.) with the other-side name resolved. Linker panel on the contact page.

### 3.8 Industry templates
- **One-click vertical setup**: Real Estate (Property), Healthcare (Patient), Education (Student), Insurance (Policy) — each provisions the custom object + a starter field set. Idempotent.

### 3.9 Automation (flows engine — CRM ⇄ WhatsApp)
The existing durable, queued flow engine now speaks CRM in both directions.

**Triggers** (start a flow): `lead_created`, `tag_added`, `form_submitted`, `meta_lead_received`, `google_lead_received`, `keyword_reply`, `inbound_message`, `order_placed`, `order_fulfilled`, `abandoned_cart`, `flow_response`, `date_reached`, **`deal_created`**, **`deal_stage_changed`** (matches a specific stage), **`deal_won`**, **`deal_lost`**, **`ticket_created`**, **`ticket_resolved`**, **`meeting_scheduled`**, **`record_created`** (custom-object record, optionally scoped to one object; requires a contact link).

**Actions** (run in a flow): `send_template`, `send_freeform`, `send_media`, `send_interactive`, `add_tag`, `remove_tag`, `update_status`, `assign_agent`, `handoff`, `send_payment`, `send_product`, `ai_reply`, `send_flow`, `webhook_call`, `delay`, `condition`, `window_check`, **`create_task`**, **`update_field`** (lifecycle/status/owner/score), **`create_deal`**, **`create_ticket`**.

Every WhatsApp send still runs the **24-hour window check** (template vs free-form). Flow *test* mode is dry-run (no CRM mutations).

> Example automations now possible: "deal won → send onboarding template + create task + set lifecycle=customer"; "keyword 'support' → open a ticket"; "ticket resolved → send CSAT survey".

### 3.10 Reporting — CRM dashboard
- `GET /crm/dashboard` (landing page) aggregates: **open pipeline value**, **revenue won**, **win rate**, pipeline **funnel by stage**, tickets (open/pending/resolved + **SLA breaches**), tasks (open/overdue/due-today), **contacts by lifecycle**.
- Rendered as metric cards + proportional funnel/lifecycle bars; cards link to the relevant lists.

### 3.11 Quotes / CPQ
- Generate a **quote from a deal's line items** — snapshotted as JSON (immutable), per-tenant `Q-####` number, +14-day validity.
- Status workflow: draft → sent → accepted / rejected / expired. Viewer with line-item table + totals. Quotes panel on the deal page.

### 3.12 Native calendar / meetings
- `meetings` records attached to a contact and/or deal (title, start/end, location, notes, status). Agenda view by date range; per-contact lookup.
- Booking a meeting linked to a contact fires the **`meeting_scheduled`** flow trigger — e.g. auto-send a WhatsApp confirmation (window-checked). Native only; no external (Google/Outlook) sync.

### 3.13 AI sales assistant (call scripts & qualification)
- `POST /ai/script` generates a context-aware **call script** or set of **qualifying questions** from a lead's brief (name, title, lifecycle, source, open deal).
- Reuses the existing Anthropic integration (per-tenant key or platform key, `AI_MOCK_MODE` for local); **metered against the shared `ai_replies` plan allowance**. `AiScriptPanel` on the contact page.

### 3.14 Email as a CRM channel (2-way)
- **Outbound:** send an email to a contact from the CRM; every send is logged in `emails` and **mirrored to the contact timeline** as an `email` activity. SMTP resolves the tenant's own credentials (`integrations.type='email_smtp'`) when configured, else the platform `MAIL_*` env. `EMAIL_MOCK_MODE` skips transport for local/test while still logging.
- **Inbound (v1):** a mail provider's inbound-parse webhook posts to `POST /webhooks/inbound-email/:token` (tenant resolved from the unguessable per-tenant token, stored as the integration's `verify_token`). The sender is matched to a contact and the reply is logged as an inbound `emails` row + timeline activity — **2-way threading** alongside outbound. Common provider payload shapes (Mailgun/SendGrid/Postmark) are normalized.
- *Still open:* IMAP polling and auto-contact-creation for unknown senders (currently dropped).

### 3.15 Service SLA precision
- Ticket SLA timers are **business-hours-aware and minute-precise**: a ticket opened mid-hour (e.g. 17:30) is credited only the remaining business minutes, not a full hour. Breach escalation reassigns to the least-loaded agent (once-only).

---

## 4. WhatsApp integration points (the differentiator)
1. **Unified timeline** merges the live WhatsApp thread into every contact record.
2. **Shared inbox** (existing) for free-form replies inside the 24h window.
3. **Flows fire WhatsApp** on CRM events (deal/ticket triggers → templates).
4. **Tickets** are often WhatsApp-sourced; **CSAT** goes out over WhatsApp on resolve.
5. **Lead ingestion** from WhatsApp inbound, Meta & Google lead ads → contacts → triggers.
6. The **24h window** governs every send; CRM events can always send approved templates.

---

## 5. API surface (CRM, under `/api/v1`, token-auth, tenant-scoped)
- **Accounts:** `GET/POST /accounts`, `GET/PUT/DELETE /accounts/:id`
- **Tasks:** `GET /tasks` (`?assigned=me`, `?related_type&related_id`, `?status`), `POST /tasks`, `PUT /tasks/:id`, `POST /tasks/:id/complete`, `DELETE`
- **Notes:** `GET /notes?related_type&related_id`, `POST/PUT/DELETE`
- **Activities/Timeline:** `POST /activities`, `GET /timeline?related_type&related_id`
- **Pipelines:** `GET /pipelines`, `GET /pipelines/:id`
- **Deals:** `GET /deals/board?pipeline_id`, `GET/PUT/DELETE /deals/:id`, `POST /deals`, `POST /deals/:id/move`, `POST/DELETE /deals/:id/line-items`
- **Quotes:** `GET/POST /deals/:id/quotes`, `GET /quotes/:id`, `POST /quotes/:id/status`, `DELETE`
- **Tickets:** `GET/POST /tickets`, `GET/PUT/DELETE /tickets/:id`, `POST /tickets/:id/status`
- **Custom objects:** `GET/POST /custom-objects`, `GET/PUT/DELETE /custom-objects/:id`, `POST /custom-objects/:id/fields`, `DELETE /custom-object-fields/:id`, `GET/POST /custom-objects/:id/records`
- **Records:** `GET/PUT/DELETE /custom-object-records/:id`
- **Associations:** `GET /associations?type&id`, `POST`, `DELETE /associations/:id`
- **Templates:** `GET /industry-templates`, `POST /industry-templates/:key/apply`
- **Dashboard:** `GET /crm/dashboard`
- **Meetings:** `GET /meetings` (`?from&to` agenda, `?contact_id`), `GET /meetings/:id`, `POST /meetings`, `PUT/DELETE /meetings/:id`
- **AI assistant:** `POST /ai/script` (`{ type: call_script\|qualification, contact_id?, deal_id? }`) — metered
- **Email:** `GET/POST /contacts/:id/emails`; `GET/POST /email/config` (owner/admin SMTP)

---

## 6. Screens (React SPA)
Dashboard · Contacts + Contact detail · Accounts · Deals (kanban) + Deal detail · Tickets + Ticket detail · Tasks · Objects (manager + templates) + Records + Record detail · Flow builder (CRM triggers/actions) · plus existing Inbox, Templates, Campaigns, Flows, Segments, Web Forms, Analytics, Integrations, Billing.

---

## 7. Multi-tenancy, roles & SaaS
- Every CRM table is tenant-scoped via `BaseModel` (throws if tenant not set). Default-pipeline and industry-template provisioning are per-tenant and idempotent.
- Roles: owner / admin / agent (record ownership via `owner_id`/`assigned_user_id`).
- Billing/licensing (Razorpay + self-hosted license) and plan limits are in place from the platform; CRM-specific plan gates are **enforced** via `PlanLimitChecker` on deal, custom-object, dashboard, and seat (agent) creation (`pipelines` and per-object field caps remain unenforced).

---

## 8. Status: shipped vs. still open

> This section was a wishlist of gaps when the CRM covered phases A–F. It has been re-audited against the codebase as of **Phase L (June 2026)** — almost the entire list has since shipped. Verdicts below are grounded in the implementing files.

### ✅ Shipped since the original gap list (phases G–L)

| Area | Capability | Proof |
|---|---|---|
| Reporting | Multiple **configurable dashboards** + widgets | `DashboardModel`, `DashboardWidgetModel`, `DashboardsController` |
| Reporting | **Custom report builder** (whitelisted metrics/dimensions, date-range presets) | `ReportService` |
| Reporting | **Forecasting + targets/quotas** & **leaderboard** | `ForecastService`, `SalesTargetModel`, `ForecastController` |
| Reporting | Report **CSV export** + client **print-to-PDF** | `ReportService::toCsv`, `GET /crm/reports/export` |
| Lists | **Saved views / list filters** per object (unified list endpoint) | `SavedViewModel`, `FilterEngine`, `CrmListController` |
| Lists | **Bulk actions** (set field, status, tag, delete) | `BulkActionService`, `POST /crm/bulk/:entity` |
| Lists | **Duplicate detection + merge** (contacts/accounts) | `DedupeMergeService`, `GET /crm/duplicates/:entity`, `POST /crm/merge/:entity` |
| Lists | Generalized CSV **import/export** (accounts, deals, custom records) | `CrmImporter`, `CrmExporter`, `ImportAdapters` |
| Lists | **Global search** across CRM records | `SearchService`, `GET /crm/search` |
| Sales | **Quote PDF** + **send over WhatsApp** (24h-window-checked) | `QuotePdfService`, `QuoteSendService` |
| Sales | **Product catalog picker** on line items (+ price-book pricing) | `DealLineItemModel.product_id`, `DealsController::addLineItem`, `PriceBookService` |
| Sales | **Price books** + **multi-currency display** | `PriceBookModel`/`PriceBookEntryModel`, `fmt.money` |
| Sales | **Deal rotting** detection + nightly scan | `RottingService`, `crm:rotting-scan` |
| Automation | **Round-robin / assignment rules** | `AssignmentService`, `AssignmentRuleModel` |
| Automation | **Lead scoring engine** (weighted signals + tiers, nightly recalc) | `LeadScoringService`, `scoring:recalc` |
| Automation | **`task_due` trigger** (reminders fire a flow → WhatsApp) | `TaskDueService`, `tasks:remind` |
| Automation | **Business-hours, minute-precise SLA** + breach escalation | `BusinessHoursService`, `SlaEscalationService` |
| Channels | **Email** outbound + logging (per-tenant SMTP, timeline mirror) | `ContactEmailService`, `emails` table |
| Channels | **Native calendar / meetings** + `meeting_scheduled` trigger | `MeetingModel`, `MeetingsController` |
| Channels | **AI call scripts & qualifying questions** (metered) | `AiScriptService`, `POST /ai/script` |
| Collaboration | **@mentions** on notes + in-app notifications | `MentionService`, `NotificationService` |
| Governance | **Audit log** of changes (diff on update) | `AuditLogger`, `AuditLogModel` |
| Governance | **Plan-limit enforcement** (deals, custom objects, dashboards, seats) | `PlanLimitChecker` wired in the respective controllers |
| Governance | **Field-level visibility** (restricted custom fields) | `custom_fields.restricted`, `ContactFieldValueModel::getForContact` |

### ⏳ Still open (genuine remaining gaps)

- **Email inbound depth** — *shipped:* 2-way threading via an inbound-parse webhook AND **IMAP polling** (Phase M) for tenants without a parse provider — connect a mailbox in Integrations; the `email:poll-imap` cron pulls unread mail, threads it onto contacts, and auto-creates email-only contacts for unknown senders. The IMAP transport is abstracted (`MailboxReader`) so the poll logic is unit-tested with a fake; the real `imap_*` adapter needs ext-imap installed and a live mailbox to exercise.
- **Quote delivery over email** — quotes send over WhatsApp; emailing the PDF reuses `ContactEmailService` but isn't wired.
- **Custom-object depth:** record list filtering is limited (`custom_object_id`, `owner_id`) and search is name-only. *(Per-type field validation — email/phone/number/date/boolean/select — is now enforced via `CustomObjectValidator`.)*
- **Custom-object flow triggers** — *now supported:* creating a record linked to a contact fires `record_created` (optionally scoped to one object). Records with no contact link still don't fire (the engine is contact-centric); a richer contact-less enrolment model remains open.
- **Record-level permissions** — *shipped (Phase M):* a per-tenant `record_visibility` mode (open/owner/team, default open = unchanged) enforced in one choke point (`BaseModel::scopeVisibility`) so it covers list/find/search/export/dashboards and — via find-then-404 in controllers — writes too. owner/admin and system/CLI/webhook contexts bypass (inbound messaging stays exempt). Explicit `record_shares` (user or team grant) widen access per record; managed via `SharesController`. Shares carry **read or edit** access: a `read` share makes a record visible but blocks edit/delete (controllers return 403 via `BaseModel::canEdit` + the `EnforcesEditAccess` guard on update/delete/move/setStatus); an `edit` share (the default) allows changes. Still open: a per-record **Share UI** (shares are currently API-only), and a "shared pool" option for unowned records (currently visible only to owner/admin in restricted modes). Field-level visibility (K3b) is unchanged.
- **Recurring / subscription deals** — *shipped:* mark a deal recurring with a billing interval (monthly/quarterly/annual); won recurring deals roll up into MRR/ARR on the dashboard, get a `next_renewal_at`, and the opt-in `deals:renewals` cron opens a fresh renewal deal on the due date (advancing the cycle, idempotent). *(Time-series trend charts also shipped — day/week/month buckets via a line chart with area fill.)*
- **Quote delivery over email** — *now wired:* a quote PDF can be emailed to the deal's primary contact (attachment) and is logged to the timeline like any outbound email; WhatsApp delivery also available.
- **Email-only contacts + auto-create for unknown email senders** — *shipped (Phase M):* `contacts.wa_number` is now nullable, so a contact can exist with just an email (CRM lead, or an unknown inbound-email sender, auto-created with `source=email_inbound`). Dedupe keys on `wa_number` when present, else email (case-insensitive); email-only contacts store NULL (never `''`) so the unique index doesn't collide. Campaign sends skip contacts without a number. Email-only and WhatsApp records stay **separate** (an inbound WhatsApp message carries no email to match on); merge later via duplicate detection if they're the same person.
- No dedicated **mobile-optimized** CRM views.

---

## 9. Suggested prioritization for what's next
The original priority list (saved views, assignment + scoring, reporting/forecasting, quote PDF + catalog, `task_due` + SLA, CSV import/export) has all shipped. Of the remaining §8 gaps, the highest-leverage next steps tend to be:
1. **Custom-object flow triggers** + richer record filters/search (makes custom objects first-class in automation).
2. **Inbound email / 2-way threading** (completes the email channel; quote-over-email falls out of it).
3. **Per-type field validation** for custom records (data quality).
4. **Time-series / trend charts** and **recurring deals** (deeper reporting & revenue modelling).

Map your requirement list against §3 (have) and §8 (still open), and I can turn any chosen item into a build phase.
