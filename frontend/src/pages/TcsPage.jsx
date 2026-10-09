import { useState, useEffect, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { travel, inr } from '../api/travel'
import { toast } from '../components/Toast'

const STATUS = { nil: ['#f1f5f9', '#64748b', 'Nothing collected'], paid: ['#dcfce7', '#15803d', 'Deposited'], partial: ['#fef3c7', '#b45309', 'Part deposited'], due: ['#e0f2fe', '#0369a1', 'Due'], overdue: ['#fee2e2', '#b91c1c', 'Overdue'] }
const th = { padding: '7px 8px', textAlign: 'left', color: 'var(--text-3)', fontSize: 11.5, textTransform: 'uppercase' }
const td = { padding: '7px 8px' }
const curFy = () => { const d = new Date(); return d.getMonth() >= 3 ? d.getFullYear() : d.getFullYear() - 1 }
const curQ = () => { const m = new Date().getMonth() + 1; return m >= 4 && m <= 6 ? 1 : m >= 7 && m <= 9 ? 2 : m >= 10 ? 3 : 4 }

function PanCell({ row, onSaved }) {
  const [v, setV] = useState(row.pan || '')
  const [busy, setBusy] = useState(false)
  const dirty = v.toUpperCase() !== (row.pan || '')
  async function save() {
    setBusy(true)
    try { await travel.setBookingPan(row.booking_id, v); toast.success('PAN saved'); onSaved() } catch (e) { toast.error('Not saved', e.message) } finally { setBusy(false) }
  }
  return (
    <span style={{ display: 'inline-flex', gap: 4 }}>
      <input className="form-input" style={{ width: 120, padding: '3px 6px' }} maxLength={10} placeholder="ABCDE1234F" value={v} onChange={e => setV(e.target.value.toUpperCase().replace(/\s/g, ''))} />
      {dirty && <button className="btn btn-sm btn-primary" disabled={busy} onClick={save}>Save</button>}
    </span>)
}

function ChallanForm({ period, onDone, onCancel }) {
  const [f, setF] = useState({ amount_rs: '', bsr_code: '', challan_serial: '', deposit_date: '' })
  const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setF(x => ({ ...x, [k]: e.target.value }))
  async function submit(e) {
    e.preventDefault(); setBusy(true)
    try { await travel.tcsAddChallan({ ...f, period, amount_rs: Number(f.amount_rs) }); toast.success('Challan recorded'); onDone() } catch (er) { toast.error('Not saved', er.message) } finally { setBusy(false) }
  }
  return (
    <form onSubmit={submit} style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end', background: 'var(--bg-2, #f8fafc)', padding: 10, borderRadius: 8, marginTop: 8 }}>
      <label style={{ fontSize: 12 }}>Amount (₹)<input className="form-input" type="number" min="1" step="0.01" required value={f.amount_rs} onChange={set('amount_rs')} style={{ width: 120 }} /></label>
      <label style={{ fontSize: 12 }}>BSR code<input className="form-input" required maxLength={7} value={f.bsr_code} onChange={set('bsr_code')} style={{ width: 100 }} /></label>
      <label style={{ fontSize: 12 }}>Challan serial<input className="form-input" required maxLength={5} value={f.challan_serial} onChange={set('challan_serial')} style={{ width: 90 }} /></label>
      <label style={{ fontSize: 12 }}>Paid on<input className="form-input" type="date" required value={f.deposit_date} onChange={set('deposit_date')} /></label>
      <button className="btn btn-primary btn-sm" disabled={busy}>Save challan</button><button type="button" className="btn btn-ghost btn-sm" onClick={onCancel}>Cancel</button>
    </form>)
}

export default function TcsPage() {
  const [fy, setFy] = useState(curFy())
  const [q, setQ] = useState(curQ())
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const [adding, setAdding] = useState(null)
  const load = useCallback(() => travel.tcsReport(fy, q).then(r => { setD(r); setErr('') }).catch(e => { setD(null); setErr(e.message) }), [fy, q])
  useEffect(() => { load() }, [load])

  const delChallan = async (c) => {
    if (!window.confirm(`Remove the challan of ${inr(c.amount)} (BSR ${c.bsr_code})?`)) return
    try { await travel.tcsDeleteChallan(c.id); load() } catch (e) { toast.error('Not removed', e.message) }
  }

  return (
    <div className="page" style={{ maxWidth: 960 }}>
      <div className="page-header"><h1 className="page-title">TCS on overseas packages</h1></div>
      <p style={{ color: 'var(--text-2)' }}>Tax you collect from customers on overseas tour packages (section 206C(1G)) must be deposited by the 7th of the next month and reported quarterly in Form 27EQ. This page works out what you collected from the payments you received, tracks your challans, and exports the data for your CA.</p>
      <div className="card card-body" style={{ marginBottom: 14, display: 'flex', gap: 10, alignItems: 'flex-end', flexWrap: 'wrap' }}>
        <label style={{ fontSize: 12.5, fontWeight: 700 }}>Financial year<select className="form-select" value={fy} onChange={e => setFy(Number(e.target.value))}>{[curFy() + 1, curFy(), curFy() - 1, curFy() - 2].map(y => <option key={y} value={y}>{y}-{String(y + 1).slice(2)}</option>)}</select></label>
        <label style={{ fontSize: 12.5, fontWeight: 700 }}>Quarter<select className="form-select" value={q} onChange={e => setQ(Number(e.target.value))}><option value={1}>Q1 Apr–Jun</option><option value={2}>Q2 Jul–Sep</option><option value={3}>Q3 Oct–Dec</option><option value={4}>Q4 Jan–Mar</option></select></label>
        {d && <button className="btn btn-sm" onClick={() => travel.tcsDownload(fy, q).catch(e => toast.error('Could not download', e.message))}>Download CSV for CA</button>}
      </div>
      {err && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8, marginBottom: 14 }}>{err}</div>}
      {d && <>
        {d.warnings.length > 0 && <div style={{ background: '#fef3c7', border: '1px solid #f59e0b', padding: 12, borderRadius: 8, marginBottom: 14 }}><b>Needs attention</b><ul style={{ margin: '6px 0 0 18px' }}>{d.warnings.map((w, i) => <li key={i}>{w}</li>)}</ul></div>}
        <div className="card card-body" style={{ marginBottom: 14 }}>
          <h3 style={{ marginTop: 0 }}>Deposits by month</h3>
          {d.months.map(m => { const [bg, fg, label] = STATUS[m.status]; return (
            <div key={m.period} style={{ borderTop: '1px solid var(--border)', padding: '10px 0' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                <div><b>{m.period}</b> <span style={{ background: bg, color: fg, padding: '1px 8px', borderRadius: 999, fontSize: 11.5, fontWeight: 700 }}>{label}</span>
                  {m.collected > 0 && <span style={{ color: 'var(--text-3)', fontSize: 13 }}> · collected {inr(m.collected)} · deposited {inr(m.deposited)} · due by {m.due_date}</span>}</div>
                {m.collected > 0 && <button className="btn btn-sm btn-ghost" onClick={() => setAdding(adding === m.period ? null : m.period)}>+ Record challan</button>}
              </div>
              {m.challans.map(c => <div key={c.id} style={{ fontSize: 13, color: 'var(--text-2)', marginLeft: 12 }}>{inr(c.amount)} · BSR {c.bsr_code} · serial {c.challan_serial} · {c.deposit_date} <button className="btn btn-sm btn-ghost" onClick={() => delChallan(c)}>remove</button></div>)}
              {adding === m.period && <ChallanForm period={m.period} onCancel={() => setAdding(null)} onDone={() => { setAdding(null); load() }} />}
            </div>) })}
        </div>
        <div className="card card-body" style={{ overflowX: 'auto' }}>
          <h3 style={{ marginTop: 0 }}>Collections ({d.rows.length}) <small style={{ fontWeight: 400, color: 'var(--text-3)' }}>· TCS {inr(d.totals.tcs)} on {inr(d.totals.received)} received</small></h3>
          {d.rows.length === 0 ? <p style={{ color: 'var(--text-3)' }}>No TCS collected in this quarter.</p> : (
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13.5 }}>
              <thead><tr><th style={th}>Date</th><th style={th}>Booking</th><th style={th}>Customer</th><th style={th}>PAN</th><th style={{ ...th, textAlign: 'right' }}>Received</th><th style={{ ...th, textAlign: 'right' }}>TCS</th></tr></thead>
              <tbody>{d.rows.map((r, i) => <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
                <td style={td}>{r.date}</td><td style={td}><Link to={`/bookings/${r.booking_id}`}>{r.booking_ref}</Link></td><td style={td}>{r.customer}</td>
                <td style={td}><PanCell row={r} onSaved={load} /></td><td style={{ ...td, textAlign: 'right' }}>{inr(r.received)}</td><td style={{ ...td, textAlign: 'right' }}>{inr(r.tcs)} <small style={{ color: 'var(--text-3)' }}>@{r.rate}%</small></td></tr>)}</tbody>
            </table>)}
          <p style={{ fontSize: 12.5, color: 'var(--text-3)', marginBottom: 0 }}>Calculated from payments marked received, in proportion to each booking's TCS. This is a working aid, not the return: your CA files Form 27EQ and should confirm the rate, the base on which it applies, refunds on cancellations and the March deposit date.</p>
        </div>
      </>}
    </div>
  )
}
