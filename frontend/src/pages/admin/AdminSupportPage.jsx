import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Inbox, Clock, Hourglass, Zap, CheckCircle2, LifeBuoy, Search } from 'lucide-react'
import { admin } from '../../api/admin'
import { adminAuth } from '../../api/adminAuth'
import { toast } from '../../components/Toast'
import { Pill, Kpi } from './AdminUi'
import { th, td, PLAN_COLOR, ago, TICKET_STATUS, PRIORITY_COLOR } from './adminUtil'
import { minutesText } from './supportTemplates'

const VIEWS = [['unanswered', 'Needs a reply', 'unanswered'], ['mine', 'Assigned to me', null], ['active', 'Active', 'active'], ['pending', 'Waiting on customer', 'pending'], ['resolved', 'Resolved', 'resolved'], ['closed', 'Closed', 'closed'], ['', 'Everything', 'total']]

const initials = (n) => String(n || '?').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase()

export default function AdminSupportPage() {
  const [view, setView] = useState('unanswered')
  const [f, setF] = useState({ q: '', assigned: '', page: 1 })
  const [me, setMe] = useState(null)
  const [d, setD] = useState(null)
  useEffect(() => { adminAuth.me().then(setMe).catch(() => {}) }, [])
  const params = useMemo(() => ({ status: view === 'mine' ? 'active' : view, assigned: view === 'mine' ? (me?.id ?? '') : f.assigned, q: f.q, page: f.page }), [view, f, me])
  useEffect(() => {
    let alive = true
    const t = setTimeout(() => { admin.supportList(params).then(r => { if (alive) setD(r) }).catch(e => toast.error('Could not load', e.message)) }, f.q ? 300 : 0)
    return () => { alive = false; clearTimeout(t) }
  }, [params, f.q])
  const C = d?.counts
  const pages = d ? Math.max(1, Math.ceil(d.total / d.per_page)) : 1
  const pick = (v) => { setView(v); setF({ ...f, assigned: '', page: 1 }) }

  return (
    <div className="page">
      <div className="page-header"><div><h1 className="page-title">Support</h1><div style={{ color: 'var(--text-3)', fontSize: 13.5 }}>Requests from customers. Answer within {C?.target_hours ?? 24} hours; the ones waiting longest come first.</div></div></div>

      {C && <div className="cu-kpis">
        <Kpi icon={Inbox} tone={C.unanswered ? '#d93025' : '#1a9d57'} label="Waiting for your reply" value={C.unanswered} accent={C.unanswered ? '#d93025' : undefined} sub={C.oldest_waiting_hours != null ? `oldest has waited ${C.oldest_waiting_hours < 1 ? 'under an hour' : C.oldest_waiting_hours + ' h'}` : 'all answered'} />
        <Kpi icon={Clock} tone={C.past_target ? '#d93025' : '#8a99b3'} label={`Past ${C.target_hours}-hour target`} value={C.past_target} accent={C.past_target ? '#d93025' : undefined} sub="answer these first" />
        <Kpi icon={Hourglass} tone="#f59e0b" label="Waiting on customer" value={C.pending} sub="we replied, they have not" />
        <Kpi icon={Zap} tone="#0a6cc4" label="First reply, 30 days" value={minutesText(C.avg_first_response_minutes)} sub="average time to our first answer" />
        <Kpi icon={CheckCircle2} tone="#1a9d57" label="Resolved this week" value={C.resolved_7d} sub={`${C.total} requests all time`} />
      </div>}

      <div className="cu-chips">
        {VIEWS.map(([k, label, key]) => <button key={k || 'all'} type="button" className={'cu-chip' + (view === k ? ' on' : '')} onClick={() => pick(k)}>{label}{C && key && <b>{C[key]}</b>}</button>)}
      </div>
      <div className="cu-filters">
        <div className="cu-search"><Search size={15} /><input value={f.q} placeholder="Search subject, customer, e-mail or TS-00012" onChange={e => setF({ ...f, q: e.target.value, page: 1 })} /></div>
        {view !== 'mine' && <select className="form-select" value={f.assigned} onChange={e => setF({ ...f, assigned: e.target.value, page: 1 })}><option value="">Anyone</option>{d?.team.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}</select>}
      </div>

      <div className="card" style={{ overflowX: 'auto' }}>
        <table className="cu-table" style={{ minWidth: 960 }}>
          <thead><tr>{['Request', 'Customer', 'Topic', 'Priority', 'Status', 'Assigned', 'Last activity'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {!d && <tr><td colSpan={7} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Loading…</td></tr>}
            {d && d.rows.length === 0 && <tr><td colSpan={7} style={{ ...td, textAlign: 'center', color: 'var(--text-3)', padding: 40 }}><LifeBuoy size={22} style={{ verticalAlign: '-5px', color: 'var(--success)' }} /> Nothing here. Inbox zero.</td></tr>}
            {d?.rows.map(r => {
              const [sl, sc] = TICKET_STATUS[r.status] || [r.status, 'var(--text-3)']
              return (
                <tr key={r.id} className={r.past_target ? 'sp-late' : r.waiting_hours != null ? 'sp-wait' : ''} onClick={() => { window.location.hash = `#/admin/support/${r.id}` }}>
                  <td style={td}><Link to={`/admin/support/${r.id}`} className="cu-name" style={{ textDecoration: 'none' }} onClick={e => e.stopPropagation()}>{r.unread && <i className="sp-dot" />}{r.subject}</Link><div className="cu-sub">{r.number} · opened {ago(r.created_at)}</div></td>
                  <td style={td}><div style={{ fontWeight: 700 }}>{r.tenant_name} <Pill color={PLAN_COLOR[r.tenant_plan]}>{r.tenant_plan}</Pill></div><div className="cu-sub">{r.user_name} · {r.user_email}</div></td>
                  <td style={td}>{r.category_label}</td>
                  <td style={td}><Pill color={PRIORITY_COLOR[r.priority]}>{r.priority}</Pill></td>
                  <td style={td}><Pill color={sc}>{sl}</Pill>{r.waiting_hours != null && <div className="cu-sub" style={{ color: r.past_target ? 'var(--danger)' : 'var(--warning)', fontWeight: 700 }}>{r.waiting_hours < 1 ? 'just arrived' : `waiting ${r.waiting_hours} h`}{r.past_target ? ' · late' : ''}</div>}</td>
                  <td style={td}>{r.assignee_name ? <span className="sp-who"><i>{initials(r.assignee_name)}</i>{r.assignee_name}</span> : <span className="cu-sub">Unassigned</span>}</td>
                  <td style={td}>{ago(r.last_message_at)}<div className="cu-sub">{r.last_from === 'customer' ? 'customer wrote last' : 'we wrote last'}</div></td>
                </tr>)
            })}
          </tbody>
        </table>
      </div>
      {pages > 1 && <div style={{ display: 'flex', gap: 10, justifyContent: 'center', marginTop: 12, alignItems: 'center' }}>
        <button className="btn btn-ghost btn-sm" disabled={f.page <= 1} onClick={() => setF({ ...f, page: f.page - 1 })}>← Prev</button><span>Page {f.page} of {pages} · {d.total} requests</span><button className="btn btn-ghost btn-sm" disabled={f.page >= pages} onClick={() => setF({ ...f, page: f.page + 1 })}>Next →</button></div>}
    </div>
  )
}
