# Meta App Review — Lead Ads Integration

TravelPilot's Meta Lead Ads feature requires approval from Meta before it can
receive real leads from production Facebook/Instagram ad campaigns.

---

## Permissions Required

| Permission | Purpose | Review Type |
|---|---|---|
| `leads_retrieval` | Read lead form submissions via the Graph API | **Restricted — requires App Review + Business Verification** |
| `pages_manage_metadata` | Subscribe a Facebook Page to leadgen webhooks | App Review |
| `pages_read_engagement` | Read page information for subscription confirmation | App Review |
| `pages_show_list` | List pages the user manages (for the connection UI) | Standard |
| `facebook_login_for_business` | OAuth flow to obtain Page Access Tokens | Standard |

---

## Approval Timeline

**Realistically plan 2–4 weeks from submission to production-ready.**

| Step | Estimated Duration |
|---|---|
| Business Verification (Meta Business Manager) | 1–3 business days |
| App Review for `leads_retrieval` + `pages_manage_metadata` | 3–7 business days |
| Re-review after rejection (if required) | Adds another cycle |

> Meta's App Review team may request a screen recording, additional context,
> or policy clarifications. Prepare a demo that shows exactly how lead data
> is used within TravelPilot.

---

## Connecting a Page — the Facebook Login path (preferred)

Since 2026-09-24 the Integrations page connects a Page for Lead Ads with the
same **Connect with Facebook** flow the Social Planner uses
(`FacebookOAuthService`), instead of a pasted Page Access Token. Why:

- The Graph API Explorer hands out short-lived tokens; the pasted-token path
  died quietly after an hour or two.
- No credential ever passes through a browser form. Meta hands the token to
  the backend, which stores it encrypted in `social_accounts`, and
  `MetaLeadAdsLinker` copies the *ciphertext* into the `meta_lead_ads`
  integration row.

**Flow:** Integrations → *Connect with Facebook* → approve on Meta → pick the
Page → `POST /api/v1/meta-integrations/link-page` links it **and** subscribes
it to `leadgen` in one go. A Page the Social Planner already connected can be
linked with one click ("Use for Lead Ads") — no second login.

**The token must carry two permissions**, or the subscription is refused with
Meta's `(#200) Requires pages_manage_metadata permission` and the UI offers a
reconnect:

| Permission | Needed for |
|---|---|
| `leads_retrieval` | `GET /{leadgen_id}` — reading the form answers |
| `pages_manage_metadata` | `POST /{page_id}/subscribed_apps` — the leadgen subscription |

For a **Facebook Login for Business** app (ours), permissions are not requested
per call: they must be ticked in the dashboard *configuration*
(`META_LOGIN_CONFIG_ID`), and a configuration can only offer permissions that
one of the app's **Use cases** unlocks. Recipe:

1. Use cases → *Manage everything on your Page* → Permissions and features →
   **Add** `leads_retrieval` and `pages_manage_metadata` (this grants Standard
   Access immediately; it does not submit anything for review).
2. Facebook Login for Business → Configurations → Edit → Permissions → tick
   both → Save.
3. **Reconnect the Page in TravelPilot.** An existing token keeps its old grant
   set; only a fresh login carries the new permissions.

For a classic Facebook Login app both permissions are already in
`FacebookOAuthService::SCOPES`.

**Webhook registration is per app, not per Page** (done once, in the dashboard):
Webhooks → *Page* → Callback URL `https://<your-domain>/webhooks/meta-leads`,
Verify token = the integration's `verify_token` (shown under "webhook details"
on the Integrations page) → Verify and save → subscribe the `leadgen` field.
`MetaLeadAdsLinker` deliberately keeps the `verify_token` across re-links so
this registration never silently breaks.

**Testing a real delivery** without spending on ads. Meta's own tools cannot
do it: the [Lead Ads Testing Tool](https://developers.facebook.com/tools/lead-ads-testing)
fills every field with "dummy data for <field>", and Business Suite's
"Test form" never enables Submit. TravelPilot therefore ships its own:

```bash
php spark meta:test-lead --tenant 1 --list                       # the Page's forms
php spark meta:test-lead --tenant 1 --form <FORM_ID> --phone "+919718991797"
```

`MetaTestLeadService` reads the form's questions, fills every one (the given
number in every phone-like question, first option for choice questions) and
POSTs `/{form}/test_leads`. Meta then fires the real webhook; within a minute
the contact exists (source `meta_lead_ads`), the form answers are on it as
custom fields, `meta_lead_received` has fired and the welcome flow has sent
its template. Reading forms needs `pages_manage_ads` on the token, so that
permission is in the Login configuration too. Meta allows one test lead per
form; if a create fails with "Service temporarily unavailable", delete the
previous one in the Testing Tool and retry. Note the CLI option syntax is
`--form ID` (a space) — `--form=ID` is not parsed.

**What a lead triggers today (Gamavis production):** flow "Meta Lead Ads —
Welcome Flow" alerts the owner's WhatsApp with the whole lead (template
`gamavis_new_lead_alert`: source, name, number, email, company and every form
answer via the `{{lead.answers}}` token of the *Alert My Number* node), then
sends `gamavis_lead_welcome`, whose quick-reply buttons start two more flows:
"Pick a call time" offers live slots and books the tap (`send_slots` →
`book_slot`, owner alerted with `{{slot}}`); "Call me now" / "Chat on WhatsApp"
alert the owner with `{{button_title}}` and hand off to a human.

**Two behaviours worth knowing:**
- A lead whose number is already a contact does not create a duplicate; the
  contact is updated and `meta_lead_received` still fires (a returning lead is
  still a lead). `lead_created` fires only for genuinely new contacts.
- Every question on the form becomes a custom field on the contact, created
  on first sight and labelled with the question text.

---

## What Works Locally (Without Approval)

All code in Sprint 5 is fully functional in mock mode:

```
META_LEADS_MOCK_MODE = true   # in backend/.env
WEBHOOK_VERIFY_SIGNATURE = false   # for local curl testing only
```

With mock mode ON:
- The Graph API lead fetch is bypassed (configurable test data used instead)
- The full pipeline runs: webhook → job → handler → contact → trigger → flow
- All PHPUnit tests pass without a live Meta App

**Test the full pipeline locally with a curl request:**
```bash
curl -X POST http://localhost:8080/webhooks/meta-leads \
  -H "Content-Type: application/json" \
  -d '{
    "object": "page",
    "entry": [{
      "id": "YOUR_PAGE_ID",
      "changes": [{
        "field": "leadgen",
        "value": {
          "leadgen_id": "test_lead_001",
          "page_id": "YOUR_PAGE_ID",
          "form_id": "test_form_001"
        }
      }]
    }]
  }'
```

Set `YOUR_PAGE_ID` to the `page_id` stored in the `integrations` table.

---

## Steps to Get App Review Approved

1. **Create a Meta App** at https://developers.facebook.com/
   - Add products: Facebook Login, Webhooks, Facebook Pages API
   - Configure the webhook URL: `https://your-domain/webhooks/meta-leads`

2. **Complete Business Verification** in Meta Business Manager
   - Submit business documents (typically 1–3 business days)

3. **Connect a Facebook Page** via the TravelPilot integration UI
   - *Connect with Facebook* → `POST /api/v1/meta-integrations/link-page` (links + subscribes)
   - or the manual fallback: `POST /api/v1/meta-integrations` with Page ID + Page Access Token,
     then `POST /api/v1/meta-integrations/{id}/subscribe`

4. **Test with Development Mode**
   - While in Development mode, leads from pages where you are a page admin work
   - Create a test Lead Ad campaign to generate test leads

5. **Submit App Review**
   - Request `leads_retrieval` + `pages_manage_metadata`
   - Write a clear use-case description: "TravelPilot uses lead form data to create
     CRM contacts and trigger WhatsApp follow-up automations."
   - Provide a screencast showing the connection flow and lead processing

6. **Switch App to Live Mode** after approval
   - Real production leads from any page that grants your app permission will flow in

---

## Policy Reminders

- Lead data may only be used for purposes disclosed to the ad platform and the user
- Do NOT use `leads_retrieval` data for advertising targeting
- Users must be able to request deletion of their data (GDPR/CCPA compliance)
- Store Page Access Tokens encrypted at rest (TravelPilot uses AES-256-GCM via TokenCipher)

---

*Last updated: 2026-09-24 — Facebook Login path added*
