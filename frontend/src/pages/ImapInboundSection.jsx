import { useState, useEffect } from 'react'
import { imap } from '../api/imap'
import { toast } from '../components/Toast'

/**
 * IMAP inbound-email connection (Phase M). Lets a tenant connect a mailbox so the
 * `email:poll-imap` cron pulls replies in and threads them onto contacts. The
 * password is write-only — never returned by the server.
 */
export default function ImapInboundSection() {
  const empty = { host: '', port: 993, username: '', password: '', folder: 'INBOX', ssl: true }
  const [form, setForm] = useState(empty)
  const [connected, setConnected] = useState(false)
  const [hasPassword, setHasPassword] = useState(false)
  const [id, setId] = useState(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    imap.get().then(r => {
      const d = r.data
      if (d?.config) {
        setForm(f => ({ ...f, ...d.config, password: '' }))
        setConnected(true)
        setHasPassword(!!d.config.has_password)
        setId(d.id)
      }
    }).catch(() => {})
  }, [])

  const set = (k) => (e) => setForm(f => ({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }))

  async function save(e) {
    e.preventDefault()
    setSaving(true)
    try {
      const r = await imap.save(form)
      setConnected(true); setHasPassword(true); setId(r.data?.id ?? id)
      setForm(f => ({ ...f, password: '' }))
      toast.success('Mailbox connected', 'Replies are checked every 5 minutes.')
    } catch (err) {
      toast.error('Could not connect mailbox', err?.message)
    }
    setSaving(false)
  }

  async function disconnect() {
    if (!id || !confirm('Disconnect this mailbox? Inbound polling will stop.')) return
    try { await imap.disconnect(id); setConnected(false); setHasPassword(false); setId(null); setForm(empty) }
    catch (err) { toast.error('Disconnect failed', err?.message) }
  }

  const inp = { width: '100%', padding: '.5rem .6rem', border: '1px solid #e5e7eb', borderRadius: 8, fontSize: 13.5 }
  const lbl = { fontSize: 12.5, fontWeight: 600, color: '#374151', display: 'block', marginBottom: 4 }

  return (
    <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, padding: '1.25rem 1.4rem', marginTop: '1.25rem' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
        <h3 style={{ margin: 0, fontSize: 15, fontWeight: 800 }}>📥 Inbound email (IMAP)</h3>
        {connected && <span style={{ fontSize: 12, fontWeight: 700, color: '#047857', background: '#d1fae5', borderRadius: 999, padding: '.2rem .6rem' }}>Connected</span>}
      </div>
      <p style={{ margin: '0 0 14px', fontSize: 13, color: '#6b7280' }}>
        Connect the mailbox your email-campaign replies go to (Zoho: <code>imap.zoho.in</code>, Gmail: <code>imap.gmail.com</code>, port 993, app password).
        Every 5 minutes TripSarthi looks for replies from people it emailed, alerts you and stops their follow-ups.
        It is read-only: nothing is marked read, moved or deleted, and all other mail is ignored.
      </p>

      <form onSubmit={save} style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
        <div style={{ gridColumn: '1 / 3' }}>
          <label style={lbl}>IMAP host</label>
          <input style={inp} value={form.host} onChange={set('host')} placeholder="imap.gmail.com" required />
        </div>
        <div>
          <label style={lbl}>Port</label>
          <input style={inp} type="number" value={form.port} onChange={set('port')} />
        </div>
        <div>
          <label style={lbl}>Folder</label>
          <input style={inp} value={form.folder} onChange={set('folder')} placeholder="INBOX" />
        </div>
        <div>
          <label style={lbl}>Username</label>
          <input style={inp} value={form.username} onChange={set('username')} placeholder="you@company.com" required />
        </div>
        <div>
          <label style={lbl}>Password {hasPassword && <span style={{ color: '#9ca3af', fontWeight: 400 }}>(leave blank to keep)</span>}</label>
          <input style={inp} type="password" value={form.password} onChange={set('password')} placeholder={hasPassword ? '••••••••' : 'mailbox password / app password'} />
        </div>
        <label style={{ gridColumn: '1 / 3', display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, color: '#374151' }}>
          <input type="checkbox" checked={form.ssl} onChange={set('ssl')} /> Use SSL/TLS (recommended)
        </label>
        <div style={{ gridColumn: '1 / 3', display: 'flex', gap: 10 }}>
          <button type="submit" disabled={saving} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 9, padding: '.55rem 1.1rem', fontWeight: 700, cursor: 'pointer', opacity: saving ? .6 : 1 }}>
            {saving ? 'Saving…' : connected ? 'Update' : 'Connect mailbox'}
          </button>
          {connected && <button type="button" onClick={disconnect} style={{ background: '#fff', border: '1px solid #fecaca', color: '#b91c1c', borderRadius: 9, padding: '.55rem 1rem', fontWeight: 600, cursor: 'pointer' }}>Disconnect</button>}
        </div>
      </form>
    </div>
  )
}
