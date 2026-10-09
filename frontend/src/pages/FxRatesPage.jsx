import { useState, useEffect, useCallback } from 'react'
import { travel } from '../api/travel'
import { toast } from '../components/Toast'

const th = { padding: '7px 8px', textAlign: 'left', color: 'var(--text-3)', fontSize: 11.5, textTransform: 'uppercase' }
const num = (v) => Number(v).toLocaleString('en-IN', { maximumFractionDigits: 6 })

function Row({ r, onChanged }) {
  const [rate, setRate] = useState(String(r.rate))
  const [buf, setBuf] = useState(String(r.buffer_pct))
  const [hist, setHist] = useState(null)
  const dirty = Number(rate) !== r.rate || Number(buf) !== r.buffer_pct
  const save = async (confirm = false) => {
    try { await travel.setFx(r.currency, { rate: Number(rate), buffer_pct: Number(buf), confirm }); toast.success(`${r.currency} saved`); onChanged() }
    catch (e) { if (e.status === 409 && window.confirm(e.message + '\n\nSave it anyway?')) save(true); else if (e.status !== 409) toast.error('Not saved', e.message) }
  }
  const act = async (fn, ok) => { try { await fn(); if (ok) toast.success(ok); onChanged() } catch (e) { toast.error('Not done', e.message) } }
  return (
    <>
      <tr style={{ borderTop: '1px solid var(--border)' }}>
        <td style={{ padding: 8 }}><b>{r.currency}</b> <small style={{ color: 'var(--text-3)' }}>{r.name}</small></td>
        <td style={{ padding: 8 }}><input className="form-input" type="number" step="any" min="0" value={rate} onChange={e => setRate(e.target.value)} style={{ width: 130 }} /></td>
        <td style={{ padding: 8 }}><input className="form-input" type="number" step="0.1" min="0" max="20" value={buf} onChange={e => setBuf(e.target.value)} style={{ width: 70 }} /> %</td>
        <td style={{ padding: 8 }}>₹{num(r.cost_rate)}</td>
        <td style={{ padding: 8 }}>
          <span style={{ background: r.source === 'auto' ? '#dcfce7' : '#e0f2fe', color: r.source === 'auto' ? '#15803d' : '#0369a1', padding: '1px 8px', borderRadius: 999, fontSize: 11.5, fontWeight: 700 }}>{r.source === 'auto' ? 'automatic' : 'manual'}</span>
          <div style={{ fontSize: 12, color: r.stale ? 'var(--danger)' : 'var(--text-3)' }}>{r.as_of ? `${r.as_of.slice(0, 16)}${r.stale ? ` — ${r.age_days} days old` : ''}` : ''}</div>
        </td>
        <td style={{ padding: 8, whiteSpace: 'nowrap', textAlign: 'right' }}>
          {dirty && <button className="btn btn-sm btn-primary" onClick={() => save()}>Save</button>}
          {r.source === 'manual' && <button className="btn btn-sm btn-ghost" title="Follow the daily market rate again" onClick={() => act(() => travel.fxAuto(r.currency), 'Back on automatic rates')}>Auto</button>}
          <button className="btn btn-sm btn-ghost" onClick={() => travel.fxHistory(r.currency).then(setHist).catch(() => {})}>History</button>
          <button className="btn btn-sm btn-ghost" onClick={() => { if (window.confirm(`Remove ${r.currency}?`)) act(() => travel.removeFx(r.currency), 'Removed') }}>✕</button>
        </td>
      </tr>
      {hist && <tr><td colSpan={6} style={{ padding: '0 8px 8px', fontSize: 12.5, color: 'var(--text-2)' }}>{hist.length === 0 ? 'No history.' : hist.slice(0, 10).map((h, i) => <span key={i} style={{ marginRight: 14 }}>{h.at.slice(5, 16)} ₹{num(h.rate)} ({h.source})</span>)}<a style={{ cursor: 'pointer' }} onClick={() => setHist(null)}> hide</a></td></tr>}
    </>)
}

export default function FxRatesPage() {
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const [add, setAdd] = useState({ currency: '', rate: '' })
  const [busy, setBusy] = useState('')
  const load = useCallback(() => travel.fxRates().then(r => { setD(r); setErr('') }).catch(e => setErr(e.message)), [])
  useEffect(() => { load() }, [load])

  async function addCurrency(e) {
    e.preventDefault(); setBusy('add')
    try { await travel.addFx({ currency: add.currency, ...(add.rate ? { rate: Number(add.rate) } : {}) }); toast.success(`${add.currency} added`); setAdd({ currency: '', rate: '' }); load() }
    catch (er) { toast.error('Could not add', er.message) } finally { setBusy('') }
  }
  async function refresh() {
    setBusy('refresh')
    try { const r = await travel.refreshFx(); toast.success(r.updated.length ? `Updated ${r.updated.join(', ')}` : 'Nothing to update', r.error || Object.values(r.skipped)[0]); load() } catch (e) { toast.error('Could not refresh', e.message) } finally { setBusy('') }
  }
  return (
    <div className="page" style={{ maxWidth: 940 }}>
      <div className="page-header"><h1 className="page-title">Exchange rates</h1></div>
      <p style={{ color: 'var(--text-2)' }}>Your books stay in <b>Indian rupees</b> — GST, TCS, invoices and reports are always INR. Foreign currencies are for what <b>suppliers charge you</b> (a USD hotel, an AED transfer) and for showing the customer an <b>indicative equivalent</b>. Rates are rupees per 1 unit of the currency.</p>
      <div className="card card-body" style={{ marginBottom: 14, fontSize: 13.5, color: 'var(--text-2)' }}>
        <b style={{ color: 'var(--text)' }}>How a foreign cost is priced:</b> amount × rate × (1 + buffer). The <b>buffer</b> covers the rupee moving between today's quote and the day you pay the supplier. The rate is <b>locked on each quote line</b>, so a quote never changes by itself — use "Re-price" on a quote to refresh it. When you pay the supplier you enter the rupees your bank actually debited, and the difference to the quote shows as forex gain or loss.
      </div>
      {err && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8, marginBottom: 12 }}>{err}</div>}
      {d && <>
        <div className="card card-body" style={{ overflowX: 'auto', marginBottom: 14 }}>
          {d.rates.length === 0 ? <p style={{ margin: 0, color: 'var(--text-3)' }}>No foreign currencies yet. Add the ones your suppliers charge in.</p> :
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13.5 }}>
              <thead><tr><th style={th}>Currency</th><th style={th}>Rate (₹ per unit)</th><th style={th}>Buffer</th><th style={th}>Cost rate</th><th style={th}>Source</th><th style={th}></th></tr></thead>
              <tbody>{d.rates.map(r => <Row key={r.currency + r.rate + r.buffer_pct + r.source} r={r} onChanged={load} />)}</tbody>
            </table>}
          {d.rates.some(r => r.stale) && <div style={{ background: '#fef3c7', padding: 8, borderRadius: 8, marginTop: 10, fontSize: 13 }}>⚠ Some rates are more than 3 days old. Refresh them (or enter today's rate) before pricing new quotes.</div>}
          {d.rates.some(r => r.source === 'auto') && <div style={{ marginTop: 10 }}><button className="btn btn-sm" disabled={busy === 'refresh'} onClick={refresh}>{busy === 'refresh' ? 'Refreshing…' : '↻ Refresh automatic rates now'}</button> <small style={{ color: 'var(--text-3)' }}>Automatic rates update once a day from the European Central Bank reference rate; AED, SAR, QAR, OMR and BHD follow their fixed dollar pegs.</small></div>}
        </div>
        <form onSubmit={addCurrency} className="card card-body" style={{ display: 'flex', gap: 8, alignItems: 'flex-end', flexWrap: 'wrap' }}>
          <label style={{ fontSize: 12.5, fontWeight: 700 }}>Add a currency<select className="form-select" required value={add.currency} onChange={e => setAdd(a => ({ ...a, currency: e.target.value }))} style={{ minWidth: 220 }}><option value="">Choose…</option>{d.available.map(c => <option key={c.code} value={c.code}>{c.code} — {c.name}</option>)}</select></label>
          <label style={{ fontSize: 12.5, fontWeight: 700 }}>Rate ₹ per unit <small style={{ fontWeight: 400 }}>(blank = automatic)</small><input className="form-input" type="number" step="any" min="0" value={add.rate} onChange={e => setAdd(a => ({ ...a, rate: e.target.value }))} style={{ width: 150 }} /></label>
          <button className="btn btn-primary" disabled={busy === 'add' || !add.currency}>{busy === 'add' ? 'Adding…' : 'Add'}</button>
        </form>
      </>}
    </div>
  )
}
