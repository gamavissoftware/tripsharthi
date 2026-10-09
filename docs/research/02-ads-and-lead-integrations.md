# 02 - Ads and Lead Integrations (Meta, Google, Others, India Marketplaces, Telephony, Messaging)

Research date: 2026-10-08. Purpose: unified ad management, real-time lead ingestion, and nurturing for Indian travel businesses (SaaS, multi-tenant).

Confidence legend: [V] verified on an official/primary page during this research; [S] from secondary sources (blogs, vendor docs), treat as likely but re-check; [K] from background knowledge (stable API behaviour, not re-fetched), re-check at implementation time.

---------------------------------------------------------------------
## 0. Executive summary: facts that change design decisions

1. Meta versions [V]: Graph API v26.0 released 2026-07-29. Marketing API v25.0 released 2026-02-18; v24.0 expired 2026-10-06 (two days ago). Marketing API auto-upgrade began 2026-07-29. Pin to v25.0/v26.0 and make the version a config constant. https://developers.facebook.com/docs/graph-api/changelog/versions/
2. Google Ads API [V/S]: current is v25.2 (2026-09-23); v25 major released 2026-07-22; v24 on 2026-04-22; v22 sunset 2026-10-07 (yesterday); v23 sunsets ~Feb 2027. Target v24/v25. https://developers.google.com/google-ads/api/docs/release-notes and https://ads-developers.googleblog.com/2026/09/google-ads-api-v22-sunset-reminder.html
3. BIG CHANGE: Google Ads API access levels now attach to the Google Cloud project that issued the OAuth credentials, not to the developer token. Developer token header is now optional/ignored (will be rejected in a future major version). New tier "Explorer" (2,880 prod ops/day), Basic (15,000 ops/day), Standard (unlimited, manual audit). Brand verification mandatory. Guidance: one dedicated Cloud project per integration. https://developers.google.com/google-ads/api/docs/access-levels and https://ppc.land/google-drops-developer-tokens-from-ads-api-access-decisions/ [V/S]
4. WhatsApp pricing [V]: per-message (template) pricing since 2025-07-01. From 2026-10-01 service (non-template) replies and utility-in-window messages become billable at the utility rate, with a per-number monthly free allowance (1,000 service msgs, per secondary sources). Messages inside the 72-hour Free Entry Point window (CTWA ads / Page CTA) stay free. India: INR billing mandatory for all WABAs by 2026-12-31, else delivery stops 2027-01-01. A payment method must be on file or service messages stop. https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing and https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing/non-template-messages
5. Meta Ads Insights: 7-day-view and 28-day-view attribution windows removed (2026-01-12); retention limits on breakdowns; unique-impression/reach metrics retired with v26. Don't build ROAS around those. https://www.kitchn.io/blog/meta-marketing-api-q2-2026-update [S]
6. Meta webhooks moved to Meta's own mTLS CA on 2026-03-31: add `meta-outbound-api-ca-2025-12.pem` to the trust store of your webhook receiver (affects reverse proxies that pin CAs). [S]
7. Legacy Advantage Shopping (ASC) and App (AAC) campaign creation via API blocked in all versions since 2026-05-19; use the unified Advantage+ structure. Irrelevant for lead-gen travel but relevant if you create "sales" campaigns. [S]
8. Meta App Review is slow in 2026 (reports of ~20 days; Meta says up to ~20 days; Business Verification up to 14 business days). Start App Review + Business Verification + Tech Provider verification on day 1; it is the critical path. https://bundle.social/blog/meta-app-review-20-days [S]
9. DPDP Rules 2025 (notified 2025-11-13): substantive data-fiduciary obligations (consent notices, security, breach, erasure, children) commence 2027-05-13; consent-manager framework 2026-11-13. Build consent capture and purge tooling now. [S] https://www.mondaq.com/dpdp-act-and-rules-2025-the-2026-compliance-milestones-businesses-cant-afford-to-miss/1830402
10. Not available / skip: TikTok (banned in India). Google Business Messages/GBP chat shut down 2024-07-31 [V] (GBP "chat" is gone; GBP calls/direction metrics remain via Performance API). Microsoft Advertising has no native lead-form format [S].

---------------------------------------------------------------------
## 1. META

### 1.1 Platform setup (applies to everything in Meta)

- Base: `https://graph.facebook.com/v26.0/` (Marketing API v25.0 is the "latest Marketing API" label on the versions page, but unversioned calls and v26 auto-upgrade apply; use the same version for all products and test on both).
- Developer docs root: https://developers.facebook.com/documentation/ads-commerce/ (note: Meta moved docs from /docs/marketing-api to /documentation/ads-commerce).
- App type: Business. Products to add: Marketing API, Webhooks, Facebook Login for Business, WhatsApp, Instagram (Graph API), Conversions API.
- Be a verified Tech Provider (needed once you serve other businesses' assets, mandatory for WhatsApp Embedded Signup as a Tech Provider and for Advanced Access to most business permissions). Steps: (1) Business Verification of your own Meta Business portfolio (legal docs: GST/PAN/MCA/utility bill, domain verification, can take up to 14 business days [S]); (2) App Review per permission with screencast + test credentials + detailed use-case text per permission; (3) Access Verification (Tech Provider) for apps touching other businesses' data [S]; (4) Data Use Checkup yearly; (5) Switch app to Live; (6) Privacy Policy URL, Data Deletion Callback URL (or instructions URL) required.
- Gotcha: submitting Advanced Access before Business Verification is auto-rejected [S]. Prepare a demo business with its own Page/ad account so reviewers can see each permission used end-to-end.
- Gotcha: Standard Access works only for assets owned by the app admins/developers/testers. Real tenants require Advanced Access.

### 1.2 Authentication models for a multi-tenant SaaS

| Option | What | Use for |
|---|---|---|
| Facebook Login for Business (config_id based) | OAuth dialog with a pre-defined permission set ("Login configurations"); returns user token, can request business-token (system-user token) type | Tenant onboarding. Preferred over classic Facebook Login. |
| Long-lived user token | exchange via `GET /oauth/access_token?grant_type=fb_exchange_token&client_id&client_secret&fb_exchange_token` ; ~60 days | Short-term; must refresh/re-auth. Revoked on password change / permission removal. |
| Page access token derived from long-lived user token | `GET /{user-id}/accounts` ; non-expiring when derived from a long-lived user token | Lead retrieval, page-scoped ops. |
| System User token (Business Manager) | Created in the tenant's Business Manager (or granted via Business Login "business token" flow); non-expiring or 60-day tokens; assigned assets (ad accounts, pages) | Server-to-server ads management; most robust. Tenant must grant the app partner access to its Business Manager (Business Settings > Partners), or use Login for Business "Business Integration System User" flow. |
| WhatsApp Embedded Signup | JS SDK flow returning code + WABA id/phone id; exchange code for business token | WhatsApp onboarding (section 1.8). |

Recommendation: Facebook Login for Business with a config requesting ads + lead + pages permissions and the "Business Integration System User access token" option so tokens are not tied to a human employee. Store tokens encrypted (KMS), track `debug_token` expiry, alert on `OAuthException` code 190 subcodes 458/460/463/467 (token invalid -> prompt re-connect).

### 1.3 Marketing API (campaign management)

Hierarchy: Ad Account `act_<id>` > Campaign > Ad Set > Ad > Ad Creative (+ Ad Image/Video).

Key endpoints [K, stable; re-check fields per version]:
- List ad accounts: `GET /me/adaccounts?fields=id,name,account_status,currency,timezone_name,amount_spent,balance`
- Campaigns: `POST /act_{id}/campaigns` with `name, objective (ODAX: OUTCOME_LEADS | OUTCOME_ENGAGEMENT | OUTCOME_TRAFFIC | OUTCOME_AWARENESS | OUTCOME_SALES), status (PAUSED|ACTIVE), special_ad_categories=[] (required, pass empty array), buying_type, daily_budget/lifetime_budget (CBO), bid_strategy, is_adset_budget_sharing_enabled` ; `POST /{campaign-id}` to update; `GET /act_{id}/campaigns`.
- Ad sets: `POST /act_{id}/adsets` with `campaign_id, name, optimization_goal (LEAD_GENERATION | QUALITY_LEAD | CONVERSATIONS | OFFSITE_CONVERSIONS | LINK_CLICKS | REACH), billing_event (IMPRESSIONS), destination_type (ON_AD | WEBSITE | WHATSAPP | MESSENGER | INSTAGRAM_DIRECT), promoted_object ({page_id} for lead ads; {page_id, whatsapp_phone_number} for CTWA; {pixel_id, custom_event_type} for web), targeting (geo_locations incl. India cities/regions, age_min/max, interests, custom_audiences, excluded_custom_audiences, targeting_automation{advantage_audience:1}), daily_budget (in minor units = paise for INR), start_time/end_time, status`.
- Ads: `POST /act_{id}/ads` with `adset_id, creative{creative_id}, status`. Creatives: `POST /act_{id}/adcreatives` with `object_story_spec` (link_data / video_data), `degrees_of_freedom_spec` (Advantage+ creative enhancements; opt-out flags), `url_tags` (UTM template), asset feed spec for dynamic creative.
- Images/videos: `POST /act_{id}/adimages` (multipart bytes), `POST /act_{id}/advideos` (resumable upload for large files).
- Budgets: change at campaign level (CBO) or ad set level; minimum daily budget depends on currency/objective (INR ~ ₹ 100-ish/day for most goals; query `GET /{adset}?fields=min_budget...` / delivery_estimate). Budget units: minor currency (paise). Budget increases limited to ~ 4-5x/day on edits in some cases [K].
- Insights: `GET /act_{id}/insights?level=campaign|adset|ad&fields=spend,impressions,clicks,ctr,cpc,cpm,actions,cost_per_action_type,conversions,purchase_roas&time_increment=1&breakdowns=publisher_platform,platform_position,region&action_attribution_windows=["1d_click","7d_click","1d_view"]`. Large pulls: POST the same path with `async` (creates `report_run_id`, poll `GET /{report_run_id}` -> `async_status`, then `/insights`).
  - 2026 gotchas: 7d_view and 28d_view windows removed (2026-01-12); unique-metric retirement with v26 (2026-07-29); breakdown retention 13 months (unique/hourly), 6 months (frequency) [S].
  - Lead counts appear in `actions` as `lead` (on-Facebook instant form) / `onsite_conversion.lead_grouped` / `offsite_conversion.fb_pixel_lead`; WhatsApp conversations as `onsite_conversion.messaging_conversation_started_7d`. Always reconcile against your own CRM counts.
- Rate limits [S/K]: Business Use Case (BUC) rate limiting, separate pools for `ads_management`, `ads_insights`, `custom_audience`, `catalog_management`. Header `X-Business-Use-Case-Usage` returns `call_count, total_cputime, total_time, estimated_time_to_regain_access, ads_api_access_tier`. Dev tier ~300 calls/hour base vs Standard tier ~100,000 base (+ 40 points per active ad) [S]. Error codes: 4, 17, 32, 613 (rate), 80000-80014 (BUC). Implement per-ad-account token-bucket with exponential backoff and read the usage headers; prefer batching (`POST /` with `batch=[...]`, max 50 sub-requests) and field expansion.
- Permissions: `ads_management` (write), `ads_read` (insights only), `business_management` (read/manage Business Manager assets, create system users, assign assets), `pages_show_list`, `pages_read_engagement`, `pages_manage_ads`, `pages_manage_metadata` (needed to subscribe webhooks), `leads_retrieval`, `instagram_basic`, `instagram_manage_messages`, `instagram_manage_insights`, `whatsapp_business_management`, `whatsapp_business_messaging`. Need Advanced Access for all of the above to serve third-party tenants.
- Marketing API tier: "Standard Access" for ads_management happens through the "Ads Management Standard Access" feature; requires >= 1,500 successful API calls in 15 days and < 15% error rate historically [K - verify current thresholds] plus App Review.
- Gotchas:
  - Always create objects PAUSED, validate, then activate. Use `execution_options=["validate_only"]` for dry run.
  - `special_ad_categories` is mandatory (credit/employment/housing/social-issues; travel is not a special category).
  - Currency: INR ad accounts take paise integers. Time zone = ad account tz (usually Asia/Kolkata); insights day boundaries follow it.
  - Indian ad accounts: GST on invoices (18%), prepaid/funding-source constraints (RBI e-mandate/recurring-payment rules can pause ad delivery when a card fails).
  - Advantage+ audience/placements are default-on in many flows; geo/age exclusions may be soft.
  - Policy: ad rejection comes via `ad_review_feedback` field and `effective_status` (DISAPPROVED, WITH_ISSUES). Poll or subscribe to Ad Account webhooks (`ad_account` object). Surface to users.

### 1.4 Custom Audiences, Lookalikes

- Create: `POST /act_{id}/customaudiences` with `name, subtype=CUSTOM, customer_file_source (USER_PROVIDED_ONLY | PARTNER_PROVIDED_ONLY | BOTH_USER_AND_PARTNER_PROVIDED), description`. Add users: `POST /{audience-id}/users` with `payload{schema:["EMAIL","PHONE","FN","LN","COUNTRY"], data:[[sha256(...)...]]}` ; batch <= 10,000 per request; session parameter for multi-batch.
- Hashing: SHA-256 of lowercased/trimmed email; phone as digits with country code and no leading zeros or "+" (e.g. 919876543210).
- Lookalike: `POST /act_{id}/customaudiences` with `subtype=LOOKALIKE, origin_audience_id, lookalike_spec{type:"similarity"|"reach", country:"IN", ratio:0.01-0.20}` ; source must have >= 100 matched users from a single country; min ~1,000 recommended.
- Required: accept Custom Audience Terms of Service per ad account (`GET /act_{id}?fields=tos_accepted` ; the tenant must click-accept once in Ads Manager). Common blocker; surface in onboarding checklist.
- Value-based/"high value traveller" seeds: build audiences from "Booked" CRM stage (not all leads) and exclude existing customers from prospecting.
- Rate limit: `custom_audience` BUC pool; user upload is rate limited (~ 190,000 calls base). Audience size updates are async; `operation_status` and `approximate_count_lower_bound` fields.
- Privacy gotcha: uploading Indian customer phone numbers requires lawful basis/consent (DPDP, see section 8.7).

### 1.5 Lead Ads (Instant Forms), Facebook and Instagram

Docs: https://developers.facebook.com/documentation/ads-commerce/marketing-api/guides/lead-ads [V] and webhooks quickstart https://developers.facebook.com/documentation/ads-commerce/marketing-api/guides/lead-ads/quickstart/webhooks-integration

Permissions [V]: `leads_retrieval` and `pages_manage_ads` (App Review), plus `pages_show_list`, `pages_read_engagement`, `pages_manage_metadata` (to subscribe the app to Page webhooks), `ads_management` / `ads_read` if reading ads/forms. Tenant user must have Page admin or "Leads access" (flexible permissions) via Business Manager (Leads Access Manager / CRM access).

Setup:
1. App webhook config: Webhooks product > object `page` > field `leadgen`; callback URL + verify token (GET challenge `hub.mode=subscribe`, `hub.verify_token`, `hub.challenge`). Validate POST bodies with `X-Hub-Signature-256` (HMAC SHA-256 of raw body with app secret).
2. Per tenant Page: `POST /{page-id}/subscribed_apps?subscribed_fields=leadgen` using a Page access token (needs `pages_manage_metadata`). Verify with `GET /{page-id}/subscribed_apps`.
3. Webhook payload (thin): `entry[].changes[].value = {leadgen_id, page_id, form_id, adgroup_id, ad_id, created_time}`. Then fetch: `GET /{leadgen_id}?fields=id,created_time,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,form_id,field_data,is_organic,platform,custom_disclaimer_responses,partner_name,retailer_item_id` using Page token. `field_data` is an array of `{name, values[]}`; standard names `email`, `phone_number`, `full_name`, `first_name`, `last_name`, `city`, `state`, `zip_code`, plus custom question keys.
4. Forms: `GET /{page-id}/leadgen_forms` (create via `POST /{page-id}/leadgen_forms` with questions, privacy policy URL, thank-you page, context card). Bulk: `GET /{form-id}/leads` (use as reconciliation poller, filtered by `time_created`).
5. Test: Lead Ads Testing Tool https://developers.facebook.com/tools/lead-ads-testing (create/delete test leads for a Page+form; fires the real webhook). Also you can create leads via `POST /{form-id}/test_leads`. Test leads have no `ad_id`.
Retention and access:
- Leads are available via API for 90 days from creation [K]; store immediately.
- Respond HTTP 200 quickly (< a few seconds); Meta retries failed deliveries with backoff for up to ~36 hours then disables. Process async from a queue.
- Page tokens are preferable to user tokens (rate limits per active Page users) [V].
- Development-mode apps get leads only for app roles; "Live" mode needed [V].
Gotchas:
- Webhooks do not deliver for organic leads form unless `is_organic` forms in the Page also subscribed; both fire `leadgen`.
- Duplicate webhook deliveries happen: dedupe on `leadgen_id`.
- Subscription silently breaks when a tenant admin loses Page role or the token is invalidated; run a daily health job (`GET /{page-id}/subscribed_apps`) and a reconciliation poll (`/{form-id}/leads?filtering=[{field:"time_created",operator:"GREATER_THAN",value:<ts>}]`) every 5-15 min as safety net.
- CRM Integration setting in Business Suite "Leads Center" can conflict (a Page can also connect a native CRM); not a blocker.
- `phone_number` arrives with country code usually as "+919876543210" but sometimes "9876543210" or "p:+91..." prefix ("p:" prefix is common on prefilled values). Normalize (section 5.2).
- Quality: Meta "Higher intent" form type (review step) cuts junk; "More volume" vs "Higher intent". Lead forms for India often attract low quality; CAPI feedback is essential (section 1.6).
- Instagram lead ads use the same Page `leadgen` webhook; `platform` field = `ig`/`fb`. No separate permission, but an IG professional account must be linked to the Page.
- Conversational/"Instant forms with messaging" (lead ad variants with WhatsApp follow-up) also arrive via `leadgen`.

### 1.6 Conversions API (CAPI): general and CRM lead feedback ("Conversion Leads")

Endpoint [K]: `POST https://graph.facebook.com/v26.0/{dataset_id}/events?access_token=...` (dataset = Pixel id; for CRM events use a dataset connected to your lead source). Body: `data[]`, optional `test_event_code` (use Events Manager > Test Events), `partner_agent`.

Event object: `event_name, event_time (unix s, must be <= 7 days old for most events), event_id (dedupe with pixel), action_source ("system_generated" | "website" | "chat" | "phone_call" | "email" | "physical_store" | "business_messaging" | "other"), event_source_url, user_data{em[], ph[] (sha256), fn, ln, ct, st, zp, country, external_id, fbp, fbc, client_ip_address, client_user_agent, lead_id (plain, NOT hashed), ctwa_clid, whatsapp_business_account_id, page_id}, custom_data{value, currency:"INR", lead_event_source, event_source:"crm", content_ids...}`.

CRM / Conversion Leads integration [V]: https://developers.facebook.com/documentation/ads-commerce/conversions-api/conversion-leads-integration
- Optimization goal "Conversion Leads" (`optimization_goal=QUALITY_LEAD` family / "conversion leads" in Ads Manager) works with Lead Ads (Instant Forms) only; Meta then optimizes toward leads likely to reach your chosen CRM stage, not just submit forms.
- Send a `lead_id` (the 15-17 digit `leadgen_id` from the webhook) with every stage event; fallback identifiers are click id / hashed phone / email but match quality drops.
- `custom_data.lead_event_source` (required) = name of your CRM/tool (e.g. "TravelPilot"); also `custom_data.event_source = "crm"`.
- Event names: standard `Lead` (initial) and for stages use Meta standard events or custom names, typical mapping: `Lead` (created), `QualifiedLead`/custom `Qualified` (sales accepted), `InitiateCheckout`/`Schedule` (proposal/quote sent), `Purchase` (booked, with `value` and `currency=INR`). Meta needs at least ~ 2 distinct stages ideally; pick the stage that happens within 28 days and reached by roughly 1-40% of leads [V].
- Volume: ~>= 200 leads/month with the event in the campaign; daily (at least) upload cadence; validation 1-2 days; learning 2-4 weeks [V].
- Window: lead stage event must occur within 28 days of lead creation to be used [V].
- Data upload from your CRM must include `event_time` = actual time stage was reached (not upload time).
- Gotcha: events older than 7 days for `action_source` website are rejected; CRM/`system_generated` events with lead_id allowed up to 28 days [K/V mix].
- Gotcha: dedupe with `event_id`; re-sending the same stage event is ignored but sending conflicting stages wastes learning.
- Value-based optimization: include `value` on Purchase for ROAS reporting in Ads Manager.
- Alternate: Meta "Conversion Leads" partner-integration flow in Events Manager > Data Sources > CRM (lets you upload a CSV with stage mapping). For SaaS, use API.

Web (landing page) CAPI: dual-send pixel + CAPI with shared `event_id`; capture `fbp` (cookie `_fbp`) and `fbc` (`fb.1.<ts>.<fbclid>`) on landing pages; store at lead creation so server events can attribute later.

### 1.7 Click-to-WhatsApp (CTWA) ads

- Ad set `destination_type=WHATSAPP`, `promoted_object={page_id, whatsapp_phone_number}`, optimization `CONVERSATIONS` or `LINK_CLICKS`; creative call-to-action `WHATSAPP_MESSAGE` with `page_welcome_message` (greeting/ice-breakers). The WhatsApp number must be a WABA number linked to the Page/ad account.
- Inbound webhook (`messages` field on WABA) first message includes `referral` object [S]: `source_url, source_id (ad id), source_type ("ad"|"post"), headline, body, media_type, image_url/video_url/thumbnail_url, ctwa_clid`. Persist the whole `referral` JSON plus `ctwa_clid` against the contact on first message; subsequent messages carry no referral.
- `ctwa_clid` expiry: attributable ~7 days after click for optimization; send CAPI within 7 days to be safe for conversion-leads style events [K, verify].
- CAPI for business messaging [S]: `POST /{dataset_id}/events` with `action_source: "business_messaging"`, `messaging_channel: "whatsapp"`, `user_data: {whatsapp_business_account_id, ctwa_clid}`, `event_name`: `Purchase`, `LeadSubmitted`, `QualifiedLead`, `OrderCreated`, `InitiateCheckout`... (the supported business-messaging event list is smaller than web; check docs). Dataset auto-named "<WABA name> Event Data".
- Pricing [V]: conversation started from CTWA creates a 72h Free Entry Point window: all message types free inside it; also free for 24h customer service window. Doc: https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing
- Gotchas: contact may message from a number different than the one in lead forms; WhatsApp BSUID/username changes (Meta rolling out usernames/BSUID in 2026) can remove phone number from some inbound payloads when the user enables a username: design contact identity to hold `wa_id`, `bsuid` (if present) and `phone` separately [S/K verify]. If the tenant uses the WhatsApp Business app (coexistence mode) the number can be on both app and API.
- Ctwa also supports "Instagram -> WhatsApp" and Facebook post boosts with WhatsApp button; `source_type=post` appears for those.

### 1.8 WhatsApp Cloud API essentials (for nurturing)

- Embedded Signup (Tech Provider): JS SDK launches flow, returns `code` and `waba_id`, `phone_number_id` via session message; server exchanges code: `GET /oauth/access_token?client_id&client_secret&code` -> business token; then `POST /{waba_id}/subscribed_apps` (subscribe your app to webhooks), `POST /{phone_number_id}/register` (with 6-digit PIN), set up payment: tenant attaches own payment method/credit line to the WABA (or you share your credit line as Tech Provider/Solution Partner). Needs permissions `whatsapp_business_management`, `whatsapp_business_messaging`, `business_management`; App Review + Business Verification + Tech Provider status. Docs: https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup
- Coexistence (Embedded Signup variant letting the tenant keep the WhatsApp Business app on the same number) is valuable for small Indian travel agents [K - verify availability/limitations: history sync, no broadcast lists, etc.].
- Webhook fields: `messages` (inbound + statuses: sent/delivered/read/failed), `message_template_status_update`, `phone_number_quality_update`, `account_update`, `template_category_update`. Same `X-Hub-Signature-256` check.
- Messaging rules: free-form only inside 24h service window; otherwise approved templates (marketing / utility / authentication). Template approval typically minutes to 24h; category may be auto-recategorized (marketing instead of utility) - billing impact.
- Limits: messaging tiers (business-initiated unique users per rolling 24h: 250 unverified; 1K, 10K, 100K, unlimited after verification + quality). Throughput 80 mps default (up to 1,000 mps). Marketing messages have per-user frequency capping (India has aggressive filtering; "marketing message not delivered - experiment/ecosystem engagement" error 131049).
- Pricing 2026 [V partly] https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing:
  - Categories: Marketing (always charged), Utility (charged outside the service window; free inside window until 2026-10-01 then charged), Authentication, Service (replies) - from 2026-10-01 charged at utility/authentication rate with a monthly free allowance per phone number [V/S]. Free: 72h Free Entry Point window from CTWA/Page CTA; customer service window non-template messages (until 2026-10-01 change; see above).
  - India rate card (secondary sources, ex-GST 18%) [S]: Marketing ~ ₹0.8631; Utility ~ ₹0.115; Authentication ~ ₹0.115; Authentication-International ~ ₹2.4971; service ~ ₹0.115 after 1,000 free/number/month. Volume tiers for utility/authentication start at 750K/month (auth) and 25M (utility) with 6-30% discounts. Confirm on the official rate card CSV (https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing , "Rate cards") before pricing plans.
  - Billing is per delivered message. INR billing: all WABAs must be INR by 2026-12-31 (new currency-migration API since 2026-06-01) [S]: https://montymobile.com/blogs/whatsapp-business-api-pricing-in-india-inr-rates-gst-and-the-2026-currency-migration-deadline
  - Payment method on WABA must exist by 2026-09-30 or service messages are blocked [V].
- BSP vs direct Cloud API: Meta's per-message rate is identical across BSPs; BSPs add platform/markup fees. As a Tech Provider you integrate Cloud API directly (no markup, you own support burden). Options: (a) be a Tech Provider and let tenants pay Meta directly (credit line on their WABA) and you charge SaaS fee; (b) Solution Partner with your own credit line and pass-through + markup (needs Partner program approval, more compliance). Recommended v1: Tech Provider, tenant-paid.
- Opt-in: Meta requires explicit opt-in for business-initiated messages; capture at lead form (checkbox), WhatsApp click, website form; store consent artifact (section 8.7).
- India-specific: marketing templates cost ~9x utility; push transactional itinerary/voucher/payment-reminder content into UTILITY templates (legitimately) and keep promos in MARKETING. Meta penalizes miscategorization.

### 1.9 Instagram

- Instagram lead ads: through Facebook Page `leadgen` webhook (above).
- Instagram DMs (`instagram_manage_messages`, Messenger Platform for Instagram): webhooks `messages`, `messaging_referral` (ad referral data for Click-to-Instagram-Direct ads with `ref`, `ad_id`, `source`), 24h window (7 days with HUMAN_AGENT tag). Useful for a unified inbox, phase 2.
- Instagram comments-to-DM automation (Comment webhooks `comments`, private replies via `POST /{ig-user-id}/messages` with `recipient{comment_id}`) - popular for travel; requires `instagram_manage_comments` + `instagram_manage_messages` Advanced Access.
- Instagram Business Login (without FB Page) exists since 2024; fewer permissions but limited to IG features; use FB Login for Business for ads.

---------------------------------------------------------------------
## 2. GOOGLE

### 2.1 Google Ads API

- Versions [V/S]: v25.2 current (2026-09-23); majors approx. quarterly (v24 Apr 22, v25 Jul 22); each major supported ~12-14 months; v22 sunset 2026-10-07, v23 sunset ~Feb 2027. Plan: pin v25, upgrade twice a year; subscribe to https://ads-developers.googleblog.com/ . Notable v25 breaking changes: restructured `CustomerLifecycleOptimizationValueSettings`, removed legacy lifecycle goal services. v24.2: SyntheticContent (AI-generated asset labelling) fields on Asset/Ad.
- Endpoints: gRPC/REST `https://googleads.googleapis.com/v25/customers/{customer_id}/googleAds:searchStream` (GAQL), `.../googleAds:mutate` (multi-resource atomic mutate), per-resource `:mutate` (campaignBudgets, campaigns, adGroups, adGroupAds, assets, assetGroups, conversionActions, customerMatch via userLists), `.../uploadClickConversions`, `.../uploadConversionAdjustments`, `.../customers:listAccessibleCustomers`.
- Headers: `Authorization: Bearer <oauth access token>`, `login-customer-id: <MCC id>` (when accessing client via manager), `developer-token` (optional now per [S], keep sending until officially rejected).
- OAuth: scope `https://www.googleapis.com/auth/adwords`. Web server flow with `access_type=offline&prompt=consent` for refresh token. Per-tenant refresh tokens; the OAuth consent screen must be verified (sensitive scope `adwords` requires Google OAuth app verification; restricted/sensitive-scope review takes days-weeks; you need privacy policy, homepage domain verification, demo video). Refresh tokens of "Testing" mode apps expire in 7 days - go to "In production".
- Access model (new, [V/S]): access level is per Cloud project: Test -> Explorer (production, 2,880 ops/day) -> Basic (15,000 ops/day, automated within minutes, brand verification required) -> Standard (unlimited, manual audit, declare permissible use: ad management / reporting-only / keyword research). SaaS ad management needs Standard. https://developers.google.com/google-ads/api/docs/access-levels . Google asks for one dedicated Cloud project per integration; account for tenant growth and rate limits per project.
- MCC: a manager (MCC) account of your own is required to hold the link to client accounts ("manager-linked" invitations: `customerClientLinks` / `CustomerManagerLink`) or you operate on the tenant's own account with their OAuth (user-delegated). Recommended: tenant OAuth (user's own access) so the tenant stays owner; optionally link to your MCC for billing/automation. Do not run all tenants under one MCC with your own credentials unless you are an agency (policy, liability).
- Rate limits: operations/day by tier; plus system limits per developer/customer (RESOURCE_EXHAUSTED/ RATE_EXCEEDED); mutate max 10,000 operations per request; searchStream no row cap but 1 query at a time per stream. Use `partial_failure=true` and `validate_only=true`.
- GAQL reporting examples:
  `SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions, metrics.conversions_value, segments.date FROM campaign WHERE segments.date DURING LAST_30_DAYS`
  Cost is micros (1,000,000 = 1 INR in account currency). Lead form submissions: `metrics.all_conversions` with `segments.conversion_action_category = SUBMIT_LEAD_FORM`; click-level join with `click_view` (one day per query; gclid available in `click_view.gclid`, only last 90 days).
- Campaign types for travel: Search (RSA), Performance Max (asset groups, can include lead form asset; "travel goals" and hotel feeds exist via Travel Ads / Hotel campaigns), Demand Gen (YouTube/Discover/Gmail; supports lead form assets & TargetCPC [V]), Display, Video (YouTube), App. Creation: Budget -> Campaign (`advertising_channel_type`, `bidding_strategy_type`, `network_settings`, `geo_target_type_setting`) -> AdGroup / AssetGroup -> Ads/Assets -> criteria (`CampaignCriterion` for locations: India geoTargetConstants/2356, languages) -> conversions.
- Policy gotchas: Travel ads policies (Hotel/Flight), "destination/travel" ads need accurate pricing; ad strength; limited-by-policy statuses via `ad_group_ad.policy_summary`.
- AI creative: PMax/Demand Gen asset automation (image enhancement, video generation, "asset automation types" in v25.2 [V]); label synthetic content per v24.2 fields.
- Recommendations API `RecommendationService` for opportunity surface; `KeywordPlanIdeaService` for research (Standard access permissible-use "keyword research").

### 2.2 Lead Form assets (lead form webhook)

Docs: https://developers.google.com/google-ads/webhook/docs/implementation [V via search summary]
- Configure in Google Ads UI (Asset > Lead form > "Lead delivery option": Webhook URL + Key). The API supports reading form leads via `lead_form_submission_data` resource (GAQL: `SELECT lead_form_submission_data.id, lead_form_submission_data.campaign, lead_form_submission_data.gclid, lead_form_submission_data.submission_date_time, lead_form_submission_data.lead_form_submission_fields FROM lead_form_submission_data`) - use as the polling backstop.
- Webhook POST JSON: `lead_id, api_version, form_id, campaign_id, adgroup_id, creative_id, gcl_id, google_key, is_test, asset_group_id (PMax), user_column_data[{column_id (FULL_NAME, EMAIL, PHONE_NUMBER, CITY, ...), column_name, string_value}], lead_stage...`.
- Verify `google_key` equals the key you configured (per-tenant key; store hashed). No signature header; use secret key + HTTPS + path token. Respond 200 within ~ seconds; Google retries on non-2xx. Dedupe on `lead_id`. Ignore unknown fields (forward compat). Handle `is_test=true` leads (do not count).
- Gotcha: webhook must be configured by the tenant in the Ads UI (or via API `AssetService` + `LeadFormAsset.delivery_methods[webhook{advertiser_webhook_url, google_secret}]`); pasting webhook per form is tedious; offer an assisted wizard and a "Test" button. Google's UI "Send test data" gives a one-off.
- Store `gcl_id` -> required for closed loop (OCI).
- Lead forms in India: Phone verification and mandatory consent text options exist; assets show in Search/YouTube/Display/Demand Gen/PMax.

### 2.3 Offline Conversion Import (closed loop) and Enhanced Conversions for Leads

Docs: https://developers.google.com/google-ads/api/docs/conversions/overview and https://support.google.com/google-ads/answer/15479791 [S]
- Create a `ConversionAction` (type `UPLOAD_CLICKS`, category e.g. `QUALIFIED_LEAD` / `CONVERTED_LEAD` / `PURCHASE` / `SUBMIT_LEAD_FORM`; `counting_type ONE_PER_CLICK` for leads; `attribution_model_settings`; `value_settings` default value INR; `status ENABLED`). Use separate actions per CRM stage to enable "Qualified lead" -> `QUALIFIED_LEAD` and "Converted" -> `CONVERTED_LEAD` (these power lead-quality goals in Smart Bidding) [S].
- Upload: `POST /customers/{id}:uploadClickConversions` with `conversions[{gclid | gbraid | wbraid, conversion_action: "customers/x/conversionActions/y", conversion_date_time: "2026-10-08 14:30:00+05:30", conversion_value, currency_code:"INR", order_id, user_identifiers[{hashed_email}|{hashed_phone_number}], consent{ad_user_data, ad_personalization}}]`, `partial_failure=true`, `validate_only` for dry run.
  - Exactly one of gclid / gbraid / wbraid per row historically; since 2025-10-03 gbraid/gclid together with user data in a row is allowed [S].
  - Time rules: conversion time after click time; click must be <= 90 days old [S]; for enhanced conversions for leads (hashed email/phone only, no gclid) the lead event must be within ~63 days [S]. Upload at least daily; Google recommends within 24h of the CRM event (and enhanced conversions for leads within 63 days).
  - Adjustments (retractions/restatements): `uploadConversionAdjustments` with `adjustment_type RETRACTION | RESTATEMENT | ENHANCEMENT`, matching on `order_id` or gclid+datetime. Use for cancellations (booking refunded) and value corrections (final booking value).
  - Hashing: SHA-256 of lowercase/trimmed email; for Gmail strip dots; phone E.164 (+919876543210) then hashed. Normalise exactly as Google specifies.
  - Consent (EEA/UK) fields `consent.ad_user_data` / `ad_personalization`: required for EEA/UK users; for India optional but add if you collect it.
- Enhanced Conversions for Leads: tag the lead form (website) with hashed email/phone via gtag/GTM user data -> Google matches on later offline upload without gclid. Requires accepting customer data terms in the tenant's account (`customer.conversion_tracking_setting.accepted_customer_data_terms`) - common blocker, verify via API.
- Lead form assets: conversions from lead forms are auto-tracked ("Submit lead form"); to add qualification stage, import by `gclid` from webhook `gcl_id` (lead form webhook includes it).
- `click_view` gclid retrieval alternative for ads where you didn't store gclid (limited to 90 days, 1 day per query).
- Managers: uploads require `login-customer-id` if via MCC and the conversion action to be owned by the client or MCC cross-account conversion tracking.
- iOS: `wbraid`/`gbraid` come in the landing URL for iOS users; capture all three at landing and persist.

### 2.4 Google Business Profile (GBP)

- Business Profile APIs (My Business Account Management v1, Business Information v1, Performance API, Reviews API (v4 legacy), Q&A, Verifications). Access to GBP APIs needs an application/approval (request form); OAuth scope `https://www.googleapis.com/auth/business.manage`. https://developers.google.com/my-business/content/overview [V as exists]
- Messaging/chat was shut down 2024-07-31 [V]: https://developers.google.com/business-communications/business-messages/resources/release-notes/update-on-gbm . Leads from GBP now = calls (call history is also removed per [S]), website clicks, direction requests (Performance API `getDailyMetricsTimeSeries`: CALL_CLICKS, WEBSITE_CLICKS, BUSINESS_DIRECTION_REQUESTS, BUSINESS_BOOKINGS...), reviews. So GBP = reputation management (reply to reviews) + performance reporting + posts; not a lead webhook. Notifications via Pub/Sub for new reviews (`accounts.updateNotificationSetting`).
- Local Services Ads: not material for Indian travel (limited countries).

### 2.5 YouTube

- YouTube Data API v3 (OAuth scopes `youtube.readonly`, `youtube.upload`) for channel analytics/uploads; YouTube Analytics API for views; ads on YouTube run through Google Ads (Video/Demand Gen). Quota: 10,000 units/day default; videos.insert costs 1600 (up to ~6 uploads/day without quota extension); quota audit needed. Unverified API projects: uploads locked private (needs compliance audit). Relevant for destination video content posting, low priority.

### 2.6 GA4 Measurement Protocol

- Endpoint: `POST https://www.google-analytics.com/mp/collect?measurement_id=G-XXXX&api_secret=...` (EU: region1.google-analytics.com). Body `{client_id, user_id?, events:[{name:"generate_lead" | "qualify_lead" | "close_convert_lead" | "purchase", params:{value, currency:"INR", ...}}]}`. GA4 "lead lifecycle" recommended events: `generate_lead`, `qualify_lead`, `disqualify_lead`, `working_lead`, `close_convert_lead`, `close_unconvert_lead` [K]. Need `client_id` from `_ga` cookie (store at landing), `session_id` for session attribution, event timestamp up to 72h backdating; user_id optional. Use `/debug/mp/collect` for validation. No auth beyond secret (tenant creates API secret in GA4 admin; or Admin API `properties.dataStreams.measurementProtocolSecrets.create` with scope `analytics.edit`). Admin API / Data API v1beta for reporting (`analytics.readonly`).
- Value: lets tenants see CRM stages in GA4 and import GA4 conversions to Google Ads. Priority medium-low; do after OCI.

---------------------------------------------------------------------
## 3. OTHER PLATFORMS (INDIA-RELEVANT)

### 3.1 Social / ad networks

| Platform | Lead capture | Ad mgmt API | Auth / approval | Notes |
|---|---|---|---|---|
| LinkedIn | Lead Gen Forms via Lead Sync API: `leadForms`, `leadFormResponses`, `leadNotifications` (webhook subscription) [V]. Scopes `r_marketing_leadgen_automation`, `r_ads`, `r_organization_admin` [V] | Marketing API (Advertising API): campaigns, creatives, analytics (`rw_ads`, `r_ads_reporting`) | OAuth 2.0 (3-legged, tokens 60 days, refresh tokens 365 days for approved); Marketing Developer Platform program approval AND separate Lead Sync approval [V] https://learn.microsoft.com/en-us/linkedin/marketing/lead-sync/getting-access-leadsync | Mostly B2B/MICE/corporate travel; versioned API headers `Linkedin-Version: YYYYMM`, `X-Restli-Protocol-Version: 2.0.0`. Approval takes weeks. |
| X (Twitter) | Lead Gen Ads relaunched 2026-08-26; Leads API: forms, cards, exports; webhook/CRM connectors [S] https://docs.x.com/x-ads-api/lead-generation | X Ads API (access by application) | OAuth 1.0a / 2.0; Ads API allowlist | Low priority in India travel. |
| Snapchat | Instant Forms; Marketing API lead-gen forms + webhook registration [S] | Snap Marketing API | OAuth 2.0; app approval | Low-medium, young audience (Goa/trips). |
| Pinterest | Lead ads API with lead data subscription, AES-256-GCM encrypted payload, HTTPS webhook [S] https://dev.pinterest.com/docs/work-with-ads/lead-ads/ | Pinterest Ads API v5 | OAuth 2.0; app review (Trial -> Standard access) | Inspiration-stage travel; low priority; lead ads availability in India - verify. |
| Microsoft Advertising | No native lead form [S]; use landing pages + UET + offline conversion import (`ApplyOfflineConversions`, MSCLKID) | Bing Ads API v13 (SOAP/REST) | OAuth (Microsoft identity), developer token | Low in India (search share small); import Google campaigns via Microsoft "import from Google Ads". Offline conversion: msclkid up to 90 days. |
| Taboola / Outbrain | No lead forms; native ads to landing pages | Taboola Backstage API; Outbrain Amplify API | Client credentials (partner account approval) | Used for content/blog travel arbitrage; spend reporting only; low priority. Conversion API (S2S) with click ids. |
| TikTok | Banned in India (since 2020) - skip | - | - | Do not build. |
| Telegram | Bot API: `setWebhook`, `sendMessage`; channels for community/lead nurture | n/a (Telegram Ads limited) | Bot token from BotFather; no review | Cheap to add as a nurture channel; webhook secret token header `X-Telegram-Bot-Api-Secret-Token`; rate ~30 msg/s global, 1 msg/s per chat. Low-medium priority. |
| YouTube | See 2.5 | via Google Ads | | |

### 3.2 Indian lead marketplaces and travel partners

IndiaMART (most documented, include early if tenants sell B2B / group tours):
- Push API [V]: tenant sets your HTTPS listener URL in Lead Manager > Settings > CRM Integration > Push API. IndiaMART POSTs JSON per lead; you must return HTTP 200; retries at regular intervals until success; integration auto-deactivates if no 200 for 48+ hours. Fields: `UNIQUE_QUERY_ID` (dedupe), `QUERY_TYPE` (W=direct enquiry, B=buy lead, P=phone call (PNS), BIZ=catalog view, WA=WhatsApp), `QUERY_TIME`, `SENDER_NAME`, `SENDER_MOBILE`, `SENDER_EMAIL`, `SENDER_COMPANY`, `SENDER_ADDRESS`, `SENDER_CITY`, `SENDER_STATE`, `SENDER_COUNTRY_ISO`, `QUERY_PRODUCT_NAME`, `QUERY_MESSAGE`, `CALL_DURATION`, `RECEIVER_MOBILE`. No signature: use unguessable per-tenant URL path. https://help.indiamart.com/knowledge-base/integration-of-indiamarts-lead-manager-crm-push-api-with-third-party-crms-real-time-push-of-leads
- Pull API [V]: `GET https://mapi.indiamart.com/wservce/crm/crmListing/v2/?glusr_crm_key=<key>&start_time=...&end_time=...` ; min 5 min between calls, max 7-day window, history <= 365 days, >5 requests/min -> 15 min lockout. Use as backstop: poll every 5 min with overlapping windows, dedupe by `UNIQUE_QUERY_ID`. https://help.indiamart.com/knowledge-base/lms-crm-integration-v2
- Auth: tenant-supplied `glusr_crm_key` (secret). Must be requested by the seller in IndiaMART Lead Manager. No app review.
JustDial:
- No self-serve webhook UI; tenant asks the JD account manager to configure a webhook/URL or uses a JD Leads integration/partner connectors (Pabbly/Kylas style) [S]. Also email-parsing and "JD Lead" API via a partner arrangement. Treat as: webhook (assisted onboarding) + email-ingest fallback. Effort M with partner dependency.
Sulekha / TradeIndia / Housing-type portals (e.g. 99acres, MagicBricks are real-estate; for travel analogues use TravelTriangle, Thrillophilia, Yatra partner portals):
- TradeIndia: lead-pull API with tenant user id + profile id + key (inquiry listing API) [S]; poll model.
- Sulekha: lead emails / dashboard export; API on request [S]. Email-ingest (parse inbound email on a tenant-specific address `leads+<tenant>@...`) is a universal fallback that covers 80% of portals (JD, Sulekha, TripAdvisor leads, MMT partner mails, Thrillophilia).
Travel-specific:
- MakeMyTrip: B2B/"myPartner" and supply connectivity APIs are for inventory/booking, not a lead-push to arbitrary CRMs [S]; lead-flow for tour operators is via MMT "Holidays partner" dashboards/email. Assume email-ingest or manual CSV unless a contractual API is provided.
- Thrillophilia, TravelTriangle, Tripadvisor (Tripadvisor "Viator"/"Experiences" and Tripadvisor Business Advantage: inquiry/booking-request emails; Tripadvisor Content/Display API is read only) [S]: email-ingest + CSV import, optionally per-partner webhook if they agree.
- Aggregators (e.g. Make/Zapier "catch hook" webhooks) are the pragmatic bridge for long tail.
Generic inbound:
- Public per-source webhook `POST /v1/ingest/{source_token}` (JSON or form-encoded), HMAC optional, with field mapping UI. Also provide ready snippets for WordPress (Contact Form 7 / WPForms / Gravity Forms webhook add-ons; Elementor Pro Form "Webhook" action posts form fields), Wix (Velo `wix-fetch` or Automations "Send via webhook"), Webflow form webhooks, Google Forms (Apps Script `onFormSubmit`), Typeform/Tally webhooks, Zapier "Webhooks by Zapier" and Make "Custom webhook". JS tracking snippet that captures UTMs + gclid/gbraid/wbraid/fbclid/fbp/fbc/_ga client id and POSTs with the form (first-party cookie, 90-day) for attribution.

### 3.3 Call tracking / cloud telephony (India)

Common model: virtual number (DID/ExoPhone) per campaign/source -> inbound call webhook (call start/end) -> match to lead by caller number; click-to-call (agent first, then customer) with status callback and recording URL.
- Exotel [V as docs exist]: Voice API. Click-to-call: `POST https://<api_key>:<api_token>@<subdomain>/v1/Accounts/<sid>/Calls/connect` (params `From` (agent), `To`/`CallerId` (ExoPhone), `StatusCallback`, `CustomField`), basic auth; webhooks at account/ExoPhone/call/campaign level; endpoint must return 200 in 15s, up to 2 retries [V]. Callback fields include CallSid, Status, From/To, Direction, Duration, RecordingUrl. https://developer.exotel.com/docs/references/webhooks . Subdomain api.in.exotel.com for India region. TRAI telemarketing rules: use 140-series for promo; DND scrubbing (NCPR) for outbound promo calls.
- Knowlarity (SuperReceptionist): `click_to_call` REST API with API key + `x-api-key`; webhook for call events configured by support/API [S]. Check docs.
- MyOperator: Click-to-call and "Webhook"/"Push API" for call events; also "Call Logs API" with token [S].
- Ozonetel (CloudAgent / Kookoo): CloudAgent REST `Dial`, events via "Webhook/Notification" URL; Kookoo XML for IVR [S].
- Servetel: click-to-call API and call-log webhook [S].
- Design: telephony adapter interface `{click_to_call(agent, lead), on_call_event(webhook) -> Interaction, fetch_recording, list_numbers}`; per-tenant credentials stored encrypted. Dedupe webhooks on CallSid. Recording consent notice (DPDP + IT rules) - play "calls may be recorded".
- Call-based attribution: allocate DID pool for dynamic number insertion (DNI) on websites keyed by session/gclid (needs telephony vendor support); simpler: dedicated number per campaign/ad group/Google call asset.
- Google Ads call-only/call assets + call reporting give `call_view` (GAQL) with caller area code, duration; import qualified calls as offline conversions through gclid only if captured; otherwise use call extensions' "call conversions".

### 3.4 SMS and DLT

- TRAI DLT: Principal Entity registration (19-digit Entity ID; PAN, GST/TAN, authorization letter; ~2-7 working days, ~₹5,900 incl. GST [S]), sender IDs/headers (6-char alpha), content templates (with `{#var#}`), consent templates, and 140xxx/160xxx series; each template gets a Template ID that must be sent with every SMS. Registration is on operator portals (Jio, Airtel, Vi, BSNL, etc.). https://www.plivo.com/docs/sms/concepts/dlt-registration-process [S]. Only Indian entities can register. Implication: DLT registration is per-tenant (their PE ID) or per-you if you are the sender "telemarketer"/aggregator; most SaaS ask the tenant to register and paste Entity ID/Header/Template IDs.
- Template categories: Transactional, Service-implicit/explicit, Promotional; promotional SMS only 9am-9pm and subject to DND/NCPR preferences and consent; transactional OTP/booking confirmations can go anytime.
- MSG91: REST API (`https://control.msg91.com/api/v5/flow/` with `authkey` header, `template_id`, `recipients[{mobiles, var...}]`), DLT template ID mapping, delivery report webhooks; also WhatsApp & Email & voice [K/S].
- Gupshup: SMS (Enterprise API) + WhatsApp BSP; DLR callbacks [K/S].
- Alternatives: Kaleyra, Route Mobile, Twilio (India DLT adds friction), Textlocal.
- Adapter: `SmsProvider.send(to, templateId, vars, dltEntityId, headerId)`; log DLR; handle error "template mismatch" (content must match template exactly; variable length limits). Time-window enforcement in the scheduler.

---------------------------------------------------------------------
## 4. PLATFORM SIZE OF EFFORT (summary of interplay)

Critical-path approvals (start immediately, in parallel):
1. Meta Business Verification (up to ~14 business days) -> App Review for `ads_management`, `ads_read`, `leads_retrieval`, `pages_manage_ads`, `pages_manage_metadata`, `pages_show_list`, `pages_read_engagement`, `business_management`, WhatsApp permissions, Instagram permissions -> Tech Provider/Access Verification. Expect 3-8 weeks total with at least one rejection cycle. Tips: one screencast per permission showing real flow, test user + sandbox credentials, privacy policy that explicitly lists data use, narrow permission requests.
2. Google Cloud project: OAuth consent screen verification for `adwords` scope (+ `business.manage`, `youtube`, `analytics` if used) and Google Ads API Basic then Standard access (manual audit; budget 1-3 weeks). Brand verification first.
3. LinkedIn MDP and Lead Sync approvals (weeks; optional).
4. DLT, WhatsApp number/template approvals are tenant-side.

---------------------------------------------------------------------
## 5. ARCHITECTURE RECOMMENDATIONS

### 5.1 Unified Lead Source abstraction

```
LeadSource (tenant_id, type, auth_ref, config, status, health, last_event_at, mapping)
  types: meta_leadgen | google_lead_form | ctwa_whatsapp | indiamart | justdial | web_form | email_parse |
         call_inbound | linkedin | snapchat | pinterest | csv | api
Connector interface:
  verify_inbound(request) -> bool              # signature / key / path token
  parse(raw) -> [RawLeadEvent]                 # idempotency key = source + external_id
  hydrate(event) -> RawLeadEvent               # e.g. fetch /{leadgen_id}
  backfill(since) -> iterator                  # poll for gap-fill
  health() -> {token_valid, webhook_subscribed, last_ok}
Pipeline: receive(200 fast) -> persist raw payload (immutable, S3/DB) -> queue -> normalise -> identity resolution
          -> dedupe/merge -> enrich (attribution, source) -> assign owner -> trigger speed-to-lead automations -> emit events
```
- Always: persist raw payload first, acknowledge in < 1-2 s, process asynchronously (SQS/Redis streams/Kafka), idempotency keys per source external id (leadgen_id, lead_id, UNIQUE_QUERY_ID, CallSid, wa message id).
- Every source = webhook (fast path) + scheduled reconciliation poll (gap path). Dead-letter queue + replay UI.
- Per-source health dashboard (token expiry, subscription status, last lead time anomalies), alerts when a source goes silent versus expected rate.
- Canonical lead schema: `person` (name, phones[], emails[], city), `lead` (source_id, external_id, created_at, product_interest: destination, travel_dates, pax, budget, message), `attribution` (channel, campaign/adset/ad ids+names, form_id, utm_*, click ids), `consent` records, `raw_ref`.

### 5.2 Identity resolution and dedup

- Phone normalisation to E.164 with `libphonenumber` default region IN: strip `p:`/spaces/dashes/brackets, handle `0` trunk prefix, `91`/`+91`/`0091`, 10-digit starting 6-9 for mobiles, landlines with STD code, reject all-same-digit/test numbers; store `phone_e164` (+919876543210), `phone_national`, `wa_id` (digits, no plus), `phone_hash_sha256` (E.164 digits only for Meta; for Google hash the "+"-prefixed E.164 string - check each spec before hashing).
- Email: lowercase/trim; for Gmail remove dots/plus-suffix for matching keys (keep original for display).
- Identity graph: person has many identifiers (phones, emails, wa_id/BSUID, fb leadgen ids, gclid, ctwa_clid). Merge rules: exact E.164 match = auto-merge into one person; email match = auto-merge unless conflicting phones; fuzzy name+city = suggest only. Maintain merge audit log and unmerge.
- Lead vs person vs opportunity: a repeat enquirer creates a new opportunity/inquiry under the same person (keep first-touch and last-touch attribution on person; per-lead attribution on lead). Re-enquiry within N days of an open lead: attach as "activity", re-notify owner, don't create duplicate.
- Cross-source duplicates (same person via Meta form, IndiaMART, call): keep all source touches; show "also enquired via..." for owners (travel agents often get the same customer from several marketplaces).
- Dedupe key within source: external id; across source: person-level. Rate-limit nuisance (bot spam) with phone validity check (HLR optional), simple heuristics.

### 5.3 Speed-to-lead (< 60 s)

- Webhook -> queue latency budget: receive < 1 s, hydrate (Meta GET) < 2 s, create lead < 1 s, notify < 5 s.
- Automations at T+0..60 s: (1) assign owner (round-robin by destination/language/availability, sticky for returning customers); (2) push notification/WhatsApp-to-agent/Telegram alert + app deep link; (3) auto WhatsApp to lead - inside 24h window only if lead initiated (CTWA); for form leads you need an approved UTILITY/MARKETING template (opt-in checkbox on the form; Meta lead-form WhatsApp opt-in is not guaranteed); (4) auto click-to-call: dial agent then customer via telephony adapter (during calling hours 9-21 IST; skip DND numbers for promo call content; first call response is transactional "you enquired" - get legal view); (5) SMS fallback with DLT template; (6) SLA timers: first-response SLA, escalate to manager at 5 min, reassign at 15 min.
- Measure: `first_response_seconds` per source/agent; cohort conversion by response time; show tenants the value.
- Business hours: out-of-hours auto-reply template with itinerary brochure link; queue call for morning.

### 5.4 Attribution model

Capture at first touch (landing page JS + server) and on each lead:
- UTM (source, medium, campaign, content, term), `gclid`, `gbraid`, `wbraid`, `fbclid`, `fbp`, `fbc`, `msclkid`, GA `client_id`/`session_id`, `ctwa_clid` + `referral` (WhatsApp), Meta `ad_id/adset_id/campaign_id/form_id` (lead ads give these directly), Google `campaign_id/adgroup_id/creative_id/gcl_id` (lead form webhook), landing URL, referrer, device, IP/UA (for CAPI user_data), timestamps.
- Persist click ids with the lead even if the lead is created later by phone/WhatsApp: use phone/email match to previous web session (identity graph).
- Models stored: first-touch, last-touch (default for reporting), and "platform-reported" (from ad APIs) separately. Reconciliation view: platform conversions vs CRM-verified leads; expected mismatch 10-30%.
- Ad-ID joins: Meta insights are keyed by `campaign_id/adset_id/ad_id` -> equals lead `ad_id`; Google by `campaign.id/ad_group.id`; build `ad_entity` table with names and a nightly sync; keep name snapshots because ads get renamed/deleted.
- Offline/organic: label unknown source "direct/unknown"; allow manual source on call leads; use per-source phone numbers/WhatsApp QR with prefilled text codes (e.g. "Hi, ref GOA-IG") as a no-click-id fallback.

### 5.5 Closed-loop feedback to Meta CAPI and Google OCI

- Event bus: on CRM stage change emit `lead.stage_changed` with stage, timestamp, value. Stage mapping per tenant (pipeline stage -> standardized event: `lead`, `qualified`, `quote_sent`/`high_intent`, `booked` (Purchase), `lost`). Stages must be mapped to what the ad platforms understand.
- Meta: Lead Ads leads -> CAPI "Conversion Leads" events with `lead_id`; web leads -> CAPI web events with `fbc/fbp/em/ph` (and event_id); CTWA -> business-messaging CAPI with `ctwa_clid` + WABA id. Send within hours (daily minimum); backfill queue with retry; log Meta `events_received`, `fbtrace_id`, and `messages` warnings; show "Event Match Quality" per tenant (Events Manager dataset quality API `GET /{dataset}/?fields=...` or event diagnostics). Respect 28-day window. Send value only for Purchase (actual booking value, paise-safe decimals) to avoid skewing.
- Google: store gclid/gbraid/wbraid at lead creation; map stages to conversion actions `QUALIFIED_LEAD`, `CONVERTED_LEAD`/`PURCHASE`; batch upload every 15-60 min (partial_failure, error-code table incl. `TOO_RECENT_CONVERSION_ACTION`, `EXPIRED_EVENT`, `CLICK_NOT_FOUND`/`UNPARSEABLE_GCLID`, `CONVERSION_PRECEDES_EVENT`); note that conversion actions need up to 6 hours after creation before they accept uploads; send adjustments on cancellation.
- Lead quality guardrails: do not report junk leads as conversions; send negative signals only where supported (Google `disqualify_lead` through GA4 MP; Meta has no explicit negative event - just don't send positive stage).
- Idempotency: ledger table `conversion_uploads(lead_id, platform, event, status, request_id, response)`; unique on (lead, platform, event) to prevent duplicates; retries with backoff.
- Privacy: hash PII client-side of the SaaS (SHA-256) before send; include consent flags; skip leads with "no marketing/profiling" consent.

### 5.6 Ad spend sync, ROAS, CAC

- Nightly + intraday (every 1-3h for today) insights sync into `ad_spend_daily(tenant, platform, account, campaign_id, adset_id, ad_id, date, spend_inr, impressions, clicks, platform_leads, platform_conversions, currency, fx)`; re-pull last 7 days every night (late attribution and corrections); handle timezone and currency conversion (multi-currency accounts -> INR using daily FX).
- Metrics per campaign / destination / package / channel: CPL (spend / CRM leads), cost per qualified lead, CAC (spend / bookings), booking revenue and gross margin from CRM, ROAS = revenue / spend (and margin-ROAS since travel margins are thin: 5-15% on packages), payback time, lead->booking cycle (travel cycles can be weeks: report cohorts by lead date, not booking date).
- Destination mapping: tag campaigns by naming convention `DEST|PRODUCT|AUDIENCE|OBJECTIVE` or a mapping UI (`campaign -> destination/package/season`); store `product_interest` on leads; join for per-destination ROAS. Offer a naming helper so created campaigns follow it.
- Attribution reconciliation card: platform-reported vs CRM; flag tracking gaps.
- GST: Meta/Google invoices include 18% GST; decide whether ROAS uses spend ex-GST (usually yes for ITC-eligible businesses). Make configurable.

### 5.7 Budget rules and automation

- Rules engine (tenant-defined, evaluated every 15-60 min and on events): `IF <condition> THEN <action>` with conditions on CRM inventory (departure sold out/seats left <= N, season_off flag, date windows), ad metrics (CPL > X for 3 days, frequency > N, spend pacing), lead quality (qualified rate < Y%), and actions: pause/resume campaign or ad set, change daily budget by %/to value (within guardrails), shift budget between campaigns, notify, create task. Guardrails: max change per day (e.g. +/-20%/day to avoid Meta learning reset), min/max budget, human approval mode, dry-run/audit log with revert, never auto-enable something that the user paused manually (track `managed_by_rule`).
- Inventory integration: if the CRM tracks departures/packages and capacity, link ads to departures (via custom `ad -> package` mapping) so "sold out" pauses the ads or swaps the CTA to waitlist form. Seasonality: scheduled flighting (`start_time/end_time` on ad sets; Google `campaign.start_date/end_date`; ad schedules).
- API actions: Meta `POST /{adset-id} {status:"PAUSED"}` / `daily_budget`; Google `CampaignOperation` update `status`/`CampaignBudget.amount_micros`.
- Safety: rate-limit writes; verify account `account_status` and `funding_source`; alert on payment failure (Meta `disable_reason`, `account_status 3 = UNSETTLED`; Google account budget/billing alerts).
- Anomaly detection: spend spike > 2x trailing average, zero leads with spend > threshold, disapproved ads, token revoked.

### 5.8 AI creative and copy suggestions

- Inputs: package data (destination, USP, price, inclusions, seasons), top-performing historical ads/creative tags per tenant, brand tone, language (English/Hindi/regional; India-specific festival/season hooks: Diwali, summer vacation, honeymoon season, long weekends), policy constraints (Meta/Google char limits: Meta primary text ~125 chars visible, headline ~40; Google RSA headlines 30 chars x up to 15, descriptions 90 x 4).
- Outputs: headline/primary text variants, RSA assets, WhatsApp template drafts (marketing vs utility classification hint), lead form question suggestions (qualifying: travel dates, pax, budget, departure city), image/video prompts or Meta Advantage+ creative enhancements; A/B testing plan; keyword/negative keyword suggestions.
- Guardrails: factual price/claims verified against package data; compliance check (no misleading "guaranteed" claims; ASCI guidelines in India; Google travel ad policies); label AI-generated media where required (Google v24.2 SyntheticContent fields [V/S]; Meta AI label); human approval before publishing; log prompts/outputs.
- Learning loop: feed CRM outcomes (booked/qualified) back to rank creatives by cost per qualified lead, not CTR.
- Cost control: use cached/templated prompts; per-tenant quotas.

### 5.9 Compliance (consent, DPDP, platform terms)

- DPDP Act 2023 / Rules 2025 [S]: notified 2025-11-13; consent-manager rules from 2026-11-13; core obligations (notice, purpose-limited consent, withdrawal as easy as giving, breach notification to Board and users, erasure on withdrawal, security safeguards, children's data < 18 needs verifiable parental consent, retention limits, grievance officer) from 2027-05-13. Your SaaS is a Data Processor for tenants (Data Fiduciaries): provide DPA, sub-processor list, data residency choice (India region), deletion APIs, audit logs, encryption, role-based access, breach workflow. Build now: consent ledger per person (purpose, channel, text version, timestamp, source, IP, proof), withdrawal handling propagating to all channels and to ad audiences (remove from Custom Audiences/Customer Match), data-retention policies (auto-purge cold leads, e.g. 24 months), export/erasure tooling.
- Consent capture: lead-form consent checkbox / Meta `custom_disclaimer_responses` stored; website forms with unticked checkbox; WhatsApp opt-in per Meta policy; calls: recording disclosure.
- TRAI/TCCCPR: DLT for SMS; calling hours and DND (NCPR) scrub for promotional voice/SMS; 140-series for telemarketers; transactional vs promotional classification; WhatsApp is outside TRAI DLT but covered by Meta policies and DPDP.
- Platform terms: Meta Platform Terms/Developer Policies - store only necessary data, delete on user request (Data Deletion Callback), no selling data; Customer List Custom Audiences ToS; Google customer data policies (customer match requires consent, health/sensitive categories off), Google EU User Consent Policy for EEA/UK traffic.
- Security: encrypt tokens/secrets, per-tenant isolation, webhook signature validation, least-privilege scopes, token rotation, audit logs, rate limits on public ingest endpoints, bot protection.

---------------------------------------------------------------------
## 6. INTEGRATION MATRIX

Effort: S <= 1 week, M 1-3 weeks, L > 3 weeks (one engineer, excluding approval wait). Priority: P0 = MVP, P1 = fast follow, P2 = later, P3 = opportunistic.

| Integration | Auth type | Sync mode | App review / approval needed | Effort | Priority |
|---|---|---|---|---|---|
| Meta Lead Ads (FB+IG) leadgen | FB Login for Business / system user, Page token | Webhook `leadgen` + reconcile poll | Yes: leads_retrieval, pages_manage_ads, pages_manage_metadata, pages_show_list, pages_read_engagement; Business Verification; Tech Provider | M | P0 |
| Meta Marketing API (campaign CRUD, budgets, insights) | FB Login for Business / system user token | Poll (insights async), write on demand; ad-account webhooks optional | Yes: ads_management, ads_read, business_management (Advanced Access + Standard ads mgmt tier) | L | P0 (read/insights) / P1 (create) |
| Meta CAPI (web + CRM Conversion Leads) | System user / dataset token (access token with ads_management or dataset-level token) | Push (batch, near real-time) | Same app approval (ads_management); no extra review | M | P0 |
| Meta CTWA + WhatsApp Cloud API (inbound, referral, send, CAPI business messaging) | Embedded Signup -> business token | Webhook `messages` + REST send | Yes: whatsapp_business_messaging/management, Business Verification, Tech Provider; template approvals per tenant | L | P0 |
| Meta Custom/Lookalike audiences | Same token | Push (batch) | ads_management; per-account CA ToS acceptance | M | P1 |
| Instagram DMs/comments | FB Login for Business + IG | Webhook | Yes: instagram_manage_messages/comments Advanced | M | P2 |
| Google Ads API (read/reporting) | OAuth2 (adwords) per tenant; MCC optional | Poll (GAQL searchStream) | Cloud project: OAuth verification + Basic/Standard access | M | P0 |
| Google Ads API (create/manage campaigns, budgets, PMax/Demand Gen) | same | Write on demand | Standard access (manual audit) with ad-management use | L | P1 |
| Google Lead Form webhook | Shared key (`google_key`) in payload | Webhook + `lead_form_submission_data` poll | None | S | P0 |
| Google Offline Conversion Import / Enhanced Conversions for Leads | OAuth2 (adwords) | Push batch (15-60 min) | Same as Ads API; customer data terms accepted by tenant | M | P0/P1 |
| GA4 Measurement Protocol / Data API | API secret (MP); OAuth analytics.* | Push / poll | OAuth verification for analytics scopes | S | P2 |
| Google Business Profile (reviews, performance) | OAuth2 `business.manage` | Poll + Pub/Sub notifications | Yes: GBP API access request | M | P2 |
| YouTube Data/Analytics | OAuth2 | Poll | Quota/compliance audit | M | P3 |
| LinkedIn Lead Gen + Marketing API | OAuth 2.0 3-legged | Webhook (`leadNotifications`) + poll | Yes: MDP + Lead Sync approval | M-L | P3 (B2B/MICE only) |
| Snapchat Marketing API + Lead webhook | OAuth2 | Webhook | App approval | M | P3 |
| Pinterest Ads API + lead ads | OAuth2 | Webhook subscription | App review (Trial->Standard) | M | P3 |
| X Ads API lead gen | OAuth 1.0a/2.0 | Webhook/pull export | Ads API access | M | P3 |
| Microsoft Advertising (import + OCI) | OAuth (Microsoft) + dev token | Poll / push | Dev token approval | M | P3 |
| Taboola/Outbrain | Client credentials | Poll (spend only) | Partner approval | S-M | P3 |
| Telegram bot | Bot token | Webhook | None | S | P2 |
| IndiaMART (Push + Pull) | Tenant `glusr_crm_key` / obscure URL | Webhook (push) + 5-min poll | None (seller enables) | S | P0 |
| JustDial | Via account manager / email parse | Webhook (assisted) / email | Commercial arrangement | M | P1 |
| TradeIndia / Sulekha | API key / email | Poll / email | None | S-M | P2 |
| Travel marketplaces (MMT, Thrillophilia, TripAdvisor, TravelTriangle) | none (email/CSV/webhook by agreement) | Email-ingest / CSV | Commercial | S (generic) | P1 (email-ingest) |
| Website forms (WordPress/Elementor/Wix/Webflow), Zapier/Make generic webhook | Per-source secret token | Webhook | None | S | P0 |
| Cloud telephony: Exotel | API key+token (basic auth) | Webhook + REST | None (account) | M | P0 |
| Knowlarity / MyOperator / Ozonetel / Servetel | API key/token | Webhook + REST | None | M each (S after adapter) | P1 |
| SMS: MSG91 / Gupshup | authkey / API key | REST + DLR webhook | DLT registration (tenant) | S-M | P0 (MSG91), P1 (Gupshup) |
| Email ingest (parse leads from portals) | n/a | Inbound email webhook (SES/Mailgun) | None | M | P1 |

---------------------------------------------------------------------
## 7. KEY RISKS / TRAPS CHECKLIST

1. Meta App Review/Business Verification lead time (weeks); start now; keep a stable demo tenant; any rejection resets the clock.
2. Meta version drift: v24 Marketing API expired 2026-10-06; v26 shipped; auto-upgrade can change behaviours (metrics retired, attribution windows removed). Version-pin and contract-test against sandbox weekly.
3. Meta webhook TLS trust store change (new Meta CA) - failures show up as silent lead loss. Add synthetic monitoring using the Lead Ads Testing Tool daily.
4. Lead ads data 90-day retention and webhook retries limited: store raw immediately; run reconciliation poller.
5. Token lifecycle: user-token-based flows break when employees leave; prefer system-user tokens; monitor `debug_token`.
6. Google access moved to Cloud-project model; Basic applications from before 2026-09-10 were closed and need re-application [S]; plan for Standard access manual audit; keep MCC strategy simple; one Cloud project per integration.
7. Google OCI: gclid max 90 days, enhanced conversions 63 days, conversion action propagation delay, `consent` fields, timestamp tz format `yyyy-mm-dd hh:mm:ss+05:30`; duplicates on re-upload need `order_id`.
8. WhatsApp: service/utility messaging became billable on 2026-10-01; payment method mandatory; INR migration by 2026-12-31; marketing templates are ~7-9x utility cost; ecosystem-level marketing frequency capping errors (131049) in India; BSUID/username changes may remove phone numbers from some webhooks [S].
9. CTWA: persist `referral` and `ctwa_clid` at first inbound message only; if you miss it, attribution is lost.
10. Phone normalisation errors cause duplicate leads and CAPI/OCI match failures; unit-test with real-world Indian formats.
11. DLT: content must match registered template exactly; promo SMS time windows and DND; per-tenant entity registration slows onboarding - provide a wizard.
12. DPDP: build consent ledger/erasure before 2027-05-13; Custom Audience uploads and call recording need documented lawful basis.
13. Ad-platform conversion counts will not equal CRM counts; surface both and explain.
14. Aggressive budget automation can reset Meta learning phase and spike spend; apply guardrails, caps and approval flows.
15. Marketplace APIs (JustDial, MMT, Thrillophilia) are partnership-dependent; ship generic email/webhook/CSV ingest first.

---------------------------------------------------------------------
## 8. SUGGESTED PHASING

Phase 0 (week 0): Kick off Meta Business Verification + App Review submissions, Google Cloud OAuth verification + Ads API Basic access, WhatsApp Tech Provider onboarding, DLT guidance docs for tenants.
Phase 1 (MVP ingest + speed-to-lead): generic webhook/web form + JS attribution snippet; Meta Lead Ads webhook; Google Lead Form webhook; IndiaMART push+pull; Exotel click-to-call/call webhook; WhatsApp Cloud API inbound/outbound + CTWA referral capture; MSG91 SMS; identity resolution; assignment + SLA timers.
Phase 2 (closed loop + reporting): Meta CAPI (Conversion Leads + CTWA business messaging), Google OCI/ECL, Meta + Google insights/spend sync, CPL/CAC/ROAS per campaign/destination dashboards.
Phase 3 (ad management): create/edit campaigns/ad sets/ads and budgets for Meta, then Google (Search/PMax/Demand Gen); rules engine (sold out/season-off); audiences/lookalikes; AI creative assistant.
Phase 4 (breadth): JustDial, email-ingest for travel marketplaces, additional telephony, LinkedIn/Snap/Pinterest, GBP reviews, GA4 MP, Instagram DM/comment automation.

---------------------------------------------------------------------
## 9. SOURCES (primary links used)

- Graph/Marketing API versions: https://developers.facebook.com/docs/graph-api/changelog/versions/
- Meta Lead Ads guide: https://developers.facebook.com/documentation/ads-commerce/marketing-api/guides/lead-ads
- Lead Ads webhooks quickstart: https://developers.facebook.com/documentation/ads-commerce/marketing-api/guides/lead-ads/quickstart/webhooks-integration
- Conversions API for CRM (Conversion Leads): https://developers.facebook.com/documentation/ads-commerce/conversions-api/conversion-leads-integration
- Marketing API rate limiting: https://developers.facebook.com/documentation/ads-commerce/marketing-api/overview/rate-limiting
- Insights best practices: https://developers.facebook.com/docs/marketing-api/insights/best-practices/
- WhatsApp pricing: https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing and /non-template-messages
- Meta Marketing API Q2 2026 changes (secondary): https://www.kitchn.io/blog/meta-marketing-api-q2-2026-update
- Meta app review timing (secondary): https://bundle.social/blog/meta-app-review-20-days
- Google Ads API release notes: https://developers.google.com/google-ads/api/docs/release-notes
- Google Ads API access levels: https://developers.google.com/google-ads/api/docs/access-levels
- Developer token change (secondary): https://ppc.land/google-drops-developer-tokens-from-ads-api-access-decisions/
- Google Ads v22 sunset: https://ads-developers.googleblog.com/2026/09/google-ads-api-v22-sunset-reminder.html
- Google Ads lead form webhook: https://developers.google.com/google-ads/webhook/docs/implementation
- Offline conversion import: https://support.google.com/google-ads/answer/15479791 , https://developers.google.com/google-ads/api/docs/conversions/upload-adjustments
- Business Messages shutdown: https://developers.google.com/business-communications/business-messages/resources/release-notes/update-on-gbm
- LinkedIn Lead Sync: https://learn.microsoft.com/en-us/linkedin/marketing/lead-sync/getting-access-leadsync
- X lead gen: https://docs.x.com/x-ads-api/lead-generation
- Pinterest lead ads: https://dev.pinterest.com/docs/work-with-ads/lead-ads/
- IndiaMART push: https://help.indiamart.com/knowledge-base/integration-of-indiamarts-lead-manager-crm-push-api-with-third-party-crms-real-time-push-of-leads ; pull: https://help.indiamart.com/knowledge-base/lms-crm-integration-v2
- Exotel webhooks: https://developer.exotel.com/docs/references/webhooks
- DLT overview: https://www.plivo.com/docs/sms/concepts/dlt-registration-process
- DPDP timeline (secondary): https://www.mondaq.com/dpdp-act-and-rules-2025-the-2026-compliance-milestones-businesses-cant-afford-to-miss/1830402
- WhatsApp INR billing (secondary): https://montymobile.com/blogs/whatsapp-business-api-pricing-in-india-inr-rates-gst-and-the-2026-currency-migration-deadline

Items to re-verify before building (not confirmed on primary pages in this pass): exact India per-message rates and the 1,000 free service-message allowance; Meta Ads Management Standard Access thresholds; exact CAPI business-messaging event list and ctwa_clid validity window; Google 63-day ECL limit; Knowlarity/MyOperator/Ozonetel/Servetel endpoint details; JustDial/Sulekha/TradeIndia API terms; Pinterest lead-ads availability in India; WhatsApp BSUID/username rollout impact on phone numbers.
