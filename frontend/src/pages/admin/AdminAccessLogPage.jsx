import { useCallback, useEffect, useMemo, useState } from 'react'
import { ShieldAlert, Activity, KeyRound, Pencil, Eye, Search } from 'lucide-react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { confirmAsk } from '../../components/confirmAsk'
import { Kpi, Pill } from './AdminUi'
import { th, td, ago, fmtDateTime } from './adminUtil'

const ENDED = { admin: 'Ended by admin', logout: 'Ended in the customer app', expired: 'Expired' }
const VIEWS = [['all', 'All'], ['live', 'Live now'], ['full', 'Full access'], ['view', 'View only'], ['changes', 'Made changes']]
const parse = (s) => new Date(String(s).replace(' ', 'T') + 'Z')
const inView = (r, v) => v === 'all' ? true : v === 'live' ? r.live : v === 'full' ? !r.read_only : v === 'view' ? r.read_only : r.actions > 0

/** Every "log in as customer" session: who, which workspace, why, how long, how many changes. */
export default function AdminAccessLogPage() {
  const [rows, setRows] = useState(null)
  const [asOf, setAsOf] = useState(0)
  const [view, setView] = useState('all')
  const [q, setQ] = useState('')
  const load = useCallback(() => admin.impersonations({ limit: 200 }).then(r => { setRows(r.rows); setAsOf(Date.now()) }).catch(e => toast.error('Could not load', e.message)), [])
  useEffect(() => { load() }, [load])

  async function end(r) {
    if (!(await confirmAsk(`End the session in ${r.tenant_name}? The person inside is signed out at once.`, { okLabel: 'End session', danger: true }))) return
    try { await admin.endImpersonation(r.id); toast.success('Session ended'); load() } catch (e) { toast.error('Could not end it', e.message) }
  }
  const mins = (r) => { const end = r.ended_at ? parse(r.ended_at) : new Date(); return Math.max(1, Math.round((end - parse(r.created_at)) / 60000)) }
  const len = (r) => { const m = mins(r); return m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${m % 60} min` }

  const k = useMemo(() => {
    const all = rows || [], week = all.filter(r => asOf - parse(r.created_at) < 7 * 86400000)
    return { live: all.filter(r => r.live).length, week: week.length, customers: new Set(week.map(r => r.tenant_id)).size, changes: all.reduce((a, r) => a + Number(r.actions || 0), 0), view: all.filter(r => r.read_only).length }
  }, [rows, asOf])
  const counts = useMemo(() => Object.fromEntries(VIEWS.map(([v]) => [v, (rows || []).filter(r => inView(r, v)).length])), [rows])
  const shown = useMemo(() => {
    const n = q.trim().toLowerCase()
    return (rows || []).filter(r => inView(r, view) && (!n || `${r.tenant_name} ${r.admin_name} ${r.reason} #${r.tenant_id}`.toLowerCase().includes(n)))
  }, [rows, view, q])

  return (
    <div className="page">
      <div className="page-header"><div><h1 className="page-title">Access log</h1><div style={{ color: 'var(--text-3)', fontSize: 13.5, maxWidth: 760 }}>Every time someone from TripSarthi signs in as a customer. Each entry also appears in that customer's own Audit log as “TripSarthi support”, with the reason, and every change made during it is tagged.</div></div></div>

      {k.live > 0 && <div className="wa-banner"><ShieldAlert size={18} /><div className="grow"><b>{k.live} support session{k.live === 1 ? ' is' : 's are'} open right now.</b> They end by themselves after 2 hours, or 30 minutes without activity.</div></div>}

      <div className="cu-kpis">
        <Kpi icon={Activity} tone={k.live ? '#f98f26' : '#1a9d57'} label="Live now" value={rows ? k.live : '…'} sub="open sessions" />
        <Kpi icon={KeyRound} tone="#0a6cc4" label="Sessions · 7 days" value={rows ? k.week : '…'} sub={rows ? `${k.customers} customer${k.customers === 1 ? '' : 's'}` : ''} />
        <Kpi icon={Pencil} tone="#12a89e" label="Changes made" value={rows ? k.changes : '…'} sub="in the sessions listed" />
        <Kpi icon={Eye} tone="#8393ad" label="View only" value={rows ? k.view : '…'} sub={rows ? `of ${rows.length} sessions` : ''} />
      </div>

      <div className="cu-chips">{VIEWS.map(([v, label]) => <button key={v} className={'cu-chip' + (view === v ? ' on' : '')} onClick={() => setView(v)}>{label} <b>{rows ? counts[v] : '…'}</b></button>)}</div>
      <div className="cu-filters"><label className="cu-search"><Search size={16} /><input placeholder="Search customer, admin or reason" value={q} onChange={e => setQ(e.target.value)} /></label></div>

      <div className="card" style={{ overflowX: 'auto' }}>
        <table className="cu-table plain" style={{ minWidth: 900 }}>
          <thead><tr>{['When', 'Customer', 'By', 'Reason', 'Mode', 'Length', 'Changes', 'Status', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {rows === null && <tr><td style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }} colSpan={9}>Loading…</td></tr>}
            {rows && shown.length === 0 && <tr><td style={{ ...td, textAlign: 'center', color: 'var(--text-3)', padding: 28 }} colSpan={9}>{rows.length === 0 ? 'No customer sign-ins yet.' : 'No sessions in this view.'}</td></tr>}
            {shown.map(r => (
              <tr key={r.id} className={r.live ? 'sp-wait' : ''}>
                <td style={td}>{fmtDateTime(r.created_at)}<div className="cu-sub">{ago(r.created_at)} · {r.ip}</div></td>
                <td style={td}><div className="cu-name">{r.tenant_name}</div><div className="cu-sub">#{r.tenant_id}</div></td>
                <td style={td}>{r.admin_name}</td>
                <td style={{ ...td, maxWidth: 320, whiteSpace: 'normal' }}>{r.reason}</td>
                <td style={td}>{r.read_only ? <Pill>view only</Pill> : <Pill color="#f98f26">full access</Pill>}</td>
                <td style={td}>{len(r)}</td>
                <td style={td}>{r.actions > 0 ? <b>{r.actions}</b> : <span style={{ color: 'var(--text-3)' }}>0</span>}</td>
                <td style={td}>{r.live ? <Pill color="#1a9d57"><ShieldAlert size={11} /> live now</Pill> : <span style={{ color: 'var(--text-3)' }}>{ENDED[r.ended_by] ?? 'Expired'}</span>}</td>
                <td style={td}>{r.live && <button className="btn btn-ghost btn-sm" onClick={() => end(r)}>End</button>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
