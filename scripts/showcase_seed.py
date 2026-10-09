#!/usr/bin/env python3
"""DEV ONLY — builds a realistic, entirely fictional agency ("Blue Horizon Holidays") in the LOCAL database so the website screenshots show a lived-in product.
Talks to the local API (php spark serve --port 8731, mock modes on) and finishes with a little SQL (back-dating, WhatsApp threads). Re-runnable: it stops if the
showcase user already has trips. Remove everything with:  python3 scripts/showcase_seed.py --reset
Every name, phone number and GSTIN below is invented."""
import json, sys, random, subprocess, os, datetime as dt, urllib.request, urllib.error

B = 'http://localhost:8731/api/v1'
EMAIL, PW = 'showcase@tripsarthi.test', 'Showcase@12345'
random.seed(7)
TOKEN = None

def api(method, path, body=None, ok=(200, 201), token=True):
    req = urllib.request.Request(B + path, method=method, data=json.dumps(body).encode() if body is not None else None,
                                 headers={'Content-Type': 'application/json', **({'Authorization': 'Bearer ' + TOKEN} if token and TOKEN else {})})
    import time
    time.sleep(0.12)
    for attempt in range(8):
        try:
            r = urllib.request.urlopen(req); raw = r.read().decode(); break
        except urllib.error.HTTPError as e:
            raw = e.read().decode()
            if e.code == 429 and attempt < 7: time.sleep(1.5 + attempt); continue
            raise SystemExit(f'{method} {path} -> {e.code}: {raw[:300]}')
    d = json.loads(raw) if raw else {}
    return d.get('data', d) if isinstance(d, dict) else d

def public_post(path):
    import time
    for attempt in range(10):
        try:
            return urllib.request.urlopen(urllib.request.Request(B + path, method='POST', data=b'{}', headers={'Content-Type': 'application/json'})).read()
        except urllib.error.HTTPError as e:
            if e.code == 429 and attempt < 9: time.sleep(2 + attempt); continue
            raise SystemExit(f'POST {path} -> {e.code}')

def sql(q):
    env = {}
    for line in open(os.path.join(os.path.dirname(__file__), '..', 'backend', '.env')):
        if line.startswith('database.default.'):
            k, _, v = line.partition('='); env[k.strip().split('.')[-1]] = v.strip()
    return subprocess.run(['mysql', '-u' + env['username'], '-h' + env['hostname'], env['database'], '-N', '-e', q], env={**os.environ, 'MYSQL_PWD': env['password']}, capture_output=True, text=True, check=True).stdout

def gstin(state, pan, entity='1'):
    chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ'; body = f'{state}{pan}{entity}Z'; s = 0
    for i, c in enumerate(body):
        v = chars.index(c) * (1 if i % 2 == 0 else 2); s += v // 36 + v % 36
    return body + chars[(36 - s % 36) % 36]

if '--reset' in sys.argv:
    tid = sql(f"select tenant_id from users where email='{EMAIL}'").strip()
    if tid:
        tabs = sql("select table_name from information_schema.columns where table_schema=database() and column_name='tenant_id'").split()
        sql('SET FOREIGN_KEY_CHECKS=0; ' + ' '.join(f'delete from `{t}` where tenant_id={int(tid)};' for t in tabs) + f' delete from tenants where id={int(tid)}; SET FOREIGN_KEY_CHECKS=1;'); print('removed tenant', tid, 'from', len(tabs), 'tables')
    raise SystemExit

# ---------------------------------------------------------------------------------------------------------------------------------------
def login_or_register():
    global TOKEN
    try:
        d = api('POST', '/auth/login', {'email': EMAIL, 'password': PW}, token=False); TOKEN = d.get('token') or d['data']['token']
    except SystemExit:
        d = api('POST', '/auth/register', {'name': 'Aarav Deshmukh', 'company': 'Blue Horizon Holidays', 'email': EMAIL, 'password': PW}, token=False); TOKEN = d['token']

def day(n): return (dt.date.today() + dt.timedelta(days=n)).isoformat()
def rs(x): return int(round(x * 100))

DEST = [('Bali', 'Indonesia', 0), ('Maldives', 'Maldives', 0), ('Dubai', 'UAE', 0), ('Thailand', 'Thailand', 0), ('Singapore', 'Singapore', 0),
        ('Kerala', 'India', 1), ('Goa', 'India', 1), ('Kashmir', 'India', 1), ('Rajasthan', 'India', 1), ('Andaman', 'India', 1)]
SUPPLIERS = [  # name, type, city, rates [(service, type, unit, rupees)]
    ('Ubud Jungle Villas', 'hotel', 'Ubud', 'Bali', [('Pool villa, breakfast', 'hotel', 'per_night', 9500), ('Deluxe room, breakfast', 'hotel', 'per_night', 6200)]),
    ('Azure Atoll Resort', 'hotel', 'Male', 'Maldives', [('Water villa, half board', 'hotel', 'per_night', 31000), ('Beach villa, half board', 'hotel', 'per_night', 22500)]),
    ('Skyline Dubai Hotels', 'hotel', 'Dubai', 'Dubai', [('4-star twin room, breakfast', 'hotel', 'per_night', 7800), ('5-star marina view', 'hotel', 'per_night', 14500)]),
    ('Desert Gold Tours', 'dmc', 'Dubai', 'Dubai', [('Desert safari with BBQ dinner', 'activity', 'per_pax', 2100), ('Dubai city tour', 'sightseeing', 'per_pax', 1800), ('Airport transfers', 'transfer', 'per_vehicle', 3200)]),
    ('Backwater Stays Kerala', 'hotel', 'Alleppey', 'Kerala', [('Premium houseboat, all meals', 'hotel', 'per_night', 14000), ('Munnar hill resort', 'hotel', 'per_night', 6800)]),
    ('Palm Grove Resorts', 'hotel', 'Calangute', 'Goa', [('Deluxe pool-view room', 'hotel', 'per_night', 5400), ('Premium suite', 'hotel', 'per_night', 8900)]),
    ('Himalayan Houseboats', 'hotel', 'Srinagar', 'Kashmir', [('Deluxe houseboat, MAP', 'hotel', 'per_night', 6200), ('Gulmarg resort', 'hotel', 'per_night', 7900)]),
    ('Island Ride Transfers', 'transport', 'Bali', 'Bali', [('Private car with driver (full day)', 'transfer', 'per_day', 3200), ('Airport transfer', 'transfer', 'per_vehicle', 1400)]),
    ('Sunrise Sightseeing', 'activity', 'Phuket', 'Thailand', [('Phi Phi islands by speedboat', 'activity', 'per_pax', 3400), ('Phuket old town and temples', 'sightseeing', 'per_pax', 1500), ('Beachfront resort', 'hotel', 'per_night', 6900)]),
]
CUSTOMERS = ['Rohan Kulkarni', 'Neha Iyer', 'Vikram Malhotra', 'Ananya Reddy', 'Sandeep Joshi', 'Pooja Nair', 'Arjun Mehta', 'Kavya Menon', 'Imran Sheikh', 'Divya Bansal', 'Karthik Subramanian', 'Meera Kapoor',
             'Harsh Vora', 'Sneha Patil', 'Aditya Rao', 'Ritu Agarwal', 'Faisal Khan', 'Tanvi Shah', 'Gaurav Chopra', 'Isha Verma', 'Nikhil Pandey', 'Shruti Bhatt', 'Manish Gupta', 'Lakshmi Pillai', 'Yash Thakur', 'Simran Kaur']
# (customer idx, destination, trip_type, intl, start in days, nights, adults, children, budget ₹, stage) stage: enquiry|quoted|negotiating|booked|paid|completed
PLAN = [
    (0, 'Bali', 'honeymoon', 1, 62, 6, 2, 0, 190000, 'booked'), (1, 'Maldives', 'honeymoon', 1, 95, 5, 2, 0, 420000, 'quoted'), (2, 'Dubai', 'family', 1, 48, 5, 2, 2, 380000, 'negotiating'),
    (3, 'Kerala', 'family', 0, 33, 6, 2, 1, 160000, 'booked'), (4, 'Goa', 'friends', 0, 21, 4, 4, 0, 90000, 'quoted'), (5, 'Thailand', 'friends', 1, 70, 6, 4, 0, 260000, 'enquiry'),
    (6, 'Kashmir', 'family', 0, 55, 7, 3, 1, 210000, 'quoted'), (7, 'Maldives', 'honeymoon', 1, 120, 4, 2, 0, 310000, 'enquiry'), (8, 'Dubai', 'leisure', 1, 28, 4, 2, 0, 150000, 'booked'),
    (9, 'Bali', 'friends', 1, 80, 6, 5, 0, 330000, 'negotiating'), (10, 'Kerala', 'honeymoon', 0, 40, 5, 2, 0, 120000, 'paid'), (11, 'Goa', 'leisure', 0, 14, 3, 2, 0, 60000, 'enquiry'),
    (12, 'Thailand', 'family', 1, 90, 6, 2, 2, 280000, 'quoted'), (13, 'Kashmir', 'honeymoon', 0, 75, 6, 2, 0, 135000, 'enquiry'), (14, 'Bali', 'honeymoon', 1, 105, 7, 2, 0, 230000, 'enquiry'),
    (15, 'Dubai', 'family', 1, -45, 5, 2, 2, 360000, 'completed'), (16, 'Kerala', 'family', 0, -70, 6, 4, 0, 200000, 'completed'), (17, 'Goa', 'friends', 0, -20, 4, 6, 0, 150000, 'completed'),
    (18, 'Bali', 'honeymoon', 1, -100, 6, 2, 0, 210000, 'completed'), (19, 'Maldives', 'honeymoon', 1, -135, 5, 2, 0, 400000, 'completed'), (20, 'Thailand', 'friends', 1, -160, 5, 4, 0, 240000, 'completed'),
    (21, 'Kashmir', 'family', 0, 66, 6, 2, 2, 190000, 'enquiry'), (22, 'Dubai', 'leisure', 1, 100, 5, 2, 0, 170000, 'quoted'), (23, 'Kerala', 'friends', 0, 52, 5, 4, 0, 130000, 'enquiry'),
    (24, 'Goa', 'family', 0, 35, 4, 2, 2, 85000, 'negotiating'), (25, 'Bali', 'family', 1, 88, 7, 2, 2, 350000, 'enquiry'),
]
TITLES = {'honeymoon': '{d} honeymoon', 'family': '{d} family holiday', 'friends': '{d} friends trip', 'leisure': '{d} getaway'}
# per destination: (day title, [(type, title, details, rupees, per, key)]) — key picks the rate card; 'night'/'pax'/'car'/'day' decide how qty is used
ITIN = {
 'Bali': [('Arrive in Bali · Ubud', [('transfer', 'Airport pickup to Ubud', 'Private car, meet and greet', 1400, 'car'), ('hotel', 'Ubud Jungle Villas — pool villa', 'Breakfast daily, private pool', 9500, 'night')]),
          ('Ubud: rice terraces & temples', [('sightseeing', 'Tegalalang rice terraces and Tirta Empul', 'Private car, English-speaking driver', 3200, 'day')]),
          ('Nusa Penida day trip', [('activity', 'Nusa Penida by fast boat', 'Kelingking, Angel’s Billabong, lunch', 4200, 'pax')]),
          ('Sunset at Uluwatu', [('activity', 'Kecak fire dance at Uluwatu', 'Entry tickets included', 1500, 'pax'), ('meal', 'Candlelight beach dinner, Jimbaran', 'Seafood set menu', 3800, 'pax')]),
          ('Departure', [('transfer', 'Hotel to airport', 'Private car', 1400, 'car')])],
 'Maldives': [('Arrive in Malé · speedboat to resort', [('transfer', 'Speedboat transfer', 'Return, shared', 9000, 'pax'), ('hotel', 'Azure Atoll — water villa', 'Half board, private deck', 31000, 'night')]),
              ('Snorkel with reef sharks', [('activity', 'Guided snorkelling safari', 'Gear included', 6500, 'pax')]), ('Sunset cruise', [('activity', 'Dolphin cruise at sunset', '2 hours', 5200, 'pax')]), ('Departure', [])],
 'Dubai': [('Arrive in Dubai', [('transfer', 'Airport transfer', 'Private SUV', 3200, 'car'), ('hotel', 'Skyline Dubai — 4★ twin room', 'Breakfast daily', 7800, 'night')]),
           ('Dubai city tour', [('sightseeing', 'Half-day city tour', 'Burj Khalifa (124th floor) entry', 1800, 'pax')]), ('Desert safari', [('activity', 'Desert safari with BBQ dinner', 'Dune bashing, camel ride, show', 2100, 'pax')]),
           ('Abu Dhabi day trip', [('sightseeing', 'Sheikh Zayed Mosque and Ferrari World', 'Full day, lunch', 4600, 'pax')]), ('Departure', [('transfer', 'Hotel to airport', 'Private SUV', 3200, 'car')])],
 'Kerala': [('Arrive in Cochin · drive to Munnar', [('transfer', 'Cochin airport to Munnar', 'Private AC Innova', 5200, 'car'), ('hotel', 'Munnar hill resort', 'Breakfast and dinner', 6800, 'night')]),
            ('Munnar tea gardens', [('sightseeing', 'Tea museum, Mattupetty, Echo Point', 'Private car', 2800, 'day')]), ('Thekkady spice trail', [('activity', 'Periyar boat ride and spice plantation', 'Entry included', 1600, 'pax')]),
            ('Alleppey houseboat', [('hotel', 'Premium houseboat', 'All meals, AC bedroom', 14000, 'night')]), ('Departure', [('transfer', 'Alleppey to Cochin airport', 'Private car', 3000, 'car')])],
 'Goa': [('Arrive in Goa', [('hotel', 'Palm Grove — deluxe pool-view room', 'Breakfast daily', 5400, 'night')]), ('North Goa beaches', [('sightseeing', 'Baga, Calangute, Fort Aguada', 'Private cab', 2400, 'day')]),
         ('Water sports & sunset cruise', [('activity', 'Water sports combo', 'Parasailing, banana ride, jet ski', 2200, 'pax')]), ('Departure', [])],
 'Kashmir': [('Arrive in Srinagar · Dal Lake', [('hotel', 'Deluxe houseboat on Dal Lake', 'MAP, shikara ride', 6200, 'night')]), ('Srinagar gardens', [('sightseeing', 'Mughal gardens and shikara ride', 'Private car', 2600, 'day')]),
             ('Gulmarg gondola', [('activity', 'Gulmarg Gondola phase 1 and 2', 'Tickets included', 2100, 'pax')]), ('Pahalgam', [('sightseeing', 'Betaab Valley and Aru Valley', 'Private car', 3100, 'day')]), ('Departure', [])],
 'Thailand': [('Arrive in Phuket', [('transfer', 'Airport transfer', 'Private van', 1800, 'car'), ('hotel', 'Beachfront resort, Patong', 'Breakfast daily', 6900, 'night')]), ('Phi Phi islands', [('activity', 'Phi Phi by speedboat', 'Lunch and snorkelling', 3400, 'pax')]),
              ('Old town & temples', [('sightseeing', 'Phuket old town and Big Buddha', 'Private van', 1500, 'pax')]), ('Departure', [])],
}

def seed_core():
    login_or_register()
    if api('GET', '/trips'):
        raise SystemExit('Showcase tenant already has trips — run with --reset first.')
    # business profile (fictional, checksum-valid GSTIN so invoices can be issued)
    g = gstin('27', 'AABCB4821K')
    api('PUT', '/billing-docs/profile', {'legal_name': 'Blue Horizon Holidays Pvt Ltd', 'trade_name': 'Blue Horizon Holidays', 'gstin': g, 'pan': 'AABCB4821K', 'state_code': '27', 'address_line1': 'Office 14, Baner Road', 'city': 'Pune', 'pincode': '411045', 'website': 'https://bluehorizon.example', 'bank_account_name': 'Blue Horizon Holidays Pvt Ltd',
        'email': 'hello@bluehorizon.example', 'phone': '+91 20 4000 0000', 'invoice_prefix': 'BHH', 'sac_code': '998554', 'bank_name': 'Example Bank', 'bank_account_no': '000000000000', 'bank_ifsc': 'EXMP0000001', 'upi_id': 'bluehorizon@example'})
    dest = {n: api('POST', '/travel/destinations', {'name': n, 'country': c, 'is_domestic': dom})['id'] for n, c, dom in DEST}
    rates = {}
    for name, typ, city, d, rr in SUPPLIERS:
        sid = api('POST', '/travel/suppliers', {'name': name, 'type': typ, 'city': city, 'phone': '+91 98765 0%04d' % random.randint(1, 9999), 'gstin': ''})['id']
        for svc, st, unit, price in rr:
            rates[(d, svc)] = api('POST', '/travel/rates', {'supplier_id': sid, 'destination_id': dest[d], 'service_name': svc, 'service_type': st, 'unit': unit, 'cost_amount': rs(price)})['id']
    out = {'dest': dest, 'trips': []}
    for i, (ci, d, ttype, intl, start, nights, ad, ch, budget, stage) in enumerate(PLAN):
        name = CUSTOMERS[ci]
        c = api('POST', '/contacts', {'name': name, 'wa_number': '9198765%05d' % (400 + ci * 7), 'email': name.lower().replace(' ', '.') + '@example.com', 'source': random.choice(['web_form', 'meta_lead_ads', 'whatsapp_inbound', 'google_lead_forms']), 'city': random.choice(['Pune', 'Mumbai', 'Bengaluru', 'Hyderabad', 'Delhi', 'Chennai', 'Ahmedabad'])})
        cid = int(c['id'])
        t = api('POST', '/trips', {'title': TITLES[ttype].format(d=d) + ' – ' + name.split()[0], 'contact_id': cid, 'trip_type': ttype, 'destination_id': dest[d], 'destination_text': d, 'is_international': intl,
                                   'start_date': day(start), 'end_date': day(start + nights), 'nights': nights, 'adults': ad, 'children': ch, 'budget_max': rs(budget), 'budget_basis': 'total', 'hotel_category': random.choice(['3_star', '4_star', '5_star'])})
        tid = int(t['id']); rec = {'trip': tid, 'contact': cid, 'stage': stage, 'dest': d, 'name': name}
        if stage != 'enquiry':
            it = api('POST', '/itineraries', {'trip_id': tid, 'markup_type': 'percent', 'markup_value': random.choice([14, 15, 16, 18]), 'gst_rate': 5 if not intl else 5, 'inclusions': 'Accommodation as listed\nDaily breakfast\nAirport transfers\nSightseeing as per itinerary', 'exclusions': 'Airfare\nPersonal expenses\nTravel insurance'})
            iid = int(it['id']); rec['itin'] = iid; pax = ad + ch; rooms = max(1, -(-ad // 2))
            for n, (dtitle, items) in enumerate(ITIN[d][:max(3, min(len(ITIN[d]), nights - 1))]):
                dd = api('POST', f'/itineraries/{iid}/days', {'title': dtitle, 'city': d, 'description': 'Relax and explore at your own pace with our local team on call.'})
                for typ, title, details, price, per in items:
                    q = pax if per == 'pax' else (rooms if per == 'night' else 1)
                    api('POST', f'/itineraries/{iid}/items', {'day_id': dd['id'], 'type': typ, 'title': title, 'details': details, 'unit_cost': rs(price), 'quantity': q, 'nights': nights - 1 if per == 'night' and typ == 'hotel' else 1})
            rec['share'] = api('POST', f'/itineraries/{iid}/share')['share_token']
            if stage in ('negotiating', 'booked', 'paid', 'completed'):
                api('GET', '/trips/%d' % tid)
            if stage in ('booked', 'paid', 'completed'):
                public_post(f"/public/quotes/{rec['share']}/accept")
                bk = api('POST', '/bookings', {'itinerary_id': iid}); rec['booking'] = int(bk['id']); rec['payments'] = [p['id'] for p in bk.get('payments', [])]
                paid = {'booked': 1, 'paid': 99, 'completed': 99}[stage]
                for pid in rec['payments'][:paid]: api('POST', f'/bookings/payments/{pid}/paid', {'mode': random.choice(['upi', 'bank_transfer', 'card'])})
            elif stage == 'negotiating':
                api('POST', f'/trips/{tid}/status', {'status': 'negotiating'})
        out['trips'].append(rec); print(i, stage, d, rec.get('booking'))
    return out


# ======================================== part 2: everything around the core data ========================================
def seed_extras():
    login_or_register()
    core = json.load(open('/tmp/showcase_core.json')); T = int(sql(f"select tenant_id from users where email='{EMAIL}'").strip())
    now = dt.datetime.now(); fmt = lambda d: d.strftime('%Y-%m-%d %H:%M:%S')
    q = lambda v: "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"
    recs = core['trips']

    # -- completed trips finish properly; then back-date so the report has six months of history ------------------------------------------------
    for r in recs:
        if r['stage'] == 'completed': api('POST', f"/trips/{r['trip']}/status", {'status': 'completed'})
    stmts = []
    for i, r in enumerate(recs):
        if r['stage'] == 'completed':
            start = dt.date.today() + dt.timedelta(days=PLAN[i][4]); created = dt.datetime.combine(start, dt.time(11, 0)) - dt.timedelta(days=random.randint(35, 70))
        elif r['stage'] == 'enquiry':
            created = now - dt.timedelta(days=random.randint(0, 9), hours=random.randint(0, 20))
        else:
            created = now - dt.timedelta(days=random.randint(6, 40), hours=random.randint(0, 20))
        stmts.append(f"update trips set created_at={q(fmt(created))}, updated_at={q(fmt(created + dt.timedelta(days=2)))} where id={r['trip']};")
        stmts.append(f"update contacts set created_at={q(fmt(created - dt.timedelta(hours=3)))} where id={r['contact']};")
        stmts.append(f"update deals set created_at={q(fmt(created))} where id=(select deal_id from trips where id={r['trip']});")
        if r.get('itin'): stmts.append(f"update itineraries set created_at={q(fmt(created + dt.timedelta(days=1)))} where id={r['itin']};")
        if r.get('booking'):
            bc = created + dt.timedelta(days=random.randint(3, 9))
            stmts.append(f"update bookings set created_at={q(fmt(bc))} where id={r['booking']};")
            stmts.append(f"update booking_payments set paid_at={q(fmt(bc + dt.timedelta(days=1)))} where booking_id={r['booking']} and status='paid';")
    sql(' '.join(stmts))
    # one overdue and a couple due-soon instalments so the dunning/receivables cards have something to say
    live = [r for r in recs if r['stage'] == 'booked' and r.get('booking')]
    if live:
        sql(f"update booking_payments set due_date=date_sub(curdate(), interval 4 day) where booking_id={live[0]['booking']} and status='pending' order by id limit 1;")
        if len(live) > 1: sql(f"update booking_payments set due_date=date_add(curdate(), interval 3 day) where booking_id={live[1]['booking']} and status='pending' order by id limit 1;")

    # -- fx, group departures ------------------------------------------------------------------------------------------------------------------------
    for c, rate in (('USD', 83.6), ('AED', 22.8)):
        try: api('POST', '/fx', {'currency': c, 'rate': rate})
        except SystemExit: pass
    dest = core['dest']
    deps = [('BALI-JAN', 'Bali Group Tour · 6N/7D', 'Bali', 'Bali', 70, 12, 8, 54990, 41000, 1), ('KASH-MAY', 'Kashmir Paradise · 6N/7D', 'Kashmir', 'Kashmir', 120, 20, 10, 28990, 21500, 0),
            ('DUBAI-DEC', 'Dubai Winter Escape · 5N/6D', 'Dubai', 'Dubai', 55, 16, 10, 42990, 33000, 1), ('KERALA-NOV', 'Kerala Backwaters · 5N/6D', 'Kerala', 'Kerala', 38, 14, 8, 24990, 18500, 0)]
    dep_ids = []
    for code, title, d, _, start, seats, minp, price, cost, intl in deps:
        x = api('POST', '/departures', {'code': code, 'title': title, 'destination_id': dest[d], 'start_date': day(start), 'end_date': day(start + 6), 'total_seats': seats, 'min_pax': minp, 'price_pax_rs': price, 'cost_pax_rs': cost,
                                        'single_supplement_rs': 9000, 'single_supplement_cost_rs': 6500, 'is_international': intl, 'gst_rate': 5, 'sell_cutoff': day(start - 10), 'inclusions': 'Hotels, daily breakfast, transfers, sightseeing, tour manager'})
        dep_ids.append(x['id'] if isinstance(x, dict) and 'id' in x else x.get('departure', {}).get('id'))
    enq = [r for r in recs if r['stage'] == 'enquiry']
    for dep_id, r, a, c in ((dep_ids[0], enq[0], 2, 0), (dep_ids[0], enq[1], 3, 0), (dep_ids[1], enq[2], 2, 1), (dep_ids[2], enq[3], 4, 0)):
        try: api('POST', f'/departures/{dep_id}/reserve', {'trip_id': r['trip'], 'adults': a, 'children': c, 'single_rooms': 0})
        except SystemExit as e: print('reserve skipped', str(e)[:80])

    # -- WhatsApp inbox ------------------------------------------------------------------------------------------------------------------------------
    threads = [
        ('Rohan Kulkarni', [('in', 'Hi! We are planning Bali for our honeymoon in Jan. Can you share a package for 6 nights?'), ('out', 'Congratulations! 🎉 Happy to help. Do you prefer a private pool villa in Ubud or beachside in Seminyak?'),
                            ('in', 'Ubud with a pool villa sounds perfect. Budget is around 2 lakh for both.'), ('out', 'Lovely. I have put together a 6N/7D plan with private villa, transfers, Nusa Penida and a candlelight dinner. Sending the quote now.'), ('in', 'Got it, looks amazing! Can we pay in instalments?')]),
        ('Neha Iyer', [('in', 'Hello, what is the best time to visit Maldives?'), ('out', 'Hi Neha! November to April is the dry season. Are you looking at a water villa or a beach villa?'), ('in', 'Water villa please. Around the 2nd week of March.')]),
        ('Vikram Malhotra', [('out', 'Hi Vikram, your Dubai quote is ready: 5 nights, desert safari, Abu Dhabi tour. Link: bluehorizon.example/q/…'), ('in', 'Thanks! Can you reduce the price a little? We are 2 adults and 2 kids.'), ('out', 'Let me check with the hotel and come back within the hour.')]),
        ('Ananya Reddy', [('in', 'Payment done for the first instalment, please confirm.'), ('out', 'Received ✅ — thank you Ananya! Your booking is confirmed. We have sent the receipt and the document checklist.'), ('in', 'Great, will upload passports tonight.')]),
        ('Sandeep Joshi', [('in', 'Need Goa for 4 friends, 21st to 25th. Any good villas under 90k total?'), ('out', 'Yes! Two options in Calangute with pool. Sending details in a minute.')]),
        ('Pooja Nair', [('in', 'Hi, is Thailand visa-free for Indians right now?'), ('out', 'Hi Pooja! Please check the latest rule before travel — I will confirm on this chat and add the checklist to your booking.'), ('in', 'Thanks, please do. We fly in 3 months.')]),
        ('Arjun Mehta', [('in', 'Can I get the Kashmir itinerary in Hindi too?'), ('out', 'Of course — sharing the Hindi version shortly.'), ('in', 'Dhanyavaad!')]),
        ('Kavya Menon', [('in', 'Hello! Saw your ad for Maldives. What is the starting price per person?')]),
    ]
    byname = {r['name']: r for r in recs}; mstm = []
    for k, (name, msgs) in enumerate(threads):
        r = byname[name]; end = now - dt.timedelta(minutes=random.randint(8, 400) + k * 23); t0 = end - dt.timedelta(minutes=len(msgs) * 7)
        last_in = max([t0 + dt.timedelta(minutes=7 * i) for i, (dr, _) in enumerate(msgs) if dr == 'in'] or [t0])
        unread = 1 if msgs[-1][0] == 'in' else 0
        wa = sql(f"select wa_number from contacts where id={r['contact']}").strip()
        sql(f"insert into conversations (tenant_id,contact_id,wa_number,contact_name,window_expires_at,last_message_at,is_read,last_inbound_at,unread_count,status,created_at,updated_at) values ({T},{r['contact']},{q(wa)},{q(name)},{q(fmt(last_in + dt.timedelta(hours=24)))},{q(fmt(end))},{0 if unread else 1},{q(fmt(last_in))},{unread},'open',{q(fmt(t0))},{q(fmt(end))});")
        cid = sql('select last_insert_id()').strip() or sql(f"select max(id) from conversations where tenant_id={T}").strip()
        cid = sql(f"select max(id) from conversations where tenant_id={T}").strip()
        for i, (dr, body) in enumerate(msgs):
            ts = t0 + dt.timedelta(minutes=7 * i); st = 'read' if dr == 'out' else 'delivered'
            mstm.append(f"insert into messages (tenant_id,contact_id,conversation_id,direction,type,category,body,status,billable,sent_at,created_at,updated_at) values ({T},{r['contact']},{cid},{q(dr)},'text','service',{q(body)},{q(st)},0,{q(fmt(ts))},{q(fmt(ts))},{q(fmt(ts))});")
    sql(' '.join(mstm))

    # -- starter automations: install, then make four of them live (approved templates) -----------------------------------------------------------
    api('POST', '/travel-automations/install', {})
    sql(f"update templates set meta_status='approved', submitted_at=now() where tenant_id={T} and meta_status='draft';")
    sql(f"update flows set status='active' where tenant_id={T} and deleted_at is null and (name like 'Quote follow-up%' or name like 'Booking confirmed%' or name like 'Payment%' or name like 'Pre-departure%' or name like 'Post-trip%');")

    # -- travel-portal lead sources + a few inbound leads ---------------------------------------------------------------------------------------
    s1 = api('POST', '/lead-sources', {'name': 'Holiday Marketplace', 'kind': 'webhook', 'create_trip': 1}); tok = s1.get('token') or ''
    leads = [('Mahesh Rane', '9198765 01001'.replace(' ', ''), 'Bali', '2 adults, 6 nights, budget 1.8 lakh'), ('Sonal Deshpande', '919876501002', 'Maldives', 'honeymoon in March, budget 3 lakh'), ('Deepak Nair', '919876501003', 'Kerala', 'family of 4, 5 days')]
    for n, ph, d, note in leads:
        try: public_post_json(f'/public/lead-sources/{tok}', {'name': n, 'phone': ph, 'destination': d, 'message': note})
        except SystemExit as e: print('lead skipped', str(e)[:60])

    # -- GST invoices + checklists + portal links ------------------------------------------------------------------------------------------------
    for r in recs:
        if r.get('booking'):
            try: api('POST', f"/billing-docs/bookings/{r['booking']}/invoice", {})
            except SystemExit as e: print('invoice skipped', r['booking'], str(e)[:90])
            if r['stage'] in ('booked', 'paid'):
                try: api('POST', f"/checklists/bookings/{r['booking']}", {})
                except SystemExit: pass
    first = next(r for r in recs if r.get('booking') and r['stage'] == 'booked')
    portal = api('POST', f"/portal/bookings/{first['booking']}/link", {})
    json.dump({'T': T, 'portal': portal, 'quote': first['share'], 'itin': first['itin'], 'booking': first['booking'], 'recs': recs, 'dep_ids': dep_ids}, open('/tmp/showcase_extra.json', 'w'))
    print('extras done; portal', json.dumps(portal)[:120])

def public_post_json(path, body):
    import time
    for attempt in range(10):
        try: return urllib.request.urlopen(urllib.request.Request(B + path, method='POST', data=json.dumps(body).encode(), headers={'Content-Type': 'application/json'})).read()
        except urllib.error.HTTPError as e:
            if e.code == 429 and attempt < 9: time.sleep(2 + attempt); continue
            raise SystemExit(f'POST {path} -> {e.code} {e.read().decode()[:120]}')

def seed_ads():
    login_or_register()
    core = json.load(open('/tmp/showcase_core.json')); ex = json.load(open('/tmp/showcase_extra.json')); T = ex['T']; recs = core['trips']; dest = core['dest']
    q = lambda v: "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"
    sql(f"delete from ad_campaigns where tenant_id={T}; delete from ad_insights_daily where tenant_id={T}; delete from lead_attributions where tenant_id={T}; delete from integrations where tenant_id={T};")
    sql(f"insert into integrations (tenant_id,type,page_id,verify_token,config,status,created_at,updated_at) select {T},type,page_id,NULL,config,status,now(),now() from integrations where tenant_id=1 and deleted_at is null;")
    sql(f"insert into ad_settings (tenant_id,daily_spend_cap,min_daily_budget,max_raise_pct,auto_run,created_at,updated_at) values ({T},3000000,10000,30,1,now(),now()) on duplicate key update daily_spend_cap=3000000;") if False else None
    api('PUT', '/ad-campaigns/settings', {'daily_spend_cap': 30000, 'min_daily_budget': 100, 'max_raise_pct': 30, 'auto_run': 1})
    C = [  # platform, kind, objective, name, destination, rupees/day, status, base CPL rupees, enquiry→booking share
        ('meta', 'lead_form', 'OUTCOME_LEADS', 'Bali Honeymoon · Lead form', 'Bali', 1500, 'ACTIVE', 420), ('meta', 'lead_form', 'OUTCOME_LEADS', 'Maldives Luxury Escapes', 'Maldives', 2200, 'ACTIVE', 780),
        ('meta', 'click_to_whatsapp', 'OUTCOME_ENGAGEMENT', 'Dubai Family Fun · Click-to-WhatsApp', 'Dubai', 1200, 'ACTIVE', 310), ('meta', 'lead_form', 'OUTCOME_LEADS', 'Kerala Backwaters · Monsoon', 'Kerala', 800, 'PAUSED', 360),
        ('google', 'search', 'SEARCH', 'Search · Goa packages', 'Goa', 900, 'ACTIVE', 290), ('google', 'pmax', 'PERFORMANCE_MAX', 'Performance Max · Thailand', 'Thailand', 1300, 'ACTIVE', 520),
        ('google', 'demand_gen', 'DEMAND_GEN', 'Demand Gen · Kashmir summer', 'Kashmir', 800, 'ACTIVE', 480)]
    ext = {}
    for i, (plat, kind, obj, name, d, bud, st, cpl) in enumerate(C):
        eid = str(2000 + i); acct = 'act_1000000001' if plat == 'meta' else '1234567890'; ext[name] = (eid, plat, bud, cpl)
        spec = {'kind': kind, 'name': name, 'platform': plat, 'daily_budget': bud * 100}
        sql(f"insert into ad_campaigns (tenant_id,platform,origin,external_id,account_ref,name,objective,status,daily_budget,destination_id,last_synced_at,created_at,updated_at,kind,effective_status,currency,start_date,spec,children,launched_at) values "
            f"({T},{q(plat)},'travelpilot',{q(eid)},{q(acct)},{q(name)},{q(obj)},{q(st)},{bud * 100},{dest[d]},now(),date_sub(now(),interval {45 - i * 3} day),now(),{q(kind)},{q(st)},'INR',date_sub(curdate(),interval {44 - i * 3} day),{q(json.dumps(spec))},'{{}}',date_sub(now(),interval {44 - i * 3} day));")
    rows = []
    for name, (eid, plat, bud, cpl) in ext.items():
        for back in range(45, -1, -1):
            if name.startswith('Kerala') and back < 12: continue          # paused 12 days ago
            if name.startswith(('Demand', 'Performance')) and back > 30: continue
            spend = int(bud * 100 * random.uniform(0.72, 0.99)); leads = max(0, int(round(spend / 100 / cpl * random.uniform(0.7, 1.35)))); clicks = int(spend / 100 / random.uniform(9, 16)); imp = clicks * random.randint(28, 55)
            rows.append(f"({T},{q(plat)},{q(eid)},date_sub(curdate(),interval {back} day),{spend},{imp},{clicks},{leads},{leads if 'WhatsApp' in name else 0},now())")
    sql('insert into ad_insights_daily (tenant_id,platform,campaign_external_id,day,spend,impressions,clicks,leads,conversations,synced_at) values ' + ','.join(rows) + ';')
    # first-touch attribution: ~70 % of the trips came from a TravelPilot-run ad, matched by destination
    by_dest = {}
    for name, (eid, plat, bud, cpl) in ext.items(): by_dest.setdefault(name.split('·')[0].strip().split()[0] if False else name, (eid, plat, name))
    pick = {'Bali': 'Bali Honeymoon · Lead form', 'Maldives': 'Maldives Luxury Escapes', 'Dubai': 'Dubai Family Fun · Click-to-WhatsApp', 'Kerala': 'Kerala Backwaters · Monsoon', 'Goa': 'Search · Goa packages', 'Thailand': 'Performance Max · Thailand', 'Kashmir': 'Demand Gen · Kashmir summer'}
    att = []
    for i, r in enumerate(recs):
        if not r.get('booking') and i % 3 == 0: continue
        name = pick[r['dest']]; eid, plat, _, _ = ext[name]
        att.append(f"({T},{r['contact']},{r['trip']},'first',{q(plat)},{q('paid_social' if plat == 'meta' else 'paid_search')},{q(eid)},{q(name)},now(),now(),now())")
    sql('insert into lead_attributions (tenant_id,contact_id,trip_id,touch,platform,channel,campaign_id,campaign_name,touched_at,created_at,updated_at) values ' + ','.join(att) + ';')
    print('ads seeded', len(rows), 'insight rows,', len(att), 'attributions')

def seed_flow_runs():
    ex = json.load(open('/tmp/showcase_extra.json')); T = ex['T']; contacts = [r['contact'] for r in ex['recs']]
    flows = [int(x) for x in sql(f"select id from flows where tenant_id={T} and status='active' and deleted_at is null").split()]
    sql(f"delete from flow_runs where tenant_id={T};")
    rows = []
    for f in flows:
        for n in range(random.randint(14, 46)):
            when = dt.datetime.now() - dt.timedelta(days=random.randint(0, 29), hours=random.randint(0, 23)); st = random.choices(['completed', 'running', 'waiting'], [82, 6, 12])[0]
            rows.append(f"({T},{f},{random.choice(contacts)},'{st}',0,{random.randint(2, 6)},'{when:%Y-%m-%d %H:%M:%S}',{('NULL' if st != 'completed' else repr(f'{when + dt.timedelta(hours=random.randint(1, 30)):%Y-%m-%d %H:%M:%S}'))},'{when:%Y-%m-%d %H:%M:%S}','{when:%Y-%m-%d %H:%M:%S}')")
    sql('insert into flow_runs (tenant_id,flow_id,contact_id,status,is_test,steps_executed,entered_at,completed_at,created_at,updated_at) values ' + ','.join(rows) + ';')
    print('flow runs', len(rows))

def seed_polish():
    ex = json.load(open('/tmp/showcase_extra.json')); T = ex['T']
    sql(f"update deals set won_at = date_add(created_at, interval floor(5 + rand()*16) day) where tenant_id={T} and status='won';")
    convs = [r.split('\t') for r in sql(f"select id,contact_id from conversations where tenant_id={T}").strip().split('\n')]
    ins = ['Hi, is this package still available?', 'Can you share the itinerary?', 'What is included in the price?', 'Thanks, will confirm by evening', 'Do you arrange visas as well?', 'Is airfare included?', 'Sending the passport copies now', 'Looks good 👍']
    outs = ['Hello! Yes it is available — sharing the details now.', 'Sure, here is the day-wise itinerary.', 'Hotels, breakfast, transfers and sightseeing are included.', 'Great, I will hold the dates for you till then.', 'Yes, our visa desk can help — I will share the checklist.', 'Airfare is extra; I can add it to the quote.', 'Received, thank you!', 'Happy to help ✈️']
    rows = []
    for back in range(1, 14):
        for _ in range(random.randint(6, 22)):
            cid, ct = random.choice(convs); ts = dt.datetime.now() - dt.timedelta(days=back, hours=random.randint(0, 13), minutes=random.randint(0, 59))
            inbound = random.random() < 0.45; body = random.choice(ins if inbound else outs).replace("'", "''")
            rows.append(f"({T},{ct},{cid},'{'in' if inbound else 'out'}','text','service','{body}','{'delivered' if inbound else 'read'}',0,'{ts:%Y-%m-%d %H:%M:%S}','{ts:%Y-%m-%d %H:%M:%S}','{ts:%Y-%m-%d %H:%M:%S}')")
    sql('insert into messages (tenant_id,contact_id,conversation_id,direction,type,category,body,status,billable,sent_at,created_at,updated_at) values ' + ','.join(rows) + ';')
    tpl = sql(f"select id from templates where tenant_id={T} and deleted_at is null order by id limit 1").strip()
    for name, n, back in (('Diwali Maldives offer', 212, 6), ('Winter Dubai early-bird', 164, 2)):
        sql(f"insert into campaigns (tenant_id,template_id,name,status,total_contacts,sent_count,failed_count,created_at,updated_at) values ({T},{tpl},'{name}','done',{n},{n - 3},3,date_sub(now(), interval {back} day),date_sub(now(), interval {back} day));")
    print('polish done', len(rows), 'messages')

if __name__ == '__main__':
    if '--polish' in sys.argv: seed_polish(); raise SystemExit
    if '--runs' in sys.argv: seed_flow_runs(); raise SystemExit
    if '--ads' in sys.argv: seed_ads(); raise SystemExit
    if '--extras' in sys.argv: seed_extras()
    elif '--core' in sys.argv: json.dump(seed_core(), open('/tmp/showcase_core.json', 'w'))
    elif '--reset' not in sys.argv: json.dump(seed_core(), open('/tmp/showcase_core.json', 'w')); seed_extras()
