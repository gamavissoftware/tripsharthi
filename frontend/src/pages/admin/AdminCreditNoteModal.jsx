import { useEffect, useState } from 'react'
import { X } from 'lucide-react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { fmtDate } from './adminUtil'

const inr2 = (p) => '₹' + (Number(p || 0) / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/** Issue a credit note (refund document) against one invoice: the refund amount INCLUDING GST, a reason, and the refund reference once the money has been returned. */
export default function AdminCreditNoteModal({ invoice, onClose, onDone }) {
  const [info, setInfo] = useState(null)
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [ref, setRef] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => { admin.creditNotesFor(invoice.id).then(setInfo).catch(e => { toast.error('Could not load', e.message); onClose() }) }, [invoice.id, onClose])
  const left = info?.remaining.total ?? 0
  const amountPaise = amount === '' ? null : Math.round(Number(amount) * 100)
  const full = amountPaise === null || amountPaise === left
  const valid = left > 0 && reason.trim().length >= 10 && (amountPaise === null || (amountPaise > 0 && amountPaise <= left))

  async function go() {
    setBusy(true)
    try {
      const r = await admin.creditNote(invoice.id, { amount_rs: amount === '' ? undefined : Number(amount), full: amount === '', reason, refund_ref: ref })
      const c = r.commission
      toast.success('Credit note issued', `${r.credit_note?.number ?? ''} · e-mailed to the customer` + (c === 'voided' ? ' · the partner commission on this payment was cancelled' : ''))
      if (c === 'paid_out') toast.info('Partner commission already paid', 'The partner was already paid on this payment: settle it with them by hand.')
      onDone()
    } catch (e) { toast.error('Not issued', e.message) } finally { setBusy(false) }
  }

  return (
    <div className="iv-backdrop" onClick={onClose}>
      <div className="iv-modal" onClick={e => e.stopPropagation()} role="dialog" aria-label="Issue a credit note">
        <div className="iv-modal-head"><div><b>Credit note for {invoice.number}</b><div className="cu-sub">{invoice.buyer_name} · invoice {inr2(invoice.total)} of {fmtDate(invoice.issue_date)}</div></div><button type="button" className="btn btn-ghost btn-sm" onClick={onClose} aria-label="Close"><X size={16} /></button></div>
        {!info ? <div style={{ padding: 20, color: 'var(--text-3)' }}>Loading…</div> : <>
          <div className="iv-modal-body">
            <div className="iv-ok" style={{ background: '#eef5fd', borderColor: '#bcdcf5', color: '#074a8c' }}>Still creditable: <b>{inr2(left)}</b>{info.remaining.credited > 0 ? ` (already credited ${inr2(info.remaining.credited)})` : ''}</div>
            {info.notes.length > 0 && <div className="cu-sub" style={{ margin: '8px 0' }}>Earlier credit notes: {info.notes.map(n => `${n.number} ${inr2(n.total)}`).join(' · ')}</div>}
            {left <= 0 ? <p style={{ marginTop: 12 }}>This invoice is fully credited.</p> : <>
              <label className="iv-lbl">Refund amount, including GST (₹)</label>
              <div style={{ display: 'flex', gap: 8 }}>
                <input className="form-input" type="number" min="0" step="0.01" max={(left / 100).toFixed(2)} value={amount} onChange={e => setAmount(e.target.value)} placeholder={`Leave empty for the full ${(left / 100).toFixed(2)}`} />
                <button type="button" className="btn btn-ghost btn-sm" onClick={() => setAmount((left / 100).toFixed(2))}>Full amount</button>
              </div>
              <div className="cu-sub" style={{ marginTop: 4 }}>The GST is split out at the invoice's rate ({invoice.supply_type === 'intra' ? 'CGST + SGST' : 'IGST'}). {full ? 'This credits everything that is left on the invoice.' : 'A partial credit; you can issue more later.'}</div>
              <label className="iv-lbl">Reason (printed on the credit note, at least 10 characters)</label>
              <textarea className="form-input" rows={2} maxLength={300} value={reason} onChange={e => setReason(e.target.value)} placeholder="e.g. Customer cancelled within 7 days of paying; refund agreed" />
              <label className="iv-lbl">Refund reference (optional)</label>
              <input className="form-input" maxLength={80} value={ref} onChange={e => setRef(e.target.value)} placeholder="Razorpay refund id or bank reference" />
              <div className="iv-missing" style={{ marginTop: 12, marginBottom: 0 }}><div style={{ fontSize: 13 }}><b>This records the refund, it does not send money.</b> Refund the customer in Razorpay or your bank, then issue the credit note. It is e-mailed to the customer{full ? ' and the partner commission on this payment is cancelled if it has not been paid out yet' : ''}. It cannot be edited or deleted afterwards.</div></div>
            </>}
          </div>
          <div className="iv-modal-foot"><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button>{left > 0 && <button type="button" className="btn btn-primary" disabled={!valid || busy} onClick={go}>{busy ? 'Issuing…' : 'Issue credit note'}</button>}</div>
        </>}
      </div>
    </div>
  )
}
