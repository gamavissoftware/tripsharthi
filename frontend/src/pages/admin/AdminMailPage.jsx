import { useEffect, useState } from 'react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { confirmAsk } from '../../components/confirmAsk'
import { Pill } from './AdminUi'
import { fmtDate } from './adminUtil'

const SOURCE = { admin: 'Saved here (password encrypted)', server: 'From the server settings', none: 'Nothing set' }

/** The mail server TripSarthi's own e-mails go through. The password is write-only: it is never shown again. */
export default function AdminMailPage() {
  const [st, setSt] = useState(null)
  const [f, setF] = useState({ host: '', port: 587, crypto: 'tls', user: '', pass: '', from_email: '', from_name: 'TripSarthi' })
  const [busy, setBusy] = useState('')

  const fill = (d) => setF({ host: d.host === 'localhost' ? '' : d.host, port: d.port === 1025 ? 587 : d.port, crypto: d.crypto || 'tls', user: d.user || '', pass: '', from_email: d.from_email === 'noreply@travelpilot.app' ? '' : d.from_email, from_name: d.from_name || 'TripSarthi' })
  useEffect(() => { admin.mail().then(d => { setSt(d); fill(d) }).catch(e => toast.error('Could not load', e.message)) }, [])

  async function save(e) {
    e.preventDefault(); setBusy('save')
    try { const d = await admin.saveMail(f); setSt(d); fill(d); toast.success('Mail settings saved', 'A test e-mail was sent to you - check your inbox.') }
    catch (err) { toast.error('Not saved', err.message) }
    setBusy('')
  }
  async function test() {
    setBusy('test')
    try { const r = await admin.testMail(); toast.success('Test e-mail sent', `Check ${r.sent_to}`) } catch (err) { toast.error('Test failed', err.message) }
    setBusy('')
  }
  async function clear() {
    if (!(await confirmAsk('Remove the mail settings saved here? E-mail will use the server settings, which may not be able to send.', { okLabel: 'Remove', danger: true }))) return
    setBusy('clear')
    try { const d = await admin.clearMail(); setSt(d); fill(d); toast.success('Saved settings removed') } catch (err) { toast.error('Could not remove', err.message) }
    setBusy('')
  }
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })

  if (!st) return <div className="page"><div className="page-header"><h1 className="page-title">Mail server</h1></div><div className="card card-body" style={{ color: 'var(--text-3)' }}>Loading…</div></div>
  return (
    <div className="page">
      <div className="page-header"><h1 className="page-title">Mail server</h1></div>

      <div className="card card-body" style={{ marginBottom: 14 }}>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
          <Pill color={st.configured ? 'var(--success)' : 'var(--danger)'}>{st.configured ? 'Ready' : 'Not set up'}</Pill>
          <b>{st.configured ? 'TripSarthi can send e-mail' : 'E-mail cannot be delivered yet'}</b>
        </div>
        <div style={{ color: 'var(--text-3)', fontSize: 13, marginTop: 6 }}>
          Server: <code>{st.host}:{st.port}</code> ({st.crypto || 'no encryption'}) · User: {st.user || '—'} · Password: {st.pass_set ? 'set' : 'not set'} · From: {st.from_name} &lt;{st.from_email}&gt; · {SOURCE[st.source]}
          {st.changed_at && <> · changed {fmtDate(st.changed_at)}</>}
        </div>
        {st.warnings.map(w => <div key={w} style={{ marginTop: 8, fontSize: 13, color: 'var(--warning)' }}>⚠ {w}</div>)}
      </div>

      <form className="card card-body" onSubmit={save} autoComplete="off" style={{ marginBottom: 14 }}>
        <div style={{ fontWeight: 700, marginBottom: 2 }}>SMTP settings</div>
        <div style={{ fontSize: 13, color: 'var(--text-3)', marginBottom: 12 }}>
          Support replies, plan reminders, password resets and marketing e-mails go out through this. Saving first sends a real test e-mail to <b>you</b>; if the mail server refuses, nothing is saved. The password is never shown again; leave it empty to keep the saved one.
          <br />Using Gmail: server <code>smtp.gmail.com</code>, port <code>587</code>, TLS, username = the Gmail address, password = a Google <b>App Password</b> (Google Account → Security → 2-Step Verification → App passwords), and the same address as “from”.
        </div>
        <div style={{ display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit,minmax(240px,1fr))', maxWidth: 760 }}>
          <label>Mail server<input className="form-input" value={f.host} onChange={set('host')} placeholder="smtp.gmail.com" spellCheck={false} required /></label>
          <label>Port<input className="form-input" type="number" value={f.port} onChange={e => setF({ ...f, port: Number(e.target.value) })} required /></label>
          <label>Encryption
            <select className="form-select" value={f.crypto} onChange={set('crypto')}><option value="tls">TLS (port 587)</option><option value="ssl">SSL (port 465)</option><option value="none">None</option></select></label>
          <label>Username<input className="form-input" value={f.user} onChange={set('user')} placeholder="you@gmail.com" autoComplete="off" spellCheck={false} /></label>
          <label>Password<input className="form-input" type="password" value={f.pass} onChange={set('pass')} placeholder={st.pass_set ? '•••••••• saved — leave empty to keep' : 'App password'} autoComplete="new-password" spellCheck={false} /></label>
          <label>“From” address<input className="form-input" type="email" value={f.from_email} onChange={set('from_email')} placeholder="support@tripsarthi.com" required /></label>
          <label>“From” name<input className="form-input" value={f.from_name} onChange={set('from_name')} maxLength={60} /></label>
        </div>
        <div style={{ display: 'flex', gap: 8, marginTop: 14, flexWrap: 'wrap' }}>
          <button className="btn btn-primary" disabled={busy !== ''}>{busy === 'save' ? 'Sending a test e-mail…' : 'Check & save'}</button>
          <button type="button" className="btn btn-ghost" onClick={test} disabled={busy !== '' || !st.configured}>{busy === 'test' ? 'Sending…' : 'Send me a test e-mail'}</button>
          {st.source === 'admin' && <button type="button" className="btn btn-ghost" onClick={clear} disabled={busy !== ''} style={{ color: 'var(--danger)' }}>Remove saved settings</button>}
        </div>
      </form>
    </div>
  )
}
