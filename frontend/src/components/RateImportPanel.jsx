import { useState } from 'react'
import { travel } from '../api/travel'
import { toast } from './Toast'

const STATUS = {
  new: ['New', 'var(--success)'], change: ['Price change', 'var(--warning)'], same: ['Already saved', 'var(--text-3)'],
  invalid: ['Needs a fix', 'var(--danger)'], duplicate_in_file: ['Duplicate in file', 'var(--text-3)'],
}
const MODE = { table: 'Read from your spreadsheet columns', ai: 'Read by AI from your text — check every price', lines: 'Read line by line (AI not available) — check every row' }
const fmt = (minor, cur) => { const e = ['JPY', 'KRW', 'VND'].includes(cur) ? 0 : ['OMR', 'KWD', 'BHD'].includes(cur) ? 3 : 2; return `${cur} ${(Number(minor) / 10 ** e).toLocaleString('en-IN', { maximumFractionDigits: e })}` }

/** Rate-sheet import: paste text or upload CSV/Excel -> preview -> pick rows -> import. Nothing is saved until "Import selected". */
export default function RateImportPanel({ suppliers, destinations, onDone }) {
  const [supplier, setSupplier] = useState(''); const [dest, setDest] = useState(''); const [currency, setCurrency] = useState('INR')
  const [text, setText] = useState(''); const [file, setFile] = useState(null)
  const [busy, setBusy] = useState(false); const [prev, setPrev] = useState(null)
  const [pick, setPick] = useState({}); const [edit, setEdit] = useState({}); const [big, setBig] = useState({})
  const th = { padding: '8px 10px', textAlign: 'left', color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase' }; const td = { padding: '8px 10px', borderTop: '1px solid var(--border)', verticalAlign: 'top' }

  async function run(e) {
    e.preventDefault(); setBusy(true)
    try {
      const p = await travel.ratePreview({ supplier_id: supplier, destination_id: dest, currency, text: file ? '' : text }, file)
      setPrev(p); setEdit({}); setBig({})
      setPick(Object.fromEntries(p.rows.map((r, i) => [i, (r.status === 'new' || (r.status === 'change' && !r.big_change))])))
      ;(p.notes || []).forEach(n => toast.info('Note', n))
    } catch (er) { toast.error('Could not read that', er.message) } finally { setBusy(false) }
  }
  async function commit() {
    const accept = Object.keys(pick).filter(i => pick[i]).map(Number)
    setBusy(true)
    try {
      const r = await travel.rateCommit(prev.id, { accept, edits: edit, confirm_big: Object.keys(big).filter(i => big[i]).map(Number) })
      toast.success('Rates imported', `${r.created} added, ${r.updated} updated`)
      setPrev(null); setText(''); setFile(null); onDone?.()
    } catch (er) { toast.error('Not imported', er.message) } finally { setBusy(false) }
  }
  const setField = (i, k, v) => setEdit(x => ({ ...x, [i]: { ...(x[i] || {}), [k]: v } }))
  const chosen = prev ? Object.values(pick).filter(Boolean).length : 0

  return (
    <div>
      {!prev ? (
        <form className="card card-body" onSubmit={run} style={{ display: 'grid', gap: 10 }}>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <select className="form-select" style={{ flex: '2 1 200px' }} value={supplier} onChange={e => setSupplier(e.target.value)} required><option value="">Supplier these rates belong to…</option>{suppliers.map(x => <option key={x.id} value={x.id}>{x.name}</option>)}</select>
            <select className="form-select" style={{ flex: '1 1 160px' }} value={dest} onChange={e => setDest(e.target.value)}><option value="">Destination (optional)</option>{destinations.map(x => <option key={x.id} value={x.id}>{x.name}</option>)}</select>
            <select className="form-select" style={{ flex: '1 1 110px' }} value={currency} onChange={e => setCurrency(e.target.value)} title="Used when the sheet doesn't say"><option>INR</option><option>USD</option><option>EUR</option><option>GBP</option><option>AED</option><option>SGD</option><option>THB</option><option>MYR</option><option>IDR</option><option>LKR</option></select>
          </div>
          <textarea className="form-input" rows={9} placeholder={'Paste the rate list here — any format, e.g.\nDeluxe Room – ₹12,500 per night (1 Dec – 31 Dec)\nAirport transfer, Innova: 2,000 per vehicle\n\n…or upload a CSV / Excel file below.'} value={text} onChange={e => setText(e.target.value)} disabled={!!file} />
          <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
            <input type="file" accept=".csv,.tsv,.txt,.xlsx,.xls" onChange={e => setFile(e.target.files?.[0] || null)} />
            {file && <a style={{ cursor: 'pointer' }} onClick={() => setFile(null)}>remove file</a>}
            <button className="btn btn-primary" style={{ marginLeft: 'auto' }} disabled={busy || !supplier || (!text.trim() && !file)}>{busy ? 'Reading…' : 'Read & preview'}</button>
          </div>
          <small style={{ color: 'var(--text-3)' }}>Nothing is saved yet — you review every row first. The AI only copies prices that are printed in your text; it never works out or guesses a price. PDFs: copy the table and paste it. Prices are your cost (not shown to customers).</small>
        </form>
      ) : (
        <div>
          <div className="card card-body" style={{ marginBottom: 10, display: 'flex', gap: 12, flexWrap: 'wrap', alignItems: 'center' }}>
            <b>{prev.counts.total} rows</b><span style={{ color: 'var(--text-3)' }}>{MODE[prev.mode]}</span>
            {['new', 'change', 'same', 'invalid', 'duplicate_in_file'].filter(k => prev.counts[k]).map(k => <span key={k} style={{ color: STATUS[k][1] }}>{prev.counts[k]} {STATUS[k][0].toLowerCase()}</span>)}
            <span style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}><button className="btn btn-ghost" onClick={() => setPrev(null)} disabled={busy}>Back</button><button className="btn btn-primary" onClick={commit} disabled={busy || !chosen}>{busy ? 'Importing…' : `Import ${chosen} selected`}</button></span>
          </div>
          <div className="card" style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead><tr>{['', 'Service', 'Type', 'Unit', 'Price', 'Valid', 'Status'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
              <tbody>{prev.rows.map((r, i) => {
                const ed = edit[i] || {}; const bad = r.errors?.length && !ed.amount
                return (
                  <tr key={i} style={{ opacity: r.status === 'same' || r.status === 'duplicate_in_file' ? 0.6 : 1 }}>
                    <td style={td}><input type="checkbox" checked={!!pick[i]} disabled={r.status === 'duplicate_in_file' || (bad && ed.amount === undefined)} onChange={e => setPick(x => ({ ...x, [i]: e.target.checked }))} /></td>
                    <td style={td}><input className="form-input" style={{ minWidth: 180 }} value={ed.service_name ?? r.service_name} onChange={e => setField(i, 'service_name', e.target.value)} />
                      {(r.errors || []).map((m, j) => <div key={j} style={{ color: 'var(--danger)', fontSize: 12 }}>{m}</div>)}{(r.warnings || []).map((m, j) => <div key={j} style={{ color: 'var(--warning)', fontSize: 12 }}>{m}</div>)}</td>
                    <td style={td}>{r.service_type}</td><td style={td}>{r.unit.replace('_', ' ')}</td>
                    <td style={td}><input className="form-input" style={{ width: 110 }} type="number" step="any" value={ed.amount ?? r.amount_major} onChange={e => { setField(i, 'amount', e.target.value); setPick(x => ({ ...x, [i]: true })) }} /> <small>{r.currency}</small>
                      {r.status === 'change' && <div style={{ fontSize: 12, color: 'var(--text-3)' }}>was {fmt(r.old_amount, r.currency)}</div>}
                      {r.big_change && <label style={{ fontSize: 12, display: 'block', color: 'var(--warning)' }}><input type="checkbox" checked={!!big[i]} onChange={e => { setBig(x => ({ ...x, [i]: e.target.checked })); if (e.target.checked) setPick(x => ({ ...x, [i]: true })) }} /> I checked this price</label>}</td>
                    <td style={td}>{r.valid_from ? `${r.valid_from} → ${r.valid_to || 'open'}` : '—'}</td>
                    <td style={{ ...td, color: STATUS[r.status][1] }}>{STATUS[r.status][0]}</td>
                  </tr>)
              })}</tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  )
}
