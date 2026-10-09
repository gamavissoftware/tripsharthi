# Social Planner — Connect with Facebook

How a Facebook Page gets connected to the Social Planner, what you must set up
once in the Meta app, and why the old "paste a Page Access Token" path is now
the fallback rather than the default.

---

## Why this exists

The original connect form asked for a Page ID and a Page Access Token pasted by
hand. Two problems:

1. **The Graph API Explorer hands out a short-lived token by default** — good
   for an hour or two. The connection looked fine, then every scheduled post
   after that quietly landed in `failed` with a Graph error nobody was reading.
   Nothing in the product said the credential had died.
2. A long-lived page credential travelled through a browser form, a clipboard,
   and whatever sat in between.

Facebook Login fixes both. Meta hands the token straight to the backend, and a
page token derived from a **long-lived user token does not expire**.

---

## One-time setup in the Meta app

You need a Meta app (the same one already used for the WhatsApp webhook is
fine).

1. **Add the Facebook Login product** to the app.
2. Under **Facebook Login → Settings → Valid OAuth Redirect URIs**, add exactly:

   ```
   https://<your-domain>/api/v1/social/oauth/callback
   ```

   The Social page prints the exact string for the current install — copy it
   from there rather than typing it, because Meta matches it byte for byte.

   > It lives under `/api/` on purpose. The production nginx and the Vite dev
   > proxy both already route that prefix to PHP, so this needs no new server
   > config in either place.

3. Put both halves of the app credential in `backend/.env`:

   ```
   META_APP_ID     = 1234567890
   META_APP_SECRET = ...
   ```

   Both must be present. With either missing, `isConfigured()` is false, the
   **Connect with Facebook** button is hidden, and the manual token form is the
   only path — which is the correct behaviour for a self-hosted install that has
   no Meta app of its own.

No restart is needed beyond whatever reloads PHP-FPM after an `.env` change.

### If the app uses Facebook Login **for Business**

Check the left sidebar of the app dashboard. If it reads *Facebook Login for
Business* rather than *Facebook Login*, the classic scope list does not apply:
the dialog rejects it outright with

```
Invalid Scopes: pages_show_list, pages_manage_posts, pages_read_engagement,
instagram_basic, instagram_content_publish
```

Permissions there live in a **configuration** you create in the dashboard
(Facebook Login for Business → Configurations), which also names the asset types
the business will grant — Pages, and Instagram accounts if you publish to
Instagram. Take that configuration's id and add:

```
META_LOGIN_CONFIG_ID = 1234567890
```

`start()` then sends `config_id` instead of `scope`, plus
`override_default_response_type=true` — without which the configuration's own
default response type wins, `response_type=code` is ignored, and no code ever
reaches the callback. Leave the variable empty for a classic app and the scope
list is used as before; both paths are covered by tests.

---

## Permissions and App Review

The flow requests:

| Scope | Why |
|---|---|
| `pages_show_list` | list the Pages the user administers, for the picker |
| `pages_manage_posts` | publish to the Page feed |
| `pages_read_engagement` | read Page metadata (name, linked IG account) |
| `instagram_basic` | resolve the linked Instagram Business account |
| `instagram_content_publish` | publish to Instagram |

**For your own Pages, today, with no App Review:** an app in Development mode
may use any of these on behalf of a user who holds a role on the app (Admin,
Developer or Tester). So if the Facebook account connecting the Page is a role
on the Meta app, this works immediately.

**For customers' Pages (SaaS):** `pages_manage_posts` and
`instagram_content_publish` are reviewable permissions. Selling this to other
businesses means App Review plus Business Verification — the same path as
[Lead Ads](./META_APP_REVIEW.md), and worth submitting together.

---

## The flow, end to end

```
Social page  ──POST /api/v1/social/oauth/start──►  state row (pending, 15-min TTL)
     │                                              returns Meta dialog URL
     ▼
Meta consent dialog
     │  user approves
     ▼
GET /api/v1/social/oauth/callback?code&state   (public — a redirect has no auth header)
     │  code ──► short-lived user token ──► LONG-LIVED user token ──► /me/accounts
     │  stores: encrypted user token + display-only page list;  state → ready
     ▼
302 back to  <APP_URL>/#/social?social_oauth_state=…
     │
     ▼
Page picker  ──POST /api/v1/social/oauth/select──►  page token fetched fresh,
                                                    stored encrypted, state consumed
```

What the design buys:

- **The state row is the authentication.** A callback carries no session, so the
  state is what ties Meta's response to a tenant and user. It is single-use,
  expires in 15 minutes, and a state belonging to another tenant reads as
  "unknown".
- **One credential at rest between steps, not many.** The long-lived *user*
  token is held only between callback and page choice; the page list stashed for
  the picker holds ids and names, never tokens. On selection the chosen page's
  token is fetched fresh and the user token is wiped.
- **No token ever reaches the browser**, in either direction.

---

## When a connection dies

Tokens still die for reasons outside our control — the user revokes the app,
changes their password, or loses admin on the Page.

`SocialPublisher` now classifies Graph failures. Codes that mean *the credential
is gone* (102, 190 and its subcodes 458/459/460/463/464/467) and the permission
codes (10, 200, 294, 299) flip the account to `status = 'reauth_required'` and
record `last_error`. The Social page then shows a banner naming the affected
Pages with a **Reconnect** button.

Ordinary post failures do **not** disconnect a page. Meta labels plain
validation errors (code 100 — an unreachable image URL, say) as
`OAuthException` too, so the classification keys on the **code**, never the
type. A single bad image must not take a healthy Page offline.

Pages connected the old way are badged **manual token** in the list, since those
are the ones most likely to expire without warning.

---

## Cron

Unchanged, and still required — OAuth only affects how a Page is connected, not
how posts go out:

```cron
* * * * * /usr/bin/php8.3 /path/to/backend/spark social:dispatch >> /dev/null 2>&1
```
