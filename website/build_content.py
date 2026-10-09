# -*- coding: utf-8 -*-
"""Content for the SEO landing pages and blog. Plain data; build.py renders it. Written to be accurate about what TripSarthi does and careful about
tax / platform rules that change (GST, TCS, WhatsApp pricing): those say "confirm with your CA" or point to the official source instead of quoting numbers."""

SOLUTIONS = [
 {
  'slug': 'travel-agency-software', 'nav': 'Travel agency software', 'img': 'c-deals.webp', 'img_alt': 'Sales pipeline board for a travel agency',
  'title': 'Travel Agency Software India: CRM, WhatsApp, GST | TripSarthi',
  'desc': 'All-in-one travel agency software for India: capture enquiries, chat on WhatsApp, send itinerary quotes, collect instalments and issue GST invoices. Start free.',
  'h1': 'Travel agency software <span class="grad">built for India</span>',
  'lead': 'One workspace for every step of a trip — from the first WhatsApp enquiry and day-wise quote to instalment payments, GST invoices and the ad campaigns that bring the next customer.',
  'sections': [
   ('What is travel agency software?', '<p>Travel agency software is the system a travel business uses to run its day: recording enquiries, building itineraries and quotes, confirming bookings, collecting payments, paying suppliers and keeping the paperwork in order. Many Indian agencies still do this with a mix of WhatsApp, Excel and handwritten notes. That works at five enquiries a week. At fifty, enquiries get lost, quotes take hours, and nobody is sure who has paid what.</p><p>TripSarthi replaces that patchwork with one connected system designed around how Indian travel agencies, tour operators and DMCs actually sell: WhatsApp-first conversations, supplier rate cards, instalment payments, group departures, GST and TCS.</p>'),
   ('What to look for in travel agency software', '<ul class="bul"><li><b>A real pipeline.</b> Every enquiry becomes a trip with a stage, an owner, a budget and travel dates — not a row in a sheet.</li><li><b>Quotes from your own rates.</b> Itineraries should be priced from your suppliers’ rate cards, with your margin visible to you and hidden from the customer.</li><li><b>WhatsApp built in.</b> Your team should reply from one shared inbox, with follow-ups that respect WhatsApp’s rules.</li><li><b>Payments and reminders.</b> Instalment schedules, payment links and polite automatic reminders save hours of phone calls.</li><li><b>Indian tax and billing.</b> GST invoices, bills of supply, credit notes, TCS tracking and clean exports for your CA.</li><li><b>Reports you can act on.</b> Which sources and campaigns produce bookings, and what margin they leave.</li></ul>'),
   ('How TripSarthi covers each stage', '<div class="grid reveal"><article class="mini"><h3>1. Capture</h3><p>Meta and Google lead ads, website forms, WhatsApp and travel-portal leads land in one pipeline with their source attached.</p></article><article class="mini"><h3>2. Quote</h3><p>Day-wise itineraries from your rate cards, GST and TCS calculated, a branded link or PDF the customer can accept online.</p></article><article class="mini"><h3>3. Book &amp; collect</h3><p>Deposit plus instalments, Razorpay payment links, receipts and WhatsApp reminders before and after each due date.</p></article><article class="mini"><h3>4. Operate</h3><p>Supplier payables, document and visa checklists, group departures with seat inventory, a customer trip portal.</p></article><article class="mini"><h3>5. Invoice</h3><p>Tax invoices and vouchers with gap-free numbering, GSTR-1 and GSTR-3B exports, TCS tracking, Tally export.</p></article><article class="mini"><h3>6. Grow</h3><p>Run Meta and Google campaigns and see leads, bookings, revenue and ROAS for each one.</p></article></div>'),
   ('Made for Indian travel businesses', '<p>Foreign software is rarely built around UPI and Razorpay, the way Indian customers pay in instalments, the 24-hour WhatsApp window, supplier quotes in rupees and dollars, or the paperwork GST and TCS demand. TripSarthi treats these as the foundation, not extras: money is held in rupees to the paisa, foreign-currency supplier costs are converted at a rate locked on each quote, and tax rates are settings you control rather than numbers buried in code.</p><p class="fine">TripSarthi is software, not tax advice. Ask your chartered accountant to confirm GST and TCS settings before you go live.</p>'),
   ('Who uses it', '<p>Solo agents who want to look professional; growing agencies with a sales team that needs to share one WhatsApp number; tour operators selling fixed-date group departures; and DMCs managing many suppliers and rate cards. Plans start with a free account, and you choose a paid plan when you connect WhatsApp and invite your team — see <a href="pricing.html">pricing</a>.</p>'),
   ('Get started in an afternoon', '<ol class="steps2"><li>Create a free account and add your business profile and GSTIN.</li><li>Add your suppliers and rate cards — import them from Excel or paste a list.</li><li>Connect your WhatsApp Business number and install the starter automations.</li><li>Capture your first enquiry and send a quote.</li><li>Invite your team and connect your ad accounts when you are ready.</li></ol>'),
  ],
  'faq': [('What is the best travel agency software in India?', 'The best choice is the one that fits how you sell. If your customers arrive on WhatsApp and pay in instalments, look for software with a shared WhatsApp inbox, quotes built from your own rate cards, payment links and GST invoicing in one place. TripSarthi is built around exactly that workflow.'),
          ('Is TripSarthi suitable for small travel agencies?', 'Yes. A solo agent can use the pipeline, quotes and WhatsApp features, and the Starter plan is priced for small teams. As you grow you can add agents, WhatsApp numbers and automations.'),
          ('Can I import my existing suppliers and rates?', 'Yes. Upload an Excel or CSV file, or paste text from an email, review every row in a preview, and only then save. Existing rates are updated in place so older quotes keep working.'),
          ('Does it handle GST invoices?', 'Yes — tax invoices, bills of supply, credit notes and receipts with gap-free numbering, plus GSTR-1, GSTR-3B and HSN exports for your CA. Please have your CA confirm the settings.'),
          ('Do I need technical knowledge to set it up?', 'No. Setup is guided, and our team can help you connect WhatsApp, import rates and set up your first automations.')],
  'related': ['whatsapp-crm-for-travel-agents', 'gst-billing-for-travel-agents', 'tour-operator-software'],
 },
 {
  'slug': 'whatsapp-crm-for-travel-agents', 'nav': 'WhatsApp CRM', 'img': 'c-inbox.webp', 'img_alt': 'Shared WhatsApp inbox for a travel agency team',
  'title': 'WhatsApp CRM for Travel Agents in India | TripSarthi',
  'desc': 'A WhatsApp CRM for travel agents: shared inbox, travel-ready automations, quote follow-ups and payment reminders that follow WhatsApp’s 24-hour rules.',
  'h1': 'A WhatsApp CRM your <span class="grad">travel team can share</span>',
  'lead': 'Reply to every enquiry from one WhatsApp number, send quotes and payment links in the chat, and let automations handle the follow-ups — without breaking WhatsApp’s rules.',
  'sections': [
   ('Why travel agents need more than the WhatsApp app', '<p>The free WhatsApp Business app is a good start, but it lives on one phone. Messages are lost when an agent leaves, nobody can see who is handling which customer, and there is no safe way to automate follow-ups. A WhatsApp CRM connects to the official WhatsApp Business Platform so your whole team can answer from one shared inbox, with every conversation tied to a trip, a quote and a payment schedule.</p>'),
   ('What you get', '<ul class="bul"><li><b>Shared inbox.</b> Assign, resolve and see unread chats; every conversation is linked to the customer’s trips and bookings.</li><li><b>The 24-hour window, handled.</b> TripSarthi shows whether a chat is inside WhatsApp’s 24-hour customer-service window. Inside it you can send free-form messages; outside it, you choose an approved template instead.</li><li><b>Templates in one place.</b> Create templates and see their Meta approval status.</li><li><b>Travel automations.</b> Seven starter flows: quote follow-up, booking confirmation, payment reminders, pre-departure checklist, passport expiry alerts, post-trip feedback and a trip-start greeting.</li><li><b>Quotes and payment links in chat.</b> Send the customer’s quote page or an instalment link without leaving the conversation.</li><li><b>Opt-outs honoured.</b> People who ask to stop are never messaged again by an automation.</li></ul>'),
   ('Automations that understand travel', '<p>Flows are triggered by what happens to a trip — not just by a keyword. A quote is sent, viewed or goes quiet; a booking is confirmed; a payment arrives or becomes overdue; a departure is a week away; a passport is about to expire. Each flow checks the 24-hour window first: free-form text when the window is open, an approved template when it is closed, and a task for your team when neither is possible.</p>'),
   ('You stay in control of cost and policy', '<p>You connect your own WhatsApp Business number and Meta account. Meta bills conversation charges directly to you; TripSarthi adds no per-message markup. Check Meta’s current pricing for your message categories before you plan campaigns.</p>'),
   ('Set up in three steps', '<ol class="steps2"><li>Connect your WhatsApp Business Platform number in Settings.</li><li>Install the starter automations — they are created as drafts and cannot go live until their templates are approved.</li><li>Invite your agents and start replying from the shared inbox.</li></ol>'),
  ],
  'faq': [('Do I need the WhatsApp Business API?', 'Yes. TripSarthi uses the official WhatsApp Business Platform with your own number, which is what allows a shared inbox, templates and safe automation.'),
          ('Can several agents use the same WhatsApp number?', 'Yes. That is the main reason to use a WhatsApp CRM: the whole team answers from one shared inbox and conversations can be assigned and resolved.'),
          ('What is the WhatsApp 24-hour rule?', 'When a customer messages you, you can reply with free-form messages for 24 hours. After that you can only start a conversation with an approved template message. TripSarthi shows the window on every chat and picks the right kind of message for you.'),
          ('Will automations message people who opted out?', 'No. Opt-outs are respected everywhere, and messages are only sent in line with WhatsApp’s window and template rules.'),
          ('Does TripSarthi charge for WhatsApp messages?', 'No. Meta bills conversation charges to your own account. TripSarthi adds no per-message markup.')],
  'related': ['travel-agency-software', 'gst-billing-for-travel-agents', 'tour-operator-software'],
 },
 {
  'slug': 'gst-billing-for-travel-agents', 'nav': 'GST billing', 'img': 'c-gst.webp', 'img_alt': 'GST invoices and return exports for a travel agency',
  'title': 'GST Invoicing & TCS Software for Travel Agents | TripSarthi',
  'desc': 'GST invoices, bills of supply, credit notes, TCS tracking, GSTR-1 and GSTR-3B exports and Tally vouchers for Indian travel agencies and tour operators.',
  'h1': 'GST invoicing and TCS for <span class="grad">travel agents</span>',
  'lead': 'Issue the right document every time, keep numbering gap-free, and hand your CA clean GSTR-1, GSTR-3B, HSN and Tally exports at month end.',
  'sections': [
   ('Billing is where travel agencies lose time', '<p>Between instalments from customers, payments to hotels and transporters, and TCS on overseas packages, the month-end paperwork of a travel business is heavier than most. Spreadsheets and hand-typed invoices lead to numbering gaps, wrong tax splits and last-minute rushes before the filing dates.</p>'),
   ('What TripSarthi does for you', '<ul class="bul"><li><b>The right document.</b> Tax invoice when you have a GSTIN, bill of supply when you do not, plus credit notes, payment receipts and service vouchers — all as branded PDFs.</li><li><b>Correct tax split.</b> CGST and SGST when the supply is within your state, IGST when it is not, based on the place of supply; GSTINs are checked for validity.</li><li><b>Gap-free numbering.</b> Invoice numbers run in sequence for each financial year, and an issued invoice cannot be edited — corrections are made with credit notes.</li><li><b>Exports for your CA.</b> GSTR-1, GSTR-3B and HSN summary exports, a sales register for Excel, and Tally vouchers.</li><li><b>Filing tracker.</b> Reminders ahead of the filing dates and a place to mark returns as filed.</li><li><b>TCS tracking.</b> Tax collected at source on overseas packages is tracked against the payments you receive, by quarter, with a collectee-wise report.</li><li><b>E-invoicing.</b> For businesses above the turnover threshold, invoices can be registered for an IRN through your GST Suvidha Provider.</li></ul>'),
   ('Rates are settings, not surprises', '<p>GST and TCS rules change. TripSarthi keeps rates as settings you control and shows how every figure was calculated, so you and your accountant can verify them. We do not give tax advice: have your chartered accountant confirm the GST scheme (with or without input tax credit), the TCS treatment and your filing calendar before you rely on any exported file. See our <a href="blog/gst-and-tcs-month-end-checklist-for-travel-agents.html">month-end checklist for travel agents</a> for a practical routine.</p>'),
   ('Customer-friendly by default', '<p>Customers receive a clean invoice or receipt with your logo, bank and UPI details and a QR code for the balance. Your supplier costs and margin never appear on anything a customer can open.</p>'),
  ],
  'faq': [('Does TripSarthi support GST invoices for travel agents and tour operators?', 'Yes. It issues tax invoices, bills of supply, credit notes and receipts with the correct CGST, SGST or IGST split, gap-free numbering and branded PDFs.'),
          ('Can I export GSTR-1 and GSTR-3B?', 'Yes. TripSarthi prepares GSTR-1 and GSTR-3B data, an HSN summary and a sales register for each month. Have your CA review the first month’s files before filing.'),
          ('How does it handle TCS on overseas tour packages?', 'TCS is tracked against the payments you actually receive, with a quarterly view and a report for your accountant. The rate is a setting, so you can follow the rules in force; please confirm the treatment with your CA.'),
          ('Is e-invoicing supported?', 'Yes, for businesses that need it, through your GST Suvidha Provider. Test in the provider’s sandbox first.'),
          ('Can I send invoices on WhatsApp?', 'Yes. Documents can be sent as a WhatsApp message following the 24-hour window and template rules, or shared as a secure link.')],
  'related': ['travel-agency-software', 'tour-operator-software', 'whatsapp-crm-for-travel-agents'],
 },
 {
  'slug': 'tour-operator-software', 'nav': 'Tour operator software', 'img': 'departures.webp', 'img_alt': 'Group departures with seat inventory for a tour operator',
  'title': 'Tour Operator Software India: Group Departures | TripSarthi',
  'desc': 'Software for Indian tour operators and DMCs: group departures with live seat inventory, supplier rate cards, payables, manifests and instalment payments.',
  'h1': 'Tour operator software with <span class="grad">group departures</span>',
  'lead': 'Sell fixed-date tours without overselling a seat, keep every supplier’s rates current, and know exactly what you owe — all connected to quotes, bookings and GST invoices.',
  'sections': [
   ('Built for tour operators and DMCs', '<p>Operators juggle two kinds of selling: custom trips priced from supplier rates, and fixed departures with a limited number of seats. TripSarthi handles both in one system, so a group booking flows through quote, instalments and invoice exactly like a custom trip.</p>'),
   ('Group departures with seat inventory', '<ul class="bul"><li><b>Seats that cannot be oversold.</b> Agents hold seats from an enquiry; holds expire on their own, and availability is re-counted under a lock on every change.</li><li><b>Pricing that matches how you sell.</b> Per-person price, single supplement and child pricing.</li><li><b>Manifests and rooming lists.</b> Download them for your tour manager — without prices or passport numbers.</li><li><b>Go / no-go at a glance.</b> Departures below their minimum travellers close to the date are flagged.</li></ul>'),
   ('Supplier rate cards and payables', '<p>Keep hotels, transporters, activity providers and DMCs with seasonal rate cards. Import a rate sheet from Excel or pasted text, review every row, and update existing rates in place. When a supplier invoice is due, the payables ledger shows what you owe by booking and pay-by date, supports part-payments and flags refunds due on cancelled services. Foreign-currency supplier costs are converted at a rate locked on each quote and the real rupee amount paid is recorded, so your margin is true.</p>'),
   ('From booking to the traveller’s phone', '<p>Customers get an online quote, a private trip portal for payments and documents, and service vouchers — all branded with your logo. Document and visa checklists track passports, photos and visas for every traveller, with uploads reviewed by your team and sensitive numbers encrypted.</p>'),
  ],
  'faq': [('Can I manage fixed departures and custom trips together?', 'Yes. Group departures create a normal quote and booking for each customer, so payments, invoices and reports work the same way as for custom trips.'),
          ('How do you prevent overselling seats?', 'Seats are held or confirmed under a database lock and availability is recounted on every change, so two agents can never sell the same last seat.'),
          ('Can I keep supplier costs in other currencies?', 'Yes. Supplier costs can be recorded in currencies such as USD, AED or THB with the exchange rate locked on each quote. Customer invoices stay in rupees.'),
          ('Is it suitable for DMCs?', 'Yes. Supplier rate cards, seasonal pricing, payables and manifests are designed for destination management companies.'),
          ('Can travellers upload documents?', 'Yes, through a private portal link. Uploads are encrypted and reviewed by your team before they count as done.')],
  'related': ['travel-agency-software', 'gst-billing-for-travel-agents', 'whatsapp-crm-for-travel-agents'],
 },
]

ARTICLES = [
 {
  'slug': 'whatsapp-for-travel-agents-24-hour-rule', 'date': '2026-10-09', 'read': 6,
  'title': 'WhatsApp for Travel Agents: 24-Hour Rule, Templates & Opt-In',
  'desc': 'How travel agents can use WhatsApp Business without getting blocked: the 24-hour window, approved templates, opt-in, and a practical follow-up playbook.',
  'h1': 'WhatsApp for travel agents: the 24-hour rule, templates and opt-in explained',
  'intro': 'Most Indian travel enquiries now start on WhatsApp. Used well, it turns enquiries into bookings faster than any other channel. Used carelessly, it gets numbers blocked. Here is how the rules work and how to build a follow-up routine around them.',
  'body': '''<h2>WhatsApp Business app vs the WhatsApp Business Platform</h2>
<p>The WhatsApp Business <em>app</em> is free and lives on a phone. It is fine for a single person, but it does not give a team a shared inbox, a safe way to automate messages, or connections to your other systems. The WhatsApp Business <em>Platform</em> (often called the API) is what CRMs such as TripSarthi connect to. It lets several agents answer from one number and lets software send messages on your behalf — within Meta’s rules.</p>
<h2>The 24-hour customer-service window</h2>
<p>When a customer messages you, a 24-hour window opens. Inside it you can reply with ordinary free-form messages: text, images, PDFs, payment links. Every new message from the customer restarts the window. Once it closes, you cannot send free-form messages any more. To reach out again you must use a pre-approved <strong>template message</strong>.</p>
<p>For a travel agent this matters most at three moments: sending a quote, chasing a quote that went quiet, and reminding someone about an instalment.</p>
<h2>Template messages</h2>
<p>A template is a message whose wording Meta has approved in advance, with placeholders for details such as the customer’s name or booking reference. Meta sorts templates into categories — for example marketing, utility and authentication — and the category affects both approval and price. Useful travel templates include a booking confirmation, a payment reminder, a document request before departure and a quote follow-up. Meta decides the final category, so write templates that really are what they say they are: a payment reminder should remind about a payment, not sneak in an offer.</p>
<h2>Opt-in and opt-out</h2>
<ul><li>Message people who have given you their number to be contacted — an enquiry form, a click-to-WhatsApp ad, or a message they sent you.</li><li>Do not upload bought lists.</li><li>If someone says “stop”, stop. Make sure your automations honour it.</li></ul>
<h2>What does it cost?</h2>
<p>Meta charges for messages according to their category and the country, and its pricing model changes from time to time. Check Meta’s current price list rather than relying on a blog post. A good CRM adds no markup of its own — with TripSarthi, Meta bills your own account directly.</p>
<h2>A follow-up routine that respects the rules</h2>
<ol><li><strong>Reply within minutes.</strong> The first reply sets the tone. Ask for dates, number of travellers and budget.</li><li><strong>Send the quote while the window is open.</strong> A link to a day-wise quote page beats a long message.</li><li><strong>Follow up inside the window.</strong> A short “any questions on the itinerary?” a few hours later is free-form and costs nothing extra.</li><li><strong>After the window closes, use a template.</strong> A quote follow-up template after two or three days of silence.</li><li><strong>Automate payment reminders with utility templates.</strong> Three days before, on the due date, and after.</li><li><strong>Stop at opt-out or when a human should take over.</strong></li></ol>
<h2>How TripSarthi helps</h2>
<p>TripSarthi shows the 24-hour window on every conversation and chooses between free-form text and an approved template for you. Its travel automations — quote follow-up, booking confirmation, payment reminders, pre-departure checklist — check the window before every send and create a task for your team if no compliant message is possible. See the <a href="../whatsapp-crm-for-travel-agents.html">WhatsApp CRM for travel agents</a> page for details.</p>''',
  'faq': [('How long can I reply to a customer on WhatsApp?', 'For 24 hours after the customer’s last message, with free-form messages. After that you need an approved template message.'),
          ('Can I send quotes on WhatsApp?', 'Yes, inside the 24-hour window you can send a link or a PDF. Outside the window, use an approved template.')],
 },
 {
  'slug': 'gst-and-tcs-month-end-checklist-for-travel-agents', 'date': '2026-10-09', 'read': 7,
  'title': 'GST and TCS Month-End Checklist for Travel Agents in India',
  'desc': 'A practical month-end routine for travel agents and tour operators: the right documents, place of supply, GSTR-1 and GSTR-3B, TCS and what to give your CA.',
  'h1': 'GST and TCS month-end checklist for travel agents in India',
  'intro': 'Month-end is where travel agencies lose evenings. This checklist walks through a routine that keeps invoices, returns and TCS in order. Rules and rates change, so treat it as a process guide and confirm the details with your chartered accountant.',
  'body': '''<p class="notice">This is general information, not tax advice. Rates, thresholds and due dates are set by the government and change; your CA should confirm what applies to your business.</p>
<h2>1. Issue the right document</h2>
<ul><li><strong>Tax invoice</strong> when you are registered under GST and charge GST.</li><li><strong>Bill of supply</strong> when you are not registered, or the supply is exempt.</li><li><strong>Credit note</strong> to correct or cancel an issued invoice — do not edit or delete invoices.</li><li><strong>Payment receipt</strong> for each instalment, so customers have a record even before the final invoice.</li></ul>
<h2>2. Keep invoice numbers in an unbroken sequence</h2>
<p>Invoice numbers should run in a continuous series for each financial year, and the number is limited to 16 characters. Gaps and duplicates are the first thing an auditor notices. Avoid deleting drafts that already consumed a number.</p>
<h2>3. Get the place of supply right</h2>
<p>Whether you charge CGST + SGST or IGST depends on the place of supply compared with your own state. For services to a registered customer it generally follows the recipient’s location; for unregistered customers rules fall back to the recipient’s address, and then to yours. A wrong split changes which tax head the money goes to — worth checking every month.</p>
<h2>4. Collect TCS where it applies</h2>
<p>Tax collected at source on overseas tour packages is collected when you <em>receive</em> money from the customer, not when you issue the invoice. That means TCS follows your instalments. Keep a note of each customer’s PAN, because the rate can differ when it is missing, and track what you collected and deposited each month. The deposit is due by the 7th of the following month, and a quarterly TCS return is filed from the details. Ask your CA which rate and base apply to your packages today.</p>
<h2>5. File on time</h2>
<p>For a monthly filer, GSTR-1 (outward supplies) is generally due on the 11th and GSTR-3B (summary and tax payment) on the 20th of the following month. Put both in your calendar and give your CA the data a few days earlier.</p>
<h2>6. Reconcile before you send anything</h2>
<ul><li>Outward tax in GSTR-3B should match GSTR-1.</li><li>Credit notes should reduce the right month’s liability.</li><li>Input tax credit depends on your GST scheme for tour operator services — your CA should confirm what is claimable.</li></ul>
<h2>7. What to hand your CA</h2>
<ul><li>GSTR-1 data and an HSN summary</li><li>A sales register (invoices and credit notes, with tax split)</li><li>Your ITC figures from supplier invoices</li><li>The TCS collected and deposited, by customer</li></ul>
<h2>Common mistakes</h2>
<ul><li>Editing an invoice instead of issuing a credit note.</li><li>Charging tax on a bill of supply or the reverse.</li><li>Forgetting that TCS follows receipts, not invoices.</li><li>Starting the return on the 19th.</li></ul>
<h2>How TripSarthi helps</h2>
<p>TripSarthi issues invoices and credit notes with gap-free numbering, splits tax by place of supply, tracks TCS against received payments, and exports GSTR-1, GSTR-3B and HSN data with a filing tracker and reminders. See <a href="../gst-billing-for-travel-agents.html">GST billing for travel agents</a>. Always have your CA review the first month’s files.</p>''',
  'faq': [('When are GSTR-1 and GSTR-3B due?', 'For monthly filers they are generally due on the 11th and the 20th of the following month. Confirm your own schedule with your CA.'),
          ('When is TCS collected on tour packages?', 'When you receive payment from the customer, so it follows your instalments rather than the invoice date.')],
 },
 {
  'slug': 'how-to-write-a-travel-quote-that-gets-accepted', 'date': '2026-10-09', 'read': 5,
  'title': 'How to Write a Travel Quote That Gets Accepted',
  'desc': 'Practical tips for travel agents: reply fast, structure a day-wise quote, make the price clear, follow up without nagging and close with an instalment plan.',
  'h1': 'How to write a travel quote that gets accepted',
  'intro': 'A quote is the moment an enquiry becomes a decision. Customers compare three agents on the same evening; the one who answers fastest with the clearest quote usually wins. Here is a simple structure that works.',
  'body': '''<h2>1. Reply fast, then qualify</h2>
<p>Within minutes, thank them and ask the five things that shape a price: dates (or flexibility), number of travellers and ages, budget range, hotel preference and must-do experiences. You can ask on WhatsApp in one message.</p>
<h2>2. Structure the quote the way a trip feels</h2>
<ul><li><strong>A short title and summary</strong> — “6N/7D Bali honeymoon, private villa, Ubud + Uluwatu”.</li><li><strong>Day by day</strong> — arrival, each day’s highlights, departure. People imagine the trip, not a list of services.</li><li><strong>Inclusions and exclusions</strong> in plain words. Flights, visas and insurance are the usual surprises.</li><li><strong>One clear price</strong> — total and per person, with what tax is included.</li><li><strong>A validity date</strong> — rates and availability move.</li></ul>
<h2>3. Make the price easy to trust</h2>
<p>Show the total, not a pile of components. If you offer options (a 4-star and a 5-star version), present them side by side so the customer chooses between yours rather than between you and a competitor. Keep your own costs and margin private.</p>
<h2>4. Send it as a link, not an attachment</h2>
<p>A web link opens on any phone, always shows the latest version, and lets the customer accept with one tap. A PDF is still handy for sharing with a spouse or parent. If your software tells you when the quote was opened, follow up while they are looking at it.</p>
<h2>5. Follow up without nagging</h2>
<ol><li>A few hours later: “Any questions on the itinerary?”</li><li>Next day: offer a tweak — a different hotel, an extra night.</li><li>After two or three days: a gentle reminder that rates are valid until a date.</li></ol>
<h2>6. Close with an easy first payment</h2>
<p>Offer a small deposit with the rest in instalments, and send a payment link in the same conversation. The fewer steps between “yes” and “paid”, the fewer second thoughts.</p>
<h2>7. Keep revisions tidy</h2>
<p>Customers always change something. Save each change as a new version of the quote so you never lose the one they accepted and everyone sees the latest.</p>
<h2>How TripSarthi helps</h2>
<p>Build the itinerary from your own rate cards, add your markup and see the margin, then send a branded quote link. You are notified when it is viewed, follow-up automations run on schedule, and when the customer accepts, a booking with an instalment plan and payment links is one click away. Explore <a href="../features.html#quotes">quotes and itineraries</a>.</p>''',
  'faq': [('How quickly should I send a travel quote?', 'As fast as you can: within hours of the enquiry. Speed is one of the biggest factors in who wins the booking.'),
          ('Should I send quotes as PDFs or links?', 'A link is better for viewing and accepting on a phone; a PDF is useful for sharing. Offering both is ideal.')],
 },
]
