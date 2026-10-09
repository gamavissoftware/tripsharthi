#!/usr/bin/env python3
"""Builds the multi-page TripSarthi site. The home page (index.html) is hand-written; this script
  * rewrites its header / footer / contact details so every page shares them,
  * generates features, pricing, about, contact, privacy and terms pages and sitemap.xml.
Run:  python3 website/build.py      (no dependencies)"""
import os, re, datetime

ROOT = os.path.dirname(os.path.abspath(__file__))
SITE = 'https://tripsarthi.com'
COMPANY = 'Gamavis Software Solutions'
EMAIL = 'manglesh@gamavis.com'
PHONES = [('+91-9718991797', '+919718991797'), ('+91-9650609615', '+919650609615'), ('+91-8447031736', '+918447031736')]
ADDRESS_LINES = ['BH-820, 8th Floor, Puri Business Hub', 'Sector 81, Faridabad', 'Haryana 121004']
ADDRESS = ', '.join(ADDRESS_LINES)
MAPS_Q = 'Puri+Business+Hub+Sector+81+Faridabad+Haryana+121004'
UPDATED = '9 October 2026'

def ico(name, cls='ico'): return f'<svg class="{cls}"><use href="#i-{name}"/></svg>'

NAV = [('features.html', 'Features'), ('index.html#product', 'Product tour'), ('pricing.html', 'Pricing'), ('about.html', 'About'), ('contact.html', 'Contact')]

def header(cur=''):
    links = ''.join(f'<a href="{h}"{" class=\"cur\" aria-current=\"page\"" if h == cur else ""}>{t}</a>' for h, t in NAV)
    return f'''<header class="nav" id="top">
  <div class="wrap nav-in">
    <a class="brand" href="index.html" aria-label="TripSarthi home"><img src="assets/brand/mark.png" alt="" width="40" height="40"><span>Trip<b>Sarthi</b></span></a>
    <nav class="links" id="menu" aria-label="Main">
      {links}
      <div class="menu-cta"><a class="btn btn-ghost" data-app="login" href="https://app.tripsarthi.com/#/login">Log in</a><a class="btn btn-primary" data-app="signup" href="https://app.tripsarthi.com/#/register">Start free</a></div>
    </nav>
    <div class="nav-cta"><a class="btn btn-ghost" data-app="login" href="https://app.tripsarthi.com/#/login">Log in</a><a class="btn btn-primary" data-app="signup" href="https://app.tripsarthi.com/#/register">Start free</a></div>
    <button class="burger" id="burger" aria-label="Open menu" aria-expanded="false" aria-controls="menu">{ico('menu')}</button>
  </div>
</header>'''

def footer():
    phones = ''.join(f'<a href="tel:{t}">{d}</a>' for d, t in PHONES)
    return f'''<footer class="foot">
  <div class="wrap foot-in">
    <div class="fbrand"><a class="brand light" href="index.html"><img src="assets/brand/mark.png" alt="" width="40" height="40"><span>Trip<b>Sarthi</b></span></a><p>Your Travel Business. Simplified.<br>CRM, WhatsApp automation, GST billing and ads for Indian travel agencies.</p>
      <address class="faddr">{'<br>'.join(ADDRESS_LINES)}</address></div>
    <div><h4>Product</h4><a href="features.html">Features</a><a href="index.html#product">Product tour</a><a href="pricing.html">Pricing</a><a href="index.html#faq">FAQ</a></div>
    <div><h4>Company</h4><a href="about.html">About us</a><a href="contact.html">Contact</a><a href="contact.html#demo">Book a demo</a><a data-app="signup" href="https://app.tripsarthi.com/#/register">Create free account</a><a data-app="login" href="https://app.tripsarthi.com/#/login">Log in</a></div>
    <div><h4>Talk to us</h4>{phones}<a href="mailto:{EMAIL}">{EMAIL}</a><h4 style="margin-top:22px">Legal</h4><a href="privacy.html">Privacy policy</a><a href="terms.html">Terms of service</a></div>
  </div>
  <div class="wrap foot-bot"><span>© <span id="yr">2026</span> {COMPANY}. TripSarthi is a product of {COMPANY}.</span><span>WhatsApp, Meta, Google and Razorpay are trademarks of their respective owners.</span></div>
</footer>'''

SPRITE = None  # filled from index.html so icons stay in one place

def page(fname, title, desc, body, cur='', extra_head='', ld=''):
    url = f'{SITE}/{fname}'
    return f'''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title}</title>
<meta name="description" content="{desc}">
<link rel="canonical" href="{url}">
<meta name="theme-color" content="#0a1f44">
<meta property="og:type" content="website"><meta property="og:site_name" content="TripSarthi"><meta property="og:title" content="{title}"><meta property="og:description" content="{desc}"><meta property="og:url" content="{url}"><meta property="og:image" content="{SITE}/assets/img/og.png"><meta name="twitter:card" content="summary_large_image">
<link rel="icon" type="image/png" sizes="32x32" href="assets/brand/favicon-32.png"><link rel="apple-touch-icon" href="assets/brand/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/site.css">
{ld}
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
{SPRITE}
{header(cur)}
<main id="main">
{body}
</main>
{footer()}
<script src="assets/js/site.js" defer></script>
</body>
</html>
'''

def hero(kicker, h1, sub, extra=''):
    return f'''<section class="phero"><div class="hero-bg" aria-hidden="true"><i class="blob b1"></i><i class="blob b2"></i></div>
  <div class="wrap"><p class="kicker">{kicker}</p><h1>{h1}</h1><p class="lead">{sub}</p>{extra}</div></section>'''

def cta(title='Ready to simplify your travel business?', sub='Create a free account, or talk to us and we’ll show you around.'):
    return f'''<section class="final"><div class="wrap final-in reveal"><img src="assets/brand/mark.png" alt="" width="84" height="84"><h2>{title}</h2><p>{sub}</p>
  <div class="cta-row center"><a class="btn btn-accent btn-lg" data-app="signup" href="https://app.tripsarthi.com/#/register">Start free {ico('arrow')}</a><a class="btn btn-glass btn-lg" href="contact.html#demo">Book a demo</a></div></div></section>'''

def frame(img, label, alt, w=1800, h=1125):
    return f'<div class="frame"><div class="frame-bar"><i></i><i></i><i></i><span>{label}</span></div><img loading="lazy" src="assets/img/{img}" width="{w}" height="{h}" alt="{alt}"></div>'

# ------------------------------------------------------------------------------------------------------------------------------------
FEATURES = [
 ('leads', 'teal', 'Leads & CRM', 'Never lose an enquiry again', 'Every lead becomes a trip in a visual pipeline, with its source, budget and travel dates attached.',
  ['Pipeline board with stages, deal value and “rotting” alerts for deals gone cold', 'Capture from Meta and Google lead ads, website forms, WhatsApp and email', 'Travel-portal leads parsed straight from a private webhook or notification email', 'Duplicate contacts merged automatically; auto-assignment and lead scoring', 'Contacts, accounts, tasks and meetings in the same workspace'], 'c-deals.webp', 'Sales pipeline board', 1600, 1068),
 ('whatsapp', 'green', 'WhatsApp inbox', 'One WhatsApp number, your whole team', 'Reply from a shared inbox that knows WhatsApp’s rules, so nobody sends a message that would be blocked.',
  ['Shared inbox with assignment, resolve and unread tracking', 'The 24-hour window is shown on every chat; when it is closed you pick an approved template instead', 'Template manager with Meta approval status; broadcast campaigns to saved segments', 'Opt-outs are honoured everywhere — no accidental messages', 'Meta bills conversations to your own account; TripSarthi adds no per-message markup'], 'c-inbox.webp', 'WhatsApp inbox', 1600, 1068),
 ('automation', 'purple', 'Automations', 'Follow-ups that run themselves', 'Start with seven travel-ready automations and edit the wording to match your voice.',
  ['Visual flow builder with travel triggers: quote sent, viewed, accepted or stale; booking confirmed; payment received or overdue; departure soon; trip started or completed; passport expiring', 'Every send checks the 24-hour window: free text when open, an approved template when closed', 'Starter flows install as drafts and cannot go live until their templates are approved', 'Run counts per flow so you can see what is working'], 'flows.webp', 'Automation flows', 1800, 1125),
 ('quotes', 'blue', 'Quotes & itineraries', 'Day-wise quotes in minutes, with margin you can see', 'Build from your own rate cards, add your markup and send a link or PDF your customer can accept online.',
  ['Day-by-day builder with hotels, transfers, sightseeing and activities', 'GST and TCS calculated for you; margin visible to staff only — customers never see costs', 'AI can draft the plan, but a price can only come from a rate card you entered', 'Versioning, duplicate-as-new-version and a branded PDF', 'Supplier costs in USD, AED, THB and more, with the exchange rate locked per quote'], 'c-itinerary.webp', 'Itinerary builder', 1600, 1068),
 ('suppliers', 'orange', 'Suppliers & rates', 'Rate cards that stay current', 'Keep every supplier, season and rate in one place and know exactly what you owe.',
  ['Supplier directory and seasonal rate cards', 'Import a rate sheet from Excel, CSV or pasted text; review every row before it is saved and price jumps over 50% need confirmation', 'Updating a rate keeps older quotes working', 'Supplier payables with pay-by dates, part-payments and refunds due'], 'payables.webp', 'Supplier payables', 1800, 1125),
 ('bookings', 'orange', 'Bookings & payments', 'Get paid on time, without awkward calls', 'Turn an accepted quote into a booking with an instalment plan and let the system do the chasing.',
  ['Deposit plus instalments, with a Razorpay payment link per instalment (using your own Razorpay account)', 'Automatic WhatsApp reminders before and after each due date, only during sensible hours', 'Receipts, service vouchers and a document and visa checklist per traveller', 'A private customer portal for payments, invoices and document uploads'], 'c-booking.webp', 'Booking and payments', 1600, 1068),
 ('gst', 'purple', 'GST & finance', 'GST invoices and month-end filing, handled', 'Issue the right document every time and give your CA clean exports.',
  ['Tax invoices, bills of supply, credit notes and receipts with gap-free numbering', 'CGST/SGST/IGST split by place of supply; GSTIN checksum validation', 'GSTR-1, GSTR-3B and HSN summary exports, a filing tracker and reminders', 'TCS tracking for overseas packages, by quarter, with a collectee-wise report', 'E-invoicing (IRN) for businesses above the turnover threshold through your GST provider; Tally voucher export'], 'c-gst.webp', 'GST and accounting exports', 1600, 1068),
 ('departures', 'teal', 'Group departures', 'Fixed-date tours, seats that can’t be oversold', 'Sell limited seats with live inventory, from first hold to final manifest.',
  ['Seat holds that expire on their own and are checked against a locked count — a seat is never sold twice', 'Per-person price, single supplement and child pricing', 'Manifest and rooming-list downloads for your tour manager', 'At-risk flag when a departure is below its minimum travellers'], 'departures.webp', 'Group departures', 1800, 1125),
 ('ads', 'red', 'Ads that pay for themselves', 'See which ads bring bookings, not just clicks', 'Create and manage campaigns without leaving TripSarthi — and see the revenue each one produced.',
  ['Meta lead forms and Click-to-WhatsApp; Google Search, Performance Max and Demand Gen', 'Campaigns start paused; a daily spend cap is required; rules can pause or alert, never raise budgets', 'Leads, quotes, bookings, revenue and ROAS per campaign, from first-touch attribution', 'Booking events are sent back to Meta and Google so they find more buyers like yours', 'Customer audiences built only from opted-in contacts and hashed before upload'], 'c-adreport.webp', 'Ads to bookings report', 1600, 1068),
 ('customer', 'blue', 'Customer experience', 'A professional face for your brand', 'What your travellers see is as polished as what your team sees.',
  ['Online quote page with one-tap accept', 'Customer trip portal: payments, invoices, vouchers and document uploads on any phone', 'Branded PDFs — quotes, GST invoices with a UPI QR code, receipts and service vouchers', 'Your costs and margin never appear in anything a customer can open'], 'quote.webp', 'Customer quote page', 1400, 1145),
 ('reports', 'teal', 'Reports & team', 'Know what makes you money', 'See the funnel, margin and cash position without exporting a spreadsheet.',
  ['Business report: funnel, revenue excluding tax, margin, top destinations, lead sources and agent performance', 'Receivables, payables and a rough cash forecast', 'Owners, admins and agents with role-based access; an audit log of changes', 'Push alerts for new leads, replies, viewed quotes and payments (mobile app rolling out)'], 'report.webp', 'Business report', 1800, 1125),
]

def features_page():
    chips = ''.join(f'<a href="#{k}">{t}</a>' for k, _, t, *_ in FEATURES)
    rows = []
    for i, (k, color, tag, h, lead, bullets, img, label, w, hh) in enumerate(FEATURES):
        bl = ''.join(f'<li>{b}</li>' for b in bullets)
        rows.append(f'''<div class="row{' rev' if i % 2 else ''} reveal" id="{k}"><div class="copy"><span class="tag {color}">{tag}</span><h3>{h}</h3><p>{lead}</p><ul class="bul">{bl}</ul></div><div class="shot">{frame(img, label, label, w, hh)}</div></div>''')
    body = hero('Features', 'Everything a travel business needs, <span class="grad">in one place</span>', 'From the first enquiry to the final payment and the GST return — here is what TripSarthi does.',
                f'<nav class="chips" aria-label="Jump to a feature">{chips}</nav>') + f'<section class="section"><div class="wrap">{"".join(rows)}</div></section>' + '''<section class="section soft"><div class="wrap"><div class="head"><p class="kicker">Security &amp; privacy</p><h2>Built to be trusted with your customers’ data</h2></div>
<div class="grid reveal"><article class="mini"><span class="ico-bubble green">''' + ico('lock') + '''</span><h3>Encrypted where it matters</h3><p>Passport numbers and customer document uploads are encrypted at rest.</p></article>
<article class="mini"><span class="ico-bubble blue">''' + ico('shield') + '''</span><h3>Your data stays separate</h3><p>Every agency’s data is isolated from every other agency’s.</p></article>
<article class="mini"><span class="ico-bubble teal">''' + ico('users') + '''</span><h3>Consent first</h3><p>Ad audiences use only opted-in contacts, hashed before upload; opt-outs are respected.</p></article></div></div></section>''' + cta()
    return page('features.html', 'Features — TripSarthi', 'Leads, WhatsApp inbox, automations, quotes, bookings, payments, GST invoicing, group departures and ads for Indian travel agencies.', body, 'features.html')

def pricing_page():
    def plan(name, desc, m, a, items, pop=False):
        li = ''.join(f'<li>{ico("check")}{x}</li>' for x in items)
        btn = 'btn-primary' if pop else 'btn-outline'
        return f'''<article class="plan{' pop' if pop else ''}">{'<span class="badge">Most popular</span>' if pop else ''}<h3>{name}</h3><p class="pdesc">{desc}</p><p class="price"><span>₹</span><b data-m="{m}" data-a="{a}">{m}</b><small>/ month</small></p>
        <a class="btn {btn} btn-block" data-app="signup" href="https://app.tripsarthi.com/#/register">Start free</a><ul>{li}</ul></article>'''
    plans = (plan('Starter', 'For solo agents getting organised', '1,499', '1,199', ['1 WhatsApp number', '2 team agents', '5 active flows', '2,000 contacts', 'CSV import', 'Basic analytics'])
             + plan('Growth', 'For growing agencies with a sales team', '3,999', '3,199', ['3 WhatsApp numbers', '10 team agents', '20 active flows', '10,000 contacts', 'Meta Lead Ads', 'Advanced analytics', 'Priority support'], True)
             + plan('Pro', 'For large teams and multi-brand operators', '6,999', '5,599', ['Unlimited WhatsApp numbers', 'Unlimited agents', 'Unlimited flows &amp; contacts', 'All integrations', 'White-label ready', 'Dedicated support']))
    yes, no = f'<span class="y">{ico("check")}</span>', '<span class="n">—</span>'
    rows = [('WhatsApp numbers', '1', '3', 'Unlimited'), ('Team agents', '2', '10', 'Unlimited'), ('Active automation flows', '5', '20', 'Unlimited'), ('Contacts', '2,000', '10,000', 'Unlimited'),
            ('CSV import', yes, yes, yes), ('Meta Lead Ads', no, yes, yes), ('Analytics', 'Basic', 'Advanced', 'Advanced'), ('All integrations', no, no, yes), ('White-label', no, no, yes), ('Support', 'Standard', 'Priority', 'Dedicated')]
    tr = ''.join(f'<tr><th scope="row">{r[0]}</th><td>{r[1]}</td><td class="hl">{r[2]}</td><td>{r[3]}</td></tr>' for r in rows)
    faq = [('Is there a free plan?', 'You can create a free account and explore with your own data. Choose a paid plan when you are ready to connect your WhatsApp number and invite your team.'),
           ('What does “per month” include?', 'Your TripSarthi workspace and the limits shown for the plan. WhatsApp conversation charges and ad spend are billed by Meta and Google directly to your own accounts; TripSarthi adds no per-message markup.'),
           ('Can I change plans later?', 'Yes. You can move to a higher plan as your team and contact list grow.'),
           ('How does annual billing work?', 'Annual plans are billed once a year at a 20% discount to the monthly price.'),
           ('Are taxes included?', 'Prices are in Indian rupees and exclude taxes where applicable.'),
           ('Do you help with setup?', 'Yes. Call or email us and we will help you connect WhatsApp, import your suppliers and rates, and set up your first automations.')]
    fq = ''.join(f'<details><summary>{q}</summary><p>{a}</p></details>' for q, a in faq)
    body = hero('Pricing', 'Simple plans that <span class="grad">grow with you</span>', 'Start free and pick a plan when you’re ready. Save 20% with annual billing.',
                '<div class="toggle" role="group" aria-label="Billing period"><button class="on" data-bill="monthly">Monthly</button><button data-bill="annual">Annual <em>−20%</em></button></div>') + f'''
<section class="section" style="padding-top:24px"><div class="wrap"><div class="plans">{plans}</div><p class="fine center">Prices in Indian rupees, per month; annual plans are billed yearly. Taxes extra where applicable. WhatsApp conversation charges are billed by Meta directly to your own account.</p></div></section>
<section class="section soft"><div class="wrap narrow"><div class="head"><h2>Compare plans</h2></div><div class="tablewrap"><table class="cmp"><thead><tr><th></th><th>Starter</th><th class="hl">Growth</th><th>Pro</th></tr></thead><tbody>{tr}</tbody></table></div></div></section>
<section class="section"><div class="wrap narrow"><div class="head"><p class="kicker">FAQ</p><h2>Pricing questions</h2></div><div class="faq">{fq}</div><p class="center fine">Need something different — more users, multiple brands or help migrating? <a href="contact.html">Talk to us</a>.</p></div></section>''' + cta()
    ld = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"SoftwareApplication","name":"TripSarthi","applicationCategory":"BusinessApplication","operatingSystem":"Web","offers":[{"@type":"Offer","name":"Starter","price":"1499","priceCurrency":"INR"},{"@type":"Offer","name":"Growth","price":"3999","priceCurrency":"INR"},{"@type":"Offer","name":"Pro","price":"6999","priceCurrency":"INR"}]}</script>'
    return page('pricing.html', 'Pricing — TripSarthi', 'TripSarthi plans start at ₹1,499 a month. Starter, Growth and Pro for travel agencies of every size. Save 20% with annual billing.', body, 'pricing.html', ld=ld)

def about_page():
    body = hero('About us', 'Built in India, for the people who <span class="grad">plan journeys</span>', 'TripSarthi is the workspace we wished every travel agency had — one calm place for enquiries, quotes, payments and paperwork.') + f'''
<section class="section"><div class="wrap two"><div class="copy"><p class="kicker">Our story</p><h2>A <em>sarthi</em> is a companion on the road</h2>
<p>In Hindi, a <em>sarthi</em> is a travelling companion — the one who guides you and steadies the journey. That is the role we want TripSarthi to play for the people who build trips for everyone else.</p>
<p style="margin-top:14px">Indian travel agencies run on WhatsApp, phone calls, spreadsheets and goodwill. Enquiries get lost, quotes take hours, payments need chasing, and month-end GST is a scramble. We built TripSarthi to bring all of that into one place, designed around how Indian travel businesses actually work: WhatsApp-first selling, GST and TCS, supplier rate cards, group departures and instalment payments.</p></div>
<div class="stack2"><div class="card quote"><p>“One workspace for the whole journey — from the first WhatsApp message to the final GST invoice.”</p><span>Our product goal</span></div></div></div></section>
<section class="section soft"><div class="wrap"><div class="head"><p class="kicker">What we believe</p><h2>Principles behind the product</h2></div>
<div class="cards3 reveal"><article class="card pain"><span class="ico-bubble teal">{ico('sparkles')}</span><h3>Simple beats clever</h3><p>Powerful tools only help if a busy agent can use them between calls. We keep screens plain and actions obvious.</p></article>
<article class="card pain"><span class="ico-bubble blue">{ico('shield')}</span><h3>Honest by design</h3><p>AI never invents a price, customers never see your costs, and campaigns never spend more than you allowed.</p></article>
<article class="card pain"><span class="ico-bubble orange">{ico('rupee')}</span><h3>India first</h3><p>GST, TCS, WhatsApp policy, Razorpay and rupee-first pricing are part of the foundation, not an afterthought.</p></article></div></div></section>
<section class="section"><div class="wrap"><div class="head"><p class="kicker">What we build</p><h2>One platform, every step of the trip</h2></div>
<div class="grid reveal"><article class="mini"><span class="ico-bubble green">{ico('chat')}</span><h3>Conversations</h3><p>Shared WhatsApp inbox and automations.</p></article><article class="mini"><span class="ico-bubble blue">{ico('file')}</span><h3>Quotes &amp; bookings</h3><p>Itineraries, instalments and customer portal.</p></article><article class="mini"><span class="ico-bubble purple">{ico('receipt')}</span><h3>Billing &amp; GST</h3><p>Invoices, TCS and filing exports.</p></article>
<article class="mini"><span class="ico-bubble red">{ico('megaphone')}</span><h3>Growth</h3><p>Meta and Google campaigns tied to real bookings.</p></article><article class="mini"><span class="ico-bubble teal">{ico('users')}</span><h3>Operations</h3><p>Suppliers, payables and group departures.</p></article><article class="mini"><span class="ico-bubble orange">{ico('chart')}</span><h3>Insight</h3><p>Funnel, margin and cash-flow reports.</p></article></div></div></section>
<section class="section soft"><div class="wrap narrow"><div class="head"><p class="kicker">The company</p><h2>{COMPANY}</h2><p class="sub">TripSarthi is a product of {COMPANY}, a software company based in Faridabad, Haryana.</p></div>
<div class="company"><div><h3>Visit</h3><address>{'<br>'.join(ADDRESS_LINES)}</address></div><div><h3>Call</h3>{''.join(f'<a href="tel:{t}">{d}</a><br>' for d, t in PHONES)}</div><div><h3>Email</h3><a href="mailto:{EMAIL}">{EMAIL}</a></div></div></div></section>''' + cta('Let’s simplify your travel business together', 'Try TripSarthi free, or call us — we’d love to hear how you work today.')
    return page('about.html', 'About us — TripSarthi', 'TripSarthi is built by Gamavis Software Solutions in Faridabad, India — a CRM, WhatsApp, quoting, billing and ads platform for travel agencies.', body, 'about.html')

def contact_page():
    phones = ''.join(f'<a class="big" href="tel:{t}">{d}</a>' for d, t in PHONES)
    body = hero('Contact us', 'Let’s talk about your <span class="grad">travel business</span>', 'Questions, a demo, help with setup or a partnership — call or write to us and we’ll get back to you.') + f'''
<section class="section" style="padding-top:24px"><div class="wrap">
<div class="cards3 reveal"><article class="card cc"><span class="ico-bubble blue">{ico('phone')}</span><h3>Call us</h3><div class="clist">{phones}</div></article>
<article class="card cc"><span class="ico-bubble teal">{ico('chat')}</span><h3>Email us</h3><div class="clist"><a class="big" href="mailto:{EMAIL}">{EMAIL}</a></div><p class="fine">We reply as soon as we can.</p></article>
<article class="card cc"><span class="ico-bubble orange">{ico('pin')}</span><h3>Visit us</h3><address>{COMPANY}<br>{'<br>'.join(ADDRESS_LINES)}</address><p style="margin-top:10px"><a href="https://www.google.com/maps/search/?api=1&amp;query={MAPS_Q}" target="_blank" rel="noopener">Open in Google Maps ↗</a></p></article></div>
<div class="cform-wrap" id="demo"><div class="cform-copy"><p class="kicker">Send a message</p><h2>Book a demo or ask us anything</h2><p class="sub">Tell us a little about your agency and we’ll reply by email or phone.</p>
<ul class="bul"><li>See the product on your own use case</li><li>Get help connecting WhatsApp and importing rates</li><li>Ask about plans, teams and migration</li></ul></div>
<form class="card cform" id="contact-form" novalidate><div class="two-f"><label>Your name *<input name="name" required autocomplete="name"></label><label>Email *<input type="email" name="email" required autocomplete="email"></label></div>
<div class="two-f"><label>Phone<input name="phone" type="tel" autocomplete="tel"></label><label>Agency / company<input name="company" autocomplete="organization"></label></div>
<label>I’d like to<select name="topic"><option value="demo">Book a demo</option><option value="pricing">Ask about pricing</option><option value="support">Get help or support</option><option value="partner">Discuss a partnership</option><option value="other">Something else</option></select></label>
<label>Message<textarea name="message" rows="5" placeholder="How many agents are on your team? Which tools do you use today?"></textarea></label>
<button class="btn btn-primary btn-lg btn-block" type="submit">Send message {ico('arrow')}</button><p class="fine" id="cf-note" role="status">This opens your email app with the message ready to send to {EMAIL}. Prefer to talk? Call {PHONES[0][0]}.</p></form></div>
<div class="map"><iframe title="Map showing the TripSarthi office at Puri Business Hub, Sector 81, Faridabad" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="https://www.google.com/maps?q={MAPS_Q}&amp;output=embed"></iframe></div>
</div></section>'''
    ld = f'<script type="application/ld+json">{{"@context":"https://schema.org","@type":"Organization","name":"{COMPANY}","url":"{SITE}/","email":"{EMAIL}","telephone":"{PHONES[0][1]}","address":{{"@type":"PostalAddress","streetAddress":"BH-820, 8th Floor, Puri Business Hub, Sector 81","addressLocality":"Faridabad","addressRegion":"Haryana","postalCode":"121004","addressCountry":"IN"}},"contactPoint":[{",".join(f"""{{"@type":"ContactPoint","telephone":"{t}","contactType":"customer support","areaServed":"IN","availableLanguage":["en","hi"]}}""" for _, t in PHONES)}]}}</script>'
    return page('contact.html', 'Contact us — TripSarthi', f'Call {PHONES[0][0]} or email {EMAIL}. Book a TripSarthi demo. Office: Puri Business Hub, Sector 81, Faridabad, Haryana 121004.', body, 'contact.html', ld=ld)

NOTICE = '<p class="notice"><b>Draft for legal review.</b> This page is a plain-language starting point prepared by the product team. Have a qualified lawyer review and adapt it (retention periods, governing law, grievance officer) before you rely on it.</p>'

def legal(fname, title, desc, inner):
    body = f'<section class="legal"><div class="wrap narrow"><div class="doc-card">{NOTICE}{inner}</div></div></section>'
    return page(fname, f'{title} — TripSarthi', desc, body)

def privacy_page():
    return legal('privacy.html', 'Privacy policy', 'How TripSarthi handles personal data.', f'''<h1>Privacy policy</h1><p class="meta">Last updated: {UPDATED}</p>
<p>TripSarthi is operated by {COMPANY}, {ADDRESS} (“we”, “us”). This policy explains what we collect, why, and the choices you have. It applies to this website and to the TripSarthi software service.</p>
<h2>Two roles</h2><p>When you use TripSarthi to manage <em>your</em> customers’ enquiries, bookings and messages, you are the data fiduciary for that data and we process it on your behalf, only to provide the service. For information about you as our customer (account, billing and usage), we are the data fiduciary.</p>
<h2>What we collect</h2><ul><li><b>Account data:</b> name, work email, company name, phone number and password (stored hashed).</li><li><b>Billing data:</b> plan, invoices and payment status. Card details are handled by our payment provider and never stored by us.</li><li><b>Workspace data you add:</b> contacts, enquiries, quotes, bookings, payments, supplier details and messages. Passport numbers and customer document uploads are encrypted at rest.</li><li><b>Messages you send us:</b> what you write in our contact form or by email or phone.</li><li><b>Technical data:</b> IP address, device and browser type, and basic logs to keep the service secure.</li></ul>
<h2>How we use it</h2><ul><li>To provide, secure and improve the service and to give support.</li><li>To reply to your enquiries and send service messages (for example billing and security notices).</li><li>To comply with law and to enforce our terms.</li></ul><p>We do not sell personal data.</p>
<h2>Integrations</h2><p>If you connect third-party services (for example WhatsApp Business Platform, Meta Ads, Google Ads, Razorpay), data is exchanged with them as you direct. Their own terms and privacy policies apply. Customer lists used to build ad audiences are limited to contacts who have opted in and are hashed before upload.</p>
<h2>Retention and deletion</h2><p>We keep workspace data while your account is active. You can export or ask us to delete it; we will delete or anonymise it within a reasonable period unless the law requires us to keep it.</p>
<h2>Your rights and grievances</h2><p>Under India’s Digital Personal Data Protection Act, 2023 you may request access, correction, erasure and grievance redressal. Write to <a href="mailto:{EMAIL}">{EMAIL}</a> or call {PHONES[0][0]}.</p>
<h2>Security</h2><p>We use encryption in transit, encryption at rest for sensitive fields, tenant isolation and access controls. No system is perfectly secure; please use strong passwords and tell us promptly about any concern.</p>
<h2>Changes</h2><p>We will post updates on this page and change the date above.</p>
<h2>Contact</h2><p>{COMPANY}<br>{'<br>'.join(ADDRESS_LINES)}<br><a href="mailto:{EMAIL}">{EMAIL}</a></p>''')

def terms_page():
    return legal('terms.html', 'Terms of service', 'The terms for using TripSarthi.', f'''<h1>Terms of service</h1><p class="meta">Last updated: {UPDATED}</p>
<p>These terms govern your use of TripSarthi, provided by {COMPANY}, {ADDRESS} (“we”). By creating an account you agree to them.</p>
<h2>The service</h2><p>TripSarthi is software for travel businesses: CRM, WhatsApp messaging, quotes, bookings, payments tracking, GST documents and ad management. Features and plan limits are described on our website and may change.</p>
<h2>Your account</h2><ul><li>You must provide accurate information and keep your credentials secure.</li><li>You are responsible for activity in your workspace, including that of users you invite.</li></ul>
<h2>Acceptable use</h2><ul><li>Message only people who have agreed to hear from you, and follow WhatsApp, Meta and Google policies.</li><li>Do not upload unlawful content or use the service to send spam, harass or defraud.</li><li>Do not attempt to disrupt or reverse-engineer the service.</li></ul>
<h2>Your data</h2><p>You own your data. You grant us permission to process it only to provide the service as described in our <a href="privacy.html">privacy policy</a>.</p>
<h2>Fees</h2><p>Paid plans are billed in advance in Indian rupees; taxes are extra where applicable. Third-party charges (for example WhatsApp conversation fees and ad spend) are billed by those providers to you directly.</p>
<h2>Tax, legal and financial output</h2><p>TripSarthi calculates GST, TCS and prepares exports using the information and rates you configure. It is not tax or legal advice; you are responsible for the accuracy of your filings and should consult your chartered accountant.</p>
<h2>Availability and liability</h2><p>We work to keep the service available but do not guarantee uninterrupted operation. To the extent permitted by law, our liability is limited to the fees you paid in the three months before the claim, and we are not liable for indirect or consequential losses.</p>
<h2>Termination</h2><p>You may stop using the service at any time. We may suspend accounts that breach these terms. On termination you can export your data for a reasonable period.</p>
<h2>Governing law</h2><p>[To be completed with the governing law and jurisdiction.]</p>
<h2>Contact</h2><p>{COMPANY}, {ADDRESS}<br><a href="mailto:{EMAIL}">{EMAIL}</a> · {PHONES[0][0]}</p>''')

# ------------------------------------------------------------------------------------------------------------------------------------
def patch_index():
    global SPRITE
    p = os.path.join(ROOT, 'index.html'); s = open(p, encoding='utf-8').read()
    SPRITE = re.search(r'<!-- icon sprite -->\s*(<svg.*?</svg>)', s, re.S).group(1)
    s = re.sub(r'<header class="nav".*?</header>', lambda m: header(), s, flags=re.S)
    s = re.sub(r'<footer class="foot">.*?</footer>', lambda m: footer(), s, flags=re.S)
    s = re.sub(r'<a class="btn btn-glass btn-lg" id="demo"[^>]*>Book a demo</a>', '<a class="btn btn-glass btn-lg" id="demo" href="contact.html#demo">Book a demo</a>', s)
    ld = f'{{"@type":"Organization","name":"TripSarthi","url":"{SITE}/","logo":"{SITE}/assets/brand/mark.png","email":"{EMAIL}","telephone":"{PHONES[0][1]}","address":{{"@type":"PostalAddress","streetAddress":"BH-820, 8th Floor, Puri Business Hub, Sector 81","addressLocality":"Faridabad","addressRegion":"Haryana","postalCode":"121004","addressCountry":"IN"}},"parentOrganization":{{"@type":"Organization","name":"{COMPANY}"}}}}'
    s = re.sub(r'\{"@type":"Organization".*?\}\}(?=,\n \{"@type":"SoftwareApplication")', lambda m: ld, s, flags=re.S)
    open(p, 'w', encoding='utf-8').write(s)

def write(fname, html):
    open(os.path.join(ROOT, fname), 'w', encoding='utf-8').write(html); print('wrote', fname)

if __name__ == '__main__':
    patch_index()
    for f, fn in [('features.html', features_page), ('pricing.html', pricing_page), ('about.html', about_page), ('contact.html', contact_page), ('privacy.html', privacy_page), ('terms.html', terms_page)]: write(f, fn())
    urls = [('', '1.0', 'weekly'), ('features.html', '0.9', 'monthly'), ('pricing.html', '0.9', 'monthly'), ('about.html', '0.6', 'yearly'), ('contact.html', '0.7', 'yearly'), ('privacy.html', '0.3', 'yearly'), ('terms.html', '0.3', 'yearly')]
    sm = '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' + ''.join(f'  <url><loc>{SITE}/{u}</loc><changefreq>{c}</changefreq><priority>{pr}</priority></url>\n' for u, pr, c in urls) + '</urlset>\n'
    open(os.path.join(ROOT, 'sitemap.xml'), 'w').write(sm); print('wrote sitemap.xml')
