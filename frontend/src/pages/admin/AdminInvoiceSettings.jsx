import { useEffect, useState } from 'react'
import { AlertTriangle, CheckCircle2 } from 'lucide-react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { validGstin } from '../../lib/gst'

const lbl = { display: 'block', fontSize: 12.5, fontWeight: 700, marginBottom: 4 }

/** The software owner's details printed on every invoice (defaults from the GST registration certificate), the SAC / GST rate, numbering and the accountant's copy. */
export default function AdminInvoiceSettings() {
  const [d, setD] = useState(null)
  const [f, setF] = useState(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { admin.invoiceSettings().then(r => { setD(r); setF({ ...r.settings, auto_email: r.settings.auto_email === '1' }) }).catch(e => toast.error('Could not load', e.message)) }, [])
  if (!f) return <div className="card card-body" style={{ color: 'var(--text-3)' }}>Loading…</div>
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })
  const field = (k, label, props = {}) => <div><label style={lbl} htmlFor={'ivs-' + k}>{label}{props.required ? ' *' : ''}</label><input id={'ivs-' + k} className="form-input" value={f[k] ?? ''} onChange={set(k)} {...props} /></div>

  async function save(e) {
    e.preventDefault()
    const g = String(f.gstin || '').trim().toUpperCase()
    if (g && !validGstin(g)) {
      const el = document.getElementById('ivs-gstin'); el?.focus()
      toast.warning('One thing needs fixing', 'That GSTIN does not pass the check digit test. Re-check it against the registration certificate.'); return
    }
    setBusy(true)
    try { const r = await admin.saveInvoiceSettings({ ...f, auto_email: f.auto_email ? '1' : '0' }); setF({ ...r.settings, auto_email: r.settings.auto_email === '1' }); setD({ ...d, problems: [] }); toast.success('Invoice settings saved', 'Used for every NEW invoice; issued invoices are never changed') }
    catch (err) { toast.error('Not saved', err.message) } finally { setBusy(false) }
  }

  return (
    <form className="card card-body" onSubmit={save} style={{ maxWidth: 900 }}>
      {d.problems.length > 0
        ? <div className="iv-missing" style={{ marginBottom: 14 }}><AlertTriangle size={18} /><div><b>Invoices cannot be issued until this is fixed</b><div style={{ fontSize: 13 }}>{d.problems.join(' ')}</div></div></div>
        : <div className="iv-ok"><CheckCircle2 size={16} /> Invoices are issued as <b>{f.legal_name}</b> · GSTIN <b>{f.gstin}</b>. The first number will look like <b>{f.prefix.toUpperCase()}/{new Date().getMonth() >= 3 ? String(new Date().getFullYear()).slice(2) : String(new Date().getFullYear() - 1).slice(2)}-…/00001</b>.</div>}
      <div style={{ fontSize: 13, color: 'var(--text-3)', margin: '4px 0 14px' }}>These details are printed on every invoice. Changing them affects only invoices issued from now on; an issued invoice keeps what it was issued with.</div>

      <h4 style={{ margin: '4px 0 8px' }}>Registration</h4>
      <div className="iv-grid">
        {field('legal_name', 'Legal name', { required: true, maxLength: 150 })}{field('trade_name', 'Trade name')}
        {field('gstin', 'GSTIN', { required: true, maxLength: 15, minLength: 15, spellCheck: false, autoCapitalize: 'characters', pattern: '[0-9]{2}[A-Za-z]{5}[0-9]{4}[A-Za-z][1-9A-Za-z][Zz][0-9A-Za-z]', title: 'A GSTIN is 15 characters, like 06ABCFG6551H1ZG' })}{field('pan', 'PAN', { maxLength: 10, spellCheck: false, autoCapitalize: 'characters', pattern: '[A-Za-z]{5}[0-9]{4}[A-Za-z]', title: 'A PAN is 10 characters, like ABCDE1234F' })}
        <div><label style={lbl} htmlFor="ivs-state">State of registration *</label><select id="ivs-state" required className="form-select" value={f.state_code} onChange={set('state_code')}><option value="">Choose…</option>{d.states.map(s => <option key={s.code} value={s.code}>{s.name} ({s.code})</option>)}</select></div>
        {field('pincode', 'PIN code', { maxLength: 6, inputMode: 'numeric', pattern: '[1-9][0-9]{5}', title: 'A PIN code is 6 digits' })}
      </div>
      <div className="iv-grid" style={{ marginTop: 12 }}>{field('address_line1', 'Address line 1')}{field('address_line2', 'Address line 2')}{field('city', 'City')}{field('phone', 'Phone', { type: 'tel', pattern: '[0-9+\\-() ]{7,20}', title: 'Digits only, with an optional + at the start' })}{field('email', 'Contact e-mail', { type: 'email' })}{field('website', 'Website', { type: 'url', placeholder: 'https://tripsarthi.com' })}</div>

      <h4 style={{ margin: '20px 0 8px' }}>Tax and numbering</h4>
      <div className="iv-grid">
        {field('sac_code', 'SAC code', { required: true, maxLength: 8, inputMode: 'numeric', pattern: '[0-9]{4,8}', title: 'A SAC code is 4 to 8 digits, like 998314' })}{field('gst_rate', 'GST rate %', { required: true, type: 'number', min: 0, max: 100, step: '0.01' })}{field('prefix', 'Invoice number prefix (max 4)', { required: true, maxLength: 4, pattern: '[A-Za-z0-9]{1,4}', title: 'Up to 4 letters or digits, like GS' })}{field('credit_prefix', 'Credit note number prefix (max 4)', { required: true, maxLength: 4, pattern: '[A-Za-z0-9]{1,4}', title: 'Up to 4 letters or digits, like CN' })}{field('signatory', 'Authorised signatory')}
      </div>
      <div className="cu-sub" style={{ marginTop: 6 }}>Numbers look like GS/26-27/00001 for invoices and CN/26-27/00001 for credit notes (separate series, restarting each financial year, no gaps). Plan prices <b>exclude GST</b>: checkout charges the price plus this GST rate, and the invoice shows the price as taxable value with the GST on top. A payment you record by hand is treated as the amount received including GST. The SAC code is a default; your CA should confirm the right one for subscription software.</div>

      <h4 style={{ margin: '20px 0 8px' }}>Bank details (optional, printed on the invoice)</h4>
      <div className="iv-grid">{field('bank_account_name', 'Account name')}{field('bank_name', 'Bank')}{field('bank_account_no', 'Account number', { inputMode: 'numeric', pattern: '[0-9]{6,20}', title: 'Digits only, 6 to 20 of them' })}{field('bank_ifsc', 'IFSC', { maxLength: 11, autoCapitalize: 'characters', pattern: '[A-Za-z]{4}0[A-Za-z0-9]{6}', title: 'An IFSC is 11 characters, like HDFC0001234' })}{field('upi_id', 'UPI ID', { pattern: '[A-Za-z0-9._\\-]{2,}@[A-Za-z]{2,}', title: 'A UPI ID looks like name@bank' })}</div>

      <h4 style={{ margin: '20px 0 8px' }}>E-mail and terms</h4>
      <label style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 14 }}><input type="checkbox" checked={f.auto_email} onChange={e => setF({ ...f, auto_email: e.target.checked })} /> E-mail the invoice to the customer's owner automatically when they pay</label>
      <div className="iv-grid" style={{ marginTop: 10 }}>{field('copy_to', 'Also send a copy of every invoice to (accountant)', { type: 'email', placeholder: 'accounts@yourfirm.com' })}</div>
      <div style={{ marginTop: 12 }}><label style={lbl} htmlFor="ivs-terms">Terms printed on the invoice</label><textarea id="ivs-terms" className="form-input" rows={3} value={f.terms ?? ''} onChange={set('terms')} /></div>

      <div style={{ marginTop: 16, display: 'flex', gap: 10, alignItems: 'center' }}><button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Save invoice settings'}</button>{!d.storage_ok && <span style={{ color: 'var(--danger)', fontSize: 13 }}>The invoice folder on the server is not writable.</span>}</div>
    </form>
  )
}
