import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Users, CheckCheck, Hourglass, XCircle, MinusCircle, Smartphone, Monitor } from 'lucide-react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import LoadFailed from '../../components/LoadFailed'
import { Pill, Kpi } from './AdminUi'
import { SendBar } from './AdminEmailPage'
import { EMAIL_STATUS_COLOR, fmtDateTime } from './adminUtil'
import { confirmAsk } from '../../components/confirmAsk'

const BLANK = { name: '', subject: '', body: '', segment: 'owners' }
const SNIPPETS = [
  ['Heading', '# Big heading\n\n'], ['Small headline', '# Small headline\n\n'], ['Bullet list', '- First point\n- Second point\n- Third point\n\n'],
  ['Highlighted note', '> Something worth noticing.\n\n'], ['Button', '[[button: Open TripSarthi | https://app.tripsarthi.com]]\n\n'], ['First name', '{{first_name}}'],
]
const fieldsOf = (x) => ({ name: x.name, subject: x.subject, body: x.body, segment: x.segment })
const countClass = (n, warn, max) => 'em-count' + (n > max ? ' bad' : n > warn ? ' warn' : '')

/** Write, preview, test and send one campaign; afterwards it shows how the send went. */
export default function AdminEmailCampaignPage() {
  const { id } = useParams()
  const nav = useNavigate()
  const isNew = !id
  const [c, setC] = useState(isNew ? { ...BLANK, status: 'draft' } : null)
  const [saved, setSaved] = useState(isNew ? JSON.stringify(BLANK) : '')
  const [segs, setSegs] = useState([])
  const [summary, setSummary] = useState(null)
  const [failed, setFailed] = useState(false)
  const [preview, setPreview] = useState(null)
  const [device, setDevice] = useState('desktop')
  const [help, setHelp] = useState(false)
  const [tested, setTested] = useState(false)
  const [busy, setBusy] = useState('')
  const area = useRef(null)

  const adopt = (r) => { setC(r); setSaved(JSON.stringify(fieldsOf(r))) }

  useEffect(() => {
    admin.emailOverview().then(o => { setSegs(o.segments); setSummary(o.summary) }).catch(() => {})
    if (id) admin.emailCampaign(id).then(r => { setC(r); setSaved(JSON.stringify(fieldsOf(r))); admin.emailPreview(r.id).then(setPreview).catch(() => {}) }).catch(() => setFailed(true))
  }, [id])

  // keep the progress fresh while a campaign is sending
  useEffect(() => {
    if (c?.status !== 'sending' || !id) return undefined
    const t = setInterval(() => admin.emailCampaign(id).then(setC).catch(() => {}), 10000)
    return () => clearInterval(t)
  }, [c?.status, id])

  const editable = c?.status === 'draft'
  const set = (k, v) => setC(x => ({ ...x, [k]: v }))
  const fields = () => fieldsOf(c)
  const dirty = editable && JSON.stringify(fields()) !== saved
  const audience = segs.find(s => s.key === c?.segment)

  async function save(quiet = false) {
    setBusy('save')
    try {
      const r = await admin.emailSave(id, fields())
      if (!quiet) toast.success('Saved')
      adopt(r)
      if (isNew) nav(`/admin/email/${r.id}`, { replace: true })
      return r
    } catch (err) { toast.error('Not saved', err.message) } finally { setBusy('') }
    return null
  }
  async function doPreview() { const r = await save(true); if (!r) return; try { setPreview(await admin.emailPreview(r.id)) } catch (err) { toast.error('No preview', err.message) } }
  async function test() { const r = await save(true); if (!r) return; setBusy('test'); try { const t = await admin.emailTest(r.id); setTested(true); toast.success('Test sent', `Check ${t.sent_to}`) } catch (err) { toast.error('Test failed', err.message) } finally { setBusy('') } }
  async function send() {
    const r = await save(true); if (!r) return
    let n = audience?.count ?? 0
    try { const o = await admin.emailOverview(); setSegs(o.segments); n = o.segments.find(s => s.key === r.segment)?.count ?? 0 } catch { /* use the count we have */ }
    if (n === 0) { toast.error('Nobody to send to', 'No one in this audience has agreed to e-mails yet.'); return }
    if (!(await confirmAsk(`Send “${r.subject}” to ${n} ${n === 1 ? 'person' : 'people'} in “${r.segment_label}”?\n\nIt goes out gradually (about 30 a minute) and cannot be recalled once sent.`))) return
    setBusy('send')
    try { setC(await admin.emailSend(r.id, n)); toast.success('Sending started') } catch (err) { toast.error('Not sent', err.message) } finally { setBusy('') }
  }
  async function cancel() {
    if (!(await confirmAsk('Stop sending? E-mails already sent cannot be recalled.'))) return
    try { setC(await admin.emailCancel(id)); toast.success('Stopped') } catch (err) { toast.error('Could not stop', err.message) }
  }
  async function remove() {
    if (!(await confirmAsk('Delete this draft?'))) return
    try { await admin.emailDelete(id); nav('/admin/email') } catch (err) { toast.error('Not deleted', err.message) }
  }
  function insert(text) {
    const el = area.current
    const at = el ? el.selectionStart : c.body.length
    const before = c.body.slice(0, at), after = c.body.slice(el ? el.selectionEnd : at)
    const glue = before && !before.endsWith('\n\n') && text !== '{{first_name}}' ? (before.endsWith('\n') ? '\n' : '\n\n') : ''
    set('body', before + glue + text + after)
    setTimeout(() => { el?.focus(); const p = (before + glue + text).length; el?.setSelectionRange(p, p) }, 0)
  }

  if (failed) return <LoadFailed what="campaign" backTo="/admin/email" />
  if (!c) return <div className="page"><div className="card card-body" style={{ color: 'var(--text-3)' }}>Loading…</div></div>

  const words = c.body.trim() ? c.body.trim().split(/\s+/).length : 0
  const checks = [
    [!!c.name.trim() && c.subject.trim().length >= 3, 'Name and subject are filled in'],
    [c.body.trim().length >= 30, 'The text is written'],
    [(audience?.count ?? 0) > 0, audience ? `${audience.count} ${audience.count === 1 ? 'person' : 'people'} will receive it` : 'Choose who receives it'],
    [summary ? summary.mail_ready : true, summary && !summary.mail_ready ? 'No mail server is set up yet (Mail server page)' : 'The mail server is ready'],
    [tested, 'You sent yourself a test and looked at it'],
  ]

  return (
    <div className="page">
      <div style={{ marginBottom: 8 }}><Link to="/admin/email">← Email marketing</Link></div>
      <div className="page-header">
        <div>
          <h1 className="page-title" style={{ margin: 0 }}>{isNew ? 'New campaign' : c.name} {!isNew && <Pill color={EMAIL_STATUS_COLOR[c.status]}>{c.status === 'cancelled' ? 'stopped' : c.status}</Pill>}</h1>
          {!isNew && <div style={{ color: 'var(--text-3)', fontSize: 13.5, marginTop: 4 }}>{c.segment_label}{c.started_at ? ` · started ${fmtDateTime(c.started_at)}` : ''}{c.finished_at ? ` · finished ${fmtDateTime(c.finished_at)}` : ''}</div>}
        </div>
        {c.status === 'sending' && <button className="btn btn-ghost" style={{ color: 'var(--danger)' }} onClick={cancel}>Stop sending</button>}
      </div>

      {!editable && (
        <>
          <div className="cu-kpis">
            <Kpi icon={Users} tone="#0a6cc4" label="Recipients" value={c.recipients} sub={c.segment_label} />
            <Kpi icon={CheckCheck} tone="#1a9d57" label="Sent" value={c.sent} sub={c.recipients ? `${Math.round(c.sent * 100 / c.recipients)}% of recipients` : ''} />
            <Kpi icon={Hourglass} tone="#f98f26" label="Waiting" value={c.waiting} sub={c.waiting ? `about ${Math.max(1, Math.ceil(c.waiting / 30))} min left` : 'none left'} />
            <Kpi icon={XCircle} tone={c.failed ? '#e5484d' : '#8393ad'} label="Failed" value={c.failed} accent={c.failed ? 'var(--danger)' : undefined} />
            <Kpi icon={MinusCircle} tone="#8393ad" label="Skipped" value={c.skipped} sub="opted out meanwhile" />
          </div>
          <div className="card card-body" style={{ marginBottom: 14 }}><SendBar c={c} />
            <div style={{ display: 'flex', gap: 16, fontSize: 12.5, color: 'var(--text-3)', marginTop: 8, flexWrap: 'wrap' }}><span><i style={{ background: '#12a89e' }} className="wa-dot" /> sent</span><span><i style={{ background: '#e5484d' }} className="wa-dot" /> failed</span><span><i style={{ background: '#c3cde0' }} className="wa-dot" /> skipped</span><span>Created {fmtDateTime(c.created_at)}</span></div>
          </div>
        </>)}
      {c.failures?.length > 0 && <div className="wa-banner bad" style={{ display: 'block' }}><b>Failures ({c.failed})</b>{c.failures.map(f => <div key={f.email} style={{ color: '#7a2a24', fontSize: 13, marginTop: 3 }}>{f.email} — {f.error}</div>)}</div>}

      <div className="em-grid">
        <div style={{ display: 'grid', gap: 14 }}>
          <div className="card card-body" style={{ display: 'grid', gap: 14 }}>
            <label>Campaign name <span style={{ color: 'var(--text-3)', fontWeight: 400 }}>(only you see it)</span>
              <input className="form-input" value={c.name} onChange={e => set('name', e.target.value)} disabled={!editable} maxLength={120} placeholder="e.g. Diwali offer for free workspaces" /></label>
            <label>Send to
              <select className="form-select" value={c.segment} onChange={e => set('segment', e.target.value)} disabled={!editable}>
                {(segs.length ? segs : [{ key: c.segment, label: c.segment_label || c.segment, count: 0 }]).map(s => <option key={s.key} value={s.key}>{s.label} ({s.count} opted in)</option>)}</select>
              {audience && <span style={{ display: 'block', fontSize: 12.5, color: 'var(--text-3)', fontWeight: 400, marginTop: 4 }}>{audience.description}</span>}</label>
            <label>Subject
              <input className="form-input" value={c.subject} onChange={e => set('subject', e.target.value)} disabled={!editable} maxLength={150} placeholder="Hi {{first_name}}, …" />
              {editable && <span className={countClass(c.subject.length, 60, 150)} style={{ display: 'block' }}>{c.subject.length}/150{c.subject.length > 60 ? ' · long subjects get cut off on phones (aim for under 60)' : ''}</span>}</label>
            <label>E-mail text
              <textarea ref={area} className="form-input" rows={14} value={c.body} onChange={e => set('body', e.target.value)} disabled={!editable} maxLength={20000} style={{ fontFamily: 'inherit', marginTop: 4 }} />
              {editable && <span className="em-count" style={{ display: 'block' }}>{words} words</span>}</label>
            {editable && (
              <div style={{ display: 'grid', gap: 8 }}>
                <div className="em-snip"><span style={{ fontSize: 12.5, color: 'var(--text-3)', alignSelf: 'center' }}>Insert:</span>{SNIPPETS.map(([label, text]) => <button type="button" key={label} onClick={() => insert(text)}>{label}</button>)}
                  <button type="button" onClick={() => setHelp(h => !h)}>{help ? 'Hide' : 'Show'} writing help</button></div>
                {help && <div className="em-help">Blank line = new paragraph · <code>{'{{first_name}}'}</code> = their first name · web addresses become links.<br />
                  <code># Title</code> on the first line = big heading (later = small headline) · lines starting <code>- </code> = bullet list · <code>&gt; text</code> = highlighted note<br />
                  <code>{'[[button: Claim my offer | https://app.tripsarthi.com]]'}</code> = a call-to-action button</div>}
              </div>)}
            {editable && (
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                <button className="btn btn-ghost" onClick={() => save()} disabled={busy !== ''}>{busy === 'save' ? 'Saving…' : 'Save draft'}</button>
                <button className="btn btn-ghost" onClick={doPreview} disabled={busy !== ''}>Update preview</button>
                <button className="btn btn-ghost" onClick={test} disabled={busy !== ''}>{busy === 'test' ? 'Sending…' : 'Send me a test'}</button>
                {dirty && <span style={{ fontSize: 12.5, color: '#b7791f', fontWeight: 600 }}>Unsaved changes</span>}
                {!isNew && <button className="btn btn-ghost" style={{ color: 'var(--danger)', marginLeft: 'auto' }} onClick={remove}>Delete</button>}
              </div>)}
          </div>

          {editable && (
            <div className="card card-body">
              <div style={{ fontWeight: 800, color: '#0a1f44', marginBottom: 6 }}>Before you send</div>
              {checks.map(([ok, text]) => <div key={text} className={'wa-check' + (ok ? '' : ' no')} style={ok ? undefined : { color: '#7a5a12' }}><span style={ok ? undefined : { background: '#f59e0b' }}>{ok ? '✓' : '·'}</span>{text}</div>)}
              <button className="btn btn-primary" style={{ marginTop: 12 }} onClick={send} disabled={busy !== ''}>{busy === 'send' ? 'Starting…' : 'Send to audience…'}</button>
              <div style={{ fontSize: 12.5, color: 'var(--text-3)', marginTop: 8 }}>You will be asked to confirm the exact number of people. E-mails go out gradually and cannot be recalled.</div>
            </div>)}
        </div>

        <div className="card card-body em-preview">
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8, gap: 8, flexWrap: 'wrap' }}>
            <div style={{ fontWeight: 800, color: '#0a1f44' }}>Preview</div>
            <div className="wa-tabs" style={{ margin: 0 }}>
              <button className={device === 'desktop' ? 'on' : ''} style={{ padding: '5px 12px', fontSize: 12.5 }} onClick={() => setDevice('desktop')}><Monitor size={14} /> Computer</button>
              <button className={device === 'mobile' ? 'on' : ''} style={{ padding: '5px 12px', fontSize: 12.5 }} onClick={() => setDevice('mobile')}><Smartphone size={14} /> Phone</button>
            </div>
          </div>
          {!preview && <div style={{ color: 'var(--text-3)', fontSize: 13 }}>{editable ? 'Press “Update preview” to see exactly what people will receive.' : 'Loading preview…'}</div>}
          {preview && (<>
            <div style={{ fontSize: 13, color: 'var(--text-3)', marginBottom: 8 }}>Subject: <b style={{ color: 'var(--text)' }}>{preview.subject}</b><br />
              Goes to {preview.audience.count} {preview.audience.count === 1 ? 'person' : 'people'} · {preview.audience.label}
              {preview.audience.sample.length > 0 && <> · e.g. {preview.audience.sample.map(s => s.email).join(', ')}</>}</div>
            <iframe title="E-mail preview" sandbox="" srcDoc={preview.html} className={'em-frame' + (device === 'mobile' ? ' mobile' : '')} />
          </>)}
        </div>
      </div>
    </div>
  )
}
