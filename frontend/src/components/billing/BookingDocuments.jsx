import { useState, useEffect, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { travel, inr } from '../../api/travel'
import { toast } from '../Toast'
import { validGstin } from '../../lib/gst'

const TYPE = {
  tax_invoice:    ['#dcfce7', '#15803d', 'Tax invoice'],
  bill_of_supply: ['#e0f2fe', '#0369a1', 'Bill of supply'],
  credit_note:    ['#fee2e2', '#b91c1c', 'Credit note'],
  receipt:        ['#f1f5f9', '#475569', 'Receipt'],
}
const lbl = { display: 'block', fontSize: 12.5, fontWeight: 700, color: 'var(--text-2)', marginBottom: 4 }
const shareUrl = (t) => `${window.location.origin}/api/v1/public/invoices/${t}/pdf`

function Modal({ title, onClose, children }) {
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', justifyContent: 'center', padding: 16, overflowY: 'auto' }}>
      <div className="card card-body" onClick={e => e.stopPropagation()} style={{ width: '100%', maxWidth: 560, margin: 'auto' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between' }}><h3 style={{ margin: 0 }}>{title}</h3><button className="btn btn-sm btn-ghost" onClick={onClose}>✕</button></div>
        {children}
      </div>
    </div>
  )
}

function IssueModal({ bookingId, kind, defaults, states, einvoice, onClose, onDone }) {
  const [f, setF] = useState({ name: defaults.name || '', address_line1: '', address_line2: '', city: defaults.city || '', state: defaults.state || '', pincode: '', gstin: '', email: defaults.email || '', phone: defaults.phone || '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const set = (k) => (e) => setF(x => ({ ...x, [k]: e.target.value }))
  const gst = f.gstin.trim()
  const stateName = (c) => states.find(s => s.code === c)?.name
  async function submit(e) {
    e.preventDefault(); setBusy(true); setErr('')
    try {
      const stateField = /^\d{2}$/.test(f.state) ? (stateName(f.state) || f.state) : f.state
      const r = await travel.issueInvoice(bookingId, { ...f, state: stateField })
      toast.success(`${r.number} issued`); onDone(r)
    } catch (er) { setErr(er.message) } finally { setBusy(false) }
  }
  return (
    <Modal title={`Issue ${kind === 'bill_of_supply' ? 'bill of supply' : 'GST tax invoice'}`} onClose={onClose}>
      <p style={{ color: 'var(--text-2)' }}>Check the customer's details — once issued, an invoice <b>cannot be edited</b> (corrections are made with a credit note).</p>
      <form onSubmit={submit} style={{ display: 'grid', gap: 10 }}>
        <div><label style={lbl}>Customer name *</label><input className="form-input" value={f.name} onChange={set('name')} required /></div>
        <div><label style={lbl}>Address</label><input className="form-input" placeholder="Address line 1" value={f.address_line1} onChange={set('address_line1')} /><input className="form-input" style={{ marginTop: 6 }} placeholder="Address line 2" value={f.address_line2} onChange={set('address_line2')} /></div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 8 }}>
          <div><label style={lbl}>City</label><input className="form-input" value={f.city} onChange={set('city')} /></div>
          <div><label style={lbl}>State</label>
            <select className="form-select" value={states.find(s => s.name === f.state)?.code || (/^\d{2}$/.test(f.state) ? f.state : '')} onChange={e => setF(x => ({ ...x, state: e.target.value }))}><option value="">Choose…</option>{states.map(s => <option key={s.code} value={s.code}>{s.name}</option>)}</select></div>
          <div><label style={lbl}>PIN code</label><input className="form-input" maxLength={6} value={f.pincode} onChange={set('pincode')} /></div>
        </div>
        {kind !== 'bill_of_supply' && <div><label style={lbl}>Customer GSTIN <small style={{ fontWeight: 400 }}>(only if the customer is GST-registered and wants input credit)</small></label>
          <input className="form-input" value={f.gstin} onChange={e => setF(x => ({ ...x, gstin: e.target.value.toUpperCase().replace(/\s/g, '') }))} maxLength={15} style={{ borderColor: gst.length === 15 ? (validGstin(gst) ? 'var(--success)' : 'var(--danger)') : undefined }} />
          {gst.length === 15 && !validGstin(gst) && <small style={{ color: 'var(--danger)' }}>This GSTIN is not valid.</small>}</div>}
        {einvoice && validGstin(gst) && <div style={{ background: '#e0f2fe', padding: 8, borderRadius: 8, fontSize: 13 }}>🧾 <b>E-invoice:</b> this invoice will be registered with the government's Invoice Registration Portal before it is issued, so the customer's <b>address, city, state and 6-digit PIN</b> are required. If registration fails, nothing is issued and no invoice number is used.</div>}
        <div style={{ fontSize: 13, color: 'var(--text-3)' }}>No state given → treated as your own state (CGST + SGST). A state or GSTIN in another state → IGST.</div>
        {err && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8, whiteSpace: 'pre-line' }}>{err}</div>}
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button className="btn btn-primary" disabled={busy}>{busy ? (einvoice && validGstin(gst) ? 'Registering with the GST portal…' : 'Issuing…') : 'Issue'}</button></div>
      </form>
    </Modal>
  )
}

function CreditModal({ invoice, onClose, onDone }) {
  const [full, setFull] = useState(true)
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  async function submit(e) {
    e.preventDefault(); setBusy(true); setErr('')
    try { const r = await travel.creditNote(invoice.id, { reason, full, amount_rs: full ? undefined : Number(amount) }); toast.success(`${r.number} issued`); onDone(r) }
    catch (er) { setErr(er.message) } finally { setBusy(false) }
  }
  return (
    <Modal title={`Credit note against ${invoice.number}`} onClose={onClose}>
      <p style={{ color: 'var(--text-2)' }}>Use a credit note to correct or cancel an issued invoice. Tax and TCS are reduced in proportion.</p>
      <form onSubmit={submit} style={{ display: 'grid', gap: 10 }}>
        <label><input type="radio" checked={full} onChange={() => setFull(true)} /> Credit the full remaining amount (cancels the invoice)</label>
        <label><input type="radio" checked={!full} onChange={() => setFull(false)} /> Credit part of it</label>
        {!full && <div><label style={lbl}>Amount to credit, before tax (₹)</label><input className="form-input" type="number" min="1" step="0.01" value={amount} onChange={e => setAmount(e.target.value)} required /></div>}
        <div><label style={lbl}>Reason * <small style={{ fontWeight: 400 }}>(printed on the credit note)</small></label><input className="form-input" value={reason} onChange={e => setReason(e.target.value)} required placeholder="e.g. Booking cancelled by customer" /></div>
        {err && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8 }}>{err}</div>}
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button className="btn btn-danger" disabled={busy}>{busy ? 'Issuing…' : 'Issue credit note'}</button></div>
      </form>
    </Modal>
  )
}

export default function BookingDocuments({ bookingId, refreshKey }) {
  const [d, setD] = useState(null)
  const [states, setStates] = useState([])
  const [issue, setIssue] = useState(false)
  const [credit, setCredit] = useState(null)
  const load = useCallback(() => travel.bookingDocs(bookingId).then(setD).catch(e => toast.error('Could not load documents', e.message)), [bookingId])
  useEffect(() => { load() }, [load, refreshKey])
  useEffect(() => { travel.billingProfile().then(r => setStates(r.states)).catch(() => {}) }, [])
  if (!d) return null

  const cancelEi = async (doc) => {
    const reason = window.prompt('Why? Type 1 = duplicate, 2 = data entry mistake, 3 = order cancelled, 4 = other', '2')
    if (!reason) return
    const remark = window.prompt('A short remark for the record (e.g. wrong customer address)')
    if (!remark) return
    if (!window.confirm(`Cancel the e-invoice for ${doc.number}? The invoice becomes cancelled and the customer can no longer open it. You can then issue a corrected invoice.`)) return
    try { await travel.cancelEinvoice(doc.id, Number(reason), remark); toast.success('E-invoice cancelled', 'Issue a corrected invoice now.'); load() } catch (e) { toast.error('Not cancelled', e.message) }
  }
  const sendWa = async (doc) => {
    if (!window.confirm(`Send ${doc.number} to the customer on WhatsApp?`)) return
    try { const r = await travel.sendDocWhatsApp('invoice', doc.id); toast.success('Sent on WhatsApp', r.mode === 'template' ? 'Sent with the approved template.' : r.mode === 'link' ? 'Sent as a download link.' : 'The PDF was sent.'); load() }
    catch (e) { toast.error('Not sent', e.message) }
  }
  const setupTpl = async () => { try { await travel.setupDocTemplate(); toast.success('Template created', 'Submit "Document Ready" from Templates for Meta approval.'); load() } catch (e) { toast.error('Could not create', e.message) } }
  const open = (doc) => travel.openInvoicePdf(doc.id).catch(e => toast.error('Could not open', e.message))
  const copy = async (doc) => { try { await navigator.clipboard.writeText(shareUrl(doc.share_token)); toast.success('Link copied', 'Anyone with this link can download the PDF.') } catch { toast.error('Could not copy', shareUrl(doc.share_token)) } }
  const kindLabel = d.document_type === 'bill_of_supply' ? 'bill of supply' : 'tax invoice'
  const th = { padding: '7px 8px', textAlign: 'left', color: 'var(--text-3)', fontSize: 11.5, textTransform: 'uppercase' }

  return (
    <div className="card card-body">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
        <h3 style={{ margin: 0 }}>Documents</h3>
        {!d.active_invoice && d.can_issue && <button className="btn btn-sm btn-primary" onClick={() => setIssue(true)}>Issue {kindLabel}</button>}
      </div>
      {!d.can_issue && <p style={{ color: 'var(--warning)', margin: '8px 0' }}>Complete your <Link to="/settings/business">business profile</Link> to issue invoices (missing: {d.missing.join(', ')}).</p>}
      {d.document_type === 'bill_of_supply' && d.can_issue && <p style={{ color: 'var(--text-3)', margin: '8px 0', fontSize: 13 }}>No GSTIN on your profile, so a <b>Bill of Supply</b> (no GST) is issued. <Link to="/settings/business">Add your GSTIN</Link> for GST invoices.</p>}
      {d.documents.length === 0 ? <p style={{ color: 'var(--text-3)', marginBottom: 0 }}>Nothing issued yet.</p> : (
        <div style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13.5, marginTop: 6 }}>
          <thead><tr><th style={th}>Document</th><th style={th}>Date</th><th style={{ ...th, textAlign: 'right' }}>Total</th><th style={th}></th></tr></thead>
          <tbody>{d.documents.map(doc => { const [bg, fg, label] = TYPE[doc.doc_type]; return (
            <tr key={doc.id} style={{ borderTop: '1px solid var(--border)' }}>
              <td style={{ padding: '8px' }}><b>{doc.number}</b> <span style={{ background: bg, color: fg, padding: '1px 8px', borderRadius: 999, fontSize: 11.5, fontWeight: 700 }}>{label}</span>
                {doc.reason && <div style={{ fontSize: 12, color: 'var(--text-3)' }}>{doc.reason}</div>}
                {doc.einvoice && <div style={{ fontSize: 12, color: doc.einvoice.status === 'cancelled' ? 'var(--danger)' : 'var(--text-3)' }}>
                  {doc.einvoice.status === 'cancelled' ? `E-invoice cancelled — ${doc.einvoice.cancel_reason || ''}` : <>E-invoice · IRN <span title={doc.einvoice.irn} style={{ cursor: 'copy' }} onClick={() => navigator.clipboard?.writeText(doc.einvoice.irn).then(() => toast.success('IRN copied')).catch(() => {})}>{doc.einvoice.irn.slice(0, 12)}…</span> · Ack {doc.einvoice.ack_no}</>}</div>}</td>
              <td style={{ padding: '8px' }}>{doc.issue_date}</td>
              <td style={{ padding: '8px', textAlign: 'right' }}>{doc.doc_type === 'credit_note' ? '−' : ''}{inr(doc.total)}</td>
              <td style={{ padding: '8px', whiteSpace: 'nowrap', textAlign: 'right' }}>
                <button className="btn btn-sm btn-ghost" onClick={() => open(doc)}>View PDF</button>
                <button className="btn btn-sm btn-ghost" onClick={() => sendWa(doc)} title="Send to the customer on WhatsApp">WhatsApp</button>
                <button className="btn btn-sm btn-ghost" onClick={() => copy(doc)} title="Copy a customer download link">🔗</button>
                {doc.einvoice?.cancel_hours_left != null && <button className="btn btn-sm btn-ghost" title={`The e-invoice can be cancelled for ${doc.einvoice.cancel_hours_left} more hours`} onClick={() => cancelEi(doc)}>Cancel e-invoice</button>}
                {['tax_invoice', 'bill_of_supply'].includes(doc.doc_type) && doc.status === 'issued' && <button className="btn btn-sm btn-ghost" onClick={() => setCredit(doc)}>Credit note</button>}
              </td></tr>) })}</tbody></table></div>)}

      {d.documents.length > 0 && d.whatsapp_template !== 'approved' && (
        <p style={{ color: 'var(--text-3)', fontSize: 12.5, margin: '8px 0 0' }}>
          WhatsApp sends the PDF directly while the customer has messaged you in the last 24 hours. Otherwise Meta requires an approved template
          {d.whatsapp_template === null ? <> — <button className="btn btn-sm btn-ghost" onClick={setupTpl}>create it</button></> : <> ("Document Ready" is {d.whatsapp_template} — submit it from Templates)</>}.
        </p>)}
      {d.deliveries?.length > 0 && (
        <div style={{ marginTop: 8, fontSize: 12.5, color: 'var(--text-3)' }}>
          {d.deliveries.slice(0, 5).map(x => <div key={x.id}>{x.status === 'sent' ? '✓' : '✗'} {x.label} · WhatsApp ({x.mode}) · {x.sent_at}{x.error ? ` · ${x.error}` : ''}</div>)}
        </div>)}
      {issue && <IssueModal bookingId={bookingId} kind={d.document_type} defaults={d.buyer_defaults} states={states} einvoice={d.einvoice_enabled} onClose={() => setIssue(false)} onDone={() => { setIssue(false); load() }} />}
      {credit && <CreditModal invoice={credit} onClose={() => setCredit(null)} onDone={() => { setCredit(null); load() }} />}
    </div>
  )
}
