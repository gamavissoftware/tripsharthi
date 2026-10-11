import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import LoadFailed from '../../components/LoadFailed'
import { Pill, Kpi } from './AdminUi'
import { EMAIL_STATUS_COLOR } from './adminUtil'

const BLANK = { name: '', subject: '', body: '', segment: 'owners' }

/** Write, preview, test and send one campaign; afterwards it shows how the send went. */
export default function AdminEmailCampaignPage() {
  const { id } = useParams()
  const nav = useNavigate()
  const isNew = !id
  const [c, setC] = useState(isNew ? { ...BLANK, status: 'draft' } : null)
  const [segs, setSegs] = useState([])
  const [failed, setFailed] = useState(false)
  const [preview, setPreview] = useState(null)
  const [busy, setBusy] = useState('')

  useEffect(() => {
    admin.emailOverview().then(o => setSegs(o.segments)).catch(() => {})
    if (id) admin.emailCampaign(id).then(setC).catch(() => setFailed(true))
  }, [id])

  // keep the progress fresh while a campaign is sending
  useEffect(() => {
    if (c?.status !== 'sending' || !id) return undefined
    const t = setInterval(() => admin.emailCampaign(id).then(setC).catch(() => {}), 10000)
    return () => clearInterval(t)
  }, [c?.status, id])

  const editable = c?.status === 'draft'
  const set = (k, v) => setC(x => ({ ...x, [k]: v }))
  const fields = () => ({ name: c.name, subject: c.subject, body: c.body, segment: c.segment })
  const audience = segs.find(s => s.key === c?.segment)

  async function save() {
    setBusy('save')
    try {
      const r = await admin.emailSave(id, fields())
      toast.success('Saved'); setC(r)
      if (isNew) nav(`/admin/email/${r.id}`, { replace: true })
      return r
    } catch (err) { toast.error('Not saved', err.message) } finally { setBusy('') }
    return null
  }
  async function doPreview() { const r = await save(); if (!r) return; try { setPreview(await admin.emailPreview(r.id)) } catch (err) { toast.error('No preview', err.message) } }
  async function test() { const r = await save(); if (!r) return; setBusy('test'); try { const t = await admin.emailTest(r.id); toast.success('Test sent', `Check ${t.sent_to}`) } catch (err) { toast.error('Test failed', err.message) } finally { setBusy('') } }
  async function send() {
    const r = await save(); if (!r) return
    let n = audience?.count ?? 0
    try { const o = await admin.emailOverview(); setSegs(o.segments); n = o.segments.find(s => s.key === r.segment)?.count ?? 0 } catch { /* use the count we have */ }
    if (n === 0) { toast.error('Nobody to send to', 'No one in this audience has agreed to e-mails yet.'); return }
    if (!window.confirm(`Send “${r.subject}” to ${n} ${n === 1 ? 'person' : 'people'} in “${r.segment_label}”?\n\nIt goes out gradually (about 30 a minute) and cannot be recalled once sent.`)) return
    setBusy('send')
    try { setC(await admin.emailSend(r.id, n)); toast.success('Sending started') } catch (err) { toast.error('Not sent', err.message) } finally { setBusy('') }
  }
  async function cancel() {
    if (!window.confirm('Stop sending? E-mails already sent cannot be recalled.')) return
    try { setC(await admin.emailCancel(id)); toast.success('Stopped') } catch (err) { toast.error('Could not stop', err.message) }
  }
  async function remove() {
    if (!window.confirm('Delete this draft?')) return
    try { await admin.emailDelete(id); nav('/admin/email') } catch (err) { toast.error('Not deleted', err.message) }
  }

  if (failed) return <LoadFailed what="campaign" backTo="/admin/email" />
  if (!c) return <div className="page"><div className="card card-body" style={{ color: 'var(--text-3)' }}>Loading…</div></div>

  return (
    <div className="page">
      <div style={{ marginBottom: 8 }}><Link to="/admin/email">← Email marketing</Link></div>
      <div className="page-header"><h1 className="page-title">{isNew ? 'New campaign' : c.name}</h1>{!isNew && <Pill color={EMAIL_STATUS_COLOR[c.status]}>{c.status}</Pill>}</div>

      {!editable && (
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
          <Kpi label="Recipients" value={c.recipients} sub={c.segment_label} />
          <Kpi label="Sent" value={c.sent} accent="var(--success)" />
          <Kpi label="Waiting" value={c.waiting} />
          <Kpi label="Failed" value={c.failed} accent={c.failed ? 'var(--danger)' : undefined} />
          <Kpi label="Skipped" value={c.skipped} sub="opted out meanwhile" />
        </div>)}
      {c.status === 'sending' && <div style={{ marginBottom: 12 }}><button className="btn btn-ghost" style={{ color: 'var(--danger)' }} onClick={cancel}>Stop sending</button></div>}
      {c.failures?.length > 0 && <div className="card card-body" style={{ marginBottom: 12, fontSize: 13 }}><b>Failures</b>{c.failures.map(f => <div key={f.email} style={{ color: 'var(--text-3)' }}>{f.email} — {f.error}</div>)}</div>}

      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0,1fr) minmax(0,1fr)', gap: 14, alignItems: 'start' }} className="admin-email-grid">
        <div className="card card-body" style={{ display: 'grid', gap: 12 }}>
          <label>Campaign name <span style={{ color: 'var(--text-3)' }}>(only you see it)</span>
            <input className="form-input" value={c.name} onChange={e => set('name', e.target.value)} disabled={!editable} maxLength={120} /></label>
          <label>Send to
            <select className="form-select" value={c.segment} onChange={e => set('segment', e.target.value)} disabled={!editable}>
              {(segs.length ? segs : [{ key: c.segment, label: c.segment_label || c.segment, count: 0 }]).map(s => <option key={s.key} value={s.key}>{s.label} ({s.count} opted in)</option>)}</select></label>
          <label>Subject
            <input className="form-input" value={c.subject} onChange={e => set('subject', e.target.value)} disabled={!editable} maxLength={150} placeholder="Hi {{first_name}}, …" /></label>
          <label>E-mail text <span style={{ color: 'var(--text-3)' }}>(blank line = new paragraph · {'{{first_name}}'} = their first name · web addresses become links)</span>
            <textarea className="form-input" rows={14} value={c.body} onChange={e => set('body', e.target.value)} disabled={!editable} maxLength={20000} style={{ fontFamily: 'inherit' }} /></label>
          {editable && (
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              <button className="btn btn-ghost" onClick={save} disabled={busy !== ''}>{busy === 'save' ? 'Saving…' : 'Save draft'}</button>
              <button className="btn btn-ghost" onClick={doPreview} disabled={busy !== ''}>Preview</button>
              <button className="btn btn-ghost" onClick={test} disabled={busy !== ''}>{busy === 'test' ? 'Sending…' : 'Send me a test'}</button>
              <button className="btn btn-primary" onClick={send} disabled={busy !== ''}>{busy === 'send' ? 'Starting…' : 'Send to audience…'}</button>
              {!isNew && <button className="btn btn-ghost" style={{ color: 'var(--danger)' }} onClick={remove}>Delete</button>}
            </div>)}
        </div>

        <div className="card card-body">
          <div style={{ fontWeight: 700, marginBottom: 6 }}>Preview</div>
          {!preview && <div style={{ color: 'var(--text-3)', fontSize: 13 }}>Press “Preview” to see exactly what people will receive.</div>}
          {preview && (<>
            <div style={{ fontSize: 13, color: 'var(--text-3)', marginBottom: 6 }}>Subject: <b style={{ color: 'var(--text)' }}>{preview.subject}</b><br />
              Goes to {preview.audience.count} {preview.audience.count === 1 ? 'person' : 'people'} · {preview.audience.label}
              {preview.audience.sample.length > 0 && <> · e.g. {preview.audience.sample.map(s => s.email).join(', ')}</>}</div>
            <iframe title="E-mail preview" sandbox="" srcDoc={preview.html} style={{ width: '100%', height: 460, border: '1px solid var(--border)', borderRadius: 8, background: '#f3f7fc' }} />
          </>)}
        </div>
      </div>
    </div>
  )
}
