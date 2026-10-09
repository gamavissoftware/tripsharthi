import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, Save, Send, CalendarClock, AlertTriangle, Repeat } from 'lucide-react'
import { emailMarketing } from '../api/emailMarketing'
import { toast } from '../components/Toast'
import EmailHtmlEditor from '../components/email/EmailHtmlEditor'
import AudiencePicker from '../components/email/AudiencePicker'
import SequenceDialog from '../components/email/SequenceDialog'
import { STARTERS } from '../components/email/emailStarters'
import { fmtUtc } from '../components/email/emailShared'

const LOCAL_TZ = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Asia/Kolkata'

const BLANK = {
  name: '', from_name: '', reply_to: '', subject: STARTERS[0].subject, preheader: STARTERS[0].preheader,
  html_body: STARTERS[0].html, segment: { all: true }, email_template_id: null, status: 'draft',
}

function Section({ n, title, children, hint }) {
  return (
    <div className="card mb-4">
      <div className="card-body">
        <div className="flex items-center gap-2 mb-4" style={{ flexWrap: 'wrap' }}>
          <span className="step-dot step-dot-active" style={{ width: 22, height: 22, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontSize: 12 }}>{n}</span>
          <strong>{title}</strong>
          {hint && <span className="text-muted text-sm" style={{ flex: '1 1 220px' }}>— {hint}</span>}
        </div>
        {children}
      </div>
    </div>
  )
}

export default function EmailCampaignEditorPage() {
  const { id } = useParams()
  const isNew = !id
  const [params] = useSearchParams()
  const navigate = useNavigate()

  // A brand-new campaign with no template to load starts ready to edit.
  const [c, setC] = useState(() => (!id && !params.get('template') ? BLANK : null))
  const [templates, setTemplates] = useState([])
  const [overview, setOverview]   = useState(null)
  const [busy, setBusy]           = useState(false)
  const [testTo, setTestTo]       = useState('')
  const [when, setWhen]           = useState('')
  const [showSchedule, setShowSchedule] = useState(false)
  const [showSeq, setShowSeq] = useState(false)

  useEffect(() => {
    emailMarketing.templates().then(r => setTemplates(r.data ?? [])).catch(() => {})
    emailMarketing.overview().then(r => setOverview(r.data)).catch(() => {})

    if (!isNew) {
      emailMarketing.campaign(id).then(r => {
        const d = r.data
        if (!['draft', 'scheduled'].includes(d.status)) { navigate(`/email/campaigns/${id}`, { replace: true }); return }
        setC({ ...d, segment: d.segment ?? { all: true } })
      }).catch(e => { toast.error('Campaign not found', e.message); navigate('/email') })
      return
    }

    const tplId = params.get('template')
    if (tplId) {
      emailMarketing.template(tplId).then(r => setC({ ...BLANK, name: r.data.name, email_template_id: r.data.id, subject: r.data.subject, preheader: r.data.preheader ?? '', html_body: r.data.html_body }))
        .catch(() => setC(BLANK))
    }
  }, [id]) // eslint-disable-line react-hooks/exhaustive-deps

  if (!c) return <div className="page text-muted">Loading…</div>
  const set = (k) => (e) => setC(x => ({ ...x, [k]: e.target.value }))

  async function loadTemplate(tid) {
    if (!tid) return
    try {
      const r = await emailMarketing.template(tid)
      setC(x => ({ ...x, email_template_id: r.data.id, subject: r.data.subject, preheader: r.data.preheader ?? '', html_body: r.data.html_body }))
    } catch (e) { toast.error('Could not load template', e.message) }
  }

  /** Save and return the campaign id. */
  async function save(quiet = false) {
    const payload = {
      name: c.name, from_name: c.from_name ?? '', reply_to: c.reply_to ?? '', subject: c.subject,
      preheader: c.preheader ?? '', html_body: c.html_body, segment: c.segment,
      email_template_id: c.email_template_id || undefined,
    }
    if (isNew) {
      const r = await emailMarketing.createCampaign(payload)
      if (!quiet) toast.success('Draft saved')
      navigate(`/email/campaigns/${r.data.id}/edit`, { replace: true })
      return r.data.id
    }
    await emailMarketing.updateCampaign(id, payload)
    if (!quiet) toast.success('Draft saved')
    return id
  }

  async function run(fn) {
    setBusy(true)
    try { await fn() } catch (e) { toast.error('Something went wrong', e.message) }
    setBusy(false)
  }

  const sendNow = () => run(async () => {
    if (!confirm(`Send "${c.subject}" to this audience now? This cannot be undone once emails leave.`)) return
    const cid = await save(true)
    await emailMarketing.send(cid)
    toast.success('Campaign is sending', 'The first batch goes out within a minute.')
    navigate(`/email/campaigns/${cid}`)
  })

  const schedule = () => run(async () => {
    const cid = await save(true)
    await emailMarketing.schedule(cid, { scheduled_at: when.replace('T', ' '), timezone: LOCAL_TZ })
    toast.success('Campaign scheduled')
    navigate('/email')
  })

  const unschedule = () => run(async () => {
    await emailMarketing.unschedule(id)
    setC(x => ({ ...x, status: 'draft', scheduled_at: null }))
    toast.success('Back to draft')
  })

  const sendTest = () => run(async () => {
    const r = await emailMarketing.testSend({ to: testTo, subject: c.subject, preheader: c.preheader, html_body: c.html_body, from_name: c.from_name, reply_to: c.reply_to })
    toast.success('Test sent', r.message)
  })

  const ready = overview?.ready !== false
  const canSubmit = c.name.trim() && c.subject.trim() && c.html_body.trim()

  return (
    <div className="page">
      <div className="page-header">
        <div>
          <Link to="/email" className="text-muted text-sm flex items-center gap-2"><ArrowLeft size={14} /> Email campaigns</Link>
          <h1 className="page-title mt-1">{isNew ? 'New email campaign' : c.name}</h1>
          {c.status === 'scheduled' && (
            <p className="text-muted mt-1 flex items-center gap-2"><CalendarClock size={14} /> Scheduled for {fmtUtc(c.scheduled_at)}
              <button className="btn btn-ghost btn-sm" onClick={unschedule} disabled={busy}>Unschedule</button>
              {!c.segment?.followup_of && <button className="btn btn-primary btn-sm" onClick={() => setShowSeq(true)}><Repeat size={13} /> Add follow-up sequence</button>}</p>
          )}
          {c.last_error && <p className="text-sm mt-1" style={{ color: '#b45309' }}>{c.last_error}</p>}
          {showSeq && <SequenceDialog campaign={c} onClose={() => setShowSeq(false)} onCreated={() => { setShowSeq(false); navigate('/email') }} />}
        </div>
        <button className="btn btn-ghost" onClick={() => run(() => save())} disabled={busy || !canSubmit}><Save size={15} /> Save draft</button>
      </div>

      <Section n={1} title="Details">
        <div className="form-grid-2">
          <div className="form-group"><label className="form-label">Campaign name (internal)</label><input className="form-input" value={c.name} onChange={set('name')} placeholder="e.g. October offer" /></div>
          <div className="form-group"><label className="form-label">From name</label><input className="form-input" value={c.from_name ?? ''} onChange={set('from_name')} placeholder={overview?.from_email ? `Default for ${overview.from_email}` : 'Your company'} /></div>
          <div className="form-group"><label className="form-label">Reply-to (optional)</label><input className="form-input" type="email" value={c.reply_to ?? ''} onChange={set('reply_to')} placeholder="sales@yourdomain.com" /></div>
        </div>
      </Section>

      <Section n={2} title="Content">
        <div className="flex items-center gap-2 mb-4" style={{ flexWrap: 'wrap' }}>
          <span className="text-muted text-sm">Load:</span>
          <select className="form-select" style={{ width: 240 }} value="" onChange={e => loadTemplate(e.target.value)}>
            <option value="">— a saved template —</option>
            {templates.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
          </select>
          {STARTERS.map(s => (
            <button key={s.key} className="btn btn-ghost btn-sm" type="button"
                    onClick={() => { if (confirm('Replace the current content with this layout?')) setC(x => ({ ...x, subject: s.subject, preheader: s.preheader, html_body: s.html, email_template_id: null })) }}>
              {s.name}
            </button>
          ))}
        </div>
        <div className="form-grid-2">
          <div className="form-group"><label className="form-label">Subject</label><input className="form-input" value={c.subject} onChange={set('subject')} /></div>
          <div className="form-group"><label className="form-label">Preview text</label><input className="form-input" value={c.preheader ?? ''} onChange={set('preheader')} placeholder="Shown after the subject in the inbox" /></div>
        </div>
        <EmailHtmlEditor value={c.html_body} onChange={html => setC(x => ({ ...x, html_body: html }))} />
        <p className="text-muted text-sm mt-2">Links are tracked automatically. An unsubscribe footer is added unless you place <code>{'{{unsubscribe_url}}'}</code> yourself.</p>
      </Section>

      <Section n={3} title="Audience" hint="contacts without an email, and anyone who unsubscribed, are skipped">
        <AudiencePicker segment={c.segment} onChange={segment => setC(x => ({ ...x, segment }))} />
      </Section>

      <Section n={4} title="Test & send">
        {!ready && (
          <div className="flex items-center gap-2 mb-4" style={{ color: '#b45309' }}>
            <AlertTriangle size={16} /> <span className="text-sm">{overview.reason} <Link to="/email?tab=settings">Open settings</Link></span>
          </div>
        )}
        <div className="flex items-center gap-2 mb-4" style={{ flexWrap: 'wrap' }}>
          <input className="form-input" type="email" placeholder="Send a test to…" value={testTo} onChange={e => setTestTo(e.target.value)} style={{ width: 260 }} />
          <button className="btn btn-ghost btn-sm" onClick={sendTest} disabled={busy || !testTo || !ready}><Send size={14} /> Send test</button>
        </div>
        <div className="flex items-center gap-2" style={{ flexWrap: 'wrap' }}>
          <button className="btn btn-primary" onClick={sendNow} disabled={busy || !canSubmit || !ready}><Send size={15} /> Send now</button>
          <button className="btn btn-ghost" onClick={() => setShowSchedule(s => !s)} disabled={busy || !canSubmit || !ready}><CalendarClock size={15} /> Schedule…</button>
          {showSchedule && (
            <>
              <input className="form-input" type="datetime-local" value={when} onChange={e => setWhen(e.target.value)} style={{ width: 220 }} />
              <span className="text-muted text-sm">{LOCAL_TZ}</span>
              <button className="btn btn-primary btn-sm" onClick={schedule} disabled={busy || !when}>Schedule</button>
            </>
          )}
        </div>
      </Section>
    </div>
  )
}
