# CLAUDE.md — TravelPilot

> WhatsApp Lead Management & Automation Platform
> By Gamavis Software Solutions
> Spec-driven build. Read this entire file before writing any code.

---

## 0. What we are building

**TravelPilot** is a standalone WhatsApp lead-management and marketing-automation product, extracted and evolved from the WhatsApp module of EaseMySale. It lets a business:

1. Capture leads — CSV/Excel upload, manual lead form, embeddable web form, and Facebook/Instagram Lead Ads.
2. Manage leads — tags, custom fields, status, source, segments (CRM-lite).
3. Send WhatsApp messages and attachments to leads via the **official WhatsApp Cloud API**.
4. Build automation **flows** (visual node editor) where messages auto-trigger based on triggers, delays, and conditions — fully respecting WhatsApp's policy rules.
5. Operate a shared team inbox for replies within the 24-hour window.

TravelPilot ships in **two commercial modes from one codebase**:
- **SaaS** — multi-tenant, monthly/annual subscription billing via Razorpay.
- **Self-hosted** — single-tenant, sold as one-time payment with source code, gated by a license key.

This is a **separate product**: separate repo, separate database, separate domain. It must NOT depend on or modify EaseMySale.

---

## 1. NON-NEGOTIABLE: WhatsApp policy backbone

Get this wrong and customers get banned. Every messaging feature must obey these rules. Treat this section as the highest-priority constraint in the whole project.

- **Cloud API only.** Integrate Meta's official WhatsApp Cloud API. Never use unofficial libraries (Baileys, whatsapp-web.js) anywhere in this product.
- **Each customer brings their own WABA.** The customer connects their own WhatsApp Business Account and phone number (via Meta Embedded Signup). They pay Meta directly per message. TravelPilot is never in the messaging-cost loop.
- **The 24-hour customer service window is the core state.** When a contact messages the business, a 24-hour window opens.
  - **Inside the window:** free-form messages (text, image, document, video) are allowed and are free.
  - **Outside the window or on first contact:** ONLY pre-approved **template messages** may be sent (paid).
- **Message categories:** Marketing, Utility, Authentication, Service. Track the category on every outbound message.
- **Every "send" action in a flow or campaign MUST run a window-check first** and route to free-form vs. template accordingly. A flow node that tries to send free-form text outside an open window must be blocked at execution time and logged, never silently sent.
- The `conversations.window_expires_at` timestamp is the single source of truth the flow engine reads against. Update it on every inbound message.

---

## 2. Reference codebase — PORT, don't reinvent

Claude Code: you have local file access to the EaseMySale repo. Before building the WhatsApp layer, **read the existing EaseMySale WhatsApp module** and port the battle-tested parts rather than writing from scratch.

Specifically, locate and study in EaseMySale:
- The WhatsApp Cloud API client/service (auth, send message, send template, send media).
- The WhatsApp webhook receiver (inbound messages, status callbacks: sent/delivered/read/failed).
- Template submission/sync logic with Meta, if present.
- The multi-tenant pattern (tenant resolution, tenant-scoped queries, tenant_id on tables).
- The Razorpay integration (subscription create, webhook verification).

Port these into TravelPilot's structure (Section 5), adapting names to TravelPilot conventions. Where EaseMySale's implementation predates the current per-message/template pricing model, upgrade it to the window + template logic in Section 1. Do not copy EaseMySale's database or couple the two products.

If the EaseMySale repo is not reachable in the current working tree, ask me for its path before proceeding with the WhatsApp layer.

---

## 3. Tech stack

- **Backend:** PHP 8.3, CodeIgniter 4
- **Frontend:** React 18 (Vite), with `@xyflow/react` (React Flow) for the flow builder
- **Database:** MySQL 8
- **Cache/queue:** Database-backed jobs table + CI4 Spark CLI worker run via cron (default). Redis optional, enabled only when available — do NOT make Redis a hard dependency (local dev is a MacBook Air 8GB; production is Hostinger Cloud).
- **Payments:** Razorpay (SaaS mode)
- **WhatsApp:** Meta WhatsApp Cloud API + Graph API (Lead Ads)
- **Local dev:** XAMPP/MAMP (PHP + MySQL). App must run fully on localhost with no paid services required for development (use sandbox/test creds and seeders).

---

## 4. Dual-mode architecture (the core design)

One codebase, behavior gated by an env flag:

```
APP_MODE=saas         # multi-tenant, Razorpay billing, registration open
APP_MODE=self_hosted  # single tenant, license-key gated, billing disabled
```

Rules:
- **Always build multi-tenant.** Every business-data table carries `tenant_id`. In `self_hosted` mode there is exactly one tenant (id=1), billing UI is hidden, and a **license service** controls activation.
- **License service (self_hosted):** on activation, validate a signed license key against a Gamavis license endpoint (phone-home) with an offline grace period (e.g., 7 days). Store activation locally. Gracefully degrade (read-only) if invalid, never hard-crash.
- **Billing service (saas):** Razorpay subscriptions; plan limits enforced via a central `plan_limits` config (contacts, agents, active flows, WABA numbers). Never gate on message volume — Meta bills that to the customer's WABA.
- A single `FeatureGate`/`Mode` service answers "is billing on?", "is registration open?", "what are this tenant's limits?" so feature code never reads `APP_MODE` directly.

---

## 5. Repository structure

```
travelpilot/
  backend/                 # CodeIgniter 4
    app/
      Controllers/
        Api/               # all endpoints under /api/v1
        Webhooks/          # Meta WhatsApp + Lead Ads, Razorpay
      Models/
      Services/
        WhatsApp/          # CloudApiClient, TemplateService, WindowService
        Flow/              # FlowEngine, NodeExecutor, JobWorker
        Leads/             # Importer, FormHandler, MetaLeadAds
        Billing/           # Razorpay (saas)
        Licensing/         # License (self_hosted)
        Tenancy/           # TenantResolver, mode/feature gate
      Database/Migrations/
      Database/Seeds/
      Commands/            # spark commands incl. flow:work, jobs:run
    tests/                 # PHPUnit
  frontend/                # React 18 + Vite
    src/
      pages/
      components/
      flow-builder/        # @xyflow/react canvas
      api/
  docs/
    SPRINTS/               # per-sprint specs (CLAUDE_FLOW.md, etc.)
```

API style: REST under `/api/v1`, JSON, token auth (per-tenant API tokens + session for the SPA). Every list endpoint is tenant-scoped automatically via a base model scope — no controller should query without tenant scoping.

---

## 6. Database schema (core tables)

All business tables include `tenant_id`, `created_at`, `updated_at`, soft `deleted_at`.

- **tenants** — id, name, plan, status, mode-specific fields
- **users** — id, tenant_id, name, email, password_hash, role (owner/admin/agent)
- **waba_accounts** — id, tenant_id, waba_id, business_id, access_token (encrypted), status
- **phone_numbers** — id, waba_account_id, phone_number_id, display_number, quality_rating
- **contacts** — id, tenant_id, wa_number, name, email, status (new/contacted/qualified/won/lost), source, opt_in (bool), last_inbound_at
- **contact_tags** / **tags** — many-to-many
- **custom_fields** / **contact_field_values** — dynamic fields
- **conversations** — id, tenant_id, contact_id, window_expires_at, last_message_at  ← drives the flow engine
- **templates** — id, tenant_id, name, language, category (marketing/utility/auth), body, variables(json), meta_status (pending/approved/rejected), header_type (none/text/image/document/video)
- **campaigns** — id, tenant_id, template_id, segment(json), status, scheduled_at, stats(json)
- **messages** — id, tenant_id, contact_id, conversation_id, direction (in/out), type (text/template/media), category, body, media_url, wa_message_id, status (queued/sent/delivered/read/failed), billable (bool), error
- **flows** — id, tenant_id, name, status (draft/active/paused), trigger_type, graph(json: nodes+edges)
- **flow_runs** — id, tenant_id, flow_id, contact_id, current_node_id, state(json), status (running/completed/stopped), next_run_at
- **jobs** — id, tenant_id, type, payload(json), run_at, attempts, status, locked_at  ← DB queue
- **integrations** — id, tenant_id, type (meta_lead_ads), config(json: page_id, form_ids, app secrets)
- **lead_imports** — id, tenant_id, filename, mapping(json), total, imported, failed, status
- **subscriptions** (saas) — id, tenant_id, razorpay_sub_id, plan, status, current_period_end
- **licenses** (self_hosted) — id, key, activated_at, last_check_at, status

Encrypt all access tokens and secrets at rest.

---

## 7. Module specs

### 7.1 Lead ingestion
- **CSV/Excel upload:** upload → preview → map columns to fields → validate (dedupe on `wa_number`) → import with a `lead_imports` progress row. Large files processed via the jobs queue.
- **Manual lead form:** simple create/edit form.
- **Embeddable web form:** hosted form + an embeddable JS snippet; submissions create contacts and can fire a flow trigger.
- **Meta Lead Ads:** Facebook App with `leads_retrieval`; subscribe Page to the `leadgen` webhook; on webhook, fetch the lead via Graph API, map to a contact, set source=`meta_lead_ads`, fire trigger. Document the Meta App Review requirement clearly in `docs/` — this has its own approval timeline.

### 7.2 Contacts / CRM-lite
List with filters (tag, status, source, window-open), bulk tag/segment actions, contact timeline (all messages + flow events).

### 7.3 WhatsApp connection
Embedded Signup to connect WABA + number; sync phone numbers and quality rating; show connection health.

### 7.4 Template manager
Create template → submit to Meta → poll/sync status → use only `approved` templates in campaigns/flows. Show category clearly (affects customer's cost). Variable mapping UI.

### 7.5 Campaigns / broadcast
Pick approved template → choose segment → personalize variables → schedule or send. Respect opt-in. Per-contact send goes through the queue; record category + billable on each message.

### 7.6 Flow / automation builder
See Section 8 — this is the most valuable and most complex module; build it after the messaging core is solid.

### 7.7 Shared inbox
Live view of conversations; agents reply free-form within open windows; window countdown shown; assignment to agents. Market this as the "free messaging" surface.

### 7.8 Analytics
Per campaign/flow: sent/delivered/read/replied, failures, estimated cost by category, flow conversion funnel.

---

## 8. Flow engine design

**Storage:** each flow is a JSON graph `{ nodes:[], edges:[] }`.

**Node types**
- Triggers: `lead_created`, `tag_added`, `form_submitted`, `meta_lead_received`, `keyword_reply`, `inbound_message`
- Actions: `send_template`, `send_freeform`, `send_media`, `add_tag`, `remove_tag`, `update_status`, `assign_agent`, `webhook_call`
- Control: `delay` (wait N minutes/hours/days), `condition` (branch on field/tag/window state), `window_check`

**Execution model**
1. A trigger fires → create a `flow_runs` row at the entry node.
2. A CI4 Spark worker (`php spark flow:work`, run via cron every minute) picks runs where `next_run_at <= now`, executes the current node, advances `current_node_id`, and sets the next `next_run_at` (for delays) — or completes the run.
3. **Every send node calls `WindowService` first.** Outside an open window, `send_freeform`/`send_media` is blocked and logged; only `send_template` (approved) may proceed. Surface this clearly to the user when designing the flow (warn that free-form steps only work inside a 24h window).
4. Delays are durable (stored as `next_run_at`), so restarts/cron gaps don't lose runs. Use row locking (`locked_at`) so concurrent workers don't double-execute.

**Frontend:** `@xyflow/react` canvas; node config panels; validation that warns on policy-risky flows (e.g., free-form node not preceded by an inbound trigger).

---

## 9. Conventions & quality bar

- PSR-12 PHP; strict types; thin controllers, logic in Services.
- All money in INR; Razorpay amounts in paise.
- All secrets in `.env`, never committed; `.env.example` maintained.
- **PHPUnit tests required** for: WindowService logic, flow node execution, lead import dedupe, license validation, billing webhook verification, and webhook signature checks. Target meaningful coverage on Services, not controllers.
- Migrations for every schema change; seeders for demo tenant + demo contacts + a sample flow so the app is testable on a fresh local install.
- Webhook endpoints must verify signatures (Meta `X-Hub-Signature-256`, Razorpay signature).
- Log all outbound sends and all blocked-by-policy attempts.

---

## 10. Local-first workflow

The app must run end-to-end on localhost (XAMPP/MAMP + MySQL) using:
- Meta test number / sandbox WABA for WhatsApp.
- Razorpay test mode for billing.
- Database queue driver (no Redis needed locally).
- A seeded demo tenant so flows and campaigns can be exercised without real Meta approval.

A `README.md` must document setup from clone → migrate → seed → run in under 10 steps. Going live is then: switch `.env` to production creds, set `APP_MODE`, point to Hostinger MySQL, configure cron for `flow:work` (the single queue worker — `jobs:run` is a no-op stub) plus the scheduled commands (`campaigns:dispatch-scheduled`, `flows:dates`, `license:check`/`subscription:check`, `waba:quality-sync`, `template:sync`).

---

## 11. Sprint roadmap

Build in this order. Do not start a sprint's WhatsApp/flow work before the foundation sprints are green.

- **Sprint 0 — Skeleton:** repo, CI4 + Vite setup, `.env`, mode/feature gate, tenancy base model, migrations baseline, auth (login, roles), seeders, README.
- **Sprint 1 — Leads core:** contacts CRUD, tags, custom fields, segments; CSV/Excel import with mapping + dedupe; manual + embeddable web form. Full local test.
- **Sprint 2 — WhatsApp core:** port Cloud API client + webhook from EaseMySale; WABA/number connection; inbound message handling; `conversations.window_expires_at`; `WindowService`; shared inbox (free-form replies inside window).
- **Sprint 3 — Templates & campaigns:** template create/submit/sync; broadcast to segment with variables + attachments; message status tracking + basic analytics.
- **Sprint 4 — Flow engine:** jobs table + Spark worker; flow data model; `@xyflow/react` builder; node executors with window-check; durable delays; sample flow seeded.
- **Sprint 5 — Meta Lead Ads:** Facebook App, leadgen webhook, Graph API fetch, map → contact → trigger flow. Document App Review.
- **Sprint 6 — Monetization:** SaaS (Razorpay subscriptions, plan limits, self-registration) AND self-hosted (license generator + validator, billing hidden). Mode-switch tested both ways.
- **Sprint 7 — Hardening & launch:** security pass, rate limiting, analytics polish, white-label config (logo/colors/domain) for the agency/source tier, production deploy runbook.

Each sprint ends with: migrations + seeders updated, PHPUnit green, and a short demo path I can click through locally.

---

## 12. Out of scope (for now)

- Unofficial WhatsApp (Web/QR) sending — never.
- SMS channel — not planned. (Email marketing shipped post-v1 as P9 in §14.)
- AI chatbot replies — note as a future module, not v1.
- Editing or importing from the EaseMySale database.

---

## 13. Definition of done (v1)

A clean local clone can: register/login → import leads → connect a (test) WABA → create & approve a template → run a broadcast → build a flow that auto-messages a new lead respecting the 24h window → see analytics — in both `saas` and `self_hosted` modes.

---

## 14. Post-v1 feature modules (P1–P4, AiSensy parity)

Shipped beyond the v1 spec to compete with aisensy.com. All migration-clean, unit-tested (PHPUnit), and behind the same tenancy/window/queue invariants. **When extending these, preserve the rules in §1 (window) and §8 (flow engine).**

- **P1** — contact CSV export (`ContactExporter`); broadcast scheduler + timezones (`ScheduledCampaignDispatcher`, `campaigns:dispatch-scheduled` cron, `campaigns.scheduled_at/schedule_timezone`, status `scheduled`); campaign retargeting (`CampaignRetargeter`, `messages.campaign_id`, `{contact_ids:[…]}` segment); click/CTA analytics (`ClickTracker`, `click_events`); agent auto-routing (`Services/Inbox/AgentRouter`, `routing_rules`) + flow `handoff` node (`conversations.handoff_at`).
- **P2** — payment links (`Services/Commerce/PaymentLinkService`, `payment_links`, per-tenant Razorpay via `integrations.type='razorpay_payments'`, `/webhooks/razorpay-payments`); catalog (`products`, `commerce_orders`, `CloudApiClient::sendProduct/sendProductList`, `OrderParser`, `integrations.type='whatsapp_catalog'`); Shopify/WooCommerce (`Services/Commerce/Ecommerce*`, `/webhooks/shopify|woocommerce`, base64-HMAC verify).
- **P3** — AI replies (`Services/AI/AiReplyService`, `ai_reply` node, `/ai/suggest`, env `ANTHROPIC_API_KEY` / `AI_MOCK_MODE`); outbound webhooks (`Services/Webhooks/OutboundWebhookService`, `webhook_subscriptions`, `webhook_deliver` job, `X-TravelPilot-Signature`); WhatsApp Flows (`CloudApiClient::sendFlow`, `send_flow` node, `nfm_reply` capture → `whatsapp_flow_responses` + `flow_response` trigger).
- **P4** — link & QR generator (frontend `WaLinkPage`, `qrcode`); campaign A/B (`campaigns.variant_template_id/ab_split`, `messages.variant`, deterministic `id % 100 < split`); multi-language (`TemplateResolver`, `contacts.language`); date automations (`DateTriggerService`, `date_reached` trigger, `flows:dates` daily cron).

- **P5 — delivery reporting (AiSensy-parity analytics).** One filter vocabulary (`Services/Analytics/MessageFilters`) shared by every report, so the funnel, the row list and the CSV can never describe different sets of messages.
  - `GET /analytics/overview` — range-aware funnel, daily trend, cost by category, failure reasons, per-template performance (`MessageStats`).
  - `GET /analytics/messages` — the **delivery log**: one row per message with recipient, status, sent/delivered/read/replied timestamps and Meta's failure reason (`MessageReportQuery`).
  - `GET /analytics/messages/export` — same slice as CSV (`ReportExporter`; capped at 20k rows, truncation reported in `X-Export-*` headers).
  - `GET /analytics/campaigns/(:num)/report` — whole-broadcast report: funnel, trend, A/B split, button taps, failures, estimated spend (`CampaignReport`).
  - **Status is a ladder.** Meta reports only the furthest rung reached, so every count rolls `read` up into delivered and sent. `WebhookService` now refuses a callback that moves a message *backwards* (a retried `delivered` after `read`, a stale `failed` after delivery); migration `RepairDowngradedMessageStatus` fixes rows corrupted before that guard.
  - **Reply attribution** (`ReplyAttribution`): an inbound message from the same contact within 24h of a send that actually left. The horizon is the WhatsApp service window — without one, old campaigns accrue "replies" forever.
  - **Cost is an estimate** (`CostEstimator`, env-overridable `WA_RATE_*_PAISE`) of the customer's own Meta bill. TravelPilot never charges for messages (§1).
  - Frontend: `AnalyticsPage` tabs (Overview / Delivery log / Campaigns / Flows) over `components/analytics/*`.

- **P6 — Meta cost (real billing).** `Services/Analytics/MetaBillingService` pulls the WABA's `pricing_analytics` (per-message pricing; `conversation_analytics` fallback) into `waba_billing` (month × category × pricing type) and reports month-on-month spend, category split, free messages, MoM change and TravelPilot's own send count beside it. `GET /analytics/billing`, `POST /analytics/billing/sync`, `spark waba:billing-sync` (daily; `--probe`, `--wabas` for diagnosis). Meta refuses windows longer than 12 months. This is the customer's actual Meta bill; `CostEstimator` remains an estimate and the two are never mixed on a report. Frontend: Analytics → "Meta cost" tab (`components/analytics/MetaBillingPanel`).

- **P7 — Meta ad spend.** `Services/Analytics/MetaAdsService`: Facebook Login with `return_to=ads` keeps the long-lived USER token (encrypted, `integrations.type='meta_ads'`) — ad-account insights cannot be read with a Page token — and the owner picks ad accounts; account-level insights (`time_increment=monthly`) land in `ad_spend` (spend, impressions, clicks, reach, Meta's lead count) and the report adds cost per lead and MoM. `/meta-ads/*`, `GET /analytics/ad-spend`, `spark meta:ads-sync` (daily). UI: Integrations → "Meta Ads spend"; Analytics → Meta cost shows ads beside WhatsApp with a combined total.

- **P8 — Meta lead archive.** `Services/Leads/MetaLeadsArchiveService` reads a month's lead-ad submissions live from Meta (all forms on the linked Page, `time_created` filter, paged), maps them with `MetaLeadMapper`, matches each to the CRM by number, and imports the chosen ones through `ContactDedupeService` (tag + custom fields + `meta_lead_events` idempotency row; flows only when `start_flows`). `GET /meta-leads?month=`, `POST /meta-leads/import`; page `/analytics/meta-leads/:month`, linked from the ad-spend table's lead count. `ContactDedupeService::upsert($tenantId, $data, $fireTriggers = true)`.

- **P9 — Email marketing.** Bulk email campaigns alongside WhatsApp. **Bulk email only ever sends through the tenant's OWN SMTP** (`integrations.type='email_smtp'`, same bring-your-own rule as the WABA) — never the platform `MAIL_*` account; `EmailComposer::settings()` refuses otherwise (except `EMAIL_MOCK_MODE`).
  - `email_templates`, `email_campaigns` (body snapshotted at creation; VARCHAR status draft/scheduled/processing/paused/done/failed/cancelled), `email_suppressions` (per-tenant, keyed on lower-cased address), `email_clicks`; recipients are `emails` rows with `email_campaign_id` + `tracking_token`, UNIQUE(email_campaign_id, contact_id) = reserve-before-send idempotency.
  - `Services/Email/Marketing/*`: `EmailCampaignSender` (keyset cursor, batch = tenant `rate_per_minute`, skips no-email / suppressed / shared-address; 3 straight SMTP failures before any success → **pause** and release those reservations so resume retries them), `EmailComposer` (merge tags via `EmailPersonalizer`, `{{contact.first_name|fallback}}`, `{{custom.key}}`), `EmailTracking` (signed click redirect, open pixel, `{{unsubscribe_url}}`/auto footer, RFC 8058 `List-Unsubscribe` headers), `EmailTrackingService`, `SuppressionService`, `EmailCampaignReport`, `EmailCampaignScheduler`.
  - Job `email_campaign_send` rides `flow:work`; scheduled campaigns ride `campaigns:dispatch-scheduled` (no new cron). Flow action `send_email` (template-based; skips suppressed/no-email, WhatsApp window does not apply).
  - API `/api/v1/email-marketing/*`; SMTP settings `/email/config` (+ `rate_per_minute`, `footer_text`, `POST /email/config/test`). Public: `GET /webhooks/email/open/{token}.gif`, `GET /webhooks/email/click/{token}?u=&s=`, `GET|POST /forms/unsubscribe/{token}` (GET only confirms — link scanners must not unsubscribe people). Env `EMAIL_TRACKING_URL`.
  - Migration `AddEmailSmtpIntegrationType` adds `email_smtp` to the `integrations.type` ENUM — it was missing, so tenant SMTP saves were silently stored with type '' and never found.
  - Frontend: Messaging → Email Marketing (`EmailMarketingPage` tabs Campaigns/Templates/Unsubscribes/Sending settings), `EmailCampaignEditorPage`, `EmailCampaignReportPage`, `EmailTemplateEditorPage`, `components/email/*` (Visual/HTML/Preview editor, audience picker).
  - **Nurture sequences.** A follow-up is an ordinary scheduled email campaign whose segment is `{followup_of, after_campaign_id, engagement, exclude_statuses}` — handled by `EmailCampaignSender::applyFollowup` (never passed to `SegmentResolver`, which would fail closed): people the source actually reached (status sent), minus anyone who clicked/opened ANY email in the sequence, minus contacts in `exclude_statuses`. `POST /email-marketing/campaigns/:id/sequence` creates the steps (days counted from the source's send date, at `send_time` in the user's zone); `EmailCampaignScheduler` holds a due step (+1h, `last_error` "Waiting for…") until its predecessor has finished. UI: `components/email/SequenceDialog` ("Add follow-up sequence" on the report page / scheduled editor).
  - **Daily limit** (`integrations.config.daily_limit`, 0 = none): rolling 24h over all outbound `emails` rows (`EmailComposer::sentInLast24h`); at the cap the batch returns `throttled` and the job retries in 15 min. Gmail preset = 450/day, port 465 SSL (the VPS blocks outbound 587).
  - `{{footer_text}}` token lets a designed footer place the tenant's company/address. `GamavisEmailNurtureSeeder` (tenant 1) seeds the branded 5-email Gamavis series ("Gamavis Nurture 1–5"), idempotent by name, never overwrites app edits; images are the public assets on www.gamavis.com.
  - **Owner copy** (`integrations.config.copy_to`): after each successful campaign/flow send, `EmailComposer::sendCopy` sends a SEPARATE untracked message ("[Copy → Name <addr>] subject") — deliberately not a BCC, whose pixel/links would credit the customer with the owner's opens (and could unsubscribe them). Copies cost SMTP quota: the daily limit counts each recipient twice when on (`usedInLast24h`).
  - Recipient CSV: `GET /email-marketing/campaigns/:id/recipients/export?filter=` (formula-safe, BOM for Excel). `NurtureSequenceService` backs both the dialog and `spark email:schedule-nurture --tag=… --start="YYYY-MM-DD HH:MM" [--reply-to --daily-limit --copy-to --dry-run]` (idempotent by campaign name, all-or-nothing). CI4's CLI parser only reads `--opt value`; that command also accepts `--opt=value`.
  - **Replies + daily report.** `ReplyTracker` scans the tenant's `email_imap` mailbox (`Mailbox/ImapReplyMailbox`: OP_READONLY + FT_PEEK — it is usually the owner's real inbox, so nothing is marked read and non-campaign mail is ignored, never turned into contacts). A message is a reply only if its sender is someone a campaign emailed; it becomes an inbound `emails` row (`message_id` dedupe, `is_auto_reply` for out-of-office), sets `replied_at` on the answered email, logs an activity, and `ReplyNotifier` alerts `integrations.config.notify_to` (Reply-To = customer). Repliers (non-auto) are excluded from every later follow-up in `applyFollowup`. `EmailDailyReport` emails notify_to at 09:00 IST (claim-first via `config.report_sent_on`). Both run from `campaigns:dispatch-scheduled` through `EmailInboxRunner` (poll every 5 min, 7-day look-back) — no new cron. Needs ext-imap on the server (`apt-get install php8.3-imap`). Manual: `spark email:replies-sync [--dry-run]`, `spark email:report [--send] [--notify-to=…]`. The older `email:poll-imap` (auto-creates contacts, marks read) is NOT scheduled — never point it at a personal inbox. `EmailsController::saveConfig` now keeps config keys the form doesn't manage.
  - `email:schedule-nurture` also refuses when any active campaign already sends the same first template to the same tag (the name-only check let a renamed re-run double every email, Sep 2026).
  - `ScheduledCampaignDispatcher::toUtc` accepts backward-compatible zone aliases — browsers in India often report `Asia/Calcutta`, which the default `timezone_identifiers_list()` omits, so every schedule from such a browser used to 422.

**New cron:** `campaigns:dispatch-scheduled` (every min), `flows:dates` (daily), `waba:billing-sync` (daily), `meta:ads-sync` (daily). Webhook delivery rides `flow:work`.

**Demo data:** `DemoFeaturesSeeder` seeds products, a routing rule, a sample webhook, a `birthday` custom field (one contact dated today), and a non-default contact language — so every module is clickable on a fresh clone.
