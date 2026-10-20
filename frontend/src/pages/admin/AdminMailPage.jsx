import { useEffect, useState } from 'react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { confirmAsk } from '../../components/confirmAsk'
import { Mail, Server, KeyRound, AtSign, ShieldCheck } from 'lucide-react'
import { Kpi, Pill } from './AdminUi'
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
  const brevo = st.host.includes('brevo')
  return (
    <div className="page">
      <div className="page-header"><div><h1 className="page-title">Mail server</h1><div style={{ color: 'var(--text-3)', fontSize: 13.5 }}>The server TripSarthi's own e-mails go through: invoices, support replies, reminders, password resets and marketing.</div></div></div>

      <div className={'wa-banner ' + (st.configured ? 'ok' : 'bad')}>
        <Mail size={18} />
        <div className="grow"><b>{st.configured ? 'TripSarthi can send e-mail.' : 'E-mail cannot be delivered yet.'}</b> {SOURCE[st.source]}{st.changed_at && <> · changed {fmtDate(st.changed_at)}</>}</div>
        <button type="button" className="btn btn-ghost btn-sm" onClick={test} disabled={busy !== '' || !st.configured}>{busy === 'test' ? 'Sending…' : 'Send me a test e-mail'}</button>
      </div>
      {st.warnings.map(w => <div key={w} className="wa-banner">⚠ <div className="grow">{w}</div></div>)}

      <div className="cu-kpis">
        <Kpi icon={Server} tone="#0a6cc4" label="Server" value={st.host === 'localhost' ? 'none' : brevo ? 'Brevo' : st.host.replace(/^smtp\./, '')} sub={`${st.host}:${st.port}`} />
        <Kpi icon={ShieldCheck} tone="#12a89e" label="Encryption" value={(st.crypto || 'none').toUpperCase()} sub="between us and the server" />
        <Kpi icon={KeyRound} tone={st.pass_set ? '#1a9d57' : '#e5484d'} label="Password" value={st.pass_set ? 'Saved' : 'Not set'} sub={st.user || 'no username'} />
        <Kpi icon={AtSign} tone="#f98f26" label="Sends as" value={st.from_name || '—'} sub={st.from_email} />
      </div>

      <div className="em-grid">
        <form className="card card-body" onSubmit={save} autoComplete="off">
          <div style={{ fontWeight: 800, color: '#0a1f44', marginBottom: 2 }}>SMTP settings</div>
          <div style={{ fontSize: 13, color: 'var(--text-3)', marginBottom: 12 }}>Saving first sends a real test e-mail to <b>you</b>; if the mail server refuses, nothing is saved. The password is never shown again — leave it empty to keep the saved one.</div>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12, flexWrap: 'wrap', fontSize: 13 }}>
            <span style={{ color: 'var(--text-3)' }}>Quick fill:</span>
            <button type="button" className="btn btn-ghost btn-sm" onClick={() => setF({ ...f, host: 'smtp-relay.brevo.com', port: 465, crypto: 'ssl', from_email: f.from_email || 'noreply@tripsarthi.com' })}>Brevo</button>
            <button type="button" className="btn btn-ghost btn-sm" onClick={() => setF({ ...f, host: 'smtp.gmail.com', port: 465, crypto: 'ssl' })}>Gmail</button>
            <span style={{ color: 'var(--text-3)' }}>fills server, port and encryption only — you type the login and key yourself.</span>
          </div>
          <div style={{ display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))' }}>
            <label>Mail server *<input className="form-input" value={f.host} onChange={set('host')} placeholder="smtp-relay.brevo.com" spellCheck={false} required pattern="[A-Za-z0-9.\-]+" title="Just the server name, like smtp-relay.brevo.com (no https:// and no port)" autoCapitalize="none" /></label>
            <label>Port *<input className="form-input" type="number" min={1} max={65535} step={1} inputMode="numeric" value={f.port} onChange={e => setF({ ...f, port: Number(e.target.value) })} required /></label>
            <label>Encryption *
              <select className="form-select" required value={f.crypto} onChange={set('crypto')}><option value="tls">TLS (port 587)</option><option value="ssl">SSL (port 465)</option><option value="none">None</option></select></label>
            <label>Username<input className="form-input" value={f.user} onChange={set('user')} placeholder="SMTP login" autoComplete="off" spellCheck={false} /></label>
            <label>Password<input className="form-input" type="password" value={f.pass} onChange={set('pass')} placeholder={st.pass_set ? '•••••••• saved — leave empty to keep' : 'SMTP key / app password'} autoComplete="new-password" spellCheck={false} /></label>
            <label>“From” address *<input className="form-input" type="email" inputMode="email" autoCapitalize="none" value={f.from_email} onChange={set('from_email')} placeholder="noreply@tripsarthi.com" required /></label>
            <label>“From” name<input className="form-input" value={f.from_name} onChange={set('from_name')} maxLength={60} /></label>
          </div>
          <div style={{ display: 'flex', gap: 8, marginTop: 14, flexWrap: 'wrap' }}>
            <button className="btn btn-primary" disabled={busy !== ''}>{busy === 'save' ? 'Sending a test e-mail…' : 'Check & save'}</button>
            {st.source === 'admin' && <button type="button" className="btn btn-ghost" onClick={clear} disabled={busy !== ''} style={{ color: 'var(--danger)', marginLeft: 'auto' }}>Remove saved settings</button>}
          </div>
        </form>

        <div style={{ display: 'grid', gap: 14 }}>
          <div className="card card-body">
            <div style={{ fontWeight: 800, color: '#0a1f44', marginBottom: 6 }}>Brevo <Pill color="var(--success)">recommended</Pill></div>
            <div className="em-help">Server <code>smtp-relay.brevo.com</code>, port <code>465</code> with SSL.<br />Username = the <b>SMTP login</b> in Brevo (SMTP &amp; API → SMTP).<br />Password = an <b>SMTP key</b> you create there — not your Brevo account password.<br />“From” = an address on <code>tripsarthi.com</code> added as a sender after you verify the domain (the DKIM and DMARC records Brevo shows). Until then Brevo refuses or rewrites the sender.</div>
          </div>
          <div className="card card-body">
            <div style={{ fontWeight: 800, color: '#0a1f44', marginBottom: 6 }}>Gmail <span style={{ fontWeight: 400, color: 'var(--text-3)', fontSize: 12.5 }}>— small volumes only</span></div>
            <div className="em-help">Server <code>smtp.gmail.com</code>, port <code>465</code> with SSL (this server blocks 587).<br />Username = the Gmail address, password = a Google <b>App Password</b> (Account → Security → 2-Step Verification → App passwords), “from” = the same address.<br />Gmail allows about 500 e-mails a day and rewrites the “from”.</div>
          </div>
        </div>
      </div>
    </div>
  )
}
