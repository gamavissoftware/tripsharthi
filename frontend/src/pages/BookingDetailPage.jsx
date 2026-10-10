import { useState, useEffect, useCallback } from 'react'
import { useParams, Link } from 'react-router-dom'
import { travel, inr } from '../api/travel'
import { fmt } from '../lib/currency'
import { toast } from '../components/Toast'
import Pill from '../components/Pill'
import { BOOKING_STATUS } from './BookingsPage'
import BookingDocuments from '../components/billing/BookingDocuments'
import BookingChecklist from '../components/travel/BookingChecklist'
import BookingPortalCard from '../components/travel/BookingPortalCard'
import LoadFailed from '../components/LoadFailed'

const PAY = { pending: { label: 'Pending', bg: '#fef9c3', color: '#a16207' }, paid: { label: 'Paid', bg: '#dcfce7', color: '#15803d' }, overdue: { label: 'Overdue', bg: '#fee2e2', color: '#b91c1c' }, waived: { label: 'Waived', bg: '#f1f5f9', color: '#475569' } }
const SVC = { to_book: { label: 'To book', bg: '#fef9c3', color: '#a16207' }, requested: { label: 'Requested', bg: '#dbeafe', color: '#1d4ed8' }, confirmed: { label: 'Confirmed', bg: '#dcfce7', color: '#15803d' }, cancelled: { label: 'Cancelled', bg: '#fee2e2', color: '#b91c1c' } }

export default function BookingDetailPage() {
  const { id } = useParams()
  const [b, setB] = useState(null)
  const [docKey, setDocKey] = useState(0)
  const [nowMs] = useState(() => Date.now())
  const [tr, setTr] = useState({ full_name: '', passport_no: '', passport_expiry: '', dob: '' })
  const [loadErr, setLoadErr] = useState('')
  const load = useCallback(() => travel.booking(id).then(x => { setB(x); setLoadErr('') }).catch(e => { setLoadErr(e.message || 'Not found'); toast.error('Could not load', e.message) }), [id])
  useEffect(() => { load() }, [load])
  if (!b && loadErr) return <LoadFailed what="booking" message={loadErr} backTo="/bookings" backLabel="Back to bookings" />
  if (!b) return <div className="page">Loading…</div>

  async function pay(p) {
    const mode = window.prompt('Payment mode (upi / card / bank / cash)', 'upi'); if (!mode) return
    const ref = window.prompt('Reference / UTR (optional)') || undefined
    try { await travel.markPaid(p.id, { mode, reference: ref }); toast.success('Payment recorded'); load() } catch (e) { toast.error('Failed', e.message) }
  }
  async function getLink(p) {
    try {
      const l = await travel.paymentLink(p.id)
      try { await navigator.clipboard.writeText(l.short_url) } catch { /* clipboard may be blocked */ }
      toast.success('Payment link ready', l.short_url + ' (copied)'); load()
    } catch (e) { toast.error('Could not create link', e.message) }
  }
  async function remind(p) {
    try { const r = await travel.remindNow(p.id); toast.success('Reminder sent', r.channel === 'whatsapp_text' ? 'as a WhatsApp message' : 'using your approved template'); load() }
    catch (e) { toast.error('Reminder not sent', e.message) }
  }
  async function history(p) {
    try {
      const rows = await travel.reminders(p.id)
      window.alert(rows.length ? rows.map(r => `${(r.sent_at || r.created_at)?.slice(0, 16)}  ${r.step.replace(/_/g, ' ')}  —  ${r.status}${r.reason ? ' (' + r.reason + ')' : ''}`).join('\n') : 'No reminders yet.')
    } catch (e) { toast.error('Failed', e.message) }
  }
  async function receipt(p) {
    try { const r = await travel.issueReceipt(p.id); await travel.openInvoicePdf(r.id); setDocKey(k => k + 1) }
    catch (e) { toast.error('Receipt not issued', e.message) }
  }
  async function addTraveler(e) {
    e.preventDefault()
    try { await travel.addTraveler(b.id, { ...tr, passport_expiry: tr.passport_expiry || undefined, dob: tr.dob || undefined }); setTr({ full_name: '', passport_no: '', passport_expiry: '', dob: '' }); toast.success('Traveller added'); load() } catch (e) { toast.error('Failed', e.message) }
  }
  const soon = (d) => d && new Date(d) < new Date(nowMs + 180 * 864e5)

  return (
    <div className="page">
      <div className="page-header">
        <div><Link to="/bookings" style={{ fontSize: 13 }}>← Bookings</Link><h1 className="page-title" style={{ margin: '4px 0' }}>{b.booking_ref} <Pill map={BOOKING_STATUS} value={b.status} /></h1><div>{b.title}</div></div>
        <div style={{ textAlign: 'right' }}><div style={{ fontSize: 22, fontWeight: 800 }}>{inr(b.total_amount)}</div><div style={{ color: 'var(--text-3)' }}>Paid {inr(b.paid_amount)} · Due <b style={{ color: b.due_amount > 0 ? 'var(--danger)' : 'var(--success)' }}>{inr(b.due_amount)}</b></div></div>
      </div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(340px,1fr))', gap: 16 }}>
        <div className="card card-body">
          <h3 style={{ marginTop: 0 }}>Customer payments</h3>
          {b.payments.map(p => (
            <div key={p.id} style={{ padding: '9px 0', borderTop: '1px solid var(--border)' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: 8 }}>
                <span><b>{p.label}</b><br /><small style={{ color: 'var(--text-3)' }}>Due {p.due_date}{p.mode ? ` · ${p.mode}` : ''}</small></span>
                <span style={{ display: 'flex', gap: 8, alignItems: 'center', whiteSpace: 'nowrap' }}><b>{inr(p.amount)}</b><Pill map={PAY} value={p.status} /></span>
              </div>
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 6 }}>
                {p.status !== 'paid' && p.status !== 'waived' && <>
                  <button className="btn btn-sm btn-ghost" title="Create / copy the customer's Razorpay payment link" onClick={() => getLink(p)}>🔗 Link</button>
                  <button className="btn btn-sm btn-ghost" title="Send a WhatsApp payment reminder now" onClick={() => remind(p)}>💬 Remind</button>
                  <button className="btn btn-sm btn-success" onClick={() => pay(p)}>Mark paid</button></>}
                {p.status === 'paid' && <button className="btn btn-sm btn-ghost" title="Payment receipt (PDF)" onClick={() => receipt(p)}>🧾 Receipt</button>}
                {Number(p.reminder_count) > 0 && <button className="btn btn-sm btn-ghost" onClick={() => history(p)} title="Reminder history">🔔 {p.reminder_count}</button>}
              </div>
            </div>))}
          <small style={{ color: 'var(--text-3)' }}>GST {inr(b.gst_amount)}{Number(b.tcs_amount) > 0 ? ` · TCS ${inr(b.tcs_amount)} (collected, remit via Form 27EQ)` : ''}</small>
        </div>
        <BookingDocuments bookingId={b.id} refreshKey={docKey} />
        <BookingPortalCard bookingId={b.id} cancelled={b.status === 'cancelled'} />
        <BookingChecklist bookingId={b.id} refreshKey={b.travelers.length} />
        <div className="card card-body">
          <h3 style={{ marginTop: 0 }}>Supplier services <small style={{ color: 'var(--text-3)', fontWeight: 400 }}>(cost {inr(b.cost_total)})</small></h3>
          {b.services.map(s => (
            <div key={s.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '9px 0', borderTop: '1px solid var(--border)', gap: 8 }}>
              <span>{s.title}<br /><small style={{ color: 'var(--text-3)' }}>{s.confirmation_no ? `Conf# ${s.confirmation_no} · ` : ''}{s.cost_currency && s.cost_currency !== 'INR' && s.cost_fx != null ? `${fmt(s.cost_fx, s.cost_currency)} = ` : ''}{inr(s.cost_amount)}{s.fx_variance != null && Number(s.fx_variance) !== 0 ? <span style={{ color: Number(s.fx_variance) > 0 ? 'var(--danger)' : 'var(--success)' }}> · forex {Number(s.fx_variance) > 0 ? 'loss' : 'gain'} {inr(Math.abs(Number(s.fx_variance)))}</span> : ''}</small></span>
              <span style={{ display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap', justifyContent: 'flex-end' }}><Pill map={SVC} value={s.status} />
                {s.voucher_token && s.status === 'confirmed' && <a className="btn btn-sm btn-ghost" href={`/api/v1/public/vouchers/${s.voucher_token}/pdf`} target="_blank" rel="noreferrer" title="Service voucher for the traveller (PDF)">📄 Voucher</a>}
                {s.voucher_token && s.status === 'confirmed' && <button className="btn btn-sm btn-ghost" title="Send the voucher to the customer on WhatsApp" onClick={async () => { if (!window.confirm('Send this voucher to the customer on WhatsApp?')) return; try { await travel.sendDocWhatsApp('voucher', s.id); toast.success('Voucher sent on WhatsApp') } catch (e) { toast.error('Not sent', e.message) } }}>Send</button>}
                {s.status !== 'confirmed' && <button className="btn btn-sm btn-ghost" onClick={async () => { const c = window.prompt('Supplier confirmation number'); if (c) { await travel.updateService(s.id, { status: 'confirmed', confirmation_no: c }); load() } }}>Confirm</button>}</span>
            </div>))}
        </div>
        <div className="card card-body">
          <h3 style={{ marginTop: 0 }}>Travellers</h3>
          {b.travelers.map(t => (
            <div key={t.id} style={{ padding: '8px 0', borderTop: '1px solid var(--border)' }}>
              <b>{t.full_name}</b> <small style={{ color: 'var(--text-3)' }}>{t.pax_type}</small>
              <div style={{ fontSize: 13 }}>{t.has_passport ? '🛂 Passport on file (encrypted)' : 'No passport yet'}{t.passport_expiry ? <span style={{ color: soon(t.passport_expiry) ? 'var(--danger)' : 'inherit' }}> · expires {t.passport_expiry}{soon(t.passport_expiry) ? ' ⚠ <6 months' : ''}</span> : ''}</div>
            </div>))}
          <form onSubmit={addTraveler} style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8, marginTop: 10 }}>
            <input className="form-input" placeholder="Full name (as on passport)" value={tr.full_name} onChange={e => setTr({ ...tr, full_name: e.target.value })} required />
            <input className="form-input" placeholder="Passport no." value={tr.passport_no} onChange={e => setTr({ ...tr, passport_no: e.target.value })} />
            <input className="form-input" type="date" title="Passport expiry" value={tr.passport_expiry} onChange={e => setTr({ ...tr, passport_expiry: e.target.value })} />
            <input className="form-input" type="date" title="Date of birth" value={tr.dob} onChange={e => setTr({ ...tr, dob: e.target.value })} />
            <button className="btn btn-sm btn-primary" style={{ gridColumn: '1 / -1' }}>Add traveller</button>
          </form>
        </div>
      </div>
    </div>
  )
}
