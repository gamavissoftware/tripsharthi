import { useState, useEffect, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { travel } from '../api/travel'
import { toast } from '../components/Toast'

const lbl = { fontSize: 12.5, fontWeight: 700, display: 'block' }

export default function EinvoiceSettingsPage() {
  const [s, setS] = useState(null)
  const [f, setF] = useState(null)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState('')
  const [test, setTest] = useState(null)
  const load = useCallback(() => travel.einvoiceSettings().then(r => {
    setS(r)
    setF({ enabled: r.enabled, aato: r.aato_confirmed, mode: r.mode, base_url: r.config.base_url, generate_path: r.config.generate_path, cancel_path: r.config.cancel_path, find_path: r.config.find_path, auth_type: r.config.auth_type,
      headers: r.config.header_names.map(n => ({ name: n, value: '' })), token: '', username: r.config.username, password: '' })
  }).catch(e => setErr(e.message)), [])
  useEffect(() => { load() }, [load])
  if (err) return <div className="page"><div style={{ background: '#fee2e2', padding: 10, borderRadius: 8 }}>{err}</div></div>
  if (!s || !f) return <div className="page">Loading…</div>
  const set = (k) => (e) => setF(x => ({ ...x, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }))

  async function save(e) {
    e.preventDefault(); setBusy('save'); setTest(null)
    try {
      await travel.saveEinvoice({ enabled: f.enabled, aato_confirmed: f.aato, mode: f.mode, base_url: f.base_url, generate_path: f.generate_path, cancel_path: f.cancel_path, find_path: f.find_path, auth_type: f.auth_type,
        headers: Object.fromEntries(f.headers.filter(h => h.name.trim()).map(h => [h.name.trim(), h.value])), token: f.token, username: f.username, password: f.password })
      toast.success('Saved'); load()
    } catch (er) { toast.error('Not saved', er.message) } finally { setBusy('') }
  }
  async function runTest() { setBusy('test'); try { setTest(await travel.testEinvoice()) } catch (er) { setTest({ ok: false, message: er.message }) } finally { setBusy('') } }

  return (
    <div className="page" style={{ maxWidth: 820 }}>
      <div className="page-header"><h1 className="page-title">E-invoicing</h1></div>
      <div className="card card-body" style={{ marginBottom: 14, fontSize: 13.5, color: 'var(--text-2)' }}>
        <b style={{ color: 'var(--text)' }}>Who needs it:</b> businesses whose aggregate turnover is above <b>₹5 crore</b> must register every <b>B2B</b> tax invoice and credit note with the government's Invoice Registration Portal (IRP) <i>before</i> giving it to the customer. The portal returns an <b>IRN</b> and a signed <b>QR code</b>, which TripSarthi prints on the invoice. B2C sales, bills of supply and receipts are not e-invoiced.
        <ul style={{ margin: '8px 0 0 18px' }}>
          <li>If registration fails, the invoice is <b>not issued</b> and no invoice number is used — you fix the problem and try again.</li>
          <li>An e-invoice can be cancelled only within <b>24 hours</b>; after that, issue a credit note.</li>
          <li>You need an e-invoice API account with a GST Suvidha Provider (GSP) or the NIC portal, and <b>must test in its sandbox first</b>. TripSarthi sends the standard INV-01 JSON to the address you configure.</li>
        </ul>
      </div>
      <form onSubmit={save} style={{ display: 'grid', gap: 14 }}>
        <div className="card card-body" style={{ display: 'grid', gap: 10 }}>
          <label style={{ display: 'flex', gap: 8, alignItems: 'center', fontWeight: 700 }}><input type="checkbox" checked={f.enabled} onChange={set('enabled')} /> Turn on e-invoicing {s.enabled_at && <small style={{ fontWeight: 400, color: 'var(--text-3)' }}>(since {s.enabled_at.slice(0, 10)})</small>}</label>
          <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start' }}><input type="checkbox" checked={f.aato} onChange={set('aato')} style={{ marginTop: 3 }} /> <span>I confirm my aggregate turnover requires e-invoicing (above ₹5 crore in any year since 2017-18). I understand the invoice will not be issued if the portal rejects it.</span></label>
          {s.demo_available && <label style={lbl}>Connection<select className="form-select" value={f.mode} onChange={set('mode')}><option value="gsp">My e-invoice provider (GSP)</option><option value="demo">Demo simulator (local testing only — IRNs are NOT valid)</option></select></label>}
        </div>

        {f.mode === 'gsp' && (
          <div className="card card-body" style={{ display: 'grid', gap: 10 }}>
            <h3 style={{ margin: 0 }}>Your provider</h3>
            <label style={lbl}>Provider API address *<input className="form-input" value={f.base_url} onChange={set('base_url')} placeholder="https://api.yourprovider.com/einvoice" /></label>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 8 }}>
              <label style={lbl}>Generate path<input className="form-input" value={f.generate_path} onChange={set('generate_path')} /></label>
              <label style={lbl}>Cancel path<input className="form-input" value={f.cancel_path} onChange={set('cancel_path')} /></label>
              <label style={lbl}>Find-by-document path<input className="form-input" value={f.find_path} onChange={set('find_path')} /></label>
            </div>
            <label style={lbl}>How the provider authenticates you
              <select className="form-select" value={f.auth_type} onChange={set('auth_type')}><option value="headers">Custom headers (API key, client id/secret, GSTIN…)</option><option value="bearer">Bearer token</option><option value="basic">Username and password</option></select></label>
            {f.auth_type === 'headers' && <div>
              <div style={lbl}>Headers {s.config.has_secret && <small style={{ fontWeight: 400, color: 'var(--text-3)' }}>— leave a value blank to keep the stored one</small>}</div>
              {f.headers.map((h, i) => <div key={i} style={{ display: 'flex', gap: 6, marginTop: 6 }}>
                <input className="form-input" placeholder="Header name, e.g. X-Api-Key" value={h.name} onChange={e => setF(x => ({ ...x, headers: x.headers.map((r, j) => j === i ? { ...r, name: e.target.value } : r) }))} />
                <input className="form-input" type="password" autoComplete="new-password" placeholder={s.config.header_names.includes(h.name) ? '•••••••• (stored)' : 'Value'} value={h.value} onChange={e => setF(x => ({ ...x, headers: x.headers.map((r, j) => j === i ? { ...r, value: e.target.value } : r) }))} />
                <button type="button" className="btn btn-sm btn-ghost" onClick={() => setF(x => ({ ...x, headers: x.headers.filter((_, j) => j !== i) }))}>✕</button></div>)}
              <button type="button" className="btn btn-sm btn-ghost" style={{ marginTop: 6 }} onClick={() => setF(x => ({ ...x, headers: [...x.headers, { name: '', value: '' }] }))}>+ Add header</button></div>}
            {f.auth_type === 'bearer' && <label style={lbl}>Token<input className="form-input" type="password" autoComplete="new-password" value={f.token} onChange={set('token')} placeholder={s.config.has_secret ? '•••••••• (stored — leave blank to keep)' : ''} /></label>}
            {f.auth_type === 'basic' && <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}><label style={lbl}>Username<input className="form-input" value={f.username} onChange={set('username')} /></label>
              <label style={lbl}>Password<input className="form-input" type="password" autoComplete="new-password" value={f.password} onChange={set('password')} placeholder={s.config.has_secret ? '•••••••• (stored)' : ''} /></label></div>}
            <small style={{ color: 'var(--text-3)' }}>Credentials are stored encrypted and are never shown again. The exact paths and headers are in your provider's API documentation.</small>
          </div>)}

        {test && <div style={{ background: test.ok ? '#dcfce7' : '#fee2e2', padding: 10, borderRadius: 8 }}>{test.message}</div>}
        <div style={{ display: 'flex', gap: 8 }}><button className="btn btn-primary" disabled={busy === 'save'}>{busy === 'save' ? 'Saving…' : 'Save'}</button><button type="button" className="btn btn-ghost" disabled={busy === 'test'} onClick={runTest}>{busy === 'test' ? 'Checking…' : 'Check connection'}</button></div>
      </form>
      <p style={{ color: 'var(--text-3)', fontSize: 12.5 }}>Your business profile must have your GSTIN, legal name, address, city and PIN — the portal rejects an e-invoice without them. <Link to="/settings/business">Open business profile</Link></p>
    </div>
  )
}
