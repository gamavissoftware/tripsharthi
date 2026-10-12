import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Users, FileEdit, Send, XCircle, Clock, Search, MailWarning } from 'lucide-react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { Kpi, Pill } from './AdminUi'
import { th, td, ago, EMAIL_STATUS_COLOR } from './adminUtil'

const VIEWS = [['all', 'All'], ['draft', 'Drafts'], ['sending', 'Sending'], ['sent', 'Sent'], ['cancelled', 'Stopped']]

/** The share of a campaign's recipients that was sent / failed / skipped / is still waiting, as one stacked bar. */
export function SendBar({ c }) {
  const total = Math.max(1, c.recipients)
  const w = (n) => `${(n * 100) / total}%`
  return (
    <div className="em-prog" title={`${c.sent} sent · ${c.failed} failed · ${c.skipped} skipped · ${c.waiting} waiting`}>
      <i className="s" style={{ width: w(c.sent) }} /><i className="f" style={{ width: w(c.failed) }} /><i className="k" style={{ width: w(c.skipped) }} />
    </div>
  )
}

/** TripSarthi's own e-mail marketing: who agreed to hear from us, and the campaigns sent to them. */
export default function AdminEmailPage() {
  const nav = useNavigate()
  const [d, setD] = useState(null)
  const [view, setView] = useState('all')
  const [q, setQ] = useState('')
  useEffect(() => { admin.emailOverview().then(setD).catch(e => toast.error('Could not load', e.message)) }, [])
  const sm = d?.summary
  const counts = useMemo(() => Object.fromEntries(VIEWS.map(([k]) => [k, (d?.campaigns || []).filter(c => k === 'all' || c.status === k).length])), [d])
  const shown = useMemo(() => {
    const needle = q.trim().toLowerCase()
    return (d?.campaigns || []).filter(c => (view === 'all' || c.status === view) && (!needle || `${c.name} ${c.subject}`.toLowerCase().includes(needle)))
  }, [d, view, q])
  const maxSeg = Math.max(1, ...(d?.segments || []).map(s => s.count))
  return (
    <div className="page">
      <div className="page-header">
        <div><h1 className="page-title">Email marketing</h1><div style={{ color: 'var(--text-3)', fontSize: 13.5 }}>Product news and offers to people who asked for them — with a one-click unsubscribe in every e-mail.</div></div>
        <button className="btn btn-primary" onClick={() => nav('/admin/email/new')}>+ New campaign</button>
      </div>

      {sm && !sm.mail_ready && <div className="wa-banner bad"><MailWarning size={18} /><div className="grow"><b>No mail server is set up.</b> Campaigns cannot be delivered until you add one.</div><Link className="btn btn-primary btn-sm" to="/admin/mail">Set up mail server</Link></div>}
      {sm && sm.queued > 0 && <div className="wa-banner"><Clock size={18} /><div className="grow"><b>{sm.queued} e-mail{sm.queued === 1 ? '' : 's'} still queued.</b> They go out about {sm.per_minute} a minute, so this takes roughly {Math.max(1, Math.ceil(sm.queued / sm.per_minute))} min.</div></div>}

      <div className="cu-kpis">
        <Kpi icon={Users} tone="#0a6cc4" label="People who agreed" value={sm ? sm.opted_in.toLocaleString('en-IN') : '…'} sub="owners and partners, one per address" />
        <Kpi icon={FileEdit} tone="#f98f26" label="Campaigns" value={sm ? sm.campaigns : '…'} sub={sm ? `${sm.drafts} draft${sm.drafts === 1 ? '' : 's'}` : ''} />
        <Kpi icon={Send} tone="#1a9d57" label="Sent · 30 days" value={sm ? sm.sent_30d.toLocaleString('en-IN') : '…'} sub="e-mails delivered to the mail server" />
        <Kpi icon={XCircle} tone={sm && sm.failure_pct >= 5 ? '#e5484d' : '#8393ad'} label="Failed · 30 days" value={sm ? (sm.failure_pct === null ? '—' : `${sm.failure_pct}%`) : '…'} sub={sm ? `${sm.failed_30d} e-mail${sm.failed_30d === 1 ? '' : 's'}` : ''} accent={sm && sm.failure_pct >= 5 ? 'var(--danger)' : undefined} />
      </div>

      <div style={{ fontWeight: 800, color: '#0a1f44', fontSize: 16, marginBottom: 2 }}>Who can receive it</div>
      <div style={{ fontSize: 13, color: 'var(--text-3)', marginBottom: 10 }}>Only people who ticked “E-mail me product news and offers” (sign-up form, Profile, partner dashboard). Nobody is added on assumption.</div>
      <div className="wa-segs" style={{ marginBottom: 18 }}>
        {d?.segments.map(s => (
          <div key={s.key} className="wa-seg">
            <div className="n">{s.count}</div><div className="t">{s.label}</div><div className="d">{s.description}</div>
            <span className="wa-meter"><i className="blue" style={{ width: `${(s.count * 100) / maxSeg}%` }} /></span>
          </div>))}
        {!d && <div style={{ color: 'var(--text-3)' }}>Loading…</div>}
      </div>

      <div className="cu-chips">
        {VIEWS.map(([k, label]) => <button key={k} className={'cu-chip' + (view === k ? ' on' : '')} onClick={() => setView(k)}>{label} <b>{d ? counts[k] : '…'}</b></button>)}
      </div>
      <div className="cu-filters"><label className="cu-search"><Search size={16} /><input placeholder="Search campaigns by name or subject" value={q} onChange={e => setQ(e.target.value)} /></label></div>

      <div className="card" style={{ overflowX: 'auto' }}>
        <table className="cu-table" style={{ minWidth: 860 }}>
          <thead><tr>{['Campaign', 'Audience', 'Status', 'Delivery', 'Started'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {!d && <tr><td colSpan={5} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Loading…</td></tr>}
            {d && shown.length === 0 && <tr><td colSpan={5} style={{ ...td, textAlign: 'center', color: 'var(--text-3)', padding: 28 }}>{d.campaigns.length === 0 ? 'No campaigns yet. Create one, send yourself a test, then send it.' : 'No campaigns in this view.'}</td></tr>}
            {shown.map(c => (
              <tr key={c.id} onClick={() => nav(`/admin/email/${c.id}`)}>
                <td style={td}><div className="cu-name"><Link to={`/admin/email/${c.id}`} onClick={e => e.stopPropagation()}>{c.name}</Link></div><div className="cu-sub">{c.subject}</div></td>
                <td style={td}>{c.segment_label}</td>
                <td style={td}><Pill color={EMAIL_STATUS_COLOR[c.status]}>{c.status === 'cancelled' ? 'stopped' : c.status}</Pill></td>
                <td style={{ ...td, minWidth: 190 }}>
                  {c.recipients ? <><div style={{ fontSize: 13 }}><b>{c.sent}</b> of {c.recipients} sent{c.failed > 0 && <span style={{ color: 'var(--danger)' }}> · {c.failed} failed</span>}{c.waiting > 0 && <span style={{ color: 'var(--text-3)' }}> · {c.waiting} waiting</span>}</div><SendBar c={c} /></> : <span style={{ color: 'var(--text-3)' }}>not sent yet</span>}
                </td>
                <td style={td}>{c.started_at ? <>{ago(c.started_at)}</> : '—'}</td>
              </tr>))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
