import { useEffect, useState, useCallback } from 'react'
import { Link, useParams } from 'react-router-dom'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { Kpi, Pill } from './AdminUi'
import { th, td, fmtDate, fmtDateTime, SEVERITY_COLOR, PLAN_COLOR } from './adminUtil'
import { QualityDot } from './AdminWhatsappPage'

const TPL_COLOR = { approved: 'var(--success)', pending: 'var(--warning)', rejected: 'var(--danger)', draft: 'var(--text-3)', paused: 'var(--warning)', disabled: 'var(--text-3)' }
const num = (v) => Number(v) || 0

/** 30 daily bars: height = messages sent, red part = failed. */
function DailyBars({ daily }) {
  if (!daily.length) return <p style={{ color: 'var(--text-3)', margin: 0 }}>No messages in the last 30 days.</p>
  const max = Math.max(1, ...daily.map(d => num(d.sent)))
  return (
    <div style={{ display: 'flex', alignItems: 'flex-end', gap: 3, height: 110 }}>
      {daily.map(d => {
        const sent = num(d.sent), failed = num(d.failed), h = Math.max(3, Math.round(sent * 100 / max))
        return (
          <div key={d.d} title={`${d.d}: ${sent} sent, ${num(d.delivered)} delivered, ${failed} failed`} style={{ flex: 1, minWidth: 4, height: `${h}%`, background: 'var(--primary, #0a6cc4)', borderRadius: '3px 3px 0 0', position: 'relative', overflow: 'hidden' }}>
            {failed > 0 && <div style={{ position: 'absolute', bottom: 0, left: 0, right: 0, height: `${Math.min(100, failed * 100 / Math.max(1, sent))}%`, background: 'var(--danger)' }} />}
          </div>)
      })}
    </div>
  )
}

function PausePanel({ ws, onChanged }) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  async function pause(paused) {
    if (paused && reason.trim().length < 5) { toast.error('Give a reason', 'At least 5 characters — the workspace sees it.'); return }
    if (!window.confirm(paused ? `Stop ${ws.name} from sending MARKETING messages? Booking confirmations and other utility messages keep working.` : `Let ${ws.name} send marketing again?`)) return
    setBusy(true)
    try { await admin.waPause(ws.id, paused, reason); toast.success(paused ? 'Marketing paused' : 'Marketing resumed', ws.name); setReason(''); onChanged() }
    catch (e) { toast.error('Not changed', e.message) } finally { setBusy(false) }
  }
  return (
    <div className="card card-body" style={{ borderColor: ws.paused ? 'var(--warning)' : undefined }}>
      <div style={{ fontWeight: 700, marginBottom: 4 }}>Marketing sends {ws.paused ? <Pill color="var(--warning)">paused</Pill> : <Pill color="var(--success)">allowed</Pill>}</div>
      {ws.paused
        ? <><p style={{ margin: '0 0 8px', fontSize: 13 }}>Paused by TripSarthi: <b>{ws.paused_reason}</b>. The workspace sees this reason on every blocked campaign, drip and flow message.</p><button className="btn btn-primary" disabled={busy} onClick={() => pause(false)}>Resume marketing</button></>
        : <>
          <p style={{ margin: '0 0 8px', fontSize: 13, color: 'var(--text-3)' }}>Use this for abuse or a wave of spam reports. Only MARKETING is stopped — booking confirmations, payment reminders and replies inside the 24-hour window are never blocked.</p>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <input className="form-input" style={{ flex: '1 1 280px' }} value={reason} onChange={e => setReason(e.target.value)} placeholder="Reason (shown to the workspace and kept in the audit log)" maxLength={300} />
            <button className="btn btn-ghost" style={{ color: 'var(--danger)' }} disabled={busy} onClick={() => pause(true)}>Pause marketing</button>
          </div>
        </>}
    </div>
  )
}

export default function AdminWhatsappWorkspacePage() {
  const { id } = useParams()
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const load = useCallback(() => admin.waWorkspace(id).then(r => { setD(r); setErr('') }).catch(e => setErr(e.message || 'Not found')), [id])
  useEffect(() => { load() }, [load])

  if (err && !d) return <div className="page"><div className="card card-body" style={{ textAlign: 'center' }}><h3 style={{ marginTop: 0 }}>Workspace not found</h3><p style={{ color: 'var(--text-3)' }}>{err}</p><Link className="btn btn-primary" to="/admin/whatsapp">Back to WhatsApp</Link></div></div>
  if (!d) return <div className="page"><p style={{ color: 'var(--text-3)' }}>Loading…</p></div>
  const w = d.workspace
  return (
    <div className="page">
      <div style={{ marginBottom: 6 }}><Link to="/admin/whatsapp" style={{ fontSize: 13 }}>← WhatsApp</Link></div>
      <div className="page-header">
        <div>
          <h1 className="page-title" style={{ margin: 0 }}>{w.name} <Pill color={PLAN_COLOR[w.plan]}>{w.plan}</Pill></h1>
          <div style={{ color: 'var(--text-3)', fontSize: 13, marginTop: 2 }}>{d.owner ? `${d.owner.name} · ${d.owner.email} · ` : ''}WhatsApp {w.whatsapp === 'none' ? 'not connected' : w.whatsapp}{w.provider ? ` (${w.provider})` : ''} · <Link to={`/admin/customers?open=${w.id}`}>customer record</Link></div>
        </div>
      </div>

      {w.flags.length > 0 && <div className="card card-body" style={{ marginBottom: 14 }}>{w.flags.map(f => <div key={f.code} style={{ padding: '3px 0' }}><Pill color={SEVERITY_COLOR[f.severity]}>{f.severity}</Pill> {f.text}</div>)}</div>}

      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
        <Kpi label={`Sent · ${d.window_days} days`} value={w.sent.toLocaleString('en-IN')} sub={`${w.marketing.toLocaleString('en-IN')} marketing · ${w.received.toLocaleString('en-IN')} received`} />
        <Kpi label="Delivered" value={w.sent ? `${Math.round(w.delivered * 1000 / w.sent) / 10}%` : '—'} sub={`${w.read.toLocaleString('en-IN')} read`} accent="var(--success)" />
        <Kpi label="Failed" value={w.failure_pct == null ? '—' : `${w.failure_pct}%`} sub={`${w.failed.toLocaleString('en-IN')} messages`} accent={w.failure_pct >= 10 ? 'var(--danger)' : undefined} />
        <Kpi label="Campaigns" value={w.campaigns} sub={`last ${d.window_days} days`} />
        <Kpi label="Meta cost · this month" value={w.cost_this_month != null ? `₹${w.cost_this_month.toLocaleString('en-IN')}` : '—'} sub="synced billing" />
      </div>

      <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit,minmax(320px,1fr))', marginBottom: 14 }}>
        <PausePanel ws={w} onChanged={load} />
        <div className="card card-body">
          <div style={{ fontWeight: 700, marginBottom: 6 }}>Numbers</div>
          {w.numbers.length === 0 ? <span style={{ color: 'var(--text-3)' }}>No number connected.</span> : w.numbers.map(n => <div key={n.number} style={{ padding: '3px 0' }}><b>{n.number}</b> · <QualityDot q={n.quality} /></div>)}
          <div style={{ marginTop: 10, fontSize: 12.5, color: 'var(--text-3)' }}>Quality is synced from Meta every 6 hours. Yellow or red automatically pauses marketing until Meta restores green.</div>
        </div>
      </div>

      <div className="card card-body" style={{ marginBottom: 14 }}>
        <div style={{ fontWeight: 700, marginBottom: 10 }}>Messages per day <span style={{ fontWeight: 400, color: 'var(--text-3)', fontSize: 12.5 }}>(red = failed)</span></div>
        <DailyBars daily={d.daily} />
      </div>

      <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit,minmax(340px,1fr))', marginBottom: 14 }}>
        <div className="card">
          <div style={{ padding: '14px 16px 4px', fontWeight: 700 }}>Why messages failed</div>
          <div style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead><tr><th style={th}>Error from Meta</th><th style={th}>Messages</th></tr></thead>
            <tbody>
              {d.errors.length === 0 && <tr><td colSpan={2} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No failures with an error message.</td></tr>}
              {d.errors.map((e, i) => <tr key={i}><td style={td}>{e.err}</td><td style={td}><b>{e.n}</b></td></tr>)}
            </tbody>
          </table></div>
        </div>
        <div className="card">
          <div style={{ padding: '14px 16px 4px', fontWeight: 700 }}>Meta billing</div>
          <div style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead><tr>{['Month', 'Category', 'Messages', 'Cost'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
            <tbody>
              {d.billing.length === 0 && <tr><td colSpan={4} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No billing synced yet.</td></tr>}
              {d.billing.map((b, i) => <tr key={i}><td style={td}>{b.month}</td><td style={{ ...td, textTransform: 'capitalize' }}>{b.category}</td><td style={td}>{num(b.volume).toLocaleString('en-IN')}</td><td style={td}>{b.currency === 'INR' ? '₹' : `${b.currency} `}{num(b.cost).toLocaleString('en-IN')}</td></tr>)}
            </tbody>
          </table></div>
        </div>
      </div>

      <h3 style={{ margin: '18px 0 8px' }}>Templates ({d.templates.length})</h3>
      <div className="card" style={{ overflowX: 'auto', marginBottom: 14 }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Template', 'Category', 'Language', 'Status', 'Submitted', 'Meta’s reason'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {d.templates.length === 0 && <tr><td colSpan={6} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No templates.</td></tr>}
            {d.templates.map(t => <tr key={t.id}><td style={td}><b>{t.display_name || t.name}</b><div style={{ fontSize: 11.5, color: 'var(--text-3)' }}>{t.name}</div></td><td style={{ ...td, textTransform: 'capitalize' }}>{t.category}</td><td style={td}>{t.language}</td><td style={td}><Pill color={TPL_COLOR[t.meta_status]}>{t.meta_status}</Pill></td><td style={td}>{fmtDate(t.submitted_at)}</td><td style={td}>{t.rejection_reason || '—'}</td></tr>)}
          </tbody>
        </table>
      </div>

      <h3 style={{ margin: '18px 0 8px' }}>Recent campaigns</h3>
      <div className="card" style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Campaign', 'Template', 'Status', 'Audience', 'Sent', 'Delivered', 'Failed', 'Created'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {d.campaigns.length === 0 && <tr><td colSpan={8} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No campaigns.</td></tr>}
            {d.campaigns.map(c => <tr key={c.id}><td style={td}><b>{c.name}</b></td><td style={td}>{c.template || '—'}</td><td style={td}>{c.status}</td><td style={td}>{c.total_contacts}</td><td style={td}>{c.sent}</td><td style={td}>{c.delivered}</td><td style={{ ...td, color: num(c.failed) > 0 ? 'var(--danger)' : undefined }}>{c.failed}</td><td style={td}>{fmtDateTime(c.created_at)}</td></tr>)}
          </tbody>
        </table>
      </div>
    </div>
  )
}
