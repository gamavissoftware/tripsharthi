import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { admin } from '../../api/admin'
import { inr } from '../../api/travel'
import { toast } from '../../components/Toast'
import { Kpi } from './AdminUi'
import { PLAN_COLOR, th, td, fmtDate } from './adminUtil'

export default function AdminOverviewPage() {
  const [d, setD] = useState(null)
  useEffect(() => { admin.overview().then(setD).catch(e => toast.error('Could not load', e.message)) }, [])
  if (!d) return <div className="page"><p style={{ color: 'var(--text-3)' }}>Loading…</p></div>
  const t = d.tenants, s = d.subscriptions, u = d.usage, i = d.inbox
  const max = Math.max(1, ...Object.values(t.by_plan))
  return (
    <div className="page">
      <div className="page-header"><h1 className="page-title">Platform overview</h1></div>
      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
        <Kpi label="Customers" value={t.total} sub={`${t.new_7d} new this week · ${t.new_30d} in 30 days`} />
        <Kpi label="Paying" value={s.paying} sub={s.complimentary ? `+ ${s.complimentary} complimentary` : 'active subscriptions'} accent="var(--success)" />
        <Kpi label="MRR" value={inr(s.mrr_paise, true)} sub="monthly recurring (annual ÷ 12)" />
        <Kpi label="Collected · 30 days" value={inr(s.collected_30d_paise, true)} sub={`${s.payments_30d} payment${s.payments_30d === 1 ? '' : 's'}`} />
        <Kpi label="Payment problems" value={s.halted} sub="halted subscriptions" accent={s.halted ? 'var(--danger)' : undefined} />
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(320px,1fr))', gap: 12, marginBottom: 12 }}>
        <div className="card card-body">
          <h3 style={{ marginTop: 0 }}>Customers by plan</h3>
          {Object.entries(t.by_plan).map(([p, n]) => (
            <div key={p} style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
              <span style={{ width: 70, textTransform: 'capitalize' }}>{p}</span>
              <div style={{ flex: 1, background: 'var(--surface-2)', borderRadius: 6, height: 14, overflow: 'hidden' }}><div style={{ width: `${(n / max) * 100}%`, height: '100%', background: PLAN_COLOR[p] }} /></div>
              <b style={{ width: 28, textAlign: 'right' }}>{n}</b>
            </div>
          ))}
          <p style={{ color: 'var(--text-3)', fontSize: 13, marginBottom: 0 }}>Active {t.by_status.active} · Suspended {t.by_status.suspended} · Cancelled {t.by_status.cancelled}</p>
        </div>
        <div className="card card-body">
          <h3 style={{ marginTop: 0 }}>Needs your attention</h3>
          <p style={{ margin: '0 0 8px' }}><Link to="/admin/inbox"><b>{i.unread_chats}</b> unread live chat{i.unread_chats === 1 ? '' : 's'}</Link> <span style={{ color: 'var(--text-3)' }}>({i.open_chats} open)</span></p>
          <p style={{ margin: '0 0 8px' }}><Link to="/admin/inbox"><b>{i.new_enquiries}</b> new website enquir{i.new_enquiries === 1 ? 'y' : 'ies'}</Link></p>
          <p style={{ margin: '0 0 8px' }}><Link to="/admin/subscriptions"><b>{s.halted}</b> halted subscription{s.halted === 1 ? '' : 's'}</Link></p>
          <p style={{ margin: 0, color: 'var(--text-3)', fontSize: 13 }}>Platform totals: {u.users} users · {u.contacts} contacts · {u.trips} trips · {u.bookings} bookings</p>
        </div>
      </div>
      <div className="card" style={{ overflowX: 'auto' }}>
        <div style={{ padding: '14px 16px 0' }}><h3 style={{ margin: 0 }}>Plans ending in the next 7 days</h3></div>
        <table style={{ width: '100%', borderCollapse: 'collapse', marginTop: 8 }}>
          <thead><tr><th style={th}>Customer</th><th style={th}>Plan</th><th style={th}>Ends</th></tr></thead>
          <tbody>
            {s.expiring_7d.length === 0 && <tr><td colSpan={3} style={{ ...td, color: 'var(--text-3)', textAlign: 'center' }}>Nothing is expiring soon.</td></tr>}
            {s.expiring_7d.map(r => <tr key={r.id}><td style={td}><Link to={`/admin/customers?open=${r.tenant_id}`}><b>{r.name}</b></Link></td><td style={{ ...td, textTransform: 'capitalize' }}>{r.plan}</td><td style={td}>{fmtDate(r.current_period_end)}</td></tr>)}
          </tbody>
        </table>
      </div>
    </div>
  )
}
