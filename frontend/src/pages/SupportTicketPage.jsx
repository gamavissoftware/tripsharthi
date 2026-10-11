import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { support } from '../api/support'
import { toast } from '../components/Toast'
import LoadFailed from '../components/LoadFailed'
import { SUPPORT_STATUS } from './supportUi'
import { confirmAsk } from '../components/confirmAsk'

const when = (s) => (s ? new Date(String(s).replace(' ', 'T') + 'Z').toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '')

export default function SupportTicketPage() {
  const { id } = useParams()
  const [t, setT] = useState(null)
  const [failed, setFailed] = useState(false)
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)
  const end = useRef(null)

  useEffect(() => { support.get(id).then(setT).catch(() => setFailed(true)) }, [id])
  useEffect(() => { end.current?.scrollIntoView({ block: 'end' }) }, [t?.messages?.length])

  async function send(e) {
    e.preventDefault(); if (!msg.trim()) return
    setBusy(true)
    try { setT(await support.reply(id, msg)); setMsg('') } catch (err) { toast.error('Not sent', err.message) }
    setBusy(false)
  }
  async function close() {
    if (!(await confirmAsk('Close this request? You can open a new one any time.'))) return
    try { setT(await support.close(id)) } catch (err) { toast.error('Could not close', err.message) }
  }

  if (failed) return <LoadFailed what="request" backTo="/support" />
  if (!t) return <div className="page"><div className="card card-body" style={{ color: 'var(--text-3)' }}>Loading…</div></div>
  const [label, color] = SUPPORT_STATUS[t.status] || [t.status, 'var(--text-3)']
  return (
    <div className="page" style={{ maxWidth: 760 }}>
      <div style={{ marginBottom: 8 }}><Link to="/support">← All requests</Link></div>
      <div className="page-header">
        <div><h1 className="page-title" style={{ marginBottom: 2 }}>{t.subject}</h1>
          <div style={{ color: 'var(--text-3)', fontSize: 13 }}>{t.number} · {t.category_label} · <b style={{ color }}>{label}</b></div></div>
        {t.status !== 'closed' && <button className="btn btn-ghost" onClick={close}>Close request</button>}
      </div>

      <div className="card card-body" style={{ display: 'grid', gap: 12 }}>
        {t.messages.map(m => m.from === 'event'
          ? <div key={m.id} style={{ textAlign: 'center', color: 'var(--text-3)', fontSize: 12.5 }}>{m.body} · {when(m.at)}</div>
          : (
            <div key={m.id} style={{ justifySelf: m.from === 'team' ? 'start' : 'end', maxWidth: '85%' }}>
              <div style={{ fontSize: 12, color: 'var(--text-3)', marginBottom: 2, textAlign: m.from === 'team' ? 'left' : 'right' }}>{m.from === 'team' ? 'TripSarthi team' : 'You'} · {when(m.at)}</div>
              <div style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', padding: '10px 14px', borderRadius: 12, background: m.from === 'team' ? 'color-mix(in srgb, var(--primary) 10%, transparent)' : 'var(--surface-2, #f3f7fc)' }}>{m.body}</div>
            </div>))}
        <div ref={end} />
      </div>

      {t.status === 'closed'
        ? <p style={{ color: 'var(--text-3)', marginTop: 12 }}>This request is closed. <Link to="/support">Open a new one</Link> if you still need help.</p>
        : (
          <form onSubmit={send} style={{ marginTop: 12 }}>
            <textarea className="form-input" rows={4} value={msg} maxLength={5000} onChange={e => setMsg(e.target.value)} placeholder="Write a reply…" />
            <div style={{ marginTop: 8 }}><button className="btn btn-primary" disabled={busy || !msg.trim()}>{busy ? 'Sending…' : 'Send reply'}</button></div>
          </form>)}
    </div>
  )
}
