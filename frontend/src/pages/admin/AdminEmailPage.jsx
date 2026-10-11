import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { Pill } from './AdminUi'
import { th, td, fmtDateTime, EMAIL_STATUS_COLOR } from './adminUtil'

/** TripSarthi's own e-mail marketing: who agreed to hear from us, and the campaigns sent to them. */
export default function AdminEmailPage() {
  const nav = useNavigate()
  const [d, setD] = useState(null)
  useEffect(() => { admin.emailOverview().then(setD).catch(e => toast.error('Could not load', e.message)) }, [])
  return (
    <div className="page">
      <div className="page-header"><h1 className="page-title">Email marketing</h1>
        <button className="btn btn-primary" onClick={() => nav('/admin/email/new')}>+ New campaign</button></div>

      <div className="card card-body" style={{ marginBottom: 14 }}>
        <div style={{ fontWeight: 700, marginBottom: 2 }}>Who can receive it</div>
        <div style={{ fontSize: 13, color: 'var(--text-3)', marginBottom: 10 }}>Only people who ticked “E-mail me product news and offers” (sign-up form, Profile, partner dashboard). Nobody is added on assumption, and every e-mail has a one-click unsubscribe link.</div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(210px,1fr))', gap: 10 }}>
          {d?.segments.map(s => (
            <div key={s.key} style={{ border: '1px solid var(--border)', borderRadius: 10, padding: '10px 12px' }}>
              <div style={{ fontSize: 24, fontWeight: 800 }}>{s.count}</div>
              <div style={{ fontWeight: 600 }}>{s.label}</div>
              <div style={{ fontSize: 12.5, color: 'var(--text-3)' }}>{s.description}</div>
            </div>))}
          {!d && <div style={{ color: 'var(--text-3)' }}>Loading…</div>}
        </div>
      </div>

      <div className="card" style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Campaign', 'Audience', 'Status', 'Recipients', 'Sent', 'Failed', 'Started'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {d && d.campaigns.length === 0 && <tr><td colSpan={7} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No campaigns yet. Create one, send yourself a test, then send it.</td></tr>}
            {d?.campaigns.map(c => (
              <tr key={c.id}>
                <td style={td}><Link to={`/admin/email/${c.id}`}><b>{c.name}</b></Link><div style={{ color: 'var(--text-3)', fontSize: 12 }}>{c.subject}</div></td>
                <td style={td}>{c.segment_label}</td>
                <td style={td}><Pill color={EMAIL_STATUS_COLOR[c.status]}>{c.status}</Pill></td>
                <td style={td}>{c.recipients || '—'}</td><td style={td}>{c.sent}</td>
                <td style={td}>{c.failed ? <span style={{ color: 'var(--danger)' }}>{c.failed}</span> : 0}</td>
                <td style={td}>{c.started_at ? fmtDateTime(c.started_at) : '—'}</td>
              </tr>))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
