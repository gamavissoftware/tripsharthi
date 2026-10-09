# TravelPilot — Product Blueprint & Roadmap

_Status: 8 Oct 2026. Phase 1 core is built and verified; everything below "Next" is planned, not built._

## 1. Positioning
Research (`docs/research/01-…`) found no Indian product that combines a **WhatsApp-native inbox + correct GST/TCS quoting +
a Tourwriter/Travefy-grade itinerary builder + supplier-rate intelligence at SMB INR pricing**. Foreign tools lack GST/TCS/WhatsApp;
Indian tools are dated or marketplace-led. TravelPilot = LeadPilot's WhatsApp/ads/automation engine + a travel-native workflow.

## 2. What exists today
| Area | Built (Phase 1) |
|---|---|
| Lead capture | WhatsApp inbound, Meta Lead Ads, Google Lead Forms, web forms, CSV, email — all inherited, now writing **ad attribution** |
| Enquiry → trip | Trip model on top of Deals; Travel Sales pipeline; **AI paste-enquiry parser** (Hinglish heuristics offline + Claude when keyed) |
| Quoting | Day-wise itinerary builder, versions, rate-card items, markup/discount, **GST + TCS engine**, margin view, **public shareable quote with Accept**, AI itinerary draft grounded in own rate cards |
| Booking | Booking from itinerary, deposit+instalment schedule, mark-paid, supplier service to-dos + confirmations, travellers with encrypted passports |
| Ads | Attribution (first/last touch), **Ads → Bookings report** (leads→quoted→booked→revenue→margin per campaign, ROAS with Meta spend), **closed-loop conversion outbox → Meta CAPI + Google offline conversions** |
| Platform (inherited) | Multi-tenant SaaS, Razorpay billing, flows, campaigns, inbox, email marketing, AI replies, 1,085 platform tests |

## 3. Next (priority order)
1. **Mobile app** (Expo): enquiries, trip/booking detail, call/WhatsApp, payments due, mark paid, and **push notifications (built; server tested, app bundled but not yet run on a device)**. Still to build: in-app WhatsApp inbox, create/edit enquiries and quotes, offline cache, biometric lock, EAS builds + store listing.
2. ~~Customer payment links + WhatsApp dunning~~ — **built** (see CLAUDE.md). Follow-ups: payment-link expiry/regeneration, partial payments, UPI-autopay/EMI, receipt PDF, per-tenant reminder wording.
3. ~~Meta/Google ad management~~ — **built and tested against a simulator only** (Meta lead + Click-to-WhatsApp; Google Search; launch/pause/budget with spend guardrails; rules; AI copy). NOT yet verified against a real ad account — needs Meta App Review (ads_management, ~3–8 wks) and Google Standard access. Still to build: audiences/lookalikes, Performance Max / Demand Gen, image upload (creatives currently take a public image URL), multiple ad sets per campaign, ad-disapproval alerts.
4. ~~Settings UI for Meta CAPI / Google Ads credentials~~ — **built** (Settings → Ad Platforms). Still needed: a Google Cloud OAuth client + brand verification, and Meta System User token guidance in onboarding.
5. ~~Travel flow triggers~~ — **built** (11 triggers + 7 starter automations, see CLAUDE.md). Follow-ups: first-name variable, visa-status trigger, review-request routing (Google reviews link), group-departure triggers, per-trip WhatsApp thread view.
6. ~~Quote/invoice PDF (GST invoice, voucher)~~ — **built** (quote, tax invoice / bill of supply, credit note, receipt, voucher). Still to build: e-invoicing (IRN + QR, >₹5 Cr turnover), GSTR-1 / Tally / Zoho export, Hindi & regional-language PDFs (needs a shaping-capable engine), sending invoices over WhatsApp, TCS (27EQ) report, saved customer billing addresses.
7. Rate-card **AI import** (PDF/Excel/WhatsApp image → supplier_rates) — the research's biggest moat.
8. Traveller portal + WhatsApp AI concierge; group inventory/departures; Hajj/Umrah and MICE modules; supply APIs (TBO, RateHawk, Hotelbeds); IndiaMART/JustDial lead ingest; call tracking (Exotel/MyOperator).
9. DPDP: consent ledger, erasure workflow, India hosting statement (obligations start ~13 May 2027).
10. Plan/pricing config: replace LeadPilot's generic plan limits with travel plans (research: Starter ₹999 / Growth ₹1,999 / Pro ₹3,499 per user/mo, WhatsApp+AI metered).

## 4. Things to verify before go-live (from research)
- TCS base and timing with a CA; GST ITC treatment per tenant.
- Meta: Business Verification + App Review + Tech Provider (WhatsApp Embedded Signup) — lead time 3–8 weeks. Webhook CA change (31 Mar 2026): server trust store must include Meta's CA.
- WhatsApp India pricing changes (service/utility billable from 1 Oct 2026; INR billing mandatory by 31 Dec 2026) — confirm against Meta's rate card.
- Meta Conversion Leads needs ~200 leads/month and works only for instant-form lead ads; Google click-ID age limits (≤90 days).
- Competitor pricing and several vendor facts in the research are tagged unverified; treat as indicative.
