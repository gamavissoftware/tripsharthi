import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { Pill, Kpi } from './AdminUi'
import { th, td, PLAN_COLOR, ago, TICKET_STATUS, PRIORITY_COLOR } from './adminUtil'


export default function AdminSupportPage() {
  const [f, setF] = useState({ status: 'active', q: '', assigned: '', page: 1 })
  const [d, setD] = useState(null)
  useEffect(() => { admin.supportList(f).then(setD).catch(e => toast.error('Could not load', e.message)) }, [f])
  const pages = d ? Math.max(1, Math.ceil(d.total / d.per_page)) : 1
  return (
    <div className="page">
      <div className="page-header"><h1 className="page-title">Support</h1></div>
      {d && (
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
          <Kpi label="Waiting for a reply" value={d.counts.unanswered} accent={d.counts.unanswered ? 'var(--danger)' : undefined} sub="customer wrote last" />
          <Kpi label="Active" value={d.counts.active} sub="open + waiting on customer" />
          <Kpi label="Resolved" value={d.counts.resolved} sub="not yet closed" />
          <Kpi label="All time" value={d.counts.total} />
        </div>)}
      <div style={{ display: 'flex', gap: 8, marginBottom: 10, flexWrap: 'wrap' }}>
        <select className="form-select" value={f.status} onChange={e => setF({ ...f, status: e.target.value, page: 1 })}>
          <option value="active">Active (open + waiting)</option><option value="unanswered">Waiting for our reply</option><option value="">Everything</option>
          <option value="open">Open</option><option value="pending">Waiting on customer</option><option value="resolved">Resolved</option><option value="closed">Closed</option>
        </select>
        <select className="form-select" value={f.assigned} onChange={e => setF({ ...f, assigned: e.target.value, page: 1 })}>
          <option value="">Anyone</option>{d?.team.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
        </select>
        <input className="form-input" style={{ maxWidth: 260 }} placeholder="Search title, customer, e-mail, TS-00012" value={f.q} onChange={e => setF({ ...f, q: e.target.value, page: 1 })} />
      </div>
      <div className="card" style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Ticket', 'Customer', 'Topic', 'Priority', 'Status', 'Assigned', 'Last activity'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {!d && <tr><td colSpan={7} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Loading…</td></tr>}
            {d && d.rows.length === 0 && <tr><td colSpan={7} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Nothing here. 🎉</td></tr>}
            {d?.rows.map(r => {
              const [sl, sc] = TICKET_STATUS[r.status] || [r.status, 'var(--text-3)']
              const wait = r.status === 'open' && r.last_from === 'customer'
              return (
                <tr key={r.id} style={wait ? { background: 'color-mix(in srgb, var(--warning) 7%, transparent)' } : undefined}>
                  <td style={td}><Link to={`/admin/support/${r.id}`}><b>{r.unread && '● '}{r.subject}</b></Link><div style={{ color: 'var(--text-3)', fontSize: 12 }}>{r.number}</div></td>
                  <td style={td}>{r.tenant_name} <Pill color={PLAN_COLOR[r.tenant_plan]}>{r.tenant_plan}</Pill><div style={{ color: 'var(--text-3)', fontSize: 12 }}>{r.user_name} · {r.user_email}</div></td>
                  <td style={td}>{r.category_label}</td>
                  <td style={td}><Pill color={PRIORITY_COLOR[r.priority]}>{r.priority}</Pill></td>
                  <td style={td}><Pill color={sc}>{sl}</Pill></td>
                  <td style={td}>{r.assignee_name || <span style={{ color: 'var(--text-3)' }}>—</span>}</td>
                  <td style={td}>{ago(r.last_message_at)}</td>
                </tr>)
            })}
          </tbody>
        </table>
      </div>
      {pages > 1 && <div style={{ display: 'flex', gap: 8, marginTop: 10, alignItems: 'center' }}>
        <button className="btn btn-ghost" disabled={f.page <= 1} onClick={() => setF({ ...f, page: f.page - 1 })}>← Previous</button>
        <span style={{ color: 'var(--text-3)' }}>Page {f.page} of {pages}</span>
        <button className="btn btn-ghost" disabled={f.page >= pages} onClick={() => setF({ ...f, page: f.page + 1 })}>Next →</button></div>}
    </div>
  )
}
