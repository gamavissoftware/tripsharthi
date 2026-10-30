import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { admin } from '../../api/admin'

/**
 * Wraps a customer-app page (Leads, Pipeline, Tasks, Automations...) so it runs against TripSarthi's marketing workspace inside the admin app.
 * Same mechanism as AdminWaModulePage: the admin session is accepted for that workspace only (MarketingWorkspaceAccess); this guard explains
 * what is missing instead of showing pages full of errors.
 */
export default function AdminCrmGate({ title, children }) {
  const [st, setSt] = useState(undefined)
  useEffect(() => { admin.waMarketing().then(d => setSt(d.status)).catch(() => setSt(null)) }, [])
  if (st === undefined) return <div className="page"><div className="card card-body" style={{ color: 'var(--text-3)' }}>Loading…</div></div>
  if (!st?.workspace) {
    return (
      <div className="page">
        <div className="page-header"><h1 className="page-title">{title}</h1></div>
        <div className="wa-banner bad"><div className="grow"><b>No marketing workspace chosen yet.</b> TripSarthi's own CRM runs inside one workspace — choose it first.</div><Link className="btn btn-primary btn-sm" to="/admin/whatsapp">Choose the workspace</Link></div>
      </div>)
  }
  return (
    <div className="ad-wa-module">
      <div className="wa-banner ok" style={{ margin: '0 0 10px', padding: '7px 12px', fontSize: 13 }}>
        <div className="grow">TripSarthi CRM · workspace <b>{st.workspace.name}</b>. Changes you make here are tagged “TripSarthi support” in that workspace's audit log.</div>
        <Link to="/admin/crm" style={{ fontSize: 13 }}>CRM dashboard →</Link>
      </div>
      {children}
    </div>
  )
}
