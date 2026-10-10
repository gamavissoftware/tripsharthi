# Deploying TripSarthi on your server

Server: Ubuntu 22.04 / 24.04 with Caddy (already running there). DNS for `tripsarthi.com`, `www` and `app` already points at the server.

| Address | What it serves |
|---|---|
| `https://tripsarthi.com` | the marketing website (static) |
| `https://app.tripsarthi.com` | the product (web app + API + webhooks) |

## 1. Connect to the server
From your own computer's terminal (you type the password yourself — nobody else needs it):

```bash
ssh root@103.209.146.108
```

## 2. Run the setup (about 10–15 minutes)
```bash
curl -fsSL https://raw.githubusercontent.com/gamavissoftware/tripsharthi/main/deploy/server-setup.sh -o setup.sh
less setup.sh        # optional: read what it will do, press q to leave
bash setup.sh
```
It installs PHP 8.3, MySQL 8, Composer, Node 20 and Caddy; downloads the code from GitHub; creates the database and a production `.env` with **generated** passwords and encryption key (never printed); builds the app; creates the tables; configures Caddy with automatic HTTPS; installs the scheduled jobs and nightly backups; and turns on a firewall (SSH, HTTP, HTTPS only). Running it again is safe — it keeps your existing `.env`. Your old Caddy config is saved as `/etc/caddy/Caddyfile.bak-<date>`.

At the end it prints three health checks. Open `https://tripsarthi.com` and `https://app.tripsarthi.com`.

## 3. Create your account and become platform admin
1. Open `https://app.tripsarthi.com/#/register` and sign up with your own email.
2. On the server:
   ```bash
   cd /var/www/tripsarthi/backend
   php spark admin:grant you@yourmail.com
   ```
   After you sign in again, a **Platform admin** menu appears (customers, subscriptions, live chat, enquiries).

## 4. Turn on email
Edit `/var/www/tripsarthi/backend/.env` and fill in your mail provider (the website contact form, live-chat alerts, password resets and invoices all use it):
```
MAIL_HOST=smtp.yourprovider.com
MAIL_PORT=587
MAIL_CRYPTO=tls
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=noreply@tripsarthi.com
```
Then `systemctl reload php8.3-fpm`. Test with the website contact form; `php spark contact:list` shows whether the notification went out.

`php spark golive:check` (run at the end of the setup) lists what is still missing. Expect these until you connect the services: **META_APP_SECRET** (needed for WhatsApp / Meta webhooks — put it in `.env`), a WhatsApp number, and the queue note (it clears after the first minute of cron).

Other settings to fill in when you are ready: Razorpay (`Settings → Payments` inside the app), WhatsApp Business (Settings → Channels), `ANTHROPIC_API_KEY` for the AI features, `GOOGLE_ADS_CLIENT_ID/SECRET`, `META_*`. Everything starts with all *mock modes switched off*, so nothing is simulated.

## 5. Secure the server (please do this today)
The root password was shared in a screenshot, so treat it as exposed.
1. **Change the root password:** `passwd`
2. **Use an SSH key instead of a password.** On your own computer: `ssh-keygen -t ed25519` then `ssh-copy-id root@103.209.146.108`. Confirm that `ssh root@103.209.146.108` now logs in without asking for the password.
3. **Then disable password logins** (only after step 2 works): edit `/etc/ssh/sshd_config`, set `PasswordAuthentication no` and `PermitRootLogin prohibit-password`, then `systemctl restart ssh`.
4. Ask Utho support to confirm no other panel/login shares that password.

## 6. Updating later
After new code is pushed to GitHub:
```bash
bash /var/www/tripsarthi/deploy/update.sh            # everything (backup -> code -> migrations -> rebuild)
bash /var/www/tripsarthi/deploy/update.sh --website-only
```

## 7. Backups
`/etc/cron.d/tripsarthi` takes a database dump and an archive of uploaded files (invoice PDFs, customer documents) every night at 01:30 into `/var/backups/tripsarthi` and keeps 14 days. **Copy that folder to another machine or cloud storage regularly** — a backup on the same disk does not survive losing the server. Restore a database with:
```bash
gunzip -c /var/backups/tripsarthi/db-YYYYMMDD-HHMMSS.sql.gz | mysql tripsarthi
```

## 8. Useful commands
```bash
systemctl status caddy php8.3-fpm mysql          # are the services up?
journalctl -u caddy -n 50 --no-pager             # web server / certificate problems
tail -f /var/www/tripsarthi/backend/writable/logs/log-$(date +%Y-%m-%d).log
cd /var/www/tripsarthi/backend && php spark golive:check     # configuration check
cd /var/www/tripsarthi/backend && php spark contact:list --new
```

## Troubleshooting
- **HTTPS certificate not issued:** the three names must resolve to this server (`dig +short tripsarthi.com`), ports 80 and 443 must be open, and `journalctl -u caddy` shows the reason. Let's Encrypt allows only a few attempts an hour.
- **App shows a blank page:** check `ls /var/www/tripsarthi/frontend/dist` exists (the build step may have run out of memory — add swap, or run `update.sh` again).
- **API errors (500):** read the log file above; the most common cause is a missing value in `.env`.
- **Webhooks (WhatsApp, Razorpay, Meta):** use `https://app.tripsarthi.com/webhooks/...` as the callback URLs.
- **The GitHub repository is public** (anyone can read the code, no secrets are in it). To make it private, change it in GitHub → Settings, then give the server a read-only deploy key and set `REPO` to the SSH URL.

## What was tested
The setup script was run end to end in a clean Ubuntu 24.04 container (packages, database, `.env` with generated secrets, all 149 migrations from an empty database, web-app build, cron file, backup and restore of the dump), and the Caddy configuration was validated and exercised with a real Caddy + PHP-FPM against the app (API routes, webhooks, page fallback, caching headers, blocked files). **It has not yet been run on your real server** — that first run, and Let's Encrypt issuing the certificates, are the parts only the live server can prove.
