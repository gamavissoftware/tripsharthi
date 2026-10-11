import { useCallback, useEffect, useState } from 'react'
import { ShieldAlert } from 'lucide-react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { confirmAsk } from '../../components/confirmAsk'
import { Pill } from './AdminUi'
import { th, td, fmtDate } from './adminUtil'

const ENDED = { admin: 'Ended by admin', logout: 'Ended in the customer app', expired: 'Expired' }

/** Every "log in as customer" session: who, which workspace, why, how long, how many changes. */
export default function AdminAccessLogPage() {
  const [rows, setRows] = useState(null)
  const load = useCallback(() => admin.impersonations({ limit: 200 }).then(r => setRows(r.rows)).catch(e => toast.error('Could not load', e.message)), [])
  useEffect(() => { load() }, [load])

  async function end(r) {
    if (!(await confirmAsk(`End the session in ${r.tenant_name}? The person inside is signed out at once.`, { okLabel: 'End session', danger: true }))) return
    try { await admin.endImpersonation(r.id); toast.success('Session ended'); load() } catch (e) { toast.error('Could not end it', e.message) }
  }
  const mins = (r) => { const end = r.ended_at ? new Date(r.ended_at.replace(' ', 'T') + 'Z') : new Date(); return Math.max(1, Math.round((end - new Date(r.created_at.replace(' ', 'T') + 'Z')) / 60000)) }

  return (
    <div className="page">
      <div className="page-header"><h1 className="page-title">Access log</h1></div>
      <p style={{ color: 'var(--text-2)', maxWidth: 760 }}>Every time someone from TripSarthi signs in as a customer. Each entry also appears in that customer's own Audit log as "TripSarthi support", with the reason, and every change made during it is tagged.</p>
      <div className="card" style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr><th style={th}>When</th><th style={th}>Customer</th><th style={th}>By</th><th style={th}>Reason</th><th style={th}>Mode</th><th style={th}>Length</th><th style={th}>Changes</th><th style={th}>Status</th><th style={th}></th></tr></thead>
          <tbody>
            {rows === null && <tr><td style={td} colSpan={9}>Loading…</td></tr>}
            {rows?.length === 0 && <tr><td style={td} colSpan={9}>No customer sign-ins yet.</td></tr>}
            {rows?.map(r => (
              <tr key={r.id}>
                <td style={td}>{fmtDate(r.created_at)}<div style={{ fontSize: 12, color: 'var(--text-3)' }}>{r.ip}</div></td>
                <td style={td}><b>{r.tenant_name}</b> <span style={{ color: 'var(--text-3)' }}>#{r.tenant_id}</span></td>
                <td style={td}>{r.admin_name}</td>
                <td style={{ ...td, maxWidth: 300 }}>{r.reason}</td>
                <td style={td}>{r.read_only ? <Pill>view only</Pill> : <Pill color="#f98f26">full access</Pill>}</td>
                <td style={td}>{mins(r)} min</td>
                <td style={td}>{r.actions}</td>
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
