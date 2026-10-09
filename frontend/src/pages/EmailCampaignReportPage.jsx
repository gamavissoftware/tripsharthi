import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, Pause, Play, XCircle, Copy, AlertTriangle, Repeat, Download } from 'lucide-react'
import { emailMarketing } from '../api/emailMarketing'
import { toast } from '../components/Toast'
import SequenceDialog from '../components/email/SequenceDialog'
import { audienceLabel, EMAIL_STATUS_BADGE, EMAIL_STATUS_LABEL, fmtUtc } from '../components/email/emailShared'

const FILTERS = [
  ['all', 'All'], ['sent', 'Sent'], ['opened', 'Opened'], ['clicked', 'Clicked'], ['replied', 'Replied'],
  ['not_opened', 'Not opened'], ['unsubscribed', 'Unsubscribed'], ['failed', 'Failed'],
]

export default function EmailCampaignReportPage() {
  const { id } = useParams()
  const [c, setC]           = useState(null)
  const [filter, setFilter] = useState('all')
  const [search, setSearch] = useState('')
  const [page, setPage]     = useState(1)
  const [list, setList]     = useState(null)
  const [showBody, setShowBody] = useState(false)
  const [showSeq, setShowSeq]   = useState(false)

  function load() {
    emailMarketing.campaign(id).then(r => setC(r.data)).catch(e => toast.error('Campaign not found', e.message))
  }
  useEffect(load, [id])

  useEffect(() => {
    const t = setTimeout(() => {
      emailMarketing.recipients(id, { filter, page, search }).then(r => setList(r.data)).catch(() => {})
    }, 200)
    return () => clearTimeout(t)
  }, [id, filter, page, search, c?.funnel?.recipients])

  useEffect(() => {
    if (c?.status !== 'processing') return
    const t = setInterval(load, 10000)
    return () => clearInterval(t)
  }, [c?.status]) // eslint-disable-line react-hooks/exhaustive-deps

  async function act(fn, msg) {
    try { await fn(); toast.success(msg); load() } catch (e) { toast.error('Action failed', e.message) }
  }

  if (!c) return <div className="page text-muted">Loading…</div>
  const f = c.funnel
  const progress = c.total_contacts > 0 ? Math.min(100, Math.round(f.recipients / c.total_contacts * 100)) : 0
  const skipped = (c.stats?.skipped_no_email ?? 0) + (c.stats?.skipped_suppressed ?? 0) + (c.stats?.skipped_duplicate ?? 0)

  return (
    <div className="page">
      <div className="page-header">
        <div>
          <Link to="/email" className="text-muted text-sm flex items-center gap-2"><ArrowLeft size={14} /> Email campaigns</Link>
          <h1 className="page-title mt-1">{c.name}</h1>
          <p className="text-muted mt-1 text-sm">
            <span className={`badge ${EMAIL_STATUS_BADGE[c.status] ?? 'badge-draft'}`}>{EMAIL_STATUS_LABEL[c.status] ?? c.status}</span>
            {' '}· “{c.subject}” · {audienceLabel(c.segment)} · started {fmtUtc(c.started_at)}
            {c.completed_at && <> · finished {fmtUtc(c.completed_at)}</>}
          </p>
        </div>
        <div className="flex items-center gap-2" style={{ flexWrap: 'wrap' }}>
          {c.status === 'processing' && <button className="btn btn-ghost" onClick={() => act(() => emailMarketing.pause(id), 'Paused')}><Pause size={15} /> Pause</button>}
          {c.status === 'paused' && <button className="btn btn-primary" onClick={() => act(() => emailMarketing.resume(id), 'Resumed')}><Play size={15} /> Resume</button>}
          {['processing', 'paused'].includes(c.status) && (
            <button className="btn btn-ghost" onClick={() => { if (confirm('Stop this campaign? Contacts not yet emailed will not receive it.')) act(() => emailMarketing.cancel(id), 'Cancelled') }}><XCircle size={15} /> Cancel</button>
          )}
          {!c.segment?.followup_of && ['processing', 'paused', 'done'].includes(c.status) && (
            <button className="btn btn-primary" onClick={() => setShowSeq(true)}><Repeat size={15} /> Add follow-up sequence</button>
          )}
          <button className="btn btn-ghost" onClick={() => act(() => emailMarketing.duplicate(id), 'Copied to a new draft')}><Copy size={15} /> Duplicate</button>
        </div>
      </div>

      {showSeq && <SequenceDialog campaign={c} onClose={() => setShowSeq(false)} onCreated={() => setShowSeq(false)} />}

      {c.last_error && c.status !== 'done' && (
        <div className="card mb-4" style={{ borderColor: '#f59e0b' }}>
          <div className="card-body flex items-start gap-3 text-sm">
            <AlertTriangle size={18} style={{ color: '#d97706', flexShrink: 0 }} />
            <div>{c.last_error} {c.status === 'paused' && <>Fix it in <Link to="/email?tab=settings">sending settings</Link>, then resume — nobody is emailed twice.</>}</div>
          </div>
        </div>
      )}

      {['processing', 'paused'].includes(c.status) && c.total_contacts > 0 && (
        <div className="card mb-4"><div className="card-body">
          <div className="flex justify-between text-sm mb-2"><span>Progress</span><span className="text-muted">{f.recipients.toLocaleString()} of ~{Number(c.total_contacts).toLocaleString()}</span></div>
          <div className="progress-track"><div className="progress-fill" style={{ width: `${progress}%` }} /></div>
        </div></div>
      )}

      <div className="mb-4" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 12 }}>
        <Metric label="Sent" value={f.sent} />
        <Metric label="Opened" value={f.opened} rate={f.open_rate} />
        <Metric label="Clicked" value={f.clicked} rate={f.click_rate} />
        <Metric label="Replied" value={f.replied ?? 0} rate={f.reply_rate ?? 0} />
        <Metric label="Unsubscribed" value={f.unsubscribed} rate={f.unsubscribe_rate} />
        <Metric label="Failed" value={f.failed} danger={f.failed > 0} />
        <Metric label="Skipped" value={skipped} hint={`${c.stats?.skipped_no_email ?? 0} invalid email · ${c.stats?.skipped_suppressed ?? 0} unsubscribed · ${c.stats?.skipped_duplicate ?? 0} shared address`} />
      </div>
      <p className="text-muted text-sm mb-4" style={{ marginTop: -4 }}>
        Opens are approximate: Apple Mail pre-loads images (inflating opens) and some clients block them. Clicks are reliable.
      </p>

      {c.links?.length > 0 && (
        <div className="card mb-4" style={{ overflowX: 'auto' }}>
          <div className="card-body" style={{ paddingBottom: 0 }}><strong>Link clicks</strong></div>
          <table className="data-table">
            <thead><tr><th>URL</th><th style={{ textAlign: 'right' }}>Clicks</th><th style={{ textAlign: 'right' }}>Unique</th></tr></thead>
            <tbody>
              {c.links.map(l => (
                <tr key={l.url}>
                  <td style={{ maxWidth: 520, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}><a href={l.url} target="_blank" rel="noreferrer">{l.url}</a></td>
                  <td style={{ textAlign: 'right' }}>{l.clicks}</td>
                  <td style={{ textAlign: 'right' }}>{l.unique_clicks}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <div className="card mb-4">
        <div className="card-body">
          <div className="flex items-center justify-between gap-2 mb-4" style={{ flexWrap: 'wrap' }}>
            <div className="flex gap-2" style={{ flexWrap: 'wrap' }}>
              {FILTERS.map(([k, l]) => (
                <button key={k} className={`btn btn-sm ${filter === k ? 'btn-primary' : 'btn-ghost'}`} onClick={() => { setFilter(k); setPage(1) }}>{l}</button>
              ))}
            </div>
            <div className="flex gap-2" style={{ flexWrap: 'wrap', minWidth: 0 }}>
              <input className="form-input" placeholder="Search name or email…" value={search} onChange={e => { setSearch(e.target.value); setPage(1) }} style={{ width: 220, maxWidth: '100%' }} />
              <button className="btn btn-ghost btn-sm" title="Download this list as CSV"
                      onClick={() => emailMarketing.exportRecipients(id, filter, search).catch(e => toast.error('Export failed', e.message))}>
                <Download size={14} /> Export CSV
              </button>
            </div>
          </div>
          {!list ? <div className="text-muted">Loading…</div> : list.rows.length === 0 ? (
            <div className="text-muted text-sm" style={{ padding: 20, textAlign: 'center' }}>No recipients here.</div>
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table className="data-table">
                <thead><tr><th>Recipient</th><th>Status</th><th>Sent</th><th>Opened</th><th>Clicked</th><th>Replied</th></tr></thead>
                <tbody>
                  {list.rows.map(r => (
                    <tr key={r.id}>
                      <td>
                        {r.contact_id ? <Link to={`/contacts/${r.contact_id}`}>{r.contact_name || r.to_email}</Link> : r.to_email}
                        <div className="text-muted" style={{ fontSize: 12 }}>{r.to_email}</div>
                      </td>
                      <td className="text-sm">
                        {r.unsubscribed_at ? <span className="badge badge-lost">unsubscribed</span>
                          : <span className={`badge ${r.status === 'sent' ? 'badge-active' : r.status === 'failed' ? 'badge-lost' : 'badge-draft'}`}>{r.status}</span>}
                        {r.error && <div style={{ fontSize: 11, color: '#dc2626', maxWidth: 280 }}>{r.error}</div>}
                      </td>
                      <td className="text-sm text-muted">{fmtUtc(r.sent_at)}</td>
                      <td className="text-sm">{r.opened_at ? <>{fmtUtc(r.opened_at)}{r.open_count > 1 && <span className="text-muted"> ×{r.open_count}</span>}</> : '—'}</td>
                      <td className="text-sm">{r.clicked_at ? <>{fmtUtc(r.clicked_at)}{r.click_count > 1 && <span className="text-muted"> ×{r.click_count}</span>}</> : '—'}</td>
                      <td className="text-sm">{r.replied_at ? <strong style={{ color: '#16a34a' }}>{fmtUtc(r.replied_at)}</strong> : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {list && list.total > list.per_page && (
            <div className="flex items-center justify-between mt-2 text-sm">
              <span className="text-muted">{list.total.toLocaleString()} recipients</span>
              <div className="flex gap-2">
                <button className="btn btn-ghost btn-sm" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Previous</button>
                <button className="btn btn-ghost btn-sm" disabled={page * list.per_page >= list.total} onClick={() => setPage(p => p + 1)}>Next</button>
              </div>
            </div>
          )}
        </div>
      </div>

      <div className="card">
        <div className="card-body">
          <button className="btn btn-ghost btn-sm" onClick={() => setShowBody(s => !s)}>{showBody ? 'Hide' : 'Show'} the email that was sent</button>
          {showBody && <iframe title="Sent email" sandbox="" srcDoc={c.html_body} style={{ width: '100%', height: 560, border: '1px solid #e5e7eb', borderRadius: 6, marginTop: 12 }} />}
        </div>
      </div>
    </div>
  )
}

function Metric({ label, value, rate, danger, hint }) {
  return (
    <div className="card" title={hint}>
      <div className="card-body">
        <div className="text-muted" style={{ fontSize: 12 }}>{label}</div>
        <div style={{ fontSize: 22, fontWeight: 700, marginTop: 4, color: danger ? '#dc2626' : undefined }}>
          {Number(value ?? 0).toLocaleString()}
          {rate !== undefined && <span className="text-muted" style={{ fontSize: 13, fontWeight: 500 }}> {rate}%</span>}
        </div>
      </div>
    </div>
  )
}
