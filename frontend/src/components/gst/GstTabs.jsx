import { useState, useEffect, useCallback } from 'react'
import { travel, inr } from '../../api/travel'
import { toast } from '../Toast'

const th = { padding: '7px 8px', textAlign: 'right', color: 'var(--text-3)', fontSize: 11.5, textTransform: 'uppercase' }
const td = { padding: '7px 8px', textAlign: 'right' }
const lbl = { fontSize: 12.5, fontWeight: 700, display: 'block' }
const ST = { filed: ['#dcfce7', '#15803d', 'Filed'], upcoming: ['#f1f5f9', '#475569', 'Upcoming'], due_soon: ['#fef3c7', '#b45309', 'Due soon'], overdue: ['#fee2e2', '#b91c1c', 'Overdue'] }
const RET = { GSTR1: 'GSTR-1', GSTR3B: 'GSTR-3B' }
const rs = (p) => (p / 100)

function Pill({ s }) { const [bg, fg, l] = ST[s.status]; return <span style={{ background: bg, color: fg, padding: '1px 8px', borderRadius: 999, fontSize: 11.5, fontWeight: 700 }}>{l}{s.status === 'overdue' ? ` ${s.days}d` : ''}{s.status === 'filed' && s.days > 0 ? ` (${s.days}d late)` : ''}</span> }

function FileForm({ period, type, onDone, onClose }) {
  const [f, setF] = useState({ filed_on: new Date().toISOString().slice(0, 10), arn: '', tax_paid_rs: '' })
  const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setF(x => ({ ...x, [k]: e.target.value }))
  async function submit(e) {
    e.preventDefault(); setBusy(true)
    try { await travel.recordGstFiling({ period, return: type, ...f }); toast.success(`${RET[type]} marked as filed`); onDone() } catch (er) { toast.error('Not saved', er.message) } finally { setBusy(false) }
  }
  return (
    <form onSubmit={submit} style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end', background: 'var(--bg-2, #f8fafc)', padding: 10, borderRadius: 8, marginTop: 8 }}>
      <label style={lbl}>Filed on<input className="form-input" type="date" required max={new Date().toISOString().slice(0, 10)} value={f.filed_on} onChange={set('filed_on')} /></label>
      <label style={lbl}>ARN (optional)<input className="form-input" maxLength={15} value={f.arn} onChange={set('arn')} placeholder="AA2910251234567" style={{ width: 170 }} /></label>
      {type === 'GSTR3B' && <label style={lbl}>Tax paid in cash ₹ (optional)<input className="form-input" type="number" min="0" step="0.01" value={f.tax_paid_rs} onChange={set('tax_paid_rs')} style={{ width: 150 }} /></label>}
      <button className="btn btn-primary btn-sm" disabled={busy}>Save</button><button type="button" className="btn btn-ghost btn-sm" onClick={onClose}>Cancel</button>
    </form>)
}

function FilingCell({ period, type, st, onChanged }) {
  const [open, setOpen] = useState(false)
  return (
    <div>
      <Pill s={st} /> <small style={{ color: 'var(--text-3)' }}>due {st.due}</small>
      {st.status === 'filed' ? <div style={{ fontSize: 12, color: 'var(--text-3)' }}>{st.filed_on}{st.arn ? ` · ${st.arn}` : ''} <a style={{ cursor: 'pointer' }} onClick={async () => { if (window.confirm(`Mark ${RET[type]} for ${period} as not filed?`)) { try { await travel.removeGstFiling(period, type); onChanged() } catch (e) { toast.error('Failed', e.message) } } }}>undo</a></div>
        : <div><button className="btn btn-sm btn-ghost" onClick={() => setOpen(o => !o)}>Mark filed</button></div>}
      {open && <FileForm period={period} type={type} onClose={() => setOpen(false)} onDone={() => { setOpen(false); onChanged() }} />}
    </div>)
}

/** GSTR-3B for one month: what we derived from your invoices, the ITC you enter, and how the tax is paid. */
export function Gstr3bTab({ period, download, busy }) {
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const [form, setForm] = useState(null)
  const [saving, setSaving] = useState(false)
  const [states, setStates] = useState([])
  const load = useCallback(() => travel.gst3b(period).then(r => { setD(r); setErr('') }).catch(e => { setD(null); setErr(e.message) }), [period])
  useEffect(() => { load() }, [load])
  useEffect(() => { travel.billingProfile().then(r => setStates(r.states)).catch(() => {}) }, [])
  useEffect(() => { travel.gstItc(period).then(e => setForm({ itc_igst: rs(e.itc.igst), itc_cgst: rs(e.itc.cgst), itc_sgst: rs(e.itc.sgst), rev_igst: rs(e.reversed.igst), rev_cgst: rs(e.reversed.cgst), rev_sgst: rs(e.reversed.sgst), opening_set: e.opening_set, open_igst: rs(e.opening.igst), open_cgst: rs(e.opening.cgst), open_sgst: rs(e.opening.sgst), notes: e.notes || '' })).catch(() => {}) }, [period])
  if (err) return <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8 }}>{err}</div>
  if (!d || !form) return <p>Loading…</p>
  const st = (c) => states.find(s => s.code === c)?.name || c
  const set = (k) => (e) => setForm(x => ({ ...x, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }))
  async function save(e) {
    e.preventDefault(); setSaving(true)
    try { await travel.saveGstItc({ period, ...form }); toast.success('Saved'); load() } catch (er) { toast.error('Not saved', er.message) } finally { setSaving(false) }
  }
  const o = d.outward, p = d.payment
  const H = ['igst', 'cgst', 'sgst']
  const amt = (name, k) => <label style={lbl}>{name}<input className="form-input" type="number" min="0" step="0.01" value={form[k]} onChange={set(k)} /></label>

  return (
    <div style={{ display: 'grid', gap: 14 }}>
      {d.warnings.length > 0 && <div style={{ background: '#fef3c7', border: '1px solid #f59e0b', padding: 12, borderRadius: 8 }}><b>Check before filing</b><ul style={{ margin: '6px 0 0 18px' }}>{d.warnings.map((w, i) => <li key={i}>{w}</li>)}</ul></div>}
      <div style={{ background: d.reconciliation.matches_gstr1 ? '#dcfce7' : '#fee2e2', padding: 10, borderRadius: 8 }}>
        {d.reconciliation.matches_gstr1 ? '✓ Outward tax matches your GSTR-1 for this month.' : <>✗ Outward tax differs from GSTR-1: {Object.entries(d.reconciliation.difference).map(([h, v]) => `${h.toUpperCase()} ${inr(v)}`).join(', ')}. Investigate before filing either return.</>}
      </div>

      <div className="card card-body" style={{ overflowX: 'auto' }}>
        <h3 style={{ marginTop: 0 }}>3.1 Outward supplies</h3>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13.5 }}>
          <thead><tr><th style={{ ...th, textAlign: 'left' }}>Nature of supply</th><th style={th}>Taxable value</th><th style={th}>IGST</th><th style={th}>CGST</th><th style={th}>SGST</th></tr></thead>
          <tbody>
            <tr style={{ borderTop: '1px solid var(--border)' }}><td style={{ ...td, textAlign: 'left' }}>(a) Taxable outward supplies <small style={{ color: 'var(--text-3)' }}>({d.counts.invoices} invoices − {d.counts.credit_notes} credit notes)</small></td><td style={td}>{inr(o.taxable.taxable)}</td>{H.map(h => <td key={h} style={td}>{inr(o.liability[h])}</td>)}</tr>
            <tr style={{ borderTop: '1px solid var(--border)' }}><td style={{ ...td, textAlign: 'left' }}>(c) Nil-rated / exempt</td><td style={td}>{inr(o.nil_exempt)}</td><td style={td}>—</td><td style={td}>—</td><td style={td}>—</td></tr>
            <tr style={{ borderTop: '1px solid var(--border)', color: 'var(--text-3)' }}><td style={{ ...td, textAlign: 'left' }}>(b) Zero-rated · (d) Reverse charge · (e) Non-GST</td><td colSpan={4} style={td}>not tracked — add with your CA if you have any</td></tr>
          </tbody>
        </table>
        {o.unregistered_interstate.length > 0 && <><h4 style={{ margin: '14px 0 4px' }}>3.2 Inter-state supplies to unregistered customers</h4>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13.5 }}><thead><tr><th style={{ ...th, textAlign: 'left' }}>Place of supply</th><th style={th}>Taxable value</th><th style={th}>IGST</th></tr></thead>
            <tbody>{o.unregistered_interstate.map(u => <tr key={u.pos} style={{ borderTop: '1px solid var(--border)' }}><td style={{ ...td, textAlign: 'left' }}>{st(u.pos)}</td><td style={td}>{inr(u.taxable)}</td><td style={td}>{inr(u.igst)}</td></tr>)}</tbody></table></>}
      </div>

      <form onSubmit={save} className="card card-body" style={{ display: 'grid', gap: 10 }}>
        <h3 style={{ margin: 0 }}>4. Input tax credit (you enter this)</h3>
        <p style={{ margin: 0, color: 'var(--text-2)', fontSize: 13.5 }}>TripSarthi does not see your suppliers' tax invoices, so enter the eligible ITC from your purchase records or GSTR-2B (₹). Tour packages sold at 5% do not allow ITC on the package; ITC applies to the 18% scheme and to other business expenses — ask your CA.</p>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 8 }}>{amt('ITC — IGST', 'itc_igst')}{amt('ITC — CGST', 'itc_cgst')}{amt('ITC — SGST', 'itc_sgst')}{amt('Reversed — IGST', 'rev_igst')}{amt('Reversed — CGST', 'rev_cgst')}{amt('Reversed — SGST', 'rev_sgst')}</div>
        <label style={{ ...lbl, display: 'flex', gap: 6, alignItems: 'center', fontWeight: 600 }}><input type="checkbox" checked={form.opening_set} onChange={set('opening_set')} /> Set the opening credit balance myself <small style={{ fontWeight: 400, color: 'var(--text-3)' }}>(otherwise last month's closing balance is carried forward — currently: {d.itc.opening_source === 'carried' ? 'carried forward' : d.itc.opening_source === 'entered' ? 'typed' : 'none yet'})</small></label>
        {form.opening_set && <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 8 }}>{amt('Opening — IGST', 'open_igst')}{amt('Opening — CGST', 'open_cgst')}{amt('Opening — SGST', 'open_sgst')}</div>}
        <label style={lbl}>Note<input className="form-input" maxLength={255} value={form.notes} onChange={set('notes')} placeholder="Source of these figures, e.g. GSTR-2B Oct 2026" /></label>
        <div><button className="btn btn-primary btn-sm" disabled={saving}>{saving ? 'Saving…' : 'Save ITC'}</button></div>
      </form>

      <div className="card card-body" style={{ overflowX: 'auto' }}>
        <h3 style={{ marginTop: 0 }}>6.1 Payment of tax</h3>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13.5 }}>
          <thead><tr><th style={{ ...th, textAlign: 'left' }}></th><th style={th}>IGST</th><th style={th}>CGST</th><th style={th}>SGST</th></tr></thead>
          <tbody>
            <tr style={{ borderTop: '1px solid var(--border)' }}><td style={{ ...td, textAlign: 'left' }}>Tax payable</td>{H.map(h => <td key={h} style={td}>{inr(p.liability[h])}</td>)}</tr>
            <tr style={{ borderTop: '1px solid var(--border)' }}><td style={{ ...td, textAlign: 'left' }}>Paid through ITC</td>{H.map(h => <td key={h} style={td}>{inr(Object.entries(p.by_itc).filter(([k]) => k.startsWith(h + '_by_')).reduce((n, [, v]) => n + v, 0))}</td>)}</tr>
            <tr style={{ borderTop: '2px solid var(--border)', fontWeight: 700 }}><td style={{ ...td, textAlign: 'left' }}>Paid in cash</td>{H.map(h => <td key={h} style={td}>{inr(p.cash[h])}</td>)}</tr>
            <tr style={{ borderTop: '1px solid var(--border)', color: 'var(--text-3)' }}><td style={{ ...td, textAlign: 'left' }}>Credit carried to next month</td>{H.map(h => <td key={h} style={td}>{inr(p.closing_credit[h])}</td>)}</tr>
          </tbody>
        </table>
        <div style={{ marginTop: 8, fontSize: 18, fontWeight: 800 }}>Cash to pay: {inr(p.cash_total)}</div>
        <small style={{ color: 'var(--text-3)' }}>IGST credit is used first (against IGST, then CGST, then SGST); CGST and SGST credit never pay each other. Interest and late fee are not calculated.</small>
      </div>

      <div className="card card-body">
        <h3 style={{ marginTop: 0 }}>Filing</h3>
        <FilingCell period={period} type="GSTR3B" st={d.filings.GSTR3B} onChanged={load} />
        <div style={{ marginTop: 10 }}><button className="btn btn-primary btn-sm" disabled={busy} onClick={() => download('gstr3b.json')}>Download GSTR-3B (JSON)</button> <small style={{ color: 'var(--text-3)' }}>Your CA or the portal's offline utility checks the figures; field names follow the GSTN format from the published spec but were not validated on the portal.</small></div>
      </div>
    </div>
  )
}

/** HSN/SAC summary (GSTR-1 table 12). */
export function HsnTab({ period, download, busy }) {
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  useEffect(() => { let live = true; travel.gstHsn(period).then(r => { if (live) { setD(r); setErr('') } }).catch(e => { if (live) setErr(e.message) }); return () => { live = false } }, [period])
  if (err) return <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8 }}>{err}</div>
  if (!d) return <p>Loading…</p>
  return (
    <div className="card card-body" style={{ overflowX: 'auto' }}>
      <h3 style={{ marginTop: 0 }}>HSN / SAC summary — {period}</h3>
      {d.rows.length === 0 ? <p style={{ color: 'var(--text-3)' }}>No tax documents this month.</p> :
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13.5 }}>
          <thead><tr><th style={{ ...th, textAlign: 'left' }}>SAC</th><th style={{ ...th, textAlign: 'left' }}>Description</th><th style={th}>UQC</th><th style={th}>Rate</th><th style={th}>Total value</th><th style={th}>Taxable value</th><th style={th}>IGST</th><th style={th}>CGST</th><th style={th}>SGST</th></tr></thead>
          <tbody>{d.rows.map((r, i) => <tr key={i} style={{ borderTop: '1px solid var(--border)' }}><td style={{ ...td, textAlign: 'left' }}><b>{r.hsn}</b></td><td style={{ ...td, textAlign: 'left' }}>{r.desc}</td><td style={td}>{r.uqc}</td><td style={td}>{r.rate}%</td><td style={td}>{inr(r.val)}</td><td style={td}>{inr(r.taxable)}</td><td style={td}>{inr(r.igst)}</td><td style={td}>{inr(r.cgst)}</td><td style={td}>{inr(r.sgst)}</td></tr>)}</tbody>
        </table>}
      <p style={{ fontSize: 12.5, color: 'var(--text-3)' }}>{d.note} The same table is included in the GSTR-1 JSON.</p>
      <div><button className="btn btn-primary btn-sm" disabled={busy || d.rows.length === 0} onClick={() => download('hsn.csv')}>Download HSN summary (CSV)</button></div>
    </div>)
}

/** Which returns are filed / due / late, by month. */
export function FilingsTab() {
  const curFy = () => { const d = new Date(); return d.getMonth() >= 3 ? d.getFullYear() : d.getFullYear() - 1 }
  const [fy, setFy] = useState(curFy())
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const load = useCallback(() => travel.gstFilings(fy).then(r => { setD(r); setErr('') }).catch(e => setErr(e.message)), [fy])
  useEffect(() => { load() }, [load])
  return (
    <div className="card card-body">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
        <h3 style={{ margin: 0 }}>Filing tracker</h3>
        <select className="form-select" style={{ width: 'auto' }} value={fy} onChange={e => setFy(Number(e.target.value))}>{[curFy() + 0, curFy() - 1, curFy() - 2].map(y => <option key={y} value={y}>FY {y}-{String(y + 1).slice(2)}</option>)}</select>
      </div>
      <p style={{ color: 'var(--text-2)', fontSize: 13.5 }}>Monthly filers: GSTR-1 is due on the 11th and GSTR-3B on the 20th of the next month. You file on the GST portal; mark it here so TripSarthi can remind you (owners and admins are alerted 3 days before and every day it is late). Quarterly (QRMP) due dates are not modelled.</p>
      {err && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8 }}>{err}</div>}
      {d && (d.months.length === 0 ? <p style={{ color: 'var(--text-3)' }}>No completed months in this financial year yet.</p> :
        <div style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13.5 }}>
          <thead><tr><th style={{ ...th, textAlign: 'left' }}>Month</th><th style={{ ...th, textAlign: 'left' }}>GSTR-1</th><th style={{ ...th, textAlign: 'left' }}>GSTR-3B</th></tr></thead>
          <tbody>{[...d.months].reverse().map(m => <tr key={m.period} style={{ borderTop: '1px solid var(--border)', verticalAlign: 'top' }}>
            <td style={{ padding: 8 }}><b>{m.period}</b></td>
            <td style={{ padding: 8 }}><FilingCell period={m.period} type="GSTR1" st={m.GSTR1} onChanged={load} /></td>
            <td style={{ padding: 8 }}><FilingCell period={m.period} type="GSTR3B" st={m.GSTR3B} onChanged={load} /></td></tr>)}</tbody></table></div>)}
    </div>)
}
