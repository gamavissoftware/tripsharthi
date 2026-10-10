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

GSC_TOKEN = ''      # Google Search Console "HTML tag" verification token (the content="…" value). Leave empty until you have it.
BING_TOKEN = ''     # Bing Webmaster Tools verification token
WHATSAPP = '919718991797'
TODAY = datetime.date.today().isoformat()

def ver(path):
    """?v=<hash of the file> so browsers/CDNs fetch a changed CSS/JS file immediately but cache an unchanged one for a long time."""
    import hashlib
    try: return '?v=' + hashlib.md5(open(os.path.join(ROOT, path), 'rb').read()).hexdigest()[:8]
    except OSError: return ''


NAV = [('features.html', 'Features'), ('solutions.html', 'Solutions'), ('pricing.html', 'Pricing'), ('blog/index.html', 'Blog'), ('about.html', 'About'), ('contact.html', 'Contact')]

def header(cur='', p=''):
    links = ''.join(f'<a href="{p}{h}"{" class=\"cur\" aria-current=\"page\"" if h == cur else ""}>{t}</a>' for h, t in NAV)
    return f"""<header class="nav" id="top">
  <div class="wrap nav-in">
    <a class="brand" href="{p}index.html" aria-label="TripSarthi home"><img src="{p}assets/brand/mark-96.png" alt="" width="40" height="40"><span>Trip<b>Sarthi</b></span></a>
    <nav class="links" id="menu" aria-label="Main">
      {links}
      <div class="menu-cta"><a class="btn btn-ghost" data-app="login" href="https://app.tripsarthi.com/#/login">Log in</a><a class="btn btn-primary" data-app="signup" href="https://app.tripsarthi.com/#/register">Start free</a></div>
    </nav>
    <div class="nav-cta"><a class="btn btn-ghost" data-app="login" href="https://app.tripsarthi.com/#/login">Log in</a><a class="btn btn-primary" data-app="signup" href="https://app.tripsarthi.com/#/register">Start free</a></div>
    <button class="burger" id="burger" aria-label="Open menu" aria-expanded="false" aria-controls="menu">{ico('menu')}</button>
  </div>
</header>"""

def footer(p=''):
    phones = ''.join(f'<a href="tel:{t}">{d}</a>' for d, t in PHONES)
    sol = ''.join(f'<a href="{p}{x["slug"]}.html">{x["nav"]}</a>' for x in SOLUTIONS)
    return f"""<footer class="foot">
  <div class="wrap foot-in">
    <div class="fbrand"><a class="brand light" href="{p}index.html"><img src="{p}assets/brand/mark-96.png" alt="" width="40" height="40"><span>Trip<b>Sarthi</b></span></a><p>Your Travel Business. Simplified.<br>CRM, WhatsApp automation, GST billing and ads for Indian travel agencies.</p>
      <address class="faddr">{COMPANY}<br>{'<br>'.join(ADDRESS_LINES)}</address></div>
    <div><p class="fh">Product</p><a href="{p}features.html">Features</a><a href="{p}index.html#product">Product tour</a><a href="{p}pricing.html">Pricing</a><a href="{p}index.html#faq">FAQ</a><a href="{p}blog/index.html">Blog</a></div>
    <div><p class="fh">Solutions</p>{sol}</div>
    <div><p class="fh">Company</p><a href="{p}about.html">About us</a><a href="{p}contact.html">Contact</a><a href="{p}contact.html#demo">Book a demo</a><a data-app="signup" href="https://app.tripsarthi.com/#/register">Create free account</a><a data-app="login" href="https://app.tripsarthi.com/#/login">Log in</a></div>
    <div><p class="fh">Talk to us</p>{phones}<a href="mailto:{EMAIL}">{EMAIL}</a><p class="fh" style="margin-top:22px">Legal</p><a href="{p}privacy.html">Privacy policy</a><a href="{p}terms.html">Terms of service</a></div>
  </div>
  <div class="wrap foot-bot"><span>© <span id="yr">2026</span> {COMPANY}. TripSarthi is a product of {COMPANY}.</span><span>WhatsApp, Meta, Google and Razorpay are trademarks of their respective owners.</span></div>
</footer>"""

SPRITE = None  # filled from index.html so icons stay in one place
import json as _json
from build_content import SOLUTIONS, ARTICLES

def jsonld(*objs):
    return ''.join('<script type="application/ld+json">' + _json.dumps(o, ensure_ascii=False, separators=(',', ':')) + '</script>' for o in objs if o)

ORG = {'@type': 'Organization', '@id': SITE + '/#org', 'name': 'TripSarthi', 'url': SITE + '/', 'logo': {'@type': 'ImageObject', 'url': SITE + '/assets/brand/mark.png', 'width': 512, 'height': 512},
       'email': EMAIL, 'telephone': PHONES[0][1], 'parentOrganization': {'@type': 'Organization', 'name': COMPANY},
       'address': {'@type': 'PostalAddress', 'streetAddress': 'BH-820, 8th Floor, Puri Business Hub, Sector 81', 'addressLocality': 'Faridabad', 'addressRegion': 'Haryana', 'postalCode': '121004', 'addressCountry': 'IN'},
       'contactPoint': [{'@type': 'ContactPoint', 'telephone': t, 'contactType': 'customer support', 'areaServed': 'IN', 'availableLanguage': ['en', 'hi']} for _, t in PHONES]}

def crumbs_ld(items):
    return {'@context': 'https://schema.org', '@type': 'BreadcrumbList', 'itemListElement': [{'@type': 'ListItem', 'position': i + 1, 'name': n, 'item': u} for i, (n, u) in enumerate(items)]}

def faq_ld(items):
    return {'@context': 'https://schema.org', '@type': 'FAQPage', 'mainEntity': [{'@type': 'Question', 'name': q, 'acceptedAnswer': {'@type': 'Answer', 'text': a}} for q, a in items]} if items else None

def head(fname, title, desc, p='', robots='index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1', og_type='website', ld='', extra='', inline_css=False):
    url = f'{SITE}/{fname}' if fname != 'index.html' else SITE + '/'
    css_tag = ('<style>' + open(os.path.join(ROOT, 'assets/css/all.min.css'), encoding='utf-8').read().replace('url(../fonts/', 'url(assets/fonts/') + '</style>') if inline_css else f'<link rel="stylesheet" href="{p}assets/css/all.min.css{ver("assets/css/all.min.css")}">'
    verify = (f'<meta name="google-site-verification" content="{GSC_TOKEN}">' if GSC_TOKEN else '') + (f'<meta name="msvalidate.01" content="{BING_TOKEN}">' if BING_TOKEN else '')
    return f"""<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title}</title>
<meta name="description" content="{desc}">
<meta name="robots" content="{robots}">
<link rel="canonical" href="{url}">
<link rel="alternate" hreflang="en-IN" href="{url}"><link rel="alternate" hreflang="x-default" href="{url}">
<meta name="theme-color" content="#0a1f44"><meta name="referrer" content="strict-origin-when-cross-origin"><meta name="author" content="{COMPANY}">
{verify}
<meta property="og:type" content="{og_type}"><meta property="og:site_name" content="TripSarthi"><meta property="og:locale" content="en_IN"><meta property="og:title" content="{title}"><meta property="og:description" content="{desc}"><meta property="og:url" content="{url}"><meta property="og:image" content="{SITE}/assets/img/og.png"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><meta property="og:image:alt" content="TripSarthi — CRM, WhatsApp and GST billing for travel agencies">
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="{title}"><meta name="twitter:description" content="{desc}"><meta name="twitter:image" content="{SITE}/assets/img/og.png">
<link rel="icon" type="image/png" sizes="32x32" href="{p}assets/brand/favicon-32.png"><link rel="apple-touch-icon" href="{p}assets/brand/apple-touch-icon.png"><link rel="manifest" href="{p}assets/manifest.webmanifest">
<link rel="preload" href="{p}assets/fonts/inter-400-latin.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="{p}assets/fonts/plusjakartasans-800-latin.woff2" as="font" type="font/woff2" crossorigin>
{css_tag}
{extra}
{ld}"""

def page(fname, title, desc, body, cur='', extra_head='', ld='', p='', crumbs=None, faq=None, article=None, robots=None, og_type=None):
    blocks = [crumbs_ld([('Home', SITE + '/')] + crumbs) if crumbs else None, faq_ld(faq)]
    if article:
        blocks.append({'@context': 'https://schema.org', '@type': 'Article', 'headline': article['h1'], 'description': desc, 'datePublished': article['date'], 'dateModified': article['date'], 'image': SITE + '/assets/img/og.png',
                       'mainEntityOfPage': f'{SITE}/{fname}', 'author': {'@type': 'Organization', 'name': 'TripSarthi', 'url': SITE + '/'}, 'publisher': ORG})
    kw = {'robots': robots} if robots else {}
    return f"""<!doctype html>
<html lang="en-IN">
<head>
{head(fname, title, desc, p, og_type=og_type or ('article' if article else 'website'), ld=ld + jsonld(*blocks), extra=extra_head, **kw)}
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
{SPRITE}
{header(cur, p)}
<main id="main">
{body}
</main>
{footer(p)}
<script src="{p}assets/js/site.js{ver("assets/js/site.js")}" defer></script>
<script src="{p}assets/js/chat.js{ver("assets/js/chat.js")}" defer></script>
</body>
</html>
"""

def hero(kicker, h1, sub, extra=''):
    return f'''<section class="phero"><div class="hero-bg" aria-hidden="true"><i class="blob b1"></i><i class="blob b2"></i></div>
  <div class="wrap"><p class="kicker">{kicker}</p><h1>{h1}</h1><p class="lead">{sub}</p>{extra}</div></section>'''

def cta(title='Ready to simplify your travel business?', sub='Create a free account, or talk to us and we’ll show you around.', p=''):
    return f'''<section class="final"><div class="wrap final-in reveal"><img src="{p}assets/brand/mark-192.png" alt="" width="84" height="84" loading="lazy"><h2>{title}</h2><p>{sub}</p>
  <div class="cta-row center"><a class="btn btn-accent btn-lg" data-app="signup" href="https://app.tripsarthi.com/#/register">Start free {ico('arrow')}</a><a class="btn btn-glass btn-lg" href="{p}contact.html#demo">Book a demo</a></div></div></section>'''

def frame(img, label, alt, w=1800, h=1125, p=''):
    return f'<div class="frame"><div class="frame-bar"><i></i><i></i><i></i><span>{label}</span></div><img loading="lazy" src="{p}assets/img/{img}" width="{w}" height="{h}" alt="{alt}"></div>'

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
    return page('features.html', 'Features — TripSarthi', 'Leads, WhatsApp inbox, automations, quotes, bookings, payments, GST invoicing, group departures and ads for Indian travel agencies.', body, 'features.html', crumbs=[('Features', SITE + '/features.html')])

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
<section class="section" style="padding-top:24px"><div class="wrap"><h2 class="sr">Choose a plan</h2><div class="plans">{plans}</div><p class="fine center">Prices in Indian rupees, per month; annual plans are billed yearly. Taxes extra where applicable. WhatsApp conversation charges are billed by Meta directly to your own account.</p></div></section>
<section class="section soft"><div class="wrap narrow"><div class="head"><h2>Compare plans</h2></div><div class="tablewrap"><table class="cmp"><thead><tr><th></th><th>Starter</th><th class="hl">Growth</th><th>Pro</th></tr></thead><tbody>{tr}</tbody></table></div></div></section>
<section class="section"><div class="wrap narrow"><div class="head"><p class="kicker">FAQ</p><h2>Pricing questions</h2></div><div class="faq">{fq}</div><p class="center fine">Need something different — more users, multiple brands or help migrating? <a href="contact.html">Talk to us</a>.</p></div></section>''' + cta()
    ld = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"SoftwareApplication","name":"TripSarthi","applicationCategory":"BusinessApplication","operatingSystem":"Web","offers":[{"@type":"Offer","name":"Starter","price":"1499","priceCurrency":"INR"},{"@type":"Offer","name":"Growth","price":"3999","priceCurrency":"INR"},{"@type":"Offer","name":"Pro","price":"6999","priceCurrency":"INR"}]}</script>'
    return page('pricing.html', 'Pricing — TripSarthi', 'TripSarthi plans start at ₹1,499 a month. Starter, Growth and Pro for travel agencies of every size. Save 20% with annual billing.', body, 'pricing.html', ld=ld, crumbs=[('Pricing', SITE + '/pricing.html')], faq=faq)

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
    return page('about.html', 'About us — TripSarthi', 'TripSarthi is built by Gamavis Software Solutions in Faridabad, India — a CRM, WhatsApp, quoting, billing and ads platform for travel agencies.', body, 'about.html', crumbs=[('About', SITE + '/about.html')])

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
<label class="chk" style="display:flex;gap:.55rem;align-items:flex-start;font-size:.86rem;line-height:1.4;margin:.2rem 0 .6rem"><input name="wa_opt_in" type="checkbox" style="margin-top:.2rem"><span>WhatsApp me about TripSarthi (optional — needs the phone number above). Reply STOP any time.</span></label>
<label>I’d like to<select name="topic"><option value="demo">Book a demo</option><option value="pricing">Ask about pricing</option><option value="support">Get help or support</option><option value="partner">Discuss a partnership</option><option value="other">Something else</option></select></label>
<label>Message<textarea name="message" rows="5" placeholder="How many agents are on your team? Which tools do you use today?"></textarea></label>
<div class="hp" aria-hidden="true"><label>Leave this field empty<input name="website" tabindex="-1" autocomplete="off"></label></div>
<button class="btn btn-primary btn-lg btn-block" type="submit">Send message {ico('arrow')}</button><p class="fine" id="cf-note" role="status">We’ll reply by email or phone. Prefer to talk? Call {PHONES[0][0]}.</p></form></div>
<div class="map"><iframe title="Map showing the TripSarthi office at Puri Business Hub, Sector 81, Faridabad" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="https://www.google.com/maps?q={MAPS_Q}&amp;output=embed"></iframe></div>
</div></section>'''
    ld = f'<script type="application/ld+json">{{"@context":"https://schema.org","@type":"Organization","name":"{COMPANY}","url":"{SITE}/","email":"{EMAIL}","telephone":"{PHONES[0][1]}","address":{{"@type":"PostalAddress","streetAddress":"BH-820, 8th Floor, Puri Business Hub, Sector 81","addressLocality":"Faridabad","addressRegion":"Haryana","postalCode":"121004","addressCountry":"IN"}},"contactPoint":[{",".join(f"""{{"@type":"ContactPoint","telephone":"{t}","contactType":"customer support","areaServed":"IN","availableLanguage":["en","hi"]}}""" for _, t in PHONES)}]}}</script>'
    return page('contact.html', 'Contact us — TripSarthi', f'Call {PHONES[0][0]} or email {EMAIL}. Book a TripSarthi demo. Office: Puri Business Hub, Sector 81, Faridabad, Haryana 121004.', body, 'contact.html', ld=ld, crumbs=[('Contact', SITE + '/contact.html')])

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
# ---------------------------------------------------------------------------------------------------------------------------------------------
def faq_html(items, title='Frequently asked questions'):
    d = ''.join(f'<details><summary>{q}</summary><p>{a}</p></details>' for q, a in items)
    return f'<section class="section soft"><div class="wrap narrow"><div class="head"><p class="kicker">FAQ</p><h2>{title}</h2></div><div class="faq">{d}</div></div></section>'

def plain(h):
    return h.replace('<span class="grad">', '').replace('</span>', '')

def solution_page(x):
    secs = ''
    for i, (h, html) in enumerate(x['sections']):
        shot = ''
        if i == 1:
            shot = '<div class="wrap prose-shot">' + frame(x['img'], x['nav'], x['img_alt'], 1600, 1068) + '</div>'
        secs += f'<section class="section{" soft" if i % 2 else ""}"><div class="wrap narrow2 prose"><h2>{h}</h2>{html}</div>{shot}</section>'
    rel = ''.join(f'<a class="card rel" href="{r["slug"]}.html"><b>{r["nav"]}</b><span>{plain(r["lead"])[:110]}…</span></a>' for r in SOLUTIONS if r['slug'] in x['related'])
    btns = '<div class="cta-row center"><a class="btn btn-primary btn-lg" data-app="signup" href="https://app.tripsarthi.com/#/register">Start free ' + ico('arrow') + '</a><a class="btn btn-outline btn-lg" href="contact.html#demo">Book a demo</a></div>'
    body = hero('Solutions', x['h1'], x['lead'], btns) + secs + faq_html(x['faq']) + f'<section class="section"><div class="wrap"><div class="head"><h2>Related solutions</h2></div><div class="relgrid">{rel}</div></div></section>' + cta()
    return page(f'{x["slug"]}.html', x['title'], x['desc'], body, 'solutions.html', crumbs=[('Solutions', SITE + '/solutions.html'), (x['nav'], f'{SITE}/{x["slug"]}.html')], faq=x['faq'])

def solutions_hub():
    cards = ''.join(f'<a class="card rel big" href="{x["slug"]}.html"><span class="tag blue">{x["nav"]}</span><b>{plain(x["h1"])}</b><span>{x["lead"]}</span><em>Learn more →</em></a>' for x in SOLUTIONS)
    body = hero('Solutions', 'Software for every kind of <span class="grad">travel business</span>', 'Whether you are a solo agent, a growing agency, a tour operator or a DMC, TripSarthi covers the workflow end to end.') + f'<section class="section"><div class="wrap"><div class="relgrid two reveal">{cards}</div></div></section>' + cta()
    return page('solutions.html', 'Solutions for Travel Agents, Tour Operators & DMCs — TripSarthi', 'Travel agency software, WhatsApp CRM, GST billing and tour operator tools — see how TripSarthi fits your travel business.', body, 'solutions.html', crumbs=[('Solutions', SITE + '/solutions.html')])

def pretty_date(iso):
    d = datetime.date.fromisoformat(iso)
    return f'{d.day} {d.strftime("%B %Y")}'

def article_page(a):
    intro = a['intro']
    top = '<section class="phero"><div class="hero-bg" aria-hidden="true"><i class="blob b1"></i><i class="blob b2"></i></div><div class="wrap"><p class="kicker"><a href="index.html" style="color:inherit">Blog</a></p>' + \
          f'<h1>{a["h1"]}</h1><p class="lead">{intro}</p><p class="byline">By the TripSarthi team · {pretty_date(a["date"])} · {a["read"]} min read</p></div></section>'
    body = top + f'<article class="section"><div class="wrap narrow2 prose">{a["body"]}</div></article>' + faq_html(a['faq'], 'Quick answers') + cta(p='../')
    return page(f'blog/{a["slug"]}.html', a['title'], a['desc'], body, 'blog/index.html', p='../', crumbs=[('Blog', SITE + '/blog/index.html'), (a['h1'], f'{SITE}/blog/{a["slug"]}.html')], faq=a['faq'], article=a)

def blog_index():
    cards = ''.join(f'<a class="card rel big" href="{a["slug"]}.html"><span class="tag teal">{a["read"]} min read</span><b>{a["h1"]}</b><span>{a["desc"]}</span><em>Read the guide →</em></a>' for a in ARTICLES)
    body = hero('Blog', 'Guides for <span class="grad">travel agents</span>', 'Practical advice on WhatsApp, GST and TCS, quotes and running a modern travel business in India.') + f'<section class="section"><div class="wrap"><div class="relgrid reveal">{cards}</div></div></section>' + cta(p='../')
    return page('blog/index.html', 'TripSarthi Blog — Guides for Indian Travel Agents', 'Guides for Indian travel agents: WhatsApp rules, GST and TCS month-end routines, quotes that convert and more.', body, 'blog/index.html', p='../', crumbs=[('Blog', SITE + '/blog/index.html')])

# ---------------------------------------------------------------------------------------------------------------------------------------------
INDEX_TITLE = 'TripSarthi — Travel Agency Software for India | CRM & GST'
INDEX_DESC = 'Travel agency software for India: capture enquiries, chat on WhatsApp, send quotes, collect instalments and issue GST invoices in one place.'

def patch_index():
    global SPRITE
    p = os.path.join(ROOT, 'index.html'); s = open(p, encoding='utf-8').read()
    SPRITE = re.search(r'<!-- icon sprite -->\s*(<svg.*?</svg>)', s, re.S).group(1)
    faq = [(re.sub(r'<[^>]+>', '', q), re.sub(r'<[^>]+>', '', a)) for q, a in re.findall(r'<details><summary>(.*?)</summary><p>(.*?)</p></details>', s, re.S)]
    graph = {'@context': 'https://schema.org', '@graph': [ORG, {'@type': 'WebSite', '@id': SITE + '/#site', 'url': SITE + '/', 'name': 'TripSarthi', 'inLanguage': 'en-IN', 'publisher': {'@id': SITE + '/#org'}},
             {'@type': 'SoftwareApplication', 'name': 'TripSarthi', 'applicationCategory': 'BusinessApplication', 'operatingSystem': 'Web', 'description': INDEX_DESC, 'url': SITE + '/', 'publisher': {'@id': SITE + '/#org'},
              'offers': [{'@type': 'Offer', 'name': n, 'price': pr, 'priceCurrency': 'INR'} for n, pr in (('Starter', '1499'), ('Growth', '3999'), ('Pro', '6999'))]}]}
    newhead = head('index.html', INDEX_TITLE, INDEX_DESC, '', ld=jsonld(graph, faq_ld(faq)), inline_css=True, extra='<link rel="preload" as="image" href="assets/img/deals-900.webp" imagesrcset="assets/img/deals-900.webp 900w, assets/img/deals.webp 1800w" imagesizes="(max-width:700px) 92vw, 1100px" type="image/webp">')
    s = re.sub(r'<head>.*?</head>', lambda m: '<head>\n' + newhead + '\n</head>', s, count=1, flags=re.S)
    s = s.replace('<html lang="en">', '<html lang="en-IN">', 1)
    s = re.sub(r'<header class="nav".*?</header>', lambda m: header(), s, flags=re.S)
    s = re.sub(r'<footer class="foot">.*?</footer>', lambda m: footer(), s, flags=re.S)
    s = re.sub(r'<a class="btn btn-glass btn-lg" id="demo"[^>]*>Book a demo</a>', '<a class="btn btn-glass btn-lg" id="demo" href="contact.html#demo">Book a demo</a>', s)
    s = re.sub(r'<script src="assets/js/(site|chat)\.js[^"]*" defer></script>\s*', '', s)
    s = s.replace('</body>', '<script src="assets/js/site.js' + ver('assets/js/site.js') + '" defer></script>\n<script src="assets/js/chat.js' + ver('assets/js/chat.js') + '" defer></script>\n</body>', 1)
    if 'id="solutions-links"' not in s:
        cards = ''.join(f'<a class="card rel" href="{x["slug"]}.html"><b>{x["nav"]}</b><span>{plain(x["lead"])[:120]}…</span></a>' for x in SOLUTIONS)
        block = f'<section class="section soft" id="solutions-links"><div class="wrap"><div class="head"><p class="kicker">Solutions</p><h2>Built for your kind of travel business</h2></div><div class="relgrid four reveal">{cards}</div></div></section>\n'
        s = s.replace('<!-- ============================ FINAL CTA', block + '<!-- ============================ FINAL CTA', 1)
    open(p, 'w', encoding='utf-8').write(add_srcset(s))

def minify_css():
    import re as _re
    css = ''.join(open(os.path.join(ROOT, 'assets/css', f), encoding='utf-8').read() + '\n' for f in ('fonts.css', 'site.css', 'chat.css'))
    css = _re.sub(r'/\*.*?\*/', '', css, flags=_re.S)
    css = _re.sub(r'\s+', ' ', css)
    css = _re.sub(r'\s*([{};,>])\s*', r'\1', css)
    css = css.replace(';}', '}')
    open(os.path.join(ROOT, 'assets/css/all.min.css'), 'w', encoding='utf-8').write(css.strip())
    print('wrote assets/css/all.min.css', len(css) // 1024, 'KB')

def ensure_variants():
    """900px-wide copies of the screenshots (and a 390px phone) for srcset, made with cwebp/dwebp when installed."""
    import subprocess, shutil, glob
    if not (shutil.which('cwebp') and shutil.which('dwebp')): print('cwebp/dwebp not found: skipping image variants'); return
    for f in glob.glob(os.path.join(ROOT, 'assets/img/*.webp')):
        name = os.path.basename(f)[:-5]
        if name == 'og' or re.search(r'-(900|390)$', name): continue
        w = 390 if name == 'portal' else 900
        out = os.path.join(ROOT, f'assets/img/{name}-{w}.webp')
        if os.path.exists(out) and os.path.getmtime(out) >= os.path.getmtime(f): continue
        tmp = '/tmp/_v.png'
        subprocess.run(['dwebp', f, '-o', tmp], check=True, capture_output=True)
        subprocess.run(['cwebp', '-q', '78', '-resize', str(w), '0', tmp, '-o', out], check=True, capture_output=True)
        print('variant', os.path.basename(out))

def add_srcset(html):
    def sub(m):
        tag = m.group(0)
        if 'srcset=' in tag: return tag
        mm = re.search(r'src="((?:\.\./)?assets/img/([a-z0-9-]+)\.webp)"', tag)
        if not mm: return tag
        base, name = mm.group(1), mm.group(2)
        small = 390 if name == 'portal' else 900
        if not os.path.exists(os.path.join(ROOT, f'assets/img/{name}-{small}.webp')): return tag
        full = re.search(r'width="(\d+)"', tag); fw = full.group(1) if full else ('780' if name == 'portal' else '1800')
        pref = base[:base.index('assets/')]
        sizes = '(max-width:700px) 40vw' if name == 'portal' else '(max-width:700px) 92vw, 1100px'
        return tag.replace(f'src="{base}"', f'src="{base}" srcset="{pref}assets/img/{name}-{small}.webp {small}w, {base} {fw}w" sizes="{sizes}"', 1)
    return re.sub(r'<img [^>]*assets/img/[^>]*>', sub, html)

def write(fname, html):
    html = add_srcset(html)
    path = os.path.join(ROOT, fname); os.makedirs(os.path.dirname(path), exist_ok=True)
    open(path, 'w', encoding='utf-8').write(html); print('wrote', fname)

if __name__ == '__main__':
    ensure_variants()
    minify_css()
    patch_index()
    pages = [('features.html', features_page), ('pricing.html', pricing_page), ('about.html', about_page), ('contact.html', contact_page), ('privacy.html', privacy_page), ('terms.html', terms_page), ('solutions.html', solutions_hub), ('blog/index.html', blog_index)]
    pages += [(f'{x["slug"]}.html', (lambda x=x: solution_page(x))) for x in SOLUTIONS] + [(f'blog/{a["slug"]}.html', (lambda a=a: article_page(a))) for a in ARTICLES]
    for f, fn in pages:
        write(f, fn())
    urls = [('', '1.0', 'weekly'), ('features.html', '0.9', 'monthly'), ('solutions.html', '0.8', 'monthly'), ('pricing.html', '0.9', 'monthly')] + [(f'{x["slug"]}.html', '0.8', 'monthly') for x in SOLUTIONS] + \
           [('blog/index.html', '0.7', 'weekly')] + [(f'blog/{a["slug"]}.html', '0.7', 'monthly') for a in ARTICLES] + [('about.html', '0.6', 'yearly'), ('contact.html', '0.7', 'yearly'), ('privacy.html', '0.3', 'yearly'), ('terms.html', '0.3', 'yearly')]
    sm = '<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n' + ''.join(f'  <url><loc>{SITE}/{u}</loc><lastmod>{TODAY}</lastmod><changefreq>{c}</changefreq><priority>{pr}</priority></url>\n' for u, pr, c in urls) + '</urlset>\n'
    open(os.path.join(ROOT, 'sitemap.xml'), 'w').write(sm); print('wrote sitemap.xml')
    keys = [('Features', 'features.html', 'Everything the product does'), ('Pricing', 'pricing.html', 'Starter ₹1,499, Growth ₹3,999, Pro ₹6,999 per month'), ('Travel agency software', 'travel-agency-software.html', 'Overview for Indian travel agencies'),
            ('WhatsApp CRM for travel agents', 'whatsapp-crm-for-travel-agents.html', 'Shared inbox and automations within WhatsApp rules'), ('GST billing for travel agents', 'gst-billing-for-travel-agents.html', 'GST invoices, TCS, GSTR exports'),
            ('Tour operator software', 'tour-operator-software.html', 'Group departures, supplier rates, payables'), ('Blog', 'blog/index.html', 'Guides for travel agents'), ('Contact', 'contact.html', f'{EMAIL}, {PHONES[0][0]}')]
    llms = f'# TripSarthi\n> TripSarthi is travel agency software for India: CRM, WhatsApp shared inbox and automation, itinerary quotes, instalment payments, GST invoicing and TCS tracking, group departures, supplier rate cards and Meta/Google ad management. A product of {COMPANY}, Faridabad, Haryana.\n\n## Key pages\n' + ''.join(f'- [{n}]({SITE}/{u}): {d}\n' for n, u, d in keys)
    open(os.path.join(ROOT, 'llms.txt'), 'w').write(llms); print('wrote llms.txt')
