import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Wallet, TrendingUp, BadgeCheck, Building2, Landmark, TriangleAlert, LifeBuoy, Tag, Handshake, MessageCircle, FileText, CheckCircle2, ShieldAlert, ArrowUpRight, ArrowDownRight } from 'lucide-react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { Kpi, Pill } from './AdminUi'
import { PLAN_COLOR, ago, fmtDate } from './adminUtil'
import { BarChart, Donut, Funnel } from './AdminCharts'

const greeting = () => { const h = new Date().getHours(); return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening' }
const rs = (paise) => '₹' + Math.round((paise || 0) / 100).toLocaleString('en-IN')
const rsK = (paise, short) => { const v = (paise || 0) / 100; return short ? (v >= 1e5 ? '₹' + (v / 1e5).toFixed(v >= 1e6 ? 0 : 1) + 'L' : v >= 1e3 ? '₹' + Math.round(v / 1e3) + 'k' : '₹' + Math.round(v)) : rs(paise) }
const TONE = { danger: 'var(--danger)', warn: 'var(--warning)', info: 'var(--primary)' }

function Card({ title, right, children, className = '' }) {
  return <section className={'card dash-card ' + className}><div className="dash-head"><h3>{title}</h3>{right}</div>{children}</section>
}
const Empty = ({ children }) => <div className="dash-empty">{children}</div>

export default function AdminOverviewPage() {
  const [d, setD] = useState(null)
  useEffect(() => { admin.dashboard().then(setD).catch(e => toast.error('Could not load', e.message)) }, [])
  if (!d) return <div className="page"><div style={{ display: 'grid', gap: 14 }}><div className="card" style={{ height: 150 }} /><div style={{ display: 'flex', gap: 14 }}>{[1, 2, 3, 4].map(i => <div key={i} className="card" style={{ flex: 1, height: 130 }} />)}</div></div></div>
  const h = d.headline, u = d.usage, sys = d.system
  const todo = d.attention.reduce((a, x) => a + x.count, 0) + d.setup.length
  const chg = h.revenue_change_pct
  const failPct = u.wa_sent > 0 ? Math.round((u.wa_failed * 1000) / u.wa_sent) / 10 : 0

  return (
    <div className="page">
      <section className="ad-hero">
        <small>{new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long' })}</small>
        <h1>{greeting()}</h1>
        <p>{todo > 0 ? `${todo} thing${todo === 1 ? '' : 's'} need${todo === 1 ? 's' : ''} you today.` : 'Everything is calm: nothing needs you right now.'} {h.customers} customer{h.customers === 1 ? '' : 's'}, {h.paying} paying, {rs(h.mrr_paise)} monthly recurring revenue before GST.</p>
        <div className="ad-actions">
          <Link to="/admin/support"><LifeBuoy size={15} /> Support inbox</Link>
          <Link to="/admin/invoices"><FileText size={15} /> GST invoices</Link>
          <Link to="/admin/offers"><Tag size={15} /> Offers</Link>
          <Link to="/admin/partners"><Handshake size={15} /> Partners</Link>
          <Link to="/admin/whatsapp"><MessageCircle size={15} /> WhatsApp</Link>
        </div>
      </section>

      {d.setup.length > 0 && <div className="dash-setup">{d.setup.map(x => <Link key={x.key} to={x.to}><ShieldAlert size={16} /> {x.label} <span>Fix →</span></Link>)}</div>}

      <div className="dash-kpis">
        <Kpi icon={TrendingUp} tone="#12a89e" label="MRR" value={rs(h.mrr_paise)} sub={`ARR ${rs(h.arr_paise)} · before GST`} />
        <Kpi icon={Wallet} tone="#1a9d57" label="Revenue this month" value={rs(h.revenue_month_paise)} sub={chg === null ? `last month ${rs(h.revenue_prev_paise)}` : <span style={{ color: chg >= 0 ? 'var(--success)' : 'var(--danger)', fontWeight: 700 }}>{chg >= 0 ? <ArrowUpRight size={13} style={{ verticalAlign: '-2px' }} /> : <ArrowDownRight size={13} style={{ verticalAlign: '-2px' }} />} {Math.abs(chg)}% vs last month ({rs(h.revenue_prev_paise)})</span>} />
        <Kpi icon={BadgeCheck} tone="#0a6cc4" label="Paying customers" value={h.paying} sub={`${h.paying_pct}% of ${h.customers}${h.complimentary ? ` · +${h.complimentary} complimentary` : ''}`} />
        <Kpi icon={Building2} tone="#3b9bea" label="Customers" value={h.customers} sub={`${h.new_7d} new this week · ${h.new_30d} in 30 days`} />
        <Kpi icon={Landmark} tone="#f98f26" label="GST this month" value={rs(h.gst_month_paise)} sub="net of credit notes" />
        <Kpi icon={TriangleAlert} tone={h.attention ? '#d93025' : '#8a99b3'} label="Customers needing attention" value={h.attention} sub={h.halted ? `${h.halted} payment failed` : 'no failed payments'} accent={h.attention ? '#d93025' : undefined} />
      </div>

      <div className="dash-row r-wide">
        <Card title="Revenue, last 12 months" right={<span className="dash-legend"><i style={{ background: '#0a6cc4' }} />Revenue (before GST) <i style={{ background: '#f98f26' }} />GST</span>}>
          <BarChart data={d.months.map(m => ({ label: m.label, title: m.month, value: m.revenue, second: m.gst }))} format={(v, tick) => rsK(v, tick)} secondLabel="GST" height={210} />
          <div className="dash-foot">Paid periods by the month they started. {d.months.reduce((a, m) => a + m.payments, 0)} payments in 12 months.</div>
        </Card>
        <Card title="Needs your attention" right={<span className="dash-count">{todo}</span>}>
          {d.attention.length === 0 && <Empty><CheckCircle2 size={18} /> Nothing needs you right now.</Empty>}
          {d.attention.map(a => (
            <Link key={a.key} to={a.to} className="dash-attn">
              <span className="n" style={{ background: TONE[a.tone] }}>{a.count}</span>
              <span style={{ flex: 1 }}><b>{a.label}</b><small>{a.sub}</small></span><span aria-hidden="true" style={{ color: '#9fb1cc' }}>›</span>
            </Link>
          ))}
        </Card>
      </div>

      <div className="dash-row r-three">
        <Card title="New customers, last 12 weeks"><BarChart data={d.weeks.map(w => ({ label: w.label, title: 'Week of ' + w.label, value: w.signups }))} height={170} color="#12a89e" mainLabel="signups" /></Card>
        <Card title="From signup to paying" right={<span className="dash-count" style={{ background: '#e6f6ee', color: '#14753f' }}>{h.paying_pct}%</span>}><Funnel steps={d.funnel} />
          <div className="dash-foot">"Added data" = a contact or trip exists. Gaps show where people stop.</div></Card>
        <Card title="Customers by plan">
          <div style={{ display: 'flex', alignItems: 'center', gap: 18, flexWrap: 'wrap' }}>
            <Donut data={Object.entries(d.plan_mix).map(([p, n]) => ({ label: p, value: n, color: PLAN_COLOR[p] }))} center={h.customers} sub="workspaces" size={140} />
            <div style={{ display: 'grid', gap: 6, fontSize: 13.5 }}>{Object.entries(d.plan_mix).map(([p, n]) => <div key={p} style={{ display: 'flex', gap: 8, alignItems: 'center' }}><i style={{ width: 10, height: 10, borderRadius: 3, background: PLAN_COLOR[p] }} /><span style={{ textTransform: 'capitalize', width: 60 }}>{p}</span><b>{n}</b></div>)}</div>
          </div>
          <div className="dash-foot">Lapsed {h.lapsed} · Suspended {h.suspended} · Complimentary {h.complimentary}</div>
        </Card>
      </div>

      <div className="dash-row r-three">
        <Card title="Latest sign-ups" right={<Link to="/admin/customers?segment=new" className="dash-more">All →</Link>}>
          {d.recent.signups.length === 0 && <Empty>No customers yet.</Empty>}
          {d.recent.signups.map(r => <Link key={r.id} to={`/admin/customers?open=${r.id}`} className="dash-li"><span><b>{r.name}</b><small>{r.owner || r.email}{r.partner ? ` · via ${r.partner}` : ''}</small></span><span style={{ textAlign: 'right' }}><Pill color={PLAN_COLOR[r.plan]}>{r.plan}</Pill><small>{ago(r.created_at)}</small></span></Link>)}
        </Card>
        <Card title="Latest payments" right={<Link to="/admin/invoices" className="dash-more">Invoices →</Link>}>
          {d.recent.payments.length === 0 && <Empty>No GST invoices yet. They appear when a customer pays.</Empty>}
          {d.recent.payments.map(r => <Link key={r.id} to="/admin/invoices" className="dash-li"><span><b>{r.buyer_name}</b><small>{r.number} · <span style={{ textTransform: 'capitalize' }}>{r.plan}</span></small></span><span style={{ textAlign: 'right' }}><b>{'₹' + (r.total / 100).toLocaleString('en-IN', { minimumFractionDigits: 2 })}</b><small>{fmtDate(r.issue_date)}</small></span></Link>)}
        </Card>
        <Card title="Open support requests" right={<Link to="/admin/support" className="dash-more">Inbox →</Link>}>
          {d.recent.tickets.length === 0 && <Empty><CheckCircle2 size={18} /> Inbox is clear.</Empty>}
          {d.recent.tickets.map(t => <Link key={t.id} to={`/admin/support/${t.id}`} className="dash-li"><span><b>{t.subject}</b><small>{t.code} · {t.tenant_name}</small></span><span style={{ textAlign: 'right' }}>{t.status === 'open' && t.last_from === 'customer' ? <Pill color="var(--danger)">Waiting on you</Pill> : <Pill color="var(--text-3)">{t.status}</Pill>}<small>{ago(t.last_message_at)}</small></span></Link>)}
        </Card>
      </div>

      <div className="dash-row r-three">
        <Card title="Top customers" right={<Link to="/admin/customers" className="dash-more">All →</Link>}>
          {d.top_customers.length === 0 && <Empty>Nobody has paid yet.</Empty>}
          {d.top_customers.map(c => <Link key={c.id} to={`/admin/customers?open=${c.id}`} className="dash-li"><span><b>{c.name}</b><small style={{ textTransform: 'capitalize' }}>{c.plan} · {c.contacts.toLocaleString('en-IN')} contacts</small></span><b>{rs(c.lifetime_paise)}</b></Link>)}
          <div className="dash-foot">Ranked by total paid so far (GST included).</div>
        </Card>
        <Card title="Upgrade opportunities">
          {d.opportunities.length === 0 && <Empty>Nobody is close to a plan limit.</Empty>}
          {d.opportunities.map(c => <Link key={c.id} to={`/admin/customers?open=${c.id}`} className="dash-li"><span><b>{c.name}</b><small style={{ textTransform: 'capitalize' }}>{c.plan} plan</small></span><span style={{ textAlign: 'right' }}><b>{c.contacts.toLocaleString('en-IN')}</b><small>of {c.limit.toLocaleString('en-IN')} contacts</small></span></Link>)}
          <div className="dash-foot">Over 90% of their contact limit: a good moment to suggest the next plan.</div>
        </Card>
        <Card title="Partner programme" right={<Link to="/admin/partners" className="dash-more">Partners →</Link>}>
          <div className="dash-stats">
            <div><b>{d.partners.active}</b><small>active partners</small></div><div><b>{d.partners.referred}</b><small>customers referred</small></div>
            <div><b>{rs(d.partners.available_paise)}</b><small>ready to pay</small></div><div><b>{rs(d.partners.pending_paise)}</b><small>on hold</small></div>
          </div>
          <div className="dash-foot">Paid out so far: {rs(d.partners.paid_paise)}</div>
        </Card>
      </div>

      <div className="dash-row r-three">
        <Card title="Platform usage">
          <div className="dash-stats">
            <div><b>{u.users.toLocaleString('en-IN')}</b><small>users</small></div><div><b>{u.contacts.toLocaleString('en-IN')}</b><small>contacts</small></div>
            <div><b>{u.trips.toLocaleString('en-IN')}</b><small>trips</small></div><div><b>{u.bookings.toLocaleString('en-IN')}</b><small>bookings</small></div>
          </div>
          <div className="dash-foot" style={{ marginTop: 12 }}>WhatsApp, last 30 days: <b>{u.wa_sent.toLocaleString('en-IN')}</b> sent · <b>{u.wa_received.toLocaleString('en-IN')}</b> received · <span style={{ color: failPct >= 10 ? 'var(--danger)' : 'inherit' }}>{u.wa_failed} failed ({failPct}%)</span></div>
        </Card>
        <Card title="Platform health">
          <div className="dash-health">
            <Link to="/admin/payments"><i className={sys.razorpay.configured && !sys.razorpay.mock ? 'ok' : 'bad'} />Razorpay<small>{!sys.razorpay.configured ? 'not set up' : sys.razorpay.mock ? 'mock mode' : sys.razorpay.mode + ' mode' + (sys.razorpay.webhook_set ? '' : ', no webhook')}</small></Link>
            <Link to="/admin/mail"><i className={sys.mail.configured ? 'ok' : 'bad'} />Mail server<small>{sys.mail.configured ? sys.mail.host : 'not set up'}</small></Link>
            <Link to="/admin/invoices"><i className={sys.invoices.ok && sys.invoices.missing === 0 ? 'ok' : 'bad'} />GST invoicing<small>{sys.invoices.ok ? (sys.invoices.missing ? `${sys.invoices.missing} paid without invoice` : 'GSTIN ' + sys.invoices.gstin) : 'settings incomplete'}</small></Link>
            <Link to="/admin/access-log"><i className={sys.live_support_sessions ? 'warn' : 'ok'} />Support sessions<small>{sys.live_support_sessions ? `${sys.live_support_sessions} live now` : 'none open'}</small></Link>
          </div>
        </Card>
        <Card title="Recent customer-account access" right={<Link to="/admin/access-log" className="dash-more">Access log →</Link>}>
          {d.recent.access.length === 0 && <Empty>Nobody has signed in as a customer.</Empty>}
          {d.recent.access.map(a => <Link key={a.id} to="/admin/access-log" className="dash-li"><span><b>{a.tenant_name}</b><small>{a.admin_name} · {a.reason}</small></span><span style={{ textAlign: 'right' }}>{a.ended_at ? <Pill color="var(--text-3)">ended</Pill> : <Pill color="var(--warning)">live</Pill>}<small>{ago(a.created_at)}</small></span></Link>)}
        </Card>
      </div>
    </div>
  )
}
