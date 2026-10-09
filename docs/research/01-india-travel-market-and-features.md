# India Travel CRM: Market, Workflow, Compliance, Integrations, AI, Pricing, Roadmap

Research date: 8 Oct 2026. Confidence tags: **[V]** = verified from a fetched/searched source cited inline; **[K]** = from domain knowledge, not re-verified this session (check before shipping); **[?]** = uncertain / needs CA or vendor confirmation.
Research limits: web search in this session was shallow on vendor-specific pages (many vendors hide pricing); treat competitor INR prices as indicative.

---

## 1. Competitor / feature teardown

### 1.1 Table

| Product | Target segment | Key features | Pricing (INR indicative) | Gaps vs. India need |
|---|---|---|---|---|
| **Sembark** (India) | Indian tour operators, DMCs, agencies | CRM + lead distribution, itinerary builder with markup, booking mgmt, payment tracking, supplier mgmt; per-user, monthly, no annual lock-in, setup fee [V: https://sembark.com/travel-software/pricing/] | Quote-only; listings say from ~INR 3,000 (aggregator, loose) [V: https://www.capterra.com/p/228103/Sembark-Travel/] | Pricing opaque; AI depth unclear [?]; likely weak self-serve onboarding |
| **TravClan** (India) | Small agents; B2B marketplace + tools | Sourcing marketplace (packages/hotels), itinerary/quote, booking coordination; Razorpay partner [V: https://razorpay.com/blog/how-travclan-leveraged-razorpays-partner-program-to-grow-its-monthly-revenue-by-400/] | From ~INR 20,000/yr per aggregator [V: https://us.fitgap.com/products/058183/travclan] | Marketplace-centric: agent's own supplier rates/CRM depth secondary; margin leakage to marketplace |
| **Travelopro** | Small/mid agencies, B2B starters | GST invoicing, B2B agent panels w/ markup + credit limits, TBO/Hotelbeds, Razorpay/PayU | Basic ~INR 2,500/user/mo; Advanced ~4,000/mo; Premium B2B 35-60K/mo [V: https://codingclave.com/guides/best-tour-operator-software-india-2026] | Dated UX, WhatsApp paid add-on, incomplete TCS, 2-4 week customisation, "too generic" for visa-heavy |
| **Travelomatix** | OTA-style portals, tier 2-3 | Multi-product (flight/hotel/bus/holiday), white-label B2B/B2C | INR 2-4K/user/mo [V: codingclave link above] | Weak itinerary builder, WhatsApp add-on |
| **Trawex / Provab** | Mid-large agencies; white-label builds | GDS (Amadeus/Sabre/Travelport) + GIATA; one-time custom builds | Trawex ~INR 3L+/yr; Provab INR 2-10L project [V: same] | Project not product; maintenance burden; dated UX |
| **TripXOXO** | B2B/B2C activities/experiences aggregator, 900+ cities | Inventory + booking platform, agent sourcing [V: capterra search result] | n/a | Inventory, not CRM |
| **CRMtravel / CRM.Travel** | Small agencies | Lead/quote CRM; CRMtravel Starter ~INR 40,000/yr; CRM.Travel free-with-premium-coming [V: https://www.capterra.in/software/1077075/CRM-TRAVEL] | 0 - 40K/yr | Thin ops/finance |
| **TeleCRM, Fuzen, Vedain, generic CRMs** | Generic Indian SMB CRMs positioned for travel | Lead mgmt, WhatsApp, automations [V: https://telecrm.in/travel-crm, https://www.fuzen.io/posts/essential-travel-agency-crm-workflows-to-automate] | ~INR 500-1,500/user/mo [?] | No itinerary/costing/vouchers/GST-TCS |
| **Zoho CRM/Books DIY** | Agencies with in-house tinkerers | Custom modules, Zoho Books GST | ~INR 1,400-2,800/user/mo [K] | No itinerary builder, big setup cost |
| **Tourwriter** (NZ) | Luxury FIT/DMC | Best-in-class itinerary + costing, branded PDFs | USD 99/149/249 per user/mo (~INR 8-21K) [V: https://www.trustradius.com/products/tourwriter/pricing] | No GST/TCS/WhatsApp, no B2B agent panel [V: codingclave] |
| **Tourplan** (AU) | Mid/large DMCs, inbound | Rates/contracts, costing, operations, accounting | From USD 250/user/mo, min 5 users; cloud from ~USD 1,000/mo [V: https://www.getapp.com/hospitality-travel-software/a/tourplan/pricing/] | Heavy, expensive, no India tax |
| **Lemax** (HR) | Large DMCs / outbound | Contracts, multi-currency, financial reporting | Custom, ~INR 2L+/mo; 4-6 month deploy [V: codingclave] | Overkill, partial GST |
| **TravelJoy** (US) | Solo/home-based agents | CRM, forms, proposals, e-sign, payments, AI | Starter USD 19, Pro USD 39/mo [V: https://agiled.app/blog/traveljoy-pricing] | US commission model; no GST/WhatsApp/INR |
| **Travefy** (US) | US agents | Itineraries, client portal, proposals | Core USD 39, Premium USD 59/mo annual [V: https://travefy.com/plans/pricing] | Same |
| **Bokun** (TripAdvisor) / **Rezdy / Checkfront** | Experience/tour operators selling on OTAs | Channel manager (Viator/GYG), booking widget, resellers | Bokun USD 49-499/mo +1-1.5%; Rezdy +3% [V: codingclave] | No GST/TCS/WhatsApp, not agency workflow |
| **TravelPerk / Navan / Zoho Expense-type** | Corporate travel | Booking + policy + expense | n/a | Different (managed corporate travel); in India: corporate desks use TBO/Riya/Cleartrip for Business |
| **Monk, Travel Sutra, Tripjack** | Monk: ?; Travel Sutra: Indian agency ERP [?]; Tripjack: B2B supply platform with API [K] | - | - | Not verified this session; treat as unverified |

### 1.2 Takeaways
- **White space [V-ish, from codingclave]:** nobody combines (a) WhatsApp-native, (b) GST+TCS-correct invoicing, (c) Western-grade itinerary builder, (d) supplier-rate intelligence, (e) INR SMB pricing. Foreign tools fail on 1-3 of these; Indian tools are dated/ERP-ish or marketplace-captured.
- Competitors' stated weaknesses repeat: dated UX, WhatsApp as paid add-on, TCS missing. These are the wedge.
- Itinerary builder quality (Tourwriter/Travefy standard) is the main conversion-visible feature; ops (vouchers, supplier payables) is the retention driver.
- Marketplace-plus-tools (TravClan) wins agents who lack supplier access; a SaaS should *integrate* with such marketplaces rather than compete (import packages, rates).

---

## 2. Indian travel agency workflow (end-to-end)

Context: small/mid agencies run on Excel + WhatsApp + Word/PDF + Tally. Pain evidence: leads from WhatsApp, Instagram DMs, website forms, walk-ins; chats lost/reassigned; 40-minute manual quotes vs. customer expectation of 15 min; itinerary version chaos (V1/V2 with different totals); missed follow-ups and payment reminders [V: https://www.fuzen.io/posts/essential-travel-agency-crm-workflows-to-automate, https://www.aurorainbox.com/en/2025/11/25/travel-agencies-quotes-itineraries-and-payment-reminders-on-autopilot/, https://msg91.com/guide/travel-business-communication-automation-guide-india].

| # | Stage | What actually happens (India) | Pain | Must-automate |
|---|---|---|---|---|
| 1 | **Enquiry capture** | WhatsApp msg/voice note, call, IndiaMART/JustDial/Sulekha lead, FB/IG lead-ad/DM, website form, Google Business call, MMT/Thrillophilia partner leads, walk-in, referral | Scattered, duplicates, lost on staff exit, voice notes | Unified inbox, auto-capture + dedupe by phone (E.164), source/campaign tag, round-robin/skill-based assignment, SLA timer, voice-note transcription |
| 2 | **Qualification** | Dest, dates (often flexible), pax (adults/children ages/infants/seniors), budget per person or total, departure city, hotel category, mode (flight/train/cab), veg/Jain/halal, passport/visa status, honeymoon/anniversary | Asked repeatedly via chat; budget vague | Smart intake form/WhatsApp flow; AI extraction from chat into fields; lead score; missing-field prompts |
| 3 | **Itinerary + quote** | Copy previous itinerary in Word, edit days, call/WhatsApp hotels/DMC for rates, add markup (flat/%/per pax), 2-3 options (Budget/Standard/Premium), inclusions/exclusions, T&Cs, cancellation policy, PDF/WhatsApp link | Slow, rate staleness, wrong inclusions, GST/TCS errors, version chaos | Template library by destination/duration; day-wise builder; rate cards (season, occupancy, extra bed, child policy, MAP/CP/AP); auto cost sheet, markup rules, multi-option, live shareable web quote + PDF; versioning with immutable sent versions; currency (AED/THB/IDR/EUR) with ROE snapshot |
| 4 | **Follow-up** | "Will discuss with family"; ping repeatedly; competitor price-shopping | Silent loss | Cadence automation (D+1, D+3...), WhatsApp nudges (consent!), quote-viewed/opened tracking, "price drop"/"seats left" triggers, lost-reason capture |
| 5 | **Booking confirm** | Token advance via UPI/bank transfer/card/payment link; traveller details; passport copies | Manual receipts; screenshot-based reconciliation | Convert quote -> booking in 1 click; payment link with UPI/EMI; auto receipt; booking number; passport/ID collection portal (secure) |
| 6 | **Payment milestones** | Typically 20-30% token, balance 15-30 days pre-departure; instalments; some EMI; flights/visa paid 100% upfront; group departures have fixed schedule | Chasing, partial payments, refunds, cancellations with slab-based charges | Milestone plans (templates), auto-reminders (WhatsApp/SMS/email), partial-payment ledger, auto GST+TCS lines, cancellation-charge calculator, refund tracking, credit notes |
| 7 | **Supplier booking + vouchers** | Book hotels (TBO/direct/DMC), flights (consolidator/TBO/Tripjack), transport (local vendor), activities, visa (VFS/agency), insurance; vouchers by Word/PDF | Re-keying; confirmation numbers lost; supplier payables unseen; overbooking | Per-service supplier assignment, request -> confirmation -> voucher PDF/WhatsApp generation, supplier payable ledger with due dates, supplier rate-vs-cost variance, API booking where available |
| 8 | **Pre-trip docs** | Tickets, vouchers, itinerary, visa, insurance, packing list, forex, emergency contacts, local guide numbers, e-SIM; GST/TCS invoice | Sent as 10 WhatsApp messages | Traveller portal/app link, one-tap pack, T-7/T-2/T-1 automated messages, checklist (passport validity 6 months, visa, vaccines e.g. Yellow fever/Umrah meningitis) |
| 9 | **In-trip support** | WhatsApp groups with guest + driver + hotel; airport pickup coordination; issues (delay, room, cab no-show) | No ticketing; escalations in 3am chats | Live trip board, driver/guide assignment, WhatsApp concierge + escalation queue, incident log, supplier SOS contacts, live itinerary updates |
| 10 | **Post-trip** | Request Google review/photos, feedback, referrals, next-trip upsell (anniversary, festival, school-holidays) | Rarely done | Auto NPS + Google review link, testimonials, referral codes, repeat-trip prediction, birthday/anniversary reminders, seasonal re-engagement |
| 11 | **Back-office** | GST invoice (5% no ITC vs 18%), TCS, supplier invoices, commission, Tally entry, agent payouts (for B2B), MIS | Month-end chaos, GSTR/TCS mismatches | Auto invoice w/ GST/TCS logic, Tally/Zoho export, trip P&L, aged receivables/payables, GSTR-1 data export |

**Best-in-class system must:** (1) single-thread WhatsApp context per customer; (2) generate a correct, compliant quote in <5 min; (3) never let a lead or payment milestone go untracked; (4) tie every booking to supplier cost, customer receipts, taxes, margin; (5) work on mobile (agents live on phones); (6) be multi-lingual (Hindi + regional at the voice/templates level).

---

## 3. India compliance and finance

> Not legal/tax advice; have a CA validate before encoding rules. Compliance logic should be **configurable rule tables with effective dates**, not hardcoded.

### 3.1 GST on tour packages
- **Tour operator services SAC 998555** (tour operator), 998554 (tour operator services incl. package), 998552 (reservation services for transportation), 998559 (other travel-related) [V: https://busy.in/sac-code-998555/, https://razorpay.com/learn/gst-on-tours-and-travels/].
  *Note: SACs vary by source; consider 9985 family and let accountants map per service line [?].*
- **Rates:** 5% with restricted ITC (no ITC on inputs except ITC of tour-operator services purchased from another tour operator), **or** 18% with ITC; operator chooses. For package inclusive of hotel/transport this is the standard path [V: https://gstindiaguide.com/tc-availability-for-travel-and-tour-operators-paying-gst-5-without-itc/, https://cleartax.in/s/gst-on-tours-travels].
- **Place of supply:** for outbound/domestic packages to an Indian recipient = location of recipient (registered address / billing state). That drives CGST+SGST vs IGST [V: https://taxguru.in/goods-and-service-tax/gst-domestic-international-tours-key-rules-traveller.html]. Flights: GST by departure/recipient rules; air ticket agency commission is separate (18% on commission) [K].
- **Pure agent / agent model:** when acting as pure agent (booking hotel/flight/visa on behalf, billing at actuals), the agent charges GST only on commission/service fee (18%) [K]. System must support both *principal (package)* and *agent (commission/service fee)* invoice types and flag/mix explicitly.
- **Inbound (DMC for foreigners):** export of services zero-rated if payment in convertible forex and conditions met; LUT filing [K].
- **Product implication:** per-booking toggle: "GST scheme: 5% no-ITC / 18% with ITC / agent-commission", per-line SAC, place-of-supply auto from customer state, GSTIN capture (B2B), reverse-charge flags for foreign suppliers [K].
- **Possible rate changes:** GST 2.0 rationalisation (Sept 2025) did not, to my knowledge, alter the 5%/18% tour operator structure, but verify current notification [?].

### 3.2 TCS on overseas tour packages (as of Oct 2026)
- **Budget 2026 change, effective 1 April 2026:** TCS on overseas tour programme packages is **flat 2% from first rupee** (earlier: 5% up to INR 10 lakh, 20% above) [V: https://www.bookmyforex.com/blog/new-tcs-on-foreign-travel-what-you-need-to-know/, https://www.careers360.com/... (Budget 2026 coverage) via https://news.careers360.com/union-education-budget-2026-tcs-cut-to-2-pc-overseas-tours-education-medical-purposes-under-lrs-fm-nirmala-sitharaman/amp].
- Now under the **Income-tax Act, 2025 (effective 1 Apr 2026), s.394(1)** (successor to s.206C(1G)) [V: https://www.patronaccounting.com/blog/tcs-rate-changes-april-2026-overseas-travel-lrs-education-liquor].
- Other LRS: education/medical reduced to 2% above INR 10 lakh (from 5%); other LRS purposes 20% above INR 10 lakh (unchanged per sources); education via specified loan 0 [V: bookmyforex article; verify per-category with CA]. Higher TCS for non-PAN/Aadhaar or specified non-filers (s.206CCA equivalent) [K/?].
- **Mechanics for the system:** TCS collected at time of receipt of payment (per instalment, not just at full payment) [K]; calculated on *gross package value incl. GST* [K/?]; filed via **Form 27EQ quarterly**, deposited by 7th of next month; **Form 27D** certificate to customer; reflects in customer's **Form 26AS / AIS** under PAN [K]. Requires collector TAN.
- **Implement:** per-payment TCS line with rate versioning by effective date; PAN capture (mandatory field for international bookings); TCS ledger; 27EQ-ready CSV export; 27D PDF; refund/cancellation handling (TCS adjustments) [?].
- **Domestic packages / inbound:** no TCS. Hajj/Umrah packages: handled via overseas tour package TCS; Hajj via HCoI has own rules [?].

### 3.3 Invoicing and e-invoice
- **E-invoice** (IRN via IRP) mandatory for businesses with aggregate turnover > **INR 5 crore** in any preceding FY since 2017-18; 30-day reporting cap applies for AATO >= INR 10 crore (as of April 2026 per sources) [V: https://getswipe.in/blog/article/e-invoice-turnover-limit-2026-5-crore-rule-india, https://www.incorpx.io/blog/gst-e-invoice-turnover-limit-2026]. Most SMB agencies are below threshold at launch but mid-size operators are above -> build IRN/QR via GSP API (ClearTax, Masters India, IRIS) as v2 feature.
- B2C invoices: dynamic QR where turnover > INR 500 cr (not relevant) [K].
- Invoice-must-haves: GSTIN, SAC, place of supply, taxable value, split of TCS, "Tax on tour operator service - 5% without ITC" declaration, bill-of-supply rules for composition/exempt [K]. Credit/debit notes. Invoice numbering series (16 chars, FY-reset).
- GSTR-1/3B data export (B2B/B2CL/B2CS tables, HSN/SAC summary).

### 3.4 FEMA / LRS / forex
- Forex agents: must be RBI-authorised (Authorised Dealer Category II/FFMC); travel agencies selling forex without licence are non-compliant [K]. Travel CRMs should **not** hold/move forex; only lead-pass to partners (referral) or integrate partner APIs (e.g., BookMyForex-type).
- **LRS:** USD 250,000 per resident individual per FY; per-transaction PAN, Form A2, purpose code; overseas-tour package payments by Indians from resident accounts count under LRS when paid abroad / via AD banks [K]. Foreign-currency payments by agency to overseas suppliers go through AD bank under FEMA (current account; purpose codes e.g. S0304/S1104) [K/?].
- Data to support: PAN, source of funds flags, cumulative LRS usage disclosure (customer-attested).

### 3.5 Industry bodies / norms
- **IATA:** IATA accredited agents (BSP India settlement, financial criteria, GDS access, ADMs/ACMs). Many small agencies use consolidators instead [K].
- **TAAI, TAFI, ADTOI, IATO, OTOAI, FAITH/FAIITA:** membership gives credibility and some supplier benefits; ADTOI focuses outbound tour operators. **Ministry of Tourism approval** for tour operators (voluntary, incentives) and **state registration** (e.g., Kerala, Goa mandatory tourist trade registration). Some states require adventure/trek operator licensing [K/?].
- **Hajj/Umrah:** Ministry of Haj & Umrah / Haj Committee of India require registered operators (HGO) [K]; Saudi "Nusuk" platform is now the booking channel for Umrah packages [K/?].
- **Consumer Protection Act 2019 / e-commerce rules:** refund timelines, misleading ad rules; DGCA refund norms for airline tickets (21 days) [K].
- Insurance: IRDAI-licensed only; agencies need POSP/CA registration or referral-only [K].

### 3.6 DPDP Act 2023 and DPDP Rules 2025
- Rules notified **13 Nov 2025**; phased: Board constitution immediate; **consent manager framework at 12 months (~13 Nov 2026)**; **main obligations (notice, consent, security, breach notification, children, rights) at 18 months, ~13 May 2027** [V: https://ssrana.in/articles/meity-notifies-final-digital-personal-data-protection-rules-2025/, https://ca.hoganlovells.com/en/publications/indias-digital-personal-data-protection-act-2023-brought-into-force-]. SPDI Rules 2011 stay for the interim [V same].
- Passport, visa, DOB, bank/UPI, health (dietary, medical conditions, Umrah vaccines) are personal data; DPDP has no "sensitive" tier but expect high duty; penalties up to INR 250 crore for security failures [K].
- **Obligations the SaaS inherits:** Agencies are **Data Fiduciaries**; you are a **Data Processor** -> need DPA/processing agreement, India-hosted (or approved region) storage, encryption at rest, field-level encryption for passport/ID, access logs, role-based access, retention + auto-erasure (e.g., passport copies deleted N days post-trip), consent notice per purpose (booking vs marketing), withdrawal, right to access/correction/erasure, grievance officer, breach notification (72h to Board + users per rules [K/?]), children's data (<18) needs verifiable parental consent (family bookings!) [K].
- **Product features:** consent ledger (who, purpose, timestamp, channel, text version); purpose-limited fields; "Delete traveller data" workflow; document vault with expiry; masked display (passport last-4); export (DSAR).

### 3.7 WhatsApp / TRAI DLT / marketing consent
- **WhatsApp Business Platform pricing (per-message since 1 Jul 2025):** India marketing ~INR 0.86/msg (revised ~10% up in 2026), utility/auth ~INR 0.115, service/in-24h-window free; BSP markups ~INR 0.10-0.30/msg [V: https://myoperator.com/blog/whatsapp-business-api-pricing-india-2026, https://baat.ai/blog/whatsapp-api-pricing-india]. Plan cost model accordingly.
- WhatsApp policy: opt-in required for outbound templates; template approval by category (marketing/utility/auth); quality rating limits; no sending on personal numbers via unofficial APIs (ban risk) [K].
- **TRAI TCCCPR 2018 (amended Feb 2025):** DLT registration of entity, headers (sender IDs), templates, consent templates required for SMS/voice; DND scrub for promotional; transactional/service messages exempt from DND but need template; consent-acquisition via DLT [V: https://www.messagecentral.com/sms-guideline/india]. WhatsApp itself is outside DLT but falls under DPDP consent; TRAI 2025 push to extend to OTT is [?].
- **Product:** separate "transactional/service" vs "promotional" streams; DLT template store; consent flag per channel; STOP/opt-out handling; quiet hours (promo 9am-9pm) [K].

---

## 4. Integrations worth building (India)

### 4.1 Payments (priority)
| Integration | Why | Notes |
|---|---|---|
| **Razorpay** (Payment Links, Smart Collect/virtual accounts, Magic checkout, EMI/Cardless EMI, Route for split to partners) | Dominant with agencies; TravClan is a Razorpay partner [V: razorpay blog above] | Partner program for referral rev share; webhooks -> auto-receipt + TCS line |
| **Cashfree** (Payment Links, Payouts for supplier/agent payments, Auto-collect/VA) | Strong payouts + cheaper | Payouts to suppliers = differentiator |
| **PayU / PhonePe PG / Paytm / Juspay** | Alternatives | v2 |
| **UPI intent / QR + UPI AutoPay mandate for instalments** | UPI is dominant; AutoPay for milestone schedules | UPI limit INR 1L (some categories 5L, e.g. travel? verify) [K/?] |
| **EMI:** bank EMI via PG, Cardless EMI (ZestMoney-like closed), Kissht/Snapmint/Bajaj "Travel loans" [K/?] | Honeymoon/Europe bookings | Convenience fee handling |
| Bank statement reconciliation (CSV/ AA) | NEFT/IMPS collections | Auto-match by UTR |

### 4.2 Accounting and tax
- **Tally Prime** export (XML/ODBC import of vouchers, ledgers), Tally is the default in Indian accounting; **Zoho Books**, **QuickBooks India**, **Busy** [K].
- **GST:** GSP/ASP (ClearTax, Masters India, IRIS, Cygnet) for e-invoice IRN, GSTR-2B reconciliation; GSTIN verification API; PAN verification (NSDL/Karza/Digio/Signzy) [K].
- **TCS:** TRACES not API-accessible; produce FVU-compatible 27EQ data file [K].

### 4.3 Supply APIs
| Supplier | Inventory | Notes |
|---|---|---|
| **TBO** (TBO Tek, listed May 2024) | Hotels, flights, ground services, holidays; API + B2B portal [V: https://phptravels.com/hi/providers/tbo-holidays] | Largest B2B in India; API onboarding needs company docs |
| **Tripjack, Riya, Cleartrip for Business, Travelomatix, Akbar Travels** | Flights/hotels/buses | Tripjack/Riya B2B APIs exist [K]; not verified |
| **RateHawk (Emerging Travel)** | Hotels global | Public API, India agent sign-up [K] |
| **Hotelbeds / WebBeds / Agoda / Booking.com (Affiliate/Demand API)** | Hotels | Hotelbeds needs volume; Agoda/Booking affiliate = redirect, not net booking [K] |
| **GDS:** Amadeus, Sabre, Travelport (Galileo) | Air content, PNR | Costly, need IATA/consolidator; use via consolidators |
| **IRCTC** | Rail | IRCTC agents via e-ticketing Agent license, API rarely public; deeplink + PNR import [K/?] |
| **Bus:** RedBus/AbhiBus APIs, **cabs:** Savaari, Ola/Uber Business, MakeMyTrip cabs, Zoomcar [K/?] | Ground | Many agencies use local vendors -> support manual vendor + WhatsApp confirmation |
| **Activities:** Viator, GetYourGuide partner, Klook, Thrillophilia B2B, TripXOXO, Musement [K] | | |
| **Visa:** VFS Global (no public agent API), Atlys/ Visa-as-a-service (Atlys partner API), iVisa, Visa123 [K/?] | Visa status tracking, doc checklists | Build checklists + status tracker; Atlys-type partner referral |
| **Insurance:** Tata AIG, Bajaj Allianz, HDFC Ergo, Digit, ICICI Lombard, Acko (travel plans; partner APIs/POSP) [K] | Add-on at quote | Requires IRDAI-compliant model |
| **Forex:** BookMyForex, Thomas Cook, Centrum, Weizmann (referral APIs) | Lead-gen rev share | Do not hold funds |
| **Others:** DigiLocker (documents), Aadhaar eSign (Leegality, Digio), OCR for passport (MRZ) | | |

Strategy: start with *manual-with-structure* supplier handling (vendors, rate cards, voucher templates), then add 1-2 API suppliers (TBO + a hotel aggregator) as v2; GDS only via partners.

### 4.4 Lead sources and channels
- **WhatsApp Business Platform** (Cloud API, via BSP: Gupshup, Interakt, WATI, AiSensy, Twilio, Meta direct) - primary channel.
- **Meta Lead Ads (FB/IG)** via Graph API leadgen webhooks; **Instagram DMs** via Messenger API; **Google Ads lead forms / Google Business Profile** (calls/messages deprecated chat in 2024, use calls + website) [K/?].
- **IndiaMART, JustDial, Sulekha**: lead APIs/email-parsing/CRM push (IndiaMART "CRM integration" key; JustDial email/API) [K].
- **Marketplace/partner leads:** MakeMyTrip (holidays partner), Thrillophilia, TripAdvisor (reviews/listings), GetYourGuide partner, TravClan -> mostly email/webhook parsing [K/?].
- **Telegram** (Bot API) for groups/pilgrim communities; **Email parsing**, **website form/webhook**, **Click-to-call/IVR** (Exotel, Knowlarity, MyOperator, Ozonetel) with call recording + transcription.
- **SMS/DLT:** MSG91, Gupshup, Kaleyra.
- **Google Calendar / Gmail** sync for sales team; **Google Reviews API** for post-trip.

---

## 5. AI features that genuinely differentiate

Ranked by (impact x feasibility). All should be *human-in-the-loop draft* first.

| # | Feature | Why it matters in India | How | Risk |
|---|---|---|---|---|
| 1 | **Enquiry -> structured lead** (WhatsApp text/voice/screenshot -> fields: dest, dates, pax, ages, budget, hotel class, diet) | Kills re-asking; feeds scoring and itinerary | LLM extraction w/ JSON schema; confidence flags | Hallucinated fields -> show source snippet |
| 2 | **AI itinerary generation from enquiry + agency's own templates/rates** | Reduces 40 min to 3 min [V pain: aurorainbox link] | RAG over agency's past itineraries, rate cards; generate 3 tiers; ground in inventory so price is real; destination-aware pacing (Char Dham road timings, altitude acclimatisation, Dubai visa days) | Must cost from real rates, not LLM-guessed prices |
| 3 | **Supplier rate parsing** (PDF/Excel/WhatsApp image/ text -> structured rate card with seasons, occupancy, blackout, child policy, validity) | Suppliers send rates as WhatsApp images/PDFs daily; biggest hidden pain; strong moat | Vision/LLM extraction + human review UI + validity tracking + diff vs previous | Needs review step; accuracy per table format |
| 4 | **Voice note transcription + translation** (Hindi, Hinglish, Gujarati, Marathi, Tamil, Telugu, Bengali, Malayalam, Kannada, Punjabi) | Many leads speak/voice-note; Tier 2-3 agents | Whisper-class/ Sarvam/ AI4Bharat/ Google STT; summarise to CRM fields | Accent/Hinglish accuracy; DPDP consent for recordings |
| 5 | **WhatsApp AI concierge (customer-facing)** | 24h-window replies free [V: baat.ai]; answers "visa docs? hotel address? pickup time?" from the booking itself | RAG over booking + itinerary + FAQs; handoff to human; guardrails (no price promises/refunds) | Wrong answers; guardrail + escalation |
| 6 | **Lead scoring by intent/budget/season/urgency** | Prioritise follow-ups; explainable (budget vs destination cost, days to travel, pax, response speed, source quality, passport ready) | Start rules + LR/GBM on win/loss; LLM for text intent | Cold-start data; use rules first |
| 7 | **Quote drafting and price sanity** (suggest markup per segment, flag below-margin, competitor-aware based on past wins) | Margin protection | Historic win/loss by markup; guardrails | |
| 8 | **Reply suggestions + follow-up writer** (tone, language, objection handling "need to ask family") | Speed, consistency across agents | LLM with conversation context + brand voice | |
| 9 | **Destination content generator** (day descriptions, FAQs, packing, visa notes, branded PDF copy) in multiple languages | Itinerary polish at scale | LLM w/ curated KB to prevent stale visa info | Visa/regulatory facts must be sourced/dated |
| 10 | **Repeat-trip / churn prediction + occasion triggers** (anniversary, school holidays, festive long weekends, pilgrim yearly cycles) | Upsell, referrals | Simple propensity model + calendar | Small data initially |
| 11 | **Ops copilot**: auto-extract voucher data from supplier confirmation emails/WhatsApp; flag missing vouchers T-3; passport OCR/MRZ validation (6-month rule) | Reduces errors | OCR + rules | |
| 12 | **Call QA / summary** from IVR recordings; sentiment; missed-follow-up detection | Manager visibility | STT + LLM | Consent |

Principles: ground in the agency's inventory; always show "why"; cost per AI action metered (token cost vs ARPU in §7); Indic-language eval sets.

---

## 6. Seasonality and segments (India)

> Seasonal timing is from general domain knowledge [K] unless cited; validate with 2-3 agencies.

### 6.1 Calendar (outbound/domestic demand)
| Window | Demand | Notes |
|---|---|---|
| **Jan-Feb** | Honeymoon/wedding season shoppers; Goa, Kerala, Rajasthan peak winter; Dubai (DSF), Thailand, Maldives | Wedding season Nov-Feb; Rajasthan/Goa/Kerala/Andaman peak Nov-Mar |
| **Mar-Apr** | Pre-summer; Kashmir tulip (Apr), summer plans booked; Char Dham yatra registration opens ~Apr | Char Dham opens late Apr/May (Akshaya Tritiya); mandatory registration |
| **May-Jun** | **Peak family/school vacation**: Kashmir, Himachal (Manali/Shimla), Uttarakhand, Sikkim/Darjeeling, Ladakh (May-Sep), Europe/Singapore/Dubai/Bali/Thailand/Vietnam; Char Dham peak | Highest volumes; booked Feb-Apr; Europe Schengen visa slots early |
| **Jul-Aug** | Monsoon: Kerala Ayurveda, Meghalaya, Ladakh, Spiti (Jun-Sep), Amarnath Yatra (Jul-Aug), Kailash Mansarovar (Jun-Sep), Kanwar; Europe off-peak value | Landslide/flight-disruption risk; Amarnath registration |
| **Sep-Oct** | Navratri/Dussehra/Diwali: **Diwali holidays** (Oct-Nov) -> domestic + Thailand/Dubai/Bali/Singapore; Goa reopens (Oct); Rajasthan season starts; Kerala season starts | Heavy short-haul |
| **Nov-Dec** | Christmas/NYE (Goa, Kerala, Rajasthan, Dubai, Thailand, Europe winter), Andaman, Kashmir snow (Gulmarg), Manali/Auli snow; Chardham closed (Nov) | Peak pricing, early booking |
| **Pilgrimage** | Char Dham (Apr/May-Oct/Nov), Amarnath (Jul-Aug), Vaishno Devi (year-round), Kedarnath, Kashi/Ayodhya (surge post-2024), Tirupati, Shirdi, Rameshwaram, Jagannath Puri (Rath Yatra Jun-Jul), Kumbh (Prayagraj 2025 done; next major Nashik Simhastha 2027) [K/?]; Umrah (Saudi season, Ramadan, Hajj Jun-ish; Hajj 2027 ~May) [K/?] |
| **Corporate/MICE** | Q3/Q4 FY (Oct-Mar) incentive trips; offsites; conferences; Dubai/Thailand/Bali/Goa/Singapore | Bid-driven; GST invoices, PO, TDS |
| **Student/edu tours** | Dec-Jan winter breaks, Feb-Mar post exam, Apr-Jun | School approvals, parental consent, per-student payments |

### 6.2 Segment -> pipeline stages and lead fields

| Segment | Pipeline stages (typical) | Critical lead/booking fields |
|---|---|---|
| **Honeymoon** | New -> Preferences (privacy/romance/beach) -> Quote (3 options) -> Negotiation -> Token -> Docs -> Pre-trip surprise setup -> Trip -> Review | Wedding date, travel window (within 3-6 mo of wedding), budget, destination shortlist, flights included?, room preferences (pool villa), special setups (cake, decor), passport status for both (name change post-marriage), mobility |
| **Family (domestic/intl)** | Enquiry -> Qualification -> Quote -> Family-discussion hold -> Token -> Docs -> Trip | Adults/kids ages (child policy slabs), infants, seniors (mobility/medical), school holiday dates, diet (veg/Jain), connectivity/road-distance tolerance, rooms config (family room/connecting), insurance |
| **Pilgrimage / Char Dham** | Enquiry -> Registration support -> Package (helicopter optional) -> Payment -> Docs -> Trip | Yatra registration ID (Uttarakhand portal), seniors' health certificate, helicopter (Kedarnath) booking slots, mode (cab/bus/heli), accommodation type (dharamshala/hotel), diet, group size; weather/landslide contingency; Aadhaar for registration |
| **Kashmir** | Enquiry -> Quote -> Token -> Docs -> Trip | Houseboat, Gulmarg gondola, Pahalgam, flight cities (SXR), season (tulip/snow), ID for entry/permits, security advisories; Amarnath (separate) |
| **Kerala** | same | Houseboat, Ayurveda, backwaters, Munnar, monsoon (Jul-Aug), train vs flight; cab-based itinerary |
| **Goa** | Short-lead | Beach vs party; group/bachelor; resort vs villa; flights/train; short-lead conversions (days) |
| **Rajasthan** | Qual -> Quote | Heritage/palace hotels, desert camp, photography; wedding (destination weddings) |
| **Dubai / UAE** | Enquiry -> Quote -> Visa -> Docs -> Trip | Visa type/processing (e-visa), passport validity, Burj/desert safari, hotels by area; flights; insurance; TCS 2% |
| **Thailand / Bali / Vietnam / Singapore** | Quote -> Visa/VoA -> Docs | Visa-free/on-arrival status (changes frequently), island combos, transfers, activities, TCS |
| **Europe (Schengen)** | Enquiry -> Itinerary -> Visa file -> Token -> Docs -> Trip | Schengen visa slot dates, travel insurance mandatory, financials (ITR), multi-country route, trains/transfers, long lead time (4-6 months), TCS, higher ticket size, instalments |
| **Corporate / MICE** | RFP -> Proposal -> Negotiation -> PO -> Contract -> Execution -> Invoice/Settlement | Company GSTIN, PAN, decision makers, budget/pax, event dates, venue, AV needs, PO/ approvals, credit terms, TDS, multiple vendors; GST 18% on MICE services |
| **Group departures (fixed)** | Inventory creation -> Seat sales -> Waitlist -> Cutoff -> Confirmed/Cancelled -> Docs -> Trip | Departure date, seats total/sold/held, min-pax cutoff, pricing by sharing (twin/single/triple), pickup city (flight/ex-Delhi), single supplement, payment schedule, tour manager |
| **Umrah / Hajj** | Enquiry -> Package -> Visa/Nusuk -> Docs -> Trip | Passport validity, mahram rules (women), vaccinations (meningitis), Saudi visa/Nusuk, hotel distance to Haram, Ziyarat, group leader, strict doc deadlines, regulated operators |
| **Student tours (school/college)** | Enquiry -> Proposal -> Approval (principal) -> Parent registration -> Payments -> Trip | School/institution, # students/teachers, guardians' consent/emergency numbers, medical, per-student instalments, safety protocols, GST |

**Common cross-segment fields:** source, campaign, owner, city, language preference, WhatsApp opt-in, budget (pp/total), flexible dates, lead temperature, decision-maker, competitor quote, lost reason, repeat customer, referral by.

---

## 7. Pricing and packaging recommendation

### 7.1 Market anchors
- Indian vendors: INR 2,000-4,000/user/mo (Travelopro/Travelomatix) [V: codingclave], TravClan ~INR 20K/yr, CRMtravel ~INR 40K/yr [V: capterra/ fitgap], Sembark per-user, quote-only [V]. Global: Tourwriter ~INR 8-21K/user/mo, Tourplan USD 250+/user [V]. Generic SMB CRMs ~INR 500-1,500/user [?].
- WhatsApp pass-through costs: INR 0.86 marketing, 0.115 utility [V] -> bill as wallet credits with small markup.

### 7.2 Proposed plans (INR, ex-GST, annual discount ~2 months free)
| Plan | Target | Price | Includes |
|---|---|---|---|
| **Starter** | Solo agent / 1-3 staff | **INR 999/user/mo** (min 1; up to 3 users) | Lead CRM, WhatsApp inbox (BYO number via BSP), itinerary builder (5 templates), PDF/web quote, payment links, GST invoice (no TCS auto), 100 bookings/yr cap, basic AI (extraction 200/mo) |
| **Growth** | Agencies 3-15 | **INR 1,999/user/mo** | + Supplier/rate cards, vouchers, payables, milestone payments, TCS + 27EQ export, Tally export, automations, lead assignment, reports, 1,000 AI credits/user |
| **Pro / DMC** | 10-50 users, B2B | **INR 3,499/user/mo + platform fee INR 9,999/mo** | + Multi-brand, agent portal/markup rules, role permissions, API suppliers, e-invoice, rate-sheet AI parsing, concierge bot, SSO, audit logs |
| **Enterprise** | OTA-lite / large DMC | Custom (INR 1.5-5L/mo) | + White-label, custom integrations, dedicated infra/ data residency, SLA |
| **Add-ons** | | | WhatsApp wallet (pass-through + 10-20%), extra AI credits, e-sign, DLT SMS bundle, extra storage |

### 7.3 Per-user vs per-booking
- **Hybrid recommended**: per-user seats (predictable, matches Indian comps) + **usage-based** for what has marginal cost (WhatsApp conversations, AI tokens, e-invoice IRNs, OCR). 
- Avoid per-booking % fees as headline (Rezdy/Checkfront 3% is called "brutal at scale" by Indian buyers [V: codingclave]); but offer **payments take-rate** (Razorpay/Cashfree partner rev share, ~0.1-0.3% of GMV [?]) as hidden upside.
- Gating levers: bookings/yr cap on Starter, active suppliers, AI credits, automations count, API integrations.
- Go-to-market: 14-day free trial, India-pricing in INR with GST invoice, UPI/e-mandate billing, onboarding concierge (import Excel of customers/suppliers), annual plan with Tally-friendly invoice; free tier limited to 20 leads/mo as acquisition [?].
- Unit economics sanity: WhatsApp marketing at INR 0.86 x 2,000 msgs = INR 1,700/mo -> must be pass-through. LLM costs per active user at ~INR 100-300/mo budget -> credit model.

---

## 8. Prioritised roadmap

### MVP (0-4 months) - "Lead to paid booking, India-correct"
1. Unified lead inbox: WhatsApp (Cloud API via BSP), web form, Meta Lead Ads, IndiaMART/JustDial email-parse, manual add; dedupe; assignment; SLA; follow-up tasks/cadences.
2. Customizable pipelines per segment (field sets from §6) and lost-reason.
3. Itinerary & quote builder: templates, day-wise, hotels/transport/activities, inclusions/exclusions, markup, 3 options, shareable web link + PDF, version lock, quote-viewed tracking.
4. Booking conversion, traveller details + doc vault (encrypted), payment milestones, Razorpay/UPI payment links, receipts, reminders (WhatsApp/SMS/email).
5. GST invoice (5% / 18% / agent commission) + **TCS 2% engine with effective-dated rules** + PAN capture; Tally CSV export.
6. Supplier master + vouchers (PDF/WhatsApp) + payables ledger.
7. AI v1: enquiry extraction, voice-note transcription (Hindi/Hinglish), reply suggestions, itinerary draft from agency templates.
8. DPDP-ready foundations: consent ledger, purpose-based notices, deletion workflow, India-hosted data, RBAC, audit log.
9. Mobile-first PWA for agents.

### v2 (4-9 months) - "Ops and scale"
- Rate cards with seasons/occupancy; **AI rate parsing from PDF/Excel/WhatsApp**; cost sheet & margin dashboard.
- Traveller portal/mini-app; pre-trip auto-messages; in-trip board; WhatsApp AI concierge with escalation.
- Lead scoring (rule -> ML); reporting (conversion by source/agent, aged receivables, supplier payables).
- Group departure inventory (seats, cutoffs); B2B agent portal with markup/credit limit.
- e-invoice IRN via GSP; GSTR-1 export; 27EQ file generator; Zoho Books/Tally Prime direct sync.
- First supply API (TBO) + 1 hotel aggregator (RateHawk/Hotelbeds); visa checklist/status tracker; insurance & forex referrals.
- Cashfree payouts for supplier payments; UPI AutoPay instalments; EMI options.
- Google review/NPS automation; multilingual templates (6 Indian languages).

### v3 (9-18 months) - "Platform and moat"
- Full supplier marketplace/aggregation, dynamic packaging with live availability, flights (consolidator/Tripjack/Riya).
- Predictive: churn/repeat-trip, dynamic markup suggestions, demand forecasting by season/destination.
- Corporate/MICE module (RFP, approvals, PO, policy), Hajj/Umrah module (operator compliance).
- White-label for OTAs-lite, Public API/marketplace of apps, data-residency options, SOC 2/ISO 27001.
- Embedded finance: instalment financing, supplier credit, insurance; partner revenue share.
- Offline-first mobile apps for guides/drivers; voice-first agent copilot (Hindi).

### Key risks to validate (next research)
1. Primary interviews with 15-20 agencies (outbound-heavy, Kerala/Kashmir DMCs, Umrah operators) to confirm pain ranking and WTP.
2. CA validation of TCS/GST rule engine (esp. TCS base incl. GST, instalment timing, cancellations, s.394 nuances).
3. TBO/RateHawk API commercial terms for SaaS resellers.
4. WhatsApp BSP selection and Meta policy fit for AI concierge.
5. Direct competitor trials: Sembark, TravClan, Travelopro (only marketing pages reviewed here).

---

### Source index (inline cited)
- https://sembark.com/travel-software/pricing/
- https://codingclave.com/guides/best-tour-operator-software-india-2026
- https://www.capterra.in/software/1077075/CRM-TRAVEL
- https://us.fitgap.com/products/058183/travclan
- https://razorpay.com/blog/how-travclan-leveraged-razorpays-partner-program-to-grow-its-monthly-revenue-by-400/
- https://agiled.app/blog/traveljoy-pricing ; https://travefy.com/plans/pricing ; https://www.trustradius.com/products/tourwriter/pricing ; https://www.getapp.com/hospitality-travel-software/a/tourplan/pricing/
- https://www.bookmyforex.com/blog/new-tcs-on-foreign-travel-what-you-need-to-know/ ; https://www.patronaccounting.com/blog/tcs-rate-changes-april-2026-overseas-travel-lrs-education-liquor ; https://news.careers360.com/union-education-budget-2026-tcs-cut-to-2-pc-overseas-tours-education-medical-purposes-under-lrs-fm-nirmala-sitharaman/amp
- https://busy.in/sac-code-998555/ ; https://razorpay.com/learn/gst-on-tours-and-travels/ ; https://cleartax.in/s/gst-on-tours-travels ; https://gstindiaguide.com/tc-availability-for-travel-and-tour-operators-paying-gst-5-without-itc/ ; https://taxguru.in/goods-and-service-tax/gst-domestic-international-tours-key-rules-traveller.html
- https://getswipe.in/blog/article/e-invoice-turnover-limit-2026-5-crore-rule-india ; https://www.incorpx.io/blog/gst-e-invoice-turnover-limit-2026
- https://ssrana.in/articles/meity-notifies-final-digital-personal-data-protection-rules-2025/ ; https://ca.hoganlovells.com/en/publications/indias-digital-personal-data-protection-act-2023-brought-into-force-
- https://myoperator.com/blog/whatsapp-business-api-pricing-india-2026 ; https://baat.ai/blog/whatsapp-api-pricing-india ; https://www.messagecentral.com/sms-guideline/india
- https://phptravels.com/hi/providers/tbo-holidays
- https://www.fuzen.io/posts/essential-travel-agency-crm-workflows-to-automate ; https://www.aurorainbox.com/en/2025/11/25/travel-agencies-quotes-itineraries-and-payment-reminders-on-autopilot/ ; https://msg91.com/guide/travel-business-communication-automation-guide-india ; https://telecrm.in/travel-crm
