import { useState, useEffect, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { travel, inr } from '../api/travel'
import { toast } from '../components/Toast'
import { fmt, toMajor } from '../lib/currency'

const today = () => new Date().toISOString().slice(0, 10)
const th = { padding: '7px 8px', textAlign: 'left', color: 'var(--text-3)', fontSize: 11.5, textTransform: 'uppercase' }

function PayModal({ item, onClose, onDone }) {
  const fxc = item.fx?.currency
  const [f, setF] = useState({ amount_rs: fxc ? '' : String(item.outstanding / 100), fx_amount: fxc ? String(toMajor(item.fx.outstanding, fxc)) : '', paid_on: today(), mode: 'bank', reference: '' })
  const [hist, setHist] = useState(null)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  useEffect(() => { travel.payableService(item.service_id).then(setHist).catch(() => {}) }, [item.service_id])
  const set = (k) => (e) => setF(x => ({ ...x, [k]: e.target.value }))
  async function submit(e) {
    e.preventDefault(); setBusy(true); setErr('')
    try { await travel.paySupplier(item.service_id, { ...f, amount_rs: Number(f.amount_rs), ...(fxc ? { fx_amount: Number(f.fx_amount) } : {}) }); toast.success('Payment recorded'); onDone() } catch (er) { setErr(er.message) } finally { setBusy(false) }
  }
  async function remove(p) {
    if (!window.confirm(`Remove the payment of ${inr(p.amount)}? Use this only to correct a mistake.`)) return
    try { setHist(await travel.deleteSupplierPayment(p.id)); } catch (er) { toast.error('Not removed', er.message) }
  }
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', justifyContent: 'center', padding: 16, overflowY: 'auto' }}>
      <div className="card card-body" onClick={e => e.stopPropagation()} style={{ width: '100%', maxWidth: 480, margin: 'auto' }}>
        <h3 style={{ marginTop: 0 }}>Pay supplier — {item.title}</h3>
        {fxc ? <p style={{ color: 'var(--text-2)', marginTop: 0 }}>{item.booking_ref} · owed <b>{fmt(item.fx.outstanding, fxc)}</b> of {fmt(item.fx.cost, fxc)} (≈ {inr(item.outstanding)} today)</p>
          : <p style={{ color: 'var(--text-2)', marginTop: 0 }}>{item.booking_ref} · cost {inr(item.cost)} · paid {inr(item.paid)} · <b>outstanding {inr(item.outstanding)}</b></p>}
        <form onSubmit={submit} style={{ display: 'grid', gap: 8 }}>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
            {fxc && <label style={{ fontSize: 12.5, fontWeight: 700 }}>Paid to supplier ({fxc})<input className="form-input" type="number" min="0" step="any" required value={f.fx_amount} onChange={set('fx_amount')} /></label>}
            <label style={{ fontSize: 12.5, fontWeight: 700 }}>{fxc ? '₹ your bank debited' : 'Amount (₹)'}<input className="form-input" type="number" min="0.01" step="0.01" required value={f.amount_rs} onChange={set('amount_rs')} /></label>
            <label style={{ fontSize: 12.5, fontWeight: 700 }}>Paid on<input className="form-input" type="date" max={today()} required value={f.paid_on} onChange={set('paid_on')} /></label>
            <label style={{ fontSize: 12.5, fontWeight: 700 }}>Mode<select className="form-select" value={f.mode} onChange={set('mode')}>{['bank', 'upi', 'cash', 'card', 'cheque', 'other'].map(m => <option key={m} value={m}>{m}</option>)}</select></label>
            <label style={{ fontSize: 12.5, fontWeight: 700 }}>Reference / UTR<input className="form-input" value={f.reference} onChange={set('reference')} /></label>
          </div>
          {fxc && Number(f.fx_amount) > 0 && Number(f.amount_rs) > 0 && <small style={{ color: 'var(--text-2)' }}>That is ₹{(Number(f.amount_rs) / Number(f.fx_amount)).toFixed(4)} per {fxc}. The difference from the quoted rate is recorded as forex gain/loss once the supplier is fully paid.</small>}
          {err && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8 }}>{err}</div>}
          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}><button type="button" className="btn btn-ghost" onClick={onClose}>Close</button><button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Record payment'}</button></div>
        </form>
        {hist?.fx?.variance != null && <div style={{ marginTop: 10, padding: 8, borderRadius: 8, background: hist.fx.variance > 0 ? '#fee2e2' : '#dcfce7', fontSize: 13 }}>Settled. Forex {hist.fx.variance > 0 ? 'loss' : 'gain'}: <b>{inr(Math.abs(hist.fx.variance))}</b> versus the quoted {inr(hist.fx.quoted_inr)}.</div>}
        {hist?.payments?.length > 0 && <div style={{ marginTop: 12, fontSize: 13 }}><b>Payments so far</b>
          {hist.payments.map(p => <div key={p.id} style={{ display: 'flex', justifyContent: 'space-between', borderTop: '1px solid var(--border)', padding: '5px 0' }}>
            <span>{p.paid_on} · {p.mode}{p.reference ? ` · ${p.reference}` : ''}</span><span>{p.fx_currency ? `${fmt(p.fx_amount, p.fx_currency)} = ` : ''}{inr(p.amount)} <button className="btn btn-sm btn-ghost" onClick={() => remove(p)}>remove</button></span></div>)}</div>}
      </div>
    </div>)
}

export default function PayablesPage() {
  const [filter, setFilter] = useState('all')
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const [pay, setPay] = useState(null)
  const load = useCallback(() => travel.payables(filter).then(r => { setD(r); setErr('') }).catch(e => { setD(null); setErr(e.message) }), [filter])
  useEffect(() => { load() }, [load])

  return (
    <div className="page" style={{ maxWidth: 980 }}>
      <div className="page-header"><h1 className="page-title">Supplier payables</h1></div>
      <p style={{ color: 'var(--text-2)' }}>What you still owe hotels, transporters and other suppliers for confirmed bookings. Where no pay-by date is set, we assume a week before travel.</p>
      {err && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8, marginBottom: 14 }}>{err}</div>}
      {d && <>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(180px,1fr))', gap: 12, marginBottom: 14 }}>
          {[['Total outstanding', d.totals.outstanding, 'all'], ['Overdue', d.totals.overdue, 'overdue'], ['Due in 7 days', d.totals.due_7d, 'week']].map(([l, v, f]) => (
            <button key={f} onClick={() => setFilter(f)} className="card card-body" style={{ textAlign: 'left', cursor: 'pointer', outline: filter === f ? '2px solid var(--primary, #08569f)' : 'none' }}>
              <div style={{ fontSize: 12, color: 'var(--text-3)' }}>{l}</div><div style={{ fontSize: 22, fontWeight: 800, color: f === 'overdue' && v > 0 ? 'var(--danger)' : 'inherit' }}>{inr(v)}</div></button>))}
        </div>
        {d.fx_exposure?.length > 0 && <div className="card card-body" style={{ marginBottom: 14 }}>
          <b>Owed in foreign currency</b> <small style={{ color: 'var(--text-3)' }}>— the rupee amount moves with the exchange rate until you pay</small>
          <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', marginTop: 6 }}>{d.fx_exposure.map(e => <div key={e.currency}><div style={{ fontSize: 18, fontWeight: 800 }}>{fmt(e.outstanding_fx, e.currency)}</div><small style={{ color: 'var(--text-3)' }}>≈ {inr(e.inr_estimate)} today · {e.services} service{e.services > 1 ? 's' : ''}</small></div>)}</div></div>}
        {d.refunds_due.count > 0 && <div style={{ background: '#fef3c7', border: '1px solid #f59e0b', padding: 10, borderRadius: 8, marginBottom: 14 }}>
          {d.refunds_due.count} cancelled service(s) have {inr(d.refunds_due.amount)} already paid to suppliers — claim these refunds back.</div>}
        {d.suppliers.length === 0 ? <div className="card card-body" style={{ color: 'var(--text-3)' }}>Nothing to pay{filter !== 'all' ? ' in this view' : ''}. 🎉</div> :
          d.suppliers.map(g => (
            <div key={g.supplier_id ?? 'none'} className="card card-body" style={{ marginBottom: 12, overflowX: 'auto' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}><h3 style={{ margin: 0 }}>{g.supplier}</h3><b>{inr(g.outstanding)}</b></div>
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13.5, marginTop: 6 }}>
                <thead><tr><th style={th}>Service</th><th style={th}>Booking</th><th style={th}>Pay by</th><th style={{ ...th, textAlign: 'right' }}>Cost</th><th style={{ ...th, textAlign: 'right' }}>Outstanding</th><th style={th}></th></tr></thead>
                <tbody>{g.items.map(i => (
                  <tr key={i.service_id} style={{ borderTop: '1px solid var(--border)' }}>
                    <td style={{ padding: 8 }}>{i.title}{i.status !== 'confirmed' && <small style={{ color: 'var(--warning)' }}> · {i.status.replace('_', ' ')}</small>}</td>
                    <td style={{ padding: 8 }}><Link to={`/bookings/${i.booking_id}`}>{i.booking_ref}</Link></td>
                    <td style={{ padding: 8, color: i.overdue ? 'var(--danger)' : 'inherit' }}>{i.due || '—'}{i.due_is_default && i.due ? <small style={{ color: 'var(--text-3)' }}> (assumed)</small> : ''}{i.overdue ? ' ⚠' : ''}</td>
                    <td style={{ padding: 8, textAlign: 'right' }}>{i.fx ? fmt(i.fx.cost, i.fx.currency) : inr(i.cost)}</td><td style={{ padding: 8, textAlign: 'right' }}>{i.fx ? <><b>{fmt(i.fx.outstanding, i.fx.currency)}</b><div style={{ fontSize: 11.5, color: 'var(--text-3)' }}>≈ {inr(i.outstanding)}</div></> : <b>{inr(i.outstanding)}</b>}</td>
                    <td style={{ padding: 8, textAlign: 'right' }}><button className="btn btn-sm btn-primary" onClick={() => setPay(i)}>Pay</button></td></tr>))}</tbody>
              </table>
            </div>))}
      </>}
      {pay && <PayModal item={pay} onClose={() => setPay(null)} onDone={() => { setPay(null); load() }} />}
    </div>
  )
}
