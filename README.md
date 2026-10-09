# TravelPilot

CRM + WhatsApp automation + ad management for the **Indian travel industry** — enquiries, AI itineraries with GST/TCS-correct quotes,
bookings and instalments, supplier rate cards, and Meta/Google ad attribution with closed-loop conversion feedback.
Forked from the LeadPilot platform. **Start with [CLAUDE.md](CLAUDE.md) and [docs/TRAVELPILOT_BLUEPRINT.md](docs/TRAVELPILOT_BLUEPRINT.md).**
Web: `frontend/` (React) · API: `backend/` (CodeIgniter 4) · Agent mobile app: `mobile/` (Expo). Ports/dev commands are in CLAUDE.md.

---

# TravelPilot

WhatsApp Lead Management & Automation Platform — with a full **WhatsApp-native CRM**  
By **Gamavis Software Solutions**

---

## 📚 Documentation

- **[docs/](docs/README.md)** — documentation index.
- **CRM module:** [Capabilities (as-built) + gaps](docs/CRM/CRM_CAPABILITIES.md) · [Architecture](docs/CRM/CRM_ARCHITECTURE.md)
- **Integrations:** [Meta App Review](docs/META_APP_REVIEW.md)

The CRM covers sales (deals/kanban/quotes), service (tickets/SLA), automation (CRM events ⇄ WhatsApp via flows), and per-vertical custom objects — all multi-tenant with a reporting dashboard. Read the **[Capabilities doc](docs/CRM/CRM_CAPABILITIES.md)** to compare features against your requirements.

**Phase L additions:** native **calendar/meetings** (with a `meeting_scheduled` flow trigger), an **AI sales assistant** that generates call scripts & qualifying questions from a lead's context (metered), **email** as an outbound CRM channel (per-tenant SMTP, logged to the contact timeline), **field-level visibility** (mark custom fields owner/admin-only), and **business-hours-aware, minute-precise** ticket SLAs.

---

## Local Setup (≤ 10 steps)

> **Requirements:** PHP 8.3, Composer 2, MySQL 8, Node 20 LTS, XAMPP/MAMP (or equivalent).

### 1 — Clone and enter the repo

```bash
git clone <repo-url> travelpilot
cd travelpilot
```

### 2 — Install backend dependencies

```bash
cd backend
composer install
```

### 3 — Configure the backend environment

```bash
cp .env.example .env
```

Open `backend/.env` and set **at minimum**:

```ini
database.default.username = root
database.default.password =          # your MySQL root password, or leave blank
```

Generate an encryption key (if not already set by `key:generate` during install):

```bash
php spark key:generate
```

### 4 — Create the database

```sql
CREATE DATABASE travelpilot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

(In XAMPP: open phpMyAdmin → New → `travelpilot`.)

### 5 — Run migrations

```bash
php spark migrate
```

Expected output: `CreateTenants` and `CreateUsers` run successfully.

### 6 — Seed demo data

```bash
php spark db:seed DatabaseSeeder
```

This creates:

| Email | Password | Role |
|---|---|---|
| `owner@demo.test` | `password` | owner |
| `admin@demo.test` | `password` | admin |
| `agent@demo.test` | `password` | agent |

### 7 — Start the backend dev server

```bash
php spark serve
# Listening on http://localhost:8080
```

### 8 — Install and start the frontend

In a **second terminal**:

```bash
cd frontend
npm install      # first time only
npm run dev
# Vite → http://localhost:5173
```

### 9 — Open the app

Visit **http://localhost:5173** and log in with `owner@demo.test` / `password`.

The Vite proxy forwards `/api/*` → `http://localhost:8080` so no CORS setup is needed locally.

### 10 — Run the PHPUnit test suite

```bash
cd backend
vendor/bin/phpunit
```

All tests should be green.

---

## APP_MODE switch

| Mode | `.env` | Effect |
|---|---|---|
| **SaaS** (default) | `APP_MODE=saas` | Registration open, Razorpay billing UI visible |
| **Self-hosted** | `APP_MODE=self_hosted` | Registration disabled, billing hidden, pro limits always active |

To test self-hosted mode locally, just change `APP_MODE=self_hosted` in `backend/.env` and restart `php spark serve`.

---

## Cron jobs (Sprint 3+)

Add these lines to your crontab. **`flow:work` is the one worker that drains the
whole database queue** — flow runs, campaign sends, lead imports, outbound
webhooks, and Meta Lead Ads fetches all flow through it. (`jobs:run` is a Sprint 3
placeholder stub and a no-op — do **not** rely on it.)

```cron
# Every minute — THE queue worker: flow runs, campaign sends, imports,
# outbound webhooks, lead-ads fetches, durable delays + node execution
* * * * * /usr/bin/php /absolute/path/to/backend/spark flow:work >> /dev/null 2>&1
# Every minute — enqueue scheduled broadcasts whose send time has arrived
* * * * * /usr/bin/php /absolute/path/to/backend/spark campaigns:dispatch-scheduled >> /dev/null 2>&1
# Every minute — publish scheduled Facebook Page / Instagram posts (Social Planner)
* * * * * /usr/bin/php /absolute/path/to/backend/spark social:dispatch >> /dev/null 2>&1
# Daily 9am — fire date-based flow triggers (birthdays, renewal reminders)
0 9 * * * /usr/bin/php /absolute/path/to/backend/spark flows:dates >> /dev/null 2>&1
# Daily — license grace period check (self_hosted mode only)
0 2 * * * /usr/bin/php /absolute/path/to/backend/spark license:check >> /dev/null 2>&1
# Daily — Razorpay subscription halt-grace enforcement (saas mode only)
0 3 * * * /usr/bin/php /absolute/path/to/backend/spark subscription:check >> /dev/null 2>&1
# Every 6 hours — sync WhatsApp phone number quality ratings from Meta
0 */6 * * * /usr/bin/php /absolute/path/to/backend/spark waba:quality-sync >> /dev/null 2>&1
# Every 2 hours — sync WhatsApp template approval statuses from Meta
0 */2 * * * /usr/bin/php /absolute/path/to/backend/spark template:sync >> /dev/null 2>&1

# ── CRM intelligence & routing (Phase H) ───────────────────────────────
# Every 5 min — fire task_due reminders for matured tasks (pairs with flow:work)
*/5 * * * * /usr/bin/php /absolute/path/to/backend/spark tasks:remind >> /dev/null 2>&1
# Every 10 min — escalate SLA-breached tickets (reassign least-loaded agent)
*/10 * * * * /usr/bin/php /absolute/path/to/backend/spark tickets:sla-scan >> /dev/null 2>&1
# Nightly 1:30am — recompute lead scores (applies recency decay)
30 1 * * * /usr/bin/php /absolute/path/to/backend/spark scoring:recalc >> /dev/null 2>&1
# Nightly 1:45am — re-queue stale leads + report rotting deals
45 1 * * * /usr/bin/php /absolute/path/to/backend/spark crm:rotting-scan >> /dev/null 2>&1
# Nightly 1:15am — open renewal deals for due won recurring (subscription) deals
15 1 * * * /usr/bin/php /absolute/path/to/backend/spark deals:renewals >> /dev/null 2>&1
# Every 5 min — poll connected IMAP mailboxes for inbound email (requires ext-imap)
*/5 * * * * /usr/bin/php /absolute/path/to/backend/spark email:poll-imap >> /dev/null 2>&1
```

---

## Production deploy (Hostinger Cloud)

1. Copy repo to server; run `composer install --no-dev` and `npm run build`.
2. Point the web root to `backend/public` (CI4) and serve the built `frontend/dist` from the same domain or a CDN.
3. Set `CI_ENVIRONMENT=production`, real DB credentials, `APP_MODE`, and payment/WhatsApp keys in `.env`.
4. Run `php spark migrate` on the server.
5. Configure cron for `flow:work` (the queue worker) plus the scheduled commands listed in the [Cron jobs](#cron-jobs-sprint-3) section above. Do not use `jobs:run` (no-op stub).
6. Enforce HTTPS at the web-server level (Nginx/Apache); the `forcehttps` CI4 filter is intentionally disabled (server handles TLS).

---

## Sprint roadmap

| Sprint | Focus |
|---|---|
| **0** ✅ | Skeleton: CI4 + Vite, auth, tenancy, FeatureGate, seeders |
| 1 | Leads core: contacts CRUD, CSV import, tags, embeddable form |
| 2 | WhatsApp core: Cloud API client, webhook, 24-h window, inbox |
| 3 | Templates & campaigns: create/submit/sync, broadcast |
| 4 | Flow engine: visual builder, node executor, durable delays |
| 5 | Meta Lead Ads: leadgen webhook, Graph API |
| 6 | Monetization: Razorpay (SaaS) + license key (self-hosted) |
| 7 | Hardening & launch: security, rate-limiting, white-label |

---

## Feature modules (post-v1, P1–P4)

Built to reach competitive parity with (and beyond) AiSensy. Each feature is migration-clean and unit-tested.

| Phase | Features | Where to find it |
|---|---|---|
| **P1** | Contact CSV **export**; broadcast **scheduler + timezones**; campaign **retargeting**; **click/CTA analytics**; **agent auto-routing** + flow **handoff** node | Contacts, Campaigns, Analytics, Settings → Inbox Routing |
| **P2** | **Payment links** in chat (Razorpay, per-tenant); **product catalog** + product messages + order capture; **Shopify** & **WooCommerce** webhooks | Settings → Payments / Store Sync, Products, Inbox 💳 |
| **P3** | **AI reply** node + inbox ✨ Suggest; **outbound webhooks / Zapier**; **WhatsApp Flows** (send + capture) | Flow builder, Inbox, Settings → Webhooks |
| **P4** | **Link & QR generator**; campaign **A/B testing**; **multi-language templates**; **birthday/date automations** | Link & QR, Campaigns, Contacts (language), Flow builder |

**Flow engine** now spans 25 node types — actions: `send_template/freeform/media/interactive`, `add_tag/remove_tag/update_status`, `assign_agent`, `handoff`, `send_payment`, `send_product`, `ai_reply`, `send_flow`, `webhook_call`, `delay`; control: `condition`, `window_check`; triggers: `lead_created`, `tag_added`, `form_submitted`, `meta_lead_received`, `keyword_reply`, `inbound_message`, `order_placed`, `order_fulfilled`, `abandoned_cart`, `flow_response`, `date_reached`.

### Per-tenant connections (bring-your-own, configured in-app)
| Integration | Page | Notes |
|---|---|---|
| Razorpay payments | Settings → Payments | Webhook: `POST /webhooks/razorpay-payments` |
| Meta product catalog | Products | Paste your Meta catalog ID |
| Shopify | Settings → Store Sync | Webhook: `POST /webhooks/shopify` |
| WooCommerce | Settings → Store Sync | Webhook: `POST /webhooks/woocommerce` |
| Outbound webhooks | Settings → Webhooks | Signed with `X-TravelPilot-Signature` (HMAC-SHA256) |
| AI replies | Settings → AI Assistant (per-tenant key) or server `ANTHROPIC_API_KEY` | Metered per plan (`ai_replies` limit); `AI_MOCK_MODE=true` for local |

### Caveats (WhatsApp platform limits, documented in-app)
- Click tracking covers quick-reply / list / template-button taps — URL CTA taps don't emit a webhook.
- WhatsApp Flows must be authored & published in Meta WhatsApp Manager; TravelPilot sends a published Flow and captures its submission.
- Payment / product / AI sends are free-form → require an open 24-hour window (gate with a Window Check node for strict flows).
