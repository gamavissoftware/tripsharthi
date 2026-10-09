import { useState, useEffect } from 'react'
import { travel } from '../api/travel'
import { toast } from '../components/Toast'
import { validGstin, stateOfGstin, panOfGstin } from '../lib/gst'

const lbl = { display: 'block', fontSize: 12.5, fontWeight: 700, color: 'var(--text-2)', marginBottom: 4 }
const grid = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(240px,1fr))', gap: 12 }

function Field({ label, hint, children }) {
  return <div className="form-group" style={{ marginBottom: 0 }}><label style={lbl}>{label}</label>{children}{hint && <small style={{ color: 'var(--text-3)' }}>{hint}</small>}</div>
}

export default function BusinessProfilePage() {
  const [p, setP] = useState(null)
  const [states, setStates] = useState([])
  const [missing, setMissing] = useState([])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => { travel.billingProfile().then(r => { setP(r.profile); setStates(r.states); setMissing(r.missing_for_invoice) }).catch(e => toast.error('Could not load', e.message)) }, [])
  if (!p) return <div className="page">Loading…</div>

  const set = (k) => (e) => setP(x => ({ ...x, [k]: e.target.value }))
  const g = (p.gstin || '').trim()
  const gstinState = !g ? null : validGstin(g) ? 'ok' : g.length >= 15 ? 'bad' : 'typing'

  function onGstin(e) {
    const v = e.target.value.toUpperCase().replace(/\s/g, '')
    setP(x => {
      const n = { ...x, gstin: v }
      if (validGstin(v)) { n.state_code = stateOfGstin(v); n.pan = panOfGstin(v) }   // a GSTIN fixes both: fill, don't ask twice
      return n
    })
  }

  async function save(e) {
    e.preventDefault()
    setSaving(true); setError('')
    try { const r = await travel.saveBillingProfile(p); setP(r); toast.success('Business profile saved'); travel.billingProfile().then(x => setMissing(x.missing_for_invoice)) }
    catch (er) { setError(er.message); toast.error('Not saved', er.message) } finally { setSaving(false) }
  }

  return (
    <div className="page" style={{ maxWidth: 900 }}>
      <div className="page-header"><h1 className="page-title">Business &amp; invoicing</h1></div>
      <div className="card card-body" style={{ marginBottom: 14 }}>
        <p style={{ margin: 0 }}>These details appear on every quote, <b>GST invoice</b>, credit note and receipt. They are copied onto each document when it is issued, so changing them later never alters an invoice you already sent.</p>
        {!p.gstin && <p style={{ margin: '8px 0 0', color: 'var(--warning)' }}>No GSTIN entered: TripSarthi will issue a <b>Bill of Supply</b> (no GST). Add your GSTIN to issue GST tax invoices.</p>}
        {missing.filter(m => !m.startsWith('GSTIN')).length > 0 && <p style={{ margin: '8px 0 0', color: 'var(--danger)' }}>Still needed before you can issue invoices: {missing.filter(m => !m.startsWith('GSTIN')).join(', ')}.</p>}
      </div>

      <form onSubmit={save}>
        <div className="card card-body" style={{ marginBottom: 14 }}>
          <h3 style={{ marginTop: 0 }}>Legal identity</h3>
          <div style={grid}>
            <Field label="Legal business name *"><input className="form-input" value={p.legal_name || ''} onChange={set('legal_name')} placeholder="As registered, e.g. Demo Travels Private Limited" /></Field>
            <Field label="Trade / brand name"><input className="form-input" value={p.trade_name || ''} onChange={set('trade_name')} /></Field>
            <Field label="GSTIN" hint={gstinState === 'ok' ? '✅ Valid — state and PAN filled in' : gstinState === 'bad' ? '❌ Not valid: check every character (the last one is a check character)' : gstinState === 'typing' ? 'Keep typing (15 characters)…' : 'Leave blank if you are not GST-registered'}>
              <input className="form-input" value={p.gstin || ''} onChange={onGstin} maxLength={15} placeholder="27ABCDE1234F1Z0" style={{ borderColor: gstinState === 'bad' ? 'var(--danger)' : gstinState === 'ok' ? 'var(--success)' : undefined }} /></Field>
            <Field label="PAN"><input className="form-input" value={p.pan || ''} onChange={e => setP(x => ({ ...x, pan: e.target.value.toUpperCase() }))} maxLength={10} /></Field>
            <Field label="SAC code" hint="Tour operator services. Confirm the code and the GST rate with your CA."><input className="form-input" value={p.sac_code || ''} onChange={set('sac_code')} maxLength={8} /></Field>
          </div>
        </div>

        <div className="card card-body" style={{ marginBottom: 14 }}>
          <h3 style={{ marginTop: 0 }}>Address &amp; contact</h3>
          <div style={grid}>
            <Field label="Address line 1 *"><input className="form-input" value={p.address_line1 || ''} onChange={set('address_line1')} /></Field>
            <Field label="Address line 2"><input className="form-input" value={p.address_line2 || ''} onChange={set('address_line2')} /></Field>
            <Field label="City *"><input className="form-input" value={p.city || ''} onChange={set('city')} /></Field>
            <Field label="State *" hint="Decides CGST+SGST vs IGST on invoices"><select className="form-select" value={p.state_code || ''} onChange={set('state_code')}><option value="">Choose…</option>{states.map(s => <option key={s.code} value={s.code}>{s.name} ({s.code})</option>)}</select></Field>
            <Field label="PIN code *"><input className="form-input" value={p.pincode || ''} onChange={set('pincode')} maxLength={6} inputMode="numeric" /></Field>
            <Field label="Phone"><input className="form-input" value={p.phone || ''} onChange={set('phone')} /></Field>
            <Field label="Email"><input className="form-input" type="email" value={p.email || ''} onChange={set('email')} /></Field>
            <Field label="Website"><input className="form-input" value={p.website || ''} onChange={set('website')} /></Field>
          </div>
        </div>

        <div className="card card-body" style={{ marginBottom: 14 }}>
          <h3 style={{ marginTop: 0 }}>Payment details</h3>
          <p style={{ color: 'var(--text-2)', marginTop: 0 }}>Shown on invoices. With a UPI ID, each invoice carries a QR code for the exact balance due.</p>
          <div style={grid}>
            <Field label="Account name"><input className="form-input" value={p.bank_account_name || ''} onChange={set('bank_account_name')} /></Field>
            <Field label="Bank"><input className="form-input" value={p.bank_name || ''} onChange={set('bank_name')} /></Field>
            <Field label="Account number"><input className="form-input" value={p.bank_account_no || ''} onChange={set('bank_account_no')} /></Field>
            <Field label="IFSC"><input className="form-input" value={p.bank_ifsc || ''} onChange={e => setP(x => ({ ...x, bank_ifsc: e.target.value.toUpperCase() }))} maxLength={11} /></Field>
            <Field label="UPI ID"><input className="form-input" value={p.upi_id || ''} onChange={set('upi_id')} placeholder="yourbusiness@okhdfcbank" /></Field>
          </div>
        </div>

        <div className="card card-body" style={{ marginBottom: 14 }}>
          <h3 style={{ marginTop: 0 }}>Numbering &amp; branding</h3>
          <div style={grid}>
            <Field label="Invoice prefix" hint="1–4 letters. Numbers look like INV/26-27/00001 and restart each financial year."><input className="form-input" value={p.invoice_prefix || ''} onChange={e => setP(x => ({ ...x, invoice_prefix: e.target.value.toUpperCase() }))} maxLength={4} /></Field>
            <Field label="Credit note prefix"><input className="form-input" value={p.credit_prefix || ''} onChange={e => setP(x => ({ ...x, credit_prefix: e.target.value.toUpperCase() }))} maxLength={4} /></Field>
            <Field label="Receipt prefix"><input className="form-input" value={p.receipt_prefix || ''} onChange={e => setP(x => ({ ...x, receipt_prefix: e.target.value.toUpperCase() }))} maxLength={4} /></Field>
            <Field label="Authorised signatory"><input className="form-input" value={p.signatory || ''} onChange={set('signatory')} /></Field>
            <Field label="Logo (public https:// image link)"><input className="form-input" value={p.logo_url || ''} onChange={set('logo_url')} placeholder="https://yoursite.com/logo.png" /></Field>
            <Field label="Brand colour"><div style={{ display: 'flex', gap: 8 }}><input type="color" value={p.brand_color || '#08569f'} onChange={set('brand_color')} style={{ width: 44, height: 38, border: '1px solid var(--border)', borderRadius: 6 }} /><input className="form-input" value={p.brand_color || ''} onChange={set('brand_color')} maxLength={7} /></div></Field>
          </div>
        </div>

        <div className="card card-body" style={{ marginBottom: 14 }}>
          <h3 style={{ marginTop: 0 }}>Terms</h3>
          <Field label="Cancellation &amp; refund policy (printed on quotes)"><textarea className="form-input" rows={4} value={p.cancellation_policy || ''} onChange={set('cancellation_policy')} placeholder={'60+ days before departure: 10% of package cost\n30–59 days: 40%\n…'} /></Field>
          <div style={{ height: 10 }} />
          <Field label="Other quote terms"><textarea className="form-input" rows={3} value={p.quote_terms || ''} onChange={set('quote_terms')} /></Field>
          <div style={{ height: 10 }} />
          <Field label="Invoice terms (printed on invoices)"><textarea className="form-input" rows={3} value={p.invoice_terms || ''} onChange={set('invoice_terms')} placeholder="Payment due as per the agreed schedule. Subject to … jurisdiction." /></Field>
        </div>

        {error && <div style={{ background: '#fee2e2', padding: 12, borderRadius: 10, marginBottom: 12 }}>{error}</div>}
        <button className="btn btn-primary" disabled={saving}>{saving ? 'Saving…' : 'Save'}</button>
      </form>
    </div>
  )
}
