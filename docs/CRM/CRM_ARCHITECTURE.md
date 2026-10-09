# TravelPilot CRM — Architecture & Schema Spec

> Evolving TravelPilot from a WhatsApp lead tool into a **WhatsApp-native, multi-industry CRM** on the existing CI4 + React + MySQL, multi-tenant SaaS foundation.
> Status: design (pre-build). Read before writing CRM code.

---

## 1. Vision & principles

A **world-class, configurable CRM** where WhatsApp is the native communication fabric — not a bolt-on. One codebase serves every industry through configurable pipelines, custom fields, and custom objects.

Three layers, cleanly separated:
- **System of record** — the CRM data (accounts, contacts, deals, tickets, custom objects). The truth.
- **Communication fabric** — WhatsApp conversations/messages (already built), email/calls later. The touch.
- **Automation engine** — the existing durable `flows` engine, extended with CRM triggers/actions. The glue.

Design rules:
1. **Multi-tenant always** — every table carries `tenant_id`, scoped via `BaseModel` (fail-closed). Soft deletes everywhere.
2. **Configurable over hard-coded** — pipelines, stages, fields, and objects are data, not code. This is what makes it multi-industry.
3. **Polymorphic associations** — any record links to any record (contact↔deal↔account↔ticket↔custom object). One association model, infinite shapes.
4. **One automation engine** — extend `flows` with CRM triggers/actions rather than building a second engine. The 24h WhatsApp window remains the governing rule for every outbound message.
5. **WhatsApp-native lead model** — a lead IS a contact from message #1 (no separate Lead object). Lifecycle stage + score live on the contact.

---

## 2. Domain model — existing → new

| CRM concept | Today | Plan |
|---|---|---|
| People | `contacts` (flat, lead-centric `status`) | extend: `account_id`, `owner_id`, `lifecycle_stage`, `lead_score`, `job_title`, address |
| Companies (B2B) | — | **`accounts`** (new) |
| Team | `users` (owner/admin/agent) | add **`teams`** + `team_members` for assignment/visibility |
| Sales pipeline | `contacts.status` enum (too flat) | **`pipelines`** + **`pipeline_stages`** (configurable) |
| Opportunities | — | **`deals`** + `deal_contacts` + `deal_line_items` |
| Work items | — | **`tasks`** (assignable, due, reminders) |
| Timeline | `messages`, `flow_run_logs` (siloed) | **`activities`** (unified, polymorphic, immutable log) |
| Notes | — | **`notes`** (editable, pinnable, polymorphic) |
| Support | `conversations.status` (open/resolved) | **`tickets`** + support pipeline + SLA |
| Custom data | `custom_fields`/`contact_field_values` (contacts only) | generalize: `entity_type` + polymorphic **`field_values`** |
| Industry objects | — | **`custom_objects`** + `custom_object_records` |
| Links | implicit FKs | **`associations`** (polymorphic, any↔any) |
| Segments/lists | `segments` (contacts) | generalize with `entity_type`; add **`saved_views`** |
| Comms | `conversations`/`messages`/`templates`/`campaigns` | reuse as-is; surface in `activities`; attach to deals/tickets |
| Automation | `flows`/`flow_runs`/`jobs` | extend triggers + actions with CRM events |
| Commerce | `products`, `commerce_orders`, `payment_links` | reuse; `deal_line_items` ties products to deals; quotes later |
| Billing | `subscriptions`/`licenses`/plan limits | extend limits (deals, objects, pipelines, seats) |

---

## 3. Lead-to-cash flow (the CRM lifecycle)

```
CAPTURE → QUALIFY → CONVERT → PIPELINE → CLOSE → ONBOARD → SERVE → RETAIN/GROW
```

1. **Capture** — lead enters via WhatsApp inbound, web form, Meta/Google Lead Ads, CSV, manual, or API → upsert `contact` (lifecycle_stage = `lead`), open `conversation` if WhatsApp. Source attributed.
2. **Qualify** — lead scoring + enrichment; owner assignment (round-robin / `routing_rules`); agent chats in the 24h window. Stage → MQL → SQL.
3. **Convert** — spin up a **`deal`** in a pipeline; link contact (+ account for B2B). Manual or flow-driven.
4. **Work the pipeline** — drag deal through stages; create **tasks** (calls/follow-ups); fire stage-based **WhatsApp templates**; attach products/quotes; everything lands in the **activities** timeline. Rotting-deal alerts.
5. **Close** — **Won** → `deal.status=won`, contact → `customer`, fire onboarding flow + WhatsApp; or **Lost** → capture reason, drop into nurture flow.
6. **Onboard & Serve** — post-sale **tickets** (often WhatsApp-originated), SLA timers, CSAT via WhatsApp.
7. **Retain & grow** — renewals pipeline, upsell deals, NPS over WhatsApp, segments + campaigns.

WhatsApp threads through **every** stage; the CRM is the record; flows connect events to actions.

---

## 4. Schema — new tables

All new tables: `id`, `tenant_id` (indexed, FK), `created_at`, `updated_at`, `deleted_at`, owner via `owner_id` where noted. Money in **paise** (INR), currency code stored.

### 4.1 accounts (companies)
`name, domain, industry, owner_id, phone, website, billing_* / shipping_* address, annual_revenue, employee_count, parent_account_id (self-FK), lifecycle_stage, notes`

### 4.2 contacts (extend existing)
add: `account_id` (FK), `owner_id` (FK users), `job_title`, `lifecycle_stage` ENUM(`subscriber,lead,mql,sql,opportunity,customer,evangelist,other`), `lead_score` (int), `phone_secondary`, `address_*`. Keep `wa_number` as the WhatsApp identity; keep `status` for pipeline-agnostic state or deprecate in favor of lifecycle_stage + deal status.

### 4.3 pipelines
`name, entity_type ENUM(deal,ticket), is_default, position`

### 4.4 pipeline_stages
`pipeline_id (FK), name, position, probability (0-100), is_won (bool), is_lost (bool), rotting_days (int null)`

### 4.5 deals (opportunities)
`title, pipeline_id, stage_id, account_id, primary_contact_id, owner_id, value_amount (paise), currency, expected_close_date, status ENUM(open,won,lost), probability, source, lost_reason, won_at, last_activity_at`

### 4.6 deal_contacts (M:N)
`deal_id, contact_id, role (e.g. decision_maker/influencer/champion)`

### 4.7 deal_line_items
`deal_id, product_id (FK products), name, quantity, unit_price (paise), discount_pct, tax_pct, total (paise)`

### 4.8 tasks
`title, description, type ENUM(call,whatsapp,email,meeting,todo), status ENUM(open,done), priority ENUM(low,med,high), due_at, reminder_at, assigned_user_id, related_type, related_id (polymorphic), created_by, completed_at`

### 4.9 activities (unified timeline — immutable)
`type ENUM(note,call,meeting,whatsapp_in,whatsapp_out,email,task_done,stage_change,field_change,deal_won,deal_lost,system), subject, body, related_type, related_id (polymorphic), actor_user_id, meta (json: e.g. {from_stage,to_stage} or {message_id}), occurred_at`
→ WhatsApp messages mirror here (whatsapp_in/out) so a record's timeline shows the full thread inline.

### 4.10 notes
`body, related_type, related_id (polymorphic), is_pinned, created_by` (editable, unlike activities)

### 4.11 tickets (cases)
`subject, description, contact_id, account_id, pipeline_id (support), stage_id, status ENUM(open,pending,resolved,closed), priority, owner_id, source ENUM(whatsapp,email,web,manual), category, sla_due_at, resolved_at, conversation_id (FK, optional)`

### 4.12 custom_objects (multi-industry)
`label_singular, label_plural, api_name (unique per tenant), icon` — defines e.g. Property, Patient, Vehicle, Policy, Course.

### 4.13 custom_object_records
`custom_object_id (FK), owner_id, name (display), data` — values via the generalized field system (4.15).

### 4.14 associations (polymorphic any↔any)
`from_type, from_id, to_type, to_id, label` — powers contact↔deal, deal↔ticket, contact↔custom_object, etc. Unique on (tenant_id, from_type, from_id, to_type, to_id).

### 4.15 custom_fields (generalize) + field_values
`custom_fields`: add `entity_type` (contact,account,deal,ticket,custom_object:<id>), keep type/options/required.
`field_values` (new, polymorphic): `field_id, entity_type, entity_id, value` — replaces contact-only `contact_field_values` (migrate existing into it). One values table for all objects.

### 4.16 saved_views (list views)
`entity_type, name, filters (json), columns (json), sort, is_shared, owner_id`

### 4.17 teams + team_members (optional, for assignment/visibility)
`teams(name)`; `team_members(team_id, user_id, role)`.

### 4.18 (Phase F) dashboards / dashboard_widgets, quotes / quote_items, price_books

### Alterations to existing
- `conversations`: add `deal_id`, `ticket_id` (nullable) — or rely on `associations`. Recommend nullable FKs for fast inbox joins.
- `flows`: widen `trigger_type` and node types with CRM events/actions (§6).
- `segments`: add `entity_type`.
- `plan_limits`: add `deals`, `pipelines`, `custom_objects`, `seats`, `dashboards`.

---

## 5. Multi-industry strategy

- **Configurable pipelines** per vertical:
  - Real estate: Inquiry → Viewing → Offer → Negotiation → Closed
  - Healthcare: Inquiry → Consultation → Treatment Plan → Follow-up
  - Education: Inquiry → Counselling → Application → Enrolled
  - Generic B2B: Lead → Qualified → Proposal → Negotiation → Won
- **Custom objects** per vertical (Properties, Patients, Courses, Policies, Vehicles) via §4.12–14.
- **Custom fields** on every object (§4.15).
- **Industry templates** — onboarding asks the industry; we seed pipelines + fields + WhatsApp templates + flows for that vertical. One click to a working CRM.
- **Associations** (§4.14) let any vertical model its real-world relationships without code.

---

## 6. WhatsApp × CRM automation (extend `flows`)

New **triggers**: `deal_created`, `deal_stage_changed`, `deal_won`, `deal_lost`, `lifecycle_stage_changed`, `task_due`, `ticket_created`, `ticket_resolved`, `custom_object_created`, `field_changed`.

New **actions**: `create_deal`, `update_deal_stage`, `assign_owner`, `create_task`, `create_ticket`, `update_field`, `add_to_pipeline` — alongside existing WhatsApp actions (`send_template`, `send_freeform`, etc.).

Governing rule unchanged: every WhatsApp send runs the **24h window check** first → template vs free-form routing. CRM events can always send approved **templates** (e.g. "deal moved to Proposal → send quote template"); free-form only inside an open window.

This keeps **one** durable, queued automation engine for both WhatsApp and CRM workflows.

---

## 7. SaaS / plans

Reuse Razorpay billing + `plan_limits`. Suggested tiers:
- **Starter** — contacts, shared inbox, 1 pipeline, basic fields.
- **Growth** — multi-pipeline, deals, automations (flows), custom fields, tasks.
- **Pro** — custom objects, tickets/SLA, reports/dashboards, API, saved views.
- **Enterprise** — teams/roles, white-label, audit log, advanced forecasting.

Never gate on WhatsApp message volume (Meta bills the customer's WABA). Gate on records/seats/objects/automations.

---

## 8. Build roadmap (phases)

- **Phase A — CRM core**: `accounts`; extend `contacts` (owner, account, lifecycle, score); generalized `custom_fields`/`field_values`; `activities` timeline; `notes`; `tasks`; unified **record page** with the WhatsApp thread inline; owner assignment. *(Foundation — everything else builds on this.)*
- **Phase B — Sales pipeline**: `pipelines`, `pipeline_stages`, `deals`, `deal_contacts`, `deal_line_items`; **kanban deal board**; win/lost; pipeline metrics.
- **Phase C — WhatsApp × CRM automation**: extend `flows` with CRM triggers/actions; stage-based WhatsApp templates; task reminders over WhatsApp; round-robin assignment.
- **Phase D — Service**: `tickets`, support pipeline, SLA timers, CSAT/NPS via WhatsApp; tie tickets to conversations.
- **Phase E — Multi-industry**: `custom_objects`, `custom_object_records`, `associations`, `saved_views`, industry templates + seeders.
- **Phase F — Reporting & sales tooling**: dashboards/reports, forecasting, quotes/CPQ, price books.

Each phase ends with: migrations + seeders, PHPUnit green (Services), and a clickable demo path — same bar as the WhatsApp sprints.

---

## 9. Open decisions (confirm before Phase A)
1. **Lead model** — recommend HubSpot-style (lifecycle_stage on contact, no separate Lead object). WhatsApp-native. ✅ default.
2. **Tasks vs activities** — recommend separate `tasks` (actionable) + `activities` (immutable log). ✅ default.
3. **One automation engine** — recommend extending `flows` rather than a second workflow engine. ✅ default.
4. **First industry template** to seed (drives Phase E priority) — TBD with you.
