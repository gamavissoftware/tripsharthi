import { useState, useEffect, useCallback, useRef } from 'react'
import { travel, inr } from '../api/travel'

const ICON = { hotel: '🏨', flight: '✈️', train: '🚆', transfer: '🚗', sightseeing: '📸', activity: '🎯', meal: '🍽️', visa: '🛂', insurance: '🛡️', other: '•' }
const KIND = { tax_invoice: 'Tax invoice', bill_of_supply: 'Bill of supply', credit_note: 'Credit note', receipt: 'Payment receipt', voucher: 'Service voucher' }
const PAY = { pending: ['#e0f2fe', '#0369a1', 'Upcoming'], overdue: ['#fee2e2', '#b91c1c', 'Overdue'], paid: ['#dcfce7', '#15803d', 'Paid'], waived: ['#f1f5f9', '#475569', 'Waived'] }

function UploadButton({ token, item, onDone }) {
  const ref = useRef(null)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState('')
  async function pick(e) {
    const f = e.target.files?.[0]; e.target.value = ''
    if (!f) return
    setBusy(true); setMsg('')
    try { const r = await travel.portalUpload(token, item.id, f); if (r.success) onDone(); else setMsg(r.message || 'Upload failed') } catch { setMsg('Upload failed. Please check your connection.') } finally { setBusy(false) }
  }
  return (
    <span>
      <input ref={ref} type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" hidden onChange={pick} />
      <button className="btn btn-sm btn-primary" disabled={busy} onClick={() => ref.current?.click()}>{busy ? 'Uploading…' : item.status === 'uploaded' ? 'Replace' : 'Upload'}</button>
      {msg && <div style={{ color: 'var(--danger)', fontSize: 12 }}>{msg}</div>}
    </span>)
}

/** Customer self-service portal (#/trip/<token>). Public: no login, no app shell, no cost or margin. */
export default function PublicPortalPage({ token }) {
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const [payBusy, setPayBusy] = useState(0)
  const load = useCallback(() => travel.portalView(token).then(r => r.success ? (setD(r.data), setErr('')) : setErr(r.message || 'This link is not valid.')).catch(() => setErr('Could not load your trip. Please try again.')), [token])
  useEffect(() => { load() }, [load])

  async function pay(p) {
    setPayBusy(p.id)
    try { const r = await travel.portalPay(token, p.id); if (r.success) window.location.assign(r.data.url); else alert(r.message || 'Could not start the payment.') }
    catch { alert('Could not start the payment. Please try again.') } finally { setPayBusy(0) }
  }
  if (err) return <div style={{ padding: 40, textAlign: 'center' }}><h2>{err}</h2><p>Please ask your travel agent for a fresh link.</p></div>
  if (!d) return <div style={{ padding: 40, textAlign: 'center' }}>Loading your trip…</div>
  const { agency: a, booking: b, itinerary: it } = d
  const cancelled = b.status === 'cancelled'
  const wa = a.phone ? `https://wa.me/${String(a.phone).replace(/\D/g, '')}` : null

  return (
    <div style={{ maxWidth: 760, margin: '0 auto', padding: '20px 16px 60px', fontFamily: 'var(--font)' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 14 }}>
        {a.logo_url && <img src={a.logo_url} alt="" style={{ height: 40, maxWidth: 120, objectFit: 'contain' }} />}
        <div style={{ flex: 1 }}><b style={{ color: a.color }}>{a.name}</b><div style={{ fontSize: 12.5, color: 'var(--text-3)' }}>{[a.phone, a.email].filter(Boolean).join(' · ')}</div></div>
        {wa && <a className="btn btn-sm btn-ghost" href={wa} target="_blank" rel="noreferrer">💬 Chat</a>}
      </div>
      <h1 style={{ marginBottom: 2 }}>{b.title}</h1>
      <p style={{ marginTop: 0, color: 'var(--text-2)' }}>Booking {b.ref}{b.travel_start ? ` · ${b.travel_start}${b.travel_end ? ` → ${b.travel_end}` : ''}` : ''}</p>
      {cancelled && <div style={{ background: '#fee2e2', padding: 12, borderRadius: 8, marginBottom: 12 }}>This booking has been cancelled. Please contact {a.name} for any questions.</div>}

      <div className="card card-body" style={{ marginBottom: 14 }}>
        <h3 style={{ marginTop: 0 }}>Payments</h3>
        <div style={{ display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 8, marginBottom: 6 }}>
          <span>Total <b>{inr(b.total)}</b></span><span>Paid <b style={{ color: 'var(--success)' }}>{inr(b.paid)}</b></span><span>Balance <b>{inr(b.due)}</b></span></div>
        {d.payments.map(p => { const [bg, fg, label] = PAY[p.status] || PAY.pending; return (
          <div key={p.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, padding: '8px 0', borderTop: '1px solid var(--border)', flexWrap: 'wrap' }}>
            <span><b>{p.label}</b><br /><small style={{ color: 'var(--text-3)' }}>{p.due_date ? `Due ${p.due_date}` : ''}</small></span>
            <span style={{ display: 'flex', gap: 8, alignItems: 'center' }}><b>{inr(p.amount)}</b><span style={{ background: bg, color: fg, padding: '1px 8px', borderRadius: 999, fontSize: 11.5, fontWeight: 700 }}>{label}</span>
              {p.payable && <button className="btn btn-sm btn-primary" disabled={payBusy === p.id} onClick={() => pay(p)}>{payBusy === p.id ? 'Opening…' : 'Pay now'}</button>}</span>
          </div>) })}
      </div>

      {d.checklist.length > 0 && (
        <div className="card card-body" style={{ marginBottom: 14 }}>
          <h3 style={{ marginTop: 0 }}>Documents we need from you <small style={{ fontWeight: 400, color: 'var(--text-3)' }}>({d.progress.done}/{d.progress.total} done)</small></h3>
          {d.checklist.map(i => (
            <div key={i.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, padding: '8px 0', borderTop: '1px solid var(--border)', flexWrap: 'wrap' }}>
              <span style={{ flex: '1 1 260px' }}>{i.status === 'received' ? '✅' : i.status === 'uploaded' ? '⏳' : i.status === 'not_applicable' ? '➖' : '⬜'} {i.label}{!i.required && <small style={{ color: 'var(--text-3)' }}> (optional)</small>}
                {i.status === 'uploaded' && <div style={{ fontSize: 12, color: 'var(--text-3)' }}>Received — we are checking it.</div>}
                {i.rejected_reason && i.status === 'pending' && <div style={{ fontSize: 12, color: 'var(--danger)' }}>Please upload again: {i.rejected_reason}</div>}
                {i.due_date && i.status === 'pending' && <div style={{ fontSize: 12, color: 'var(--text-3)' }}>Needed by {i.due_date}</div>}</span>
              {i.can_upload && !cancelled && <UploadButton token={token} item={i} onDone={load} />}
            </div>))}
          <small style={{ color: 'var(--text-3)' }}>PDF, JPG or PNG, up to 5 MB. Your files are stored encrypted and only {a.name} can see them.</small>
        </div>)}

      {d.documents.length > 0 && (
        <div className="card card-body" style={{ marginBottom: 14 }}>
          <h3 style={{ marginTop: 0 }}>Your documents</h3>
          {d.documents.map((x, i) => <div key={i} style={{ padding: '6px 0', borderTop: i ? '1px solid var(--border)' : 'none' }}><a href={x.url} target="_blank" rel="noreferrer">📄 {KIND[x.kind] || 'Document'} — {x.title}</a></div>)}
        </div>)}

      {it && it.days?.length > 0 && (
        <div style={{ marginBottom: 14 }}>
          <h3>Your itinerary</h3>
          {it.days.map(day => (
            <div key={day.id} className="card card-body" style={{ marginBottom: 10 }}>
              <b>Day {day.day_no}: {day.title}</b>
              {day.description && <p style={{ margin: '4px 0' }}>{day.description}</p>}
              {day.items.map(x => <div key={x.id} style={{ padding: '2px 0' }}>{ICON[x.type] || '•'} {x.title}</div>)}
            </div>))}
        </div>)}
    </div>
  )
}
