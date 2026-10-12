#!/usr/bin/env python3
"""DEV ONLY - fictional referral partner ("Anita Verma") with invented referred workspaces, commissions and a payout in the LOCAL database,
so the website's partner-programme screenshots show a lived-in portal. Login partner: anita@partner-showcase.test / Showcase@12345
Run: python3 scripts/partner_showcase_seed.py        Remove: python3 scripts/partner_showcase_seed.py --reset"""
import os, sys, subprocess, datetime as dt

def env():
    e = {}
    for line in open(os.path.join(os.path.dirname(__file__), '..', 'backend', '.env')):
        if line.startswith('database.default.'):
            k, _, v = line.partition('='); e[k.strip().split('.')[-1]] = v.strip()
    return e
def sql(q):
    e = env()
    r = subprocess.run(['mysql', '-u' + e['username'], '-h' + e['hostname'], e['database'], '-N', '-e', q], env={**os.environ, 'MYSQL_PWD': e['password']}, capture_output=True, text=True)
    if r.returncode: raise SystemExit(r.stderr)
    return r.stdout.strip()

EMAIL = 'anita@partner-showcase.test'
if '--reset' in sys.argv:
    pid = sql(f"select id from partners where email='{EMAIL}'")
    if pid:
        sql(f"delete from subscriptions where razorpay_sub_id like 'pshow-%'; delete from tenants where slug like 'pshow-%'; delete from partners where id={int(pid)};")
    print('removed'); raise SystemExit
if sql(f"select id from partners where email='{EMAIL}'"): raise SystemExit('already seeded (use --reset)')

pw = subprocess.run(['php', '-r', "echo password_hash('Showcase@12345', PASSWORD_BCRYPT);"], capture_output=True, text=True).stdout
now = dt.datetime.now()
d = lambda days: (now - dt.timedelta(days=days)).strftime('%Y-%m-%d %H:%M:%S')
sql(f"insert into partners (name,company,email,phone,code,status,commission_pct,commission_months,hold_days,password_hash,last_login_at,created_at,updated_at) values ('Anita Verma','Verma Travel Network','{EMAIL}','919800000011','ANITAVERMA','active',20,12,30,'{pw}','{d(0)}','{d(150)}','{d(0)}')")
pid = int(sql(f"select id from partners where email='{EMAIL}'"))
PRICE = {'starter': 149900, 'growth': 399900, 'pro': 699900}
# name, plan, days since referred, payments made (monthly), paying now
W = [('Sunrise Yatra Tours', 'growth', 140, 5, True), ('Kailash Holidays', 'starter', 118, 4, True), ('Marwar Trails & Tours', 'pro', 96, 3, True),
     ('Blue Lagoon Getaways', 'growth', 70, 2, True), ('Ganga Darshan Travels', 'starter', 41, 1, True), ('Deccan Wanderers', 'growth', 22, 1, True), ('Himalaya Route Makers', 'starter', 9, 0, False)]
payout_total = 0; payout_n = 0; rows = []
for i, (name, plan, ago, n, paying) in enumerate(W):
    n = (ago - 1) // 30 + 1 if paying else 0
    slug = f'pshow-{i+1}'
    sql(f"insert into tenants (name,slug,plan,status,mode,created_at,updated_at,referred_partner_id,referred_at,referral_source) values ('{name}','{slug}','{plan if paying else 'free'}','active','saas','{d(ago)}','{d(ago)}',{pid},'{d(ago)}','link')")
    tid = int(sql(f"select id from tenants where slug='{slug}'"))
    for k in range(n):
        days_ago = ago - 1 - 30 * k
        if days_ago < 0: continue
        base = PRICE[plan]; gst = round(base * .18); tot = base + gst
        last = k == n - 1
        status = 'active' if (last and paying) else 'downgraded'
        end = (now - dt.timedelta(days=days_ago) + dt.timedelta(days=30)).strftime('%Y-%m-%d %H:%M:%S')
        sql(f"insert into subscriptions (tenant_id,razorpay_sub_id,plan,amount_paise,billing_cycle,status,current_period_start,current_period_end,created_at,updated_at,taxable_paise,gst_paise,gst_rate,list_paise,discount_paise) values ({tid},'pshow-{i+1}-{k}','{plan}',{tot},'monthly','{status}','{d(days_ago)}','{end}','{d(days_ago)}','{d(days_ago)}',{base},{gst},18,{base},0)")
        sid = int(sql(f"select id from subscriptions where razorpay_sub_id='pshow-{i+1}-{k}'"))
        amt = round(base * .20); rows.append((tid, sid, plan, base, amt, days_ago))
# commissions older than 30 days are paid out in one payout; the rest are available (>30d, not paid - none) or on hold
paid = [r for r in rows if r[5] > 45]; rest = [r for r in rows if r[5] <= 45]
pay_amt = sum(r[4] for r in paid)
sql(f"insert into partner_payouts (partner_id,amount_paise,method,reference,commissions,paid_at,created_at) values ({pid},{pay_amt},'upi','UTR-DEMO-0001',{len(paid)},'{d(40)}','{d(40)}')")
poid = int(sql("select max(id) from partner_payouts"))
for tid, sid, plan, base, amt, ago in rows:
    if ago > 45: st, po = 'paid', poid
    else: st, po = 'pending', 'NULL'
    sql(f"insert into partner_commissions (partner_id,tenant_id,subscription_id,plan,cycle,base_paise,rate_pct,amount_paise,status,payout_id,earned_at,payable_after,created_at) values ({pid},{tid},{sid},'{plan}','monthly',{base},20,{amt},'{st}',{po},'{d(ago)}','{(now - dt.timedelta(days=ago) + dt.timedelta(days=30)).strftime('%Y-%m-%d %H:%M:%S')}','{d(ago)}')")
print('seeded partner', pid, 'login', EMAIL)
