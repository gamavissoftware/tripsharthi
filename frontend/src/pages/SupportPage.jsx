import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { support } from '../api/support'
import { toast } from '../components/Toast'
import { SUPPORT_STATUS } from './supportUi'

const pill = (status) => { const [label, color] = SUPPORT_STATUS[status] || [status, 'var(--text-3)']; return <span style={{ display: 'inline-block', padding: '2px 9px', borderRadius: 999, fontSize: 12, fontWeight: 600, color, background: 'color-mix(in srgb, currentColor 12%, transparent)' }}>{label}</span> }
const when = (s) => (s ? new Date(String(s).replace(' ', 'T') + 'Z').toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '')

/** Contact the TripSarthi team: raise a request, follow the replies. */
export default function SupportPage() {
  const nav = useNavigate()
  const [d, setD] = useState(null)
  const [filter, setFilter] = useState('all')
  const [form, setForm] = useState({ subject: '', category: 'howto', message: '' })
  const [busy, setBusy] = useState(false)
  const [showForm, setShowForm] = useState(false)

  const load = () => support.list(filter).then(setD).catch(e => toast.error('Could not load', e.message))
  useEffect(() => { load() }, [filter]) // eslint-disable-line react-hooks/exhaustive-deps

  async function submit(e) {
    e.preventDefault(); setBusy(true)
    try { const t = await support.create(form); toast.success('Request sent', `Reference ${t.number}. We'll reply here and by e-mail.`); nav(`/support/${t.id}`) }
    catch (err) { toast.error('Not sent', err.message) }
    setBusy(false)
  }

  return (
    <div className="page">
      <div className="page-header">
        <h1 className="page-title">Help &amp; support</h1>
        <button className="btn btn-primary" onClick={() => setShowForm(v => !v)}>{showForm ? 'Cancel' : '+ New request'}</button>
      </div>

      {showForm && (
        <form className="card card-body" onSubmit={submit} style={{ marginBottom: 14, maxWidth: 680 }}>
          <div style={{ display: 'grid', gap: 12 }}>
            <label>What is it about?
              <select className="form-select" value={form.category} onChange={e => setForm({ ...form, category: e.target.value })}>
                {Object.entries(d?.categories || {}).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select></label>
            <label>Short title<input className="form-input" value={form.subject} maxLength={160} onChange={e => setForm({ ...form, subject: e.target.value })} placeholder="e.g. Cannot connect my WhatsApp number" required /></label>
            <label>Tell us what happened<textarea className="form-input" rows={6} value={form.message} maxLength={5000} onChange={e => setForm({ ...form, message: e.target.value })} placeholder="What you did, what you expected, what you saw. Please don't include passwords or card numbers." required /></label>
          </div>
          <div style={{ marginTop: 12 }}><button className="btn btn-primary" disabled={busy}>{busy ? 'Sending…' : 'Send to the TripSarthi team'}</button></div>
        </form>
      )}

      <div style={{ display: 'flex', gap: 8, marginBottom: 10 }}>
        {[['all', 'All'], ['open', 'Open'], ['closed', 'Closed']].map(([k, l]) => (
          <button key={k} className={'btn ' + (filter === k ? 'btn-primary' : 'btn-ghost')} onClick={() => setFilter(k)}>{l}</button>))}
      </div>

      <div className="card">
        {!d && <div className="card-body" style={{ color: 'var(--text-3)' }}>Loading…</div>}
        {d && d.tickets.length === 0 && <div className="card-body" style={{ color: 'var(--text-3)' }}>No requests yet. Something not working or unclear? Use “New request” and we&apos;ll help.</div>}
        {d?.tickets.map(t => (
          <Link key={t.id} to={`/support/${t.id}`} style={{ display: 'flex', gap: 12, alignItems: 'center', padding: '12px 16px', borderTop: '1px solid var(--border)', color: 'inherit', textDecoration: 'none' }}>
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontWeight: t.unread ? 800 : 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{t.unread && <span style={{ color: 'var(--primary)' }}>● </span>}{t.subject}</div>
              <div style={{ color: 'var(--text-3)', fontSize: 12.5 }}>{t.number} · {t.category_label} · {when(t.last_message_at)}</div>
            </div>
            {pill(t.status)}
          </Link>))}
      </div>
    </div>
  )
}
