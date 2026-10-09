import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { admin } from '../../api/admin'
import { inr } from '../../api/travel'
import { toast } from '../../components/Toast'
import { Pill } from './AdminUi'
import { th, td, fmtDate, STATUS_COLOR, PLAN_COLOR } from './adminUtil'

export default function AdminSubscriptionsPage() {
  const [f, setF] = useState({ status: '', plan: '', page: 1 })
  const [d, setD] = useState(null)
  useEffect(() => { admin.subscriptions(f).then(setD).catch(e => toast.error('Could not load', e.message)) }, [f])
  const pages = d ? Math.max(1, Math.ceil(d.total / d.per_page)) : 1
  return (
    <div className="page">
      <div className="page-header"><h1 className="page-title">Subscriptions</h1>
        <div style={{ display: 'flex', gap: 8 }}>
          <select className="form-select" value={f.status} onChange={e => setF({ ...f, status: e.target.value, page: 1 })}><option value="">All statuses</option>{['active', 'halted', 'cancelled', 'downgraded', 'created', 'authenticated'].map(s => <option key={s}>{s}</option>)}</select>
          <select className="form-select" value={f.plan} onChange={e => setF({ ...f, plan: e.target.value, page: 1 })}><option value="">All plans</option>{['starter', 'growth', 'pro', 'free'].map(s => <option key={s}>{s}</option>)}</select>
        </div>
      </div>
      <div className="card" style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Customer', 'Plan', 'Amount', 'Cycle', 'Status', 'Started', 'Ends', 'Source'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {!d && <tr><td colSpan={8} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Loading…</td></tr>}
            {d && d.rows.length === 0 && <tr><td colSpan={8} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No subscriptions match.</td></tr>}
            {d?.rows.map(r => (
              <tr key={r.id}>
                <td style={td}><Link to={`/admin/customers?open=${r.tenant_id}`}><b>{r.tenant_name || `#${r.tenant_id}`}</b></Link></td>
                <td style={td}><Pill color={PLAN_COLOR[r.plan]}>{r.plan}</Pill></td>
                <td style={td}>{r.amount_paise ? inr(r.amount_paise) : <span style={{ color: 'var(--text-3)' }}>₹0</span>}</td>
                <td style={td}>{r.billing_cycle}</td>
                <td style={td}><Pill color={STATUS_COLOR[r.status]}>{r.status}</Pill></td>
                <td style={td}>{fmtDate(r.current_period_start)}</td><td style={td}>{fmtDate(r.current_period_end)}</td>
                <td style={td}>{r.manual ? <Pill color="var(--warning)">given by hand</Pill> : 'Razorpay'}</td>
              </tr>))}
          </tbody>
        </table>
      </div>
      {d && d.total > d.per_page && <div style={{ display: 'flex', gap: 10, justifyContent: 'center', marginTop: 12, alignItems: 'center' }}>
        <button className="btn btn-ghost btn-sm" disabled={f.page <= 1} onClick={() => setF({ ...f, page: f.page - 1 })}>← Prev</button><span>Page {f.page} of {pages}</span><button className="btn btn-ghost btn-sm" disabled={f.page >= pages} onClick={() => setF({ ...f, page: f.page + 1 })}>Next →</button></div>}
    </div>
  )
}
