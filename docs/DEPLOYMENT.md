# Deployment runbook — travelpilot.gamavis.com

Deploying TravelPilot to a Hostinger (or any nginx/Apache + PHP-FPM) host.

Written against the state on 1 Sept 2026: CodeIgniter 4.7.3, PHP 8.2+, Node
v20.20.2, MySQL 8.

Run `php spark golive:check` after every step — it exits non-zero while any
blocker remains, so it doubles as the gate at the end of a deploy script.

---

## 0. What you need before starting

| | |
|---|---|
| SSH or file access | to the host serving `travelpilot.gamavis.com` |
| PHP | 8.2 or newer, with `intl`, `mbstring`, `json`, `curl`, `mysqlnd` |
| MySQL | 8, with an empty database and a user that can create tables |
| Node | only to build the frontend — this can be done locally and the `dist/` uploaded |
| Meta App Secret | Meta App Dashboard → Settings → Basic → App Secret |

The DNS record already exists and resolves. Right now it serves Hostinger's
default page.

---

## 1. Get the code onto the server

```bash
git clone git@github.com:gamavissoftware/whatsapptool.git travelpilot
cd travelpilot/backend
php8.3 $(command -v composer) install --no-dev --optimize-autoloader
```

**Invoke Composer through the PHP you actually serve with.** On this host the
default `php` is 8.2 while the site runs on 8.3, and a bare `composer install`
resolves against 8.2 — `phpoffice/phpspreadsheet` then fails on a
`php-64bit ^8.3` constraint and the install silently leaves `vendor` stale. The
site keeps working until the first request that needs the missing package.

`--no-dev` matters: it omits PHPUnit and its dependencies from production.

`.env` is deliberately **not** in the repository. The app will not boot without
one — that is by design, so secrets never reach GitHub.

---

## 2. Build the frontend

Either on the server (needs Node v20.20.2):

```bash
cd travelpilot/frontend
npm ci
npm run build      # → frontend/dist
```

…or build locally and upload `frontend/dist` to the server. The build is fully
static; nothing in it is environment-specific, because the browser calls `/api`
on the same origin.

---

## 3. Create the database

```sql
CREATE DATABASE travelpilot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'travelpilot'@'localhost' IDENTIFIED BY '<strong password>';
GRANT ALL PRIVILEGES ON travelpilot.* TO 'travelpilot'@'localhost';
FLUSH PRIVILEGES;
```

---

## 4. Write `backend/.env`

Copy `backend/.env.example` and set at least these. Everything else can keep its
example default.

```ini
CI_ENVIRONMENT = production

app.baseURL = 'https://travelpilot.gamavis.com'
APP_URL     = https://travelpilot.gamavis.com
CORS_ORIGIN = https://travelpilot.gamavis.com
APP_MODE    = saas

database.default.hostname = localhost
database.default.database = travelpilot
database.default.username = travelpilot
database.default.password = <db password>
database.default.DBDriver = MySQLi
database.default.port     = 3306

encryption.key = <generated in step 5>

# Meta — the webhook is unauthenticated without these
META_APP_SECRET          = <Meta App Dashboard → Settings → Basic>
WEBHOOK_VERIFY_SIGNATURE = true
META_GRAPH_VERSION       = v22.0

WHATSAPP_MOCK_MODE   = false
META_LEADS_MOCK_MODE = false
AI_MOCK_MODE         = false
RAZORPAY_MOCK_MODE   = false
RAZORPAY_VERIFY_SIGNATURE  = true
ECOMMERCE_VERIFY_SIGNATURE = true

QUEUE_DRIVER = database
```

### Check the file before you reload — every time

```bash
cd backend && php scripts/env-check.php
```

A malformed `.env` is the worst failure this app has. CodeIgniter's DotEnv parser
throws during boot, *before* the logger exists, so every request dies as a bare
HTTP 500 with **nothing written to any log** — and because the frontend is static
files, the site still loads and simply shows no data. It looks like a database or
API bug and it is not. It also kills every cron run, so the queue worker stops.

The rule that bites: **an unquoted value may not contain a space.**

```ini
META_APP_SECRET = <new secret>     # FATAL — app will not boot
META_APP_SECRET = "two words"      # fine, quoted
META_APP_SECRET = aff71205c65d…    # fine, no spaces
```

This took production down for 45 hours on 8 Sep 2026. `env-check.php` deliberately
does not boot the framework, so it still runs when the app is dead; it exits
non-zero on anything fatal. `golive:check` reports the same file as its `Env file`
row, but it can only run once the app boots — so `env-check.php` is the one to
reach for after an edit.

**Set `META_APP_SECRET` before flipping `WEBHOOK_VERIFY_SIGNATURE` to true.**
The flag without the secret rejects every incoming webhook, which silently stops
inbound messages and delivery receipts.

`CI_ENVIRONMENT = production` is not cosmetic: in `development` the app returns
framework stack traces and file paths in API errors, and injects the debug
toolbar into public pages including the hosted lead form.

---

## 5. Generate the encryption key

```bash
cd backend
php spark key:generate
```

This encrypts WABA access tokens, provider credentials and API keys at rest.

> **Migrating an existing database?** The key must be the *same* one that
> encrypted the existing rows. Copy `encryption.key` from the old `.env` instead
> of generating a new one, or every stored token becomes undecryptable.

---

## 6. Run the migrations

```bash
cd backend
php spark migrate
```

107 migrations as of this writing. Verify with `php spark migrate:status`.

Do **not** run `php spark db:seed DatabaseSeeder` on production — it creates
demo accounts (`admin@demo.test`, `agent@demo.test`) whose password is published
in the repository, plus demo contacts and a demo catalogue.

---

## 7. Web server

The backend's document root is `backend/public` — never the repository root, or
`.env`, `app/` and `writable/` become downloadable.

Two workable layouts:

### A. Single vhost (recommended)

Serve the built frontend at `/`, route the API and webhook paths to the backend.

```nginx
server {
    listen 443 ssl http2;
    server_name travelpilot.gamavis.com;

    ssl_certificate     /path/fullchain.pem;
    ssl_certificate_key /path/privkey.pem;

    root /var/www/travelpilot/frontend/dist;
    index index.html;

    client_max_body_size 20M;   # CSV imports and media uploads

    # SPA — hash routing, so everything falls back to index.html
    location / {
        try_files $uri $uri/ /index.html;
    }

    # Backend: API, Meta/Razorpay/Shopify webhooks, hosted forms, embed script
    location ~ ^/(api|webhooks|forms|embed)(/|$) {
        root /var/www/travelpilot/backend/public;
        try_files $uri /index.php$is_args$args;

        location ~ \.php$ {
            include fastcgi_params;
            fastcgi_pass unix:/run/php/php8.3-fpm.sock;
            fastcgi_param SCRIPT_FILENAME /var/www/travelpilot/backend/public/index.php;
        }
    }
}
```

### B. Two vhosts

`travelpilot.gamavis.com` serves `frontend/dist`, `api.travelpilot.gamavis.com`
serves `backend/public`. If you do this, `CORS_ORIGIN` must list the frontend
origin, and `app.baseURL` must be the **API** host, since it builds the webhook
URL and the hosted form's action.

### Permissions

```bash
chown -R www-data:www-data backend/writable
chmod -R 775 backend/writable
```

`writable/` must be writable by PHP-FPM or sessions, logs and uploads fail.

---

## 8. Cron

`flow:work` is the one that matters — campaigns, flow steps, lead imports and
outbound webhooks all stall without it.

```cron
* * * * *  cd /var/www/travelpilot/backend && php spark flow:work                 >> /dev/null 2>&1
* * * * *  cd /var/www/travelpilot/backend && php spark campaigns:dispatch-scheduled >> /dev/null 2>&1
* * * * *  cd /var/www/travelpilot/backend && php spark social:dispatch            >> /dev/null 2>&1
0 2 * * *  cd /var/www/travelpilot/backend && php spark flows:dates               >> /dev/null 2>&1
0 3 * * *  cd /var/www/travelpilot/backend && php spark waba:quality-sync         >> /dev/null 2>&1
0 4 * * *  cd /var/www/travelpilot/backend && php spark template:sync             >> /dev/null 2>&1
0 5 * * *  cd /var/www/travelpilot/backend && php spark subscription:check        >> /dev/null 2>&1
```

`jobs:run` is a no-op stub — do not schedule it.

Connecting a Facebook Page to the Social Planner needs a one-time setup in
the Meta app (Facebook Login product + the OAuth redirect URI + `META_APP_ID`).
See [Social Planner — Connect with Facebook](./SOCIAL_FACEBOOK_LOGIN.md).

Confirm the worker is alive after install: `golive:check` reports how long ago a
job was last processed.

---

## 9. Point Meta at the new host

Once the domain serves the app:

1. **Meta App Dashboard → WhatsApp → Configuration → Webhook**
   - Callback URL: `https://travelpilot.gamavis.com/webhooks/whatsapp`
   - Verify token: the value in `waba_accounts.verify_token` (Settings →
     WhatsApp in the app shows it)
   - Subscribe to the **messages** field
2. Verify it: `GET https://travelpilot.gamavis.com/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=42&hub.verify_token=<token>` must return `42`
3. **Razorpay dashboard** → webhook → `https://travelpilot.gamavis.com/webhooks/razorpay-payments`, events `payment_link.paid`, `.cancelled`, `.expired`
4. Retire the ngrok tunnel — it is no longer the path for anything

Until step 1 is done, inbound messages and delivery receipts keep arriving at
the old address. `golive:check` warns while `APP_URL` and `app.baseURL` disagree.

---

## 10. Verify

```bash
cd backend && php scripts/env-check.php   # must exit 0 — works even if the app is broken
cd backend && php spark golive:check      # must exit 0
```

Then, by hand:

- Sign in as the owner
- Add one contact and send an approved template — confirm it reaches the phone
- Reply from that phone — confirm the message appears in the Inbox and the
  24-hour window opens
- Open the hosted form (`/forms/<token>`) and submit it — confirm a contact is
  created
- Check `backend/writable/logs/` for errors

---

## Notes carried over from setup

**Media hosting.** Template header and carousel images are fetched by Meta from
their URL on *every send*. A slow host fails the message with
`#131053 — Your server hosting media content did not respond back in time`.
Keep images at 100–200 KB; ~1.3 MB files caused exactly this failure.

**Active flows.** Flows with a `lead_created` or `meta_lead_received` trigger
fire on the first contact that arrives. Check the Flows page before importing a
list.

**Opt-in.** Marketing templates to contacts who have not opted in are the
fastest way to lose a green quality rating.

**Backups.** `mysqldump` before any migration on a database that holds real
conversations. `backend/storage/` and `backend/writable/uploads/` hold runtime
data that is not in git.
