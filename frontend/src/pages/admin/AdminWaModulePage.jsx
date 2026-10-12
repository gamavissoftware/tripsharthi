import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { admin } from '../../api/admin'
import InboxPage from '../InboxPage'
import CampaignsPage from '../CampaignsPage'
import TemplatesPage from '../TemplatesPage'

/**
 * TripSarthi's OWN WhatsApp, inside the admin app: the customer app's Inbox, Campaigns and Templates pages, run against the marketing workspace
 * (chosen on WhatsApp -> Our marketing). The admin session is accepted for that workspace only (see MarketingWorkspaceAccess); this guard just
 * explains what is missing instead of showing pages full of errors.
 */
const PAGES = { inbox: [InboxPage, 'WhatsApp inbox'], campaigns: [CampaignsPage, 'WhatsApp campaigns'], templates: [TemplatesPage, 'WhatsApp templates'] }

export default function AdminWaModulePage({ kind }) {
  const [st, setSt] = useState(undefined)   // undefined = loading
  useEffect(() => { admin.waMarketing().then(d => setSt(d.status)).catch(() => setSt(null)) }, [])
  const [Page, title] = PAGES[kind]
  if (st === undefined) return <div className="page"><div className="card card-body" style={{ color: 'var(--text-3)' }}>Loading…</div></div>
  if (!st?.workspace) {
    return (
      <div className="page">
        <div className="page-header"><h1 className="page-title">{title}</h1></div>
        <div className="wa-banner bad"><div className="grow"><b>No marketing workspace chosen yet.</b> TripSarthi's own WhatsApp runs inside one workspace — choose it first.</div><Link className="btn btn-primary btn-sm" to="/admin/whatsapp">Choose the workspace</Link></div>
      </div>)
  }
  return (
    <div className="ad-wa-module">
      <div className="wa-banner ok" style={{ margin: '0 0 10px', padding: '7px 12px', fontSize: 13 }}>
        <div className="grow">TripSarthi's own WhatsApp · workspace <b>{st.workspace.name}</b>{st.numbers[0] ? ` · ${st.numbers[0].number}` : ' · no number connected'}. Changes you make here are tagged “TripSarthi support” in that workspace's audit log.</div>
        <Link to="/admin/whatsapp" style={{ fontSize: 13 }}>Readiness &amp; audiences →</Link>
      </div>
      <Page />
    </div>
  )
}
