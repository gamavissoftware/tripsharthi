import { useEffect, useState } from 'react'
import { AlertTriangle, CheckCircle2 } from 'lucide-react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'

const lbl = { display: 'block', fontSize: 12.5, fontWeight: 700, marginBottom: 4 }

/** The software owner's details printed on every invoice (defaults from the GST registration certificate), the SAC / GST rate, numbering and the accountant's copy. */
export default function AdminInvoiceSettings() {
  const [d, setD] = useState(null)
  const [f, setF] = useState(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { admin.invoiceSettings().then(r => { setD(r); setF({ ...r.settings, auto_email: r.settings.auto_email === '1' }) }).catch(e => toast.error('Could not load', e.message)) }, [])
  if (!f) return <div className="card card-body" style={{ color: 'var(--text-3)' }}>Loading…</div>
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })
  const field = (k, label, props = {}) => <div><label style={lbl}>{label}</label><input className="form-input" value={f[k] ?? ''} onChange={set(k)} {...props} /></div>

  async function save(e) {
    e.preventDefault(); setBusy(true)
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
        {field('legal_name', 'Legal name')}{field('trade_name', 'Trade name')}
        {field('gstin', 'GSTIN', { maxLength: 15, spellCheck: false })}{field('pan', 'PAN', { maxLength: 10, spellCheck: false })}
        <div><label style={lbl}>State of registration</label><select className="form-select" value={f.state_code} onChange={set('state_code')}><option value="">Choose…</option>{d.states.map(s => <option key={s.code} value={s.code}>{s.name} ({s.code})</option>)}</select></div>
        {field('pincode', 'PIN code', { maxLength: 6 })}
      </div>
      <div className="iv-grid" style={{ marginTop: 12 }}>{field('address_line1', 'Address line 1')}{field('address_line2', 'Address line 2')}{field('city', 'City')}{field('phone', 'Phone')}{field('email', 'Contact e-mail', { type: 'email' })}{field('website', 'Website')}</div>

      <h4 style={{ margin: '20px 0 8px' }}>Tax and numbering</h4>
      <div className="iv-grid">
        {field('sac_code', 'SAC code', { maxLength: 8 })}{field('gst_rate', 'GST rate %', { type: 'number', min: 0, max: 40, step: '0.01' })}{field('prefix', 'Invoice number prefix (max 4)', { maxLength: 4 })}{field('credit_prefix', 'Credit note number prefix (max 4)', { maxLength: 4 })}{field('signatory', 'Authorised signatory')}
      </div>
      <div className="cu-sub" style={{ marginTop: 6 }}>Numbers look like GS/26-27/00001 for invoices and CN/26-27/00001 for credit notes (separate series, restarting each financial year, no gaps). Plan prices <b>exclude GST</b>: checkout charges the price plus this GST rate, and the invoice shows the price as taxable value with the GST on top. A payment you record by hand is treated as the amount received including GST. The SAC code is a default; your CA should confirm the right one for subscription software.</div>

      <h4 style={{ margin: '20px 0 8px' }}>Bank details (optional, printed on the invoice)</h4>
      <div className="iv-grid">{field('bank_account_name', 'Account name')}{field('bank_name', 'Bank')}{field('bank_account_no', 'Account number')}{field('bank_ifsc', 'IFSC')}{field('upi_id', 'UPI ID')}</div>

      <h4 style={{ margin: '20px 0 8px' }}>E-mail and terms</h4>
      <label style={{ display: 'flex', gap: 8, alignItems: 'center', fontSize: 14 }}><input type="checkbox" checked={f.auto_email} onChange={e => setF({ ...f, auto_email: e.target.checked })} /> E-mail the invoice to the customer's owner automatically when they pay</label>
      <div className="iv-grid" style={{ marginTop: 10 }}>{field('copy_to', 'Also send a copy of every invoice to (accountant)', { type: 'email', placeholder: 'accounts@yourfirm.com' })}</div>
      <div style={{ marginTop: 12 }}><label style={lbl}>Terms printed on the invoice</label><textarea className="form-input" rows={3} value={f.terms ?? ''} onChange={set('terms')} /></div>

      <div style={{ marginTop: 16, display: 'flex', gap: 10, alignItems: 'center' }}><button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Save invoice settings'}</button>{!d.storage_ok && <span style={{ color: 'var(--danger)', fontSize: 13 }}>The invoice folder on the server is not writable.</span>}</div>
    </form>
  )
}
