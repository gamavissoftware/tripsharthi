import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Save, Send } from 'lucide-react'
import { emailMarketing } from '../api/emailMarketing'
import { toast } from '../components/Toast'
import EmailHtmlEditor from '../components/email/EmailHtmlEditor'
import { STARTERS } from '../components/email/emailStarters'

export default function EmailTemplateEditorPage() {
  const { id } = useParams()
  const isNew = !id || id === 'new'
  const navigate = useNavigate()
  const [tpl, setTpl]       = useState(isNew ? { name: '', subject: STARTERS[0].subject, preheader: STARTERS[0].preheader, html_body: STARTERS[0].html } : null)
  const [saving, setSaving] = useState(false)
  const [testTo, setTestTo] = useState('')

  useEffect(() => {
    if (isNew) return
    emailMarketing.template(id).then(r => setTpl(r.data)).catch(e => { toast.error('Template not found', e.message); navigate('/email?tab=templates') })
  }, [id]) // eslint-disable-line react-hooks/exhaustive-deps

  if (!tpl) return <div className="page text-muted">Loading…</div>
  const set = (k) => (e) => setTpl(t => ({ ...t, [k]: e.target.value }))

  async function save() {
    setSaving(true)
    try {
      const payload = { name: tpl.name, subject: tpl.subject, preheader: tpl.preheader, html_body: tpl.html_body }
      if (isNew) {
        const r = await emailMarketing.createTemplate(payload)
        toast.success('Template saved')
        navigate(`/email/templates/${r.data.id}`, { replace: true })
      } else {
        await emailMarketing.updateTemplate(id, payload)
        toast.success('Template saved')
      }
    } catch (e) { toast.error('Could not save', e.message) }
    setSaving(false)
  }

  async function sendTest() {
    try {
      const r = await emailMarketing.testSend({ to: testTo, subject: tpl.subject, preheader: tpl.preheader, html_body: tpl.html_body })
      toast.success('Test sent', r.message)
    } catch (e) { toast.error('Test failed', e.message) }
  }

  return (
    <div className="page">
      <div className="page-header">
        <div>
          <Link to="/email?tab=templates" className="text-muted text-sm flex items-center gap-2"><ArrowLeft size={14} /> Email templates</Link>
          <h1 className="page-title mt-1">{isNew ? 'New email template' : tpl.name}</h1>
        </div>
        <button className="btn btn-primary" onClick={save} disabled={saving || !tpl.name.trim() || !tpl.subject.trim()}>
          <Save size={15} /> {saving ? 'Saving…' : 'Save template'}
        </button>
      </div>

      {isNew && (
        <div className="flex items-center gap-2 mb-4" style={{ flexWrap: 'wrap' }}>
          <span className="text-muted text-sm">Start from:</span>
          {STARTERS.map(s => (
            <button key={s.key} className="btn btn-ghost btn-sm"
                    onClick={() => setTpl(t => ({ ...t, subject: s.subject, preheader: s.preheader, html_body: s.html }))}>{s.name}</button>
          ))}
        </div>
      )}

      <div className="card mb-4"><div className="card-body">
        <div className="form-grid-2">
          <div className="form-group"><label className="form-label">Template name</label><input className="form-input" value={tpl.name} onChange={set('name')} placeholder="e.g. Monthly newsletter" /></div>
          <div className="form-group"><label className="form-label">Subject</label><input className="form-input" value={tpl.subject} onChange={set('subject')} /></div>
        </div>
        <div className="form-group">
          <label className="form-label">Preview text</label>
          <input className="form-input" value={tpl.preheader ?? ''} onChange={set('preheader')} placeholder="The grey line shown after the subject in the inbox" />
        </div>
      </div></div>

      <EmailHtmlEditor value={tpl.html_body} onChange={html => setTpl(t => ({ ...t, html_body: html }))} />

      <div className="flex items-center gap-2 mt-4" style={{ flexWrap: 'wrap' }}>
        <input className="form-input" type="email" placeholder="Send a test to…" value={testTo} onChange={e => setTestTo(e.target.value)} style={{ width: 260 }} />
        <button className="btn btn-ghost btn-sm" onClick={sendTest} disabled={!testTo}><Send size={14} /> Send test</button>
        <span className="text-muted text-sm">An unsubscribe footer is added automatically if you don't place <code>{'{{unsubscribe_url}}'}</code> yourself.</span>
      </div>
    </div>
  )
}
