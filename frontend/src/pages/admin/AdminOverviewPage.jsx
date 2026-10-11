import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Building2, BadgeCheck, TrendingUp, Wallet, TriangleAlert, LifeBuoy, Handshake, Tag, MessageCircle } from 'lucide-react'
import { admin } from '../../api/admin'
import { inr } from '../../api/travel'
import { toast } from '../../components/Toast'
import { Kpi } from './AdminUi'
import { PLAN_COLOR, th, td, fmtDate } from './adminUtil'

const greeting = () => { const h = new Date().getHours(); return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening' }

function Attn({ to, n, label, sub, hot }) {
  return (
    <Link to={to} className={'ad-attn' + (hot && n > 0 ? ' hot' : '')}>
      <span className="dot">{n}</span>
      <span style={{ flex: 1 }}><b style={{ fontWeight: 600 }}>{label}</b>{sub && <span style={{ display: 'block', fontSize: 12.5, color: 'var(--text-3)' }}>{sub}</span>}</span>
      <span aria-hidden="true" style={{ color: '#9fb1cc' }}>›</span>
    </Link>
  )
}

export default function AdminOverviewPage() {
  const [d, setD] = useState(null)
  useEffect(() => { admin.overview().then(setD).catch(e => toast.error('Could not load', e.message)) }, [])
  if (!d) return <div className="page"><div style={{ display: 'grid', gap: 14 }}><div className="card" style={{ height: 150 }} /><div style={{ display: 'flex', gap: 14 }}>{[1, 2, 3, 4].map(i => <div key={i} className="card" style={{ flex: 1, height: 130 }} />)}</div></div></div>
  const t = d.tenants, s = d.subscriptions, u = d.usage, i = d.inbox
  const max = Math.max(1, ...Object.values(t.by_plan))
  const todo = (i.unread_chats || 0) + (i.support_unanswered || 0) + (i.new_enquiries || 0) + (s.halted || 0)
  return (
    <div className="page">
      <section className="ad-hero">
        <small>{new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long' })}</small>
        <h1>{greeting()}</h1>
        <p>{todo > 0 ? `${todo} thing${todo === 1 ? '' : 's'} need${todo === 1 ? 's' : ''} your attention today.` : 'Everything is calm. No open items need you right now.'} {t.total} customer{t.total === 1 ? '' : 's'}, {s.paying} paying, {inr(s.mrr_paise, true)} monthly recurring revenue.</p>
        <div className="ad-actions">
          <Link to="/admin/support"><LifeBuoy size={15} /> Support inbox</Link>
          <Link to="/admin/offers"><Tag size={15} /> Offers</Link>
          <Link to="/admin/partners"><Handshake size={15} /> Partners</Link>
          <Link to="/admin/whatsapp"><MessageCircle size={15} /> WhatsApp</Link>
        </div>
      </section>

      <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap', marginBottom: 14 }}>
        <Kpi icon={Building2} tone="#0a6cc4" label="Customers" value={t.total} sub={`${t.new_7d} new this week · ${t.new_30d} in 30 days`} />
        <Kpi icon={BadgeCheck} tone="#1a9d57" label="Paying" value={s.paying} sub={s.complimentary ? `+ ${s.complimentary} complimentary` : 'active subscriptions'} />
        <Kpi icon={TrendingUp} tone="#12a89e" label="MRR" value={inr(s.mrr_paise, true)} sub="monthly recurring (annual ÷ 12)" />
        <Kpi icon={Wallet} tone="#f98f26" label="Collected · 30 days" value={inr(s.collected_30d_paise, true)} sub={`${s.payments_30d} payment${s.payments_30d === 1 ? '' : 's'}`} />
        <Kpi icon={TriangleAlert} tone={s.halted ? '#d93025' : '#8a99b3'} label="Payment problems" value={s.halted} sub="halted subscriptions" accent={s.halted ? '#d93025' : undefined} />
      </div>

      <div className="ad-grid" style={{ gridTemplateColumns: 'repeat(auto-fit,minmax(330px,1fr))', marginBottom: 14 }}>
        <div className="card card-body">
          <h3 style={{ marginTop: 0 }}>Customers by plan</h3>
          {Object.entries(t.by_plan).map(([p, n]) => (
            <div key={p} style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 12 }}>
              <span style={{ width: 70, textTransform: 'capitalize', fontWeight: 600, color: '#26395c' }}>{p}</span>
              <div className="ad-bar"><i style={{ width: `${(n / max) * 100}%`, background: PLAN_COLOR[p] }} /></div>
              <b style={{ width: 28, textAlign: 'right' }}>{n}</b>
            </div>
          ))}
          <p style={{ color: 'var(--text-3)', fontSize: 13, marginBottom: 0 }}>Active {t.by_status.active} · Suspended {t.by_status.suspended} · Cancelled {t.by_status.cancelled}</p>
        </div>
        <div className="card card-body">
          <h3 style={{ marginTop: 0 }}>Needs your attention</h3>
          <Attn to="/admin/support" n={i.support_unanswered || 0} label="Support requests waiting for a reply" hot />
          <Attn to="/admin/inbox" n={i.unread_chats} label="Unread live chats" sub={`${i.open_chats} open`} hot />
          <Attn to="/admin/inbox" n={i.new_enquiries} label={`New website enquir${i.new_enquiries === 1 ? 'y' : 'ies'}`} hot />
          <Attn to="/admin/subscriptions" n={s.halted} label="Halted subscriptions" hot />
          <p style={{ margin: '10px 0 0', color: 'var(--text-3)', fontSize: 13 }}>Platform totals: {u.users} users · {u.contacts} contacts · {u.trips} trips · {u.bookings} bookings</p>
        </div>
      </div>

      <div className="card" style={{ overflowX: 'auto' }}>
        <div style={{ padding: '16px 20px 6px' }}><h3 style={{ margin: 0 }}>Plans ending in the next 7 days</h3></div>
        <table style={{ width: '100%', borderCollapse: 'collapse', marginTop: 8 }}>
          <thead><tr><th style={th}>Customer</th><th style={th}>Plan</th><th style={th}>Ends</th></tr></thead>
          <tbody>
            {s.expiring_7d.length === 0 && <tr><td colSpan={3} style={{ ...td, color: 'var(--text-3)', textAlign: 'center', padding: '26px 18px' }}>Nothing is expiring soon.</td></tr>}
            {s.expiring_7d.map(r => <tr key={r.id}><td style={td}><Link to={`/admin/customers?open=${r.tenant_id}`}><b>{r.name}</b></Link></td><td style={{ ...td, textTransform: 'capitalize' }}>{r.plan}</td><td style={td}>{fmtDate(r.current_period_end)}</td></tr>)}
          </tbody>
        </table>
      </div>
    </div>
  )
}
