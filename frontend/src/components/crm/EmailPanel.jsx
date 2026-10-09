import { useState, useEffect } from 'react'
import { crm } from '../../api/crm'
import { toast } from '../Toast'

const STATUS = {
  sent:   { label: 'Sent',   bg: '#dcfce7', color: '#15803d' },
  failed: { label: 'Failed', bg: '#fee2e2', color: '#b91c1c' },
  queued: { label: 'Queued', bg: '#e0e7ff', color: '#074a8c' },
}

function when(dt) {
  if (!dt || typeof dt !== 'string') return ''
  return new Date(dt.replace(' ', 'T')).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
}

/**
 * Send an email to a contact (logged + mirrored to the timeline) and show the
 * outbound email log. Outbound only — inbound / 2-way is a documented follow-up.
 */
export default function EmailPanel({ contactId, onSent }) {
  const [emails, setEmails]   = useState([])
  const [loading, setLoading] = useState(true)
  const [open, setOpen]       = useState(false)
  const [form, setForm]       = useState({ subject: '', body: '' })
  const [sending, setSending] = useState(false)

  useEffect(() => { load() }, [contactId])

  async function load() {
    setLoading(true)
    try {
      const r = await crm.listEmails(contactId)
      setEmails(r.data ?? [])
    } catch (e) { toast.error('Failed to load emails', e.message) }
    finally { setLoading(false) }
  }

  async function send(e) {
    e.preventDefault()
    if (!form.subject.trim()) { toast.error('Subject is required'); return }
    if (!form.body.trim())    { toast.error('Message is required'); return }
    setSending(true)
    try {
      await crm.sendEmail(contactId, { subject: form.subject.trim(), body: form.body })
      setForm({ subject: '', body: '' })
      setOpen(false)
      toast.success('Email sent')
      await load()
      onSent?.()
    } catch (e) { toast.error('Could not send email', e.message) }
    finally { setSending(false) }
  }

  return (
    <div className="lp-cd-card">
      <div style={{ display: 'flex', alignItems: 'center', marginBottom: 12 }}>
        <span style={{ fontWeight: 800, fontSize: 14, color: '#111827' }}>✉️ Email</span>
        <button onClick={() => setOpen(o => !o)}
          style={{ marginLeft: 'auto', background: open ? '#f1f5f9' : 'var(--primary,#0a6cc4)', color: open ? '#475569' : '#fff', border: 'none', borderRadius: 8, padding: '.4rem 1rem', fontSize: '.82rem', fontWeight: 600, cursor: 'pointer' }}>
          {open ? 'Cancel' : 'Compose'}
        </button>
      </div>

      {open && (
        <form onSubmit={send} style={{ display: 'grid', gap: 8, marginBottom: 14 }}>
          <input value={form.subject} onChange={e => setForm(f => ({ ...f, subject: e.target.value }))}
            placeholder="Subject" style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.9rem' }} />
          <textarea value={form.body} onChange={e => setForm(f => ({ ...f, body: e.target.value }))}
            placeholder="Write your message…" rows={5}
            style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.9rem', resize: 'vertical' }} />
          <button type="submit" disabled={sending}
            style={{ justifySelf: 'start', background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 8, padding: '.45rem 1.1rem', fontSize: '.85rem', fontWeight: 600, cursor: 'pointer' }}>
            {sending ? 'Sending…' : 'Send email'}
          </button>
        </form>
      )}

      {loading ? (
        <div style={{ height: 40, borderRadius: 8, background: '#f3f4f6' }} />
      ) : emails.length === 0 ? (
        <p style={{ margin: 0, color: '#94a3b8', fontSize: 13 }}>No emails sent to this contact yet.</p>
      ) : (
        <div style={{ display: 'grid', gap: 6 }}>
          {emails.map(em => {
            const st = STATUS[em.status] ?? STATUS.queued
            const inbound = em.direction === 'in'
            return (
              <div key={em.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '.5rem .65rem', border: '1px solid #f1f5f9', borderRadius: 8, background: inbound ? '#f8fafc' : '#fff' }}>
                <span title={inbound ? 'Received' : 'Sent'} style={{ fontSize: '.95rem' }}>{inbound ? '↩' : '↦'}</span>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontWeight: 600, fontSize: '.86rem', color: '#111827', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{em.subject}</div>
                  <div style={{ fontSize: '.72rem', color: '#94a3b8' }}>{inbound ? `from ${em.from_email}` : `to ${em.to_email}`} · {when(em.created_at)}</div>
                </div>
                {!inbound && <span style={{ background: st.bg, color: st.color, borderRadius: 999, padding: '.1rem .5rem', fontSize: '.68rem', fontWeight: 700 }}>{st.label}</span>}
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
