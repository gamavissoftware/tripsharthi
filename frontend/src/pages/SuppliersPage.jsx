import { useState, useEffect, useCallback, useMemo } from 'react'
import { Building2, Tags, AlertTriangle, CalendarClock } from 'lucide-react'
import { travel, inr } from '../api/travel'
import { toast } from '../components/Toast'
import { confirmAsk } from '../components/confirmAsk'
import { StatRow, StatTile } from '../components/StatTile'
import { CUR, toMinor, toMajor, fmt } from '../lib/currency'
import RateImportPanel from '../components/RateImportPanel'

const STYPES = ['hotel', 'transport', 'activity', 'dmc', 'airline', 'visa', 'insurance', 'guide', 'cruise', 'other']
const RTYPES = ['hotel', 'transfer', 'sightseeing', 'activity', 'flight', 'visa', 'insurance', 'meal', 'other']
const UNITS = [['per_night', 'Per night (per room)'], ['per_pax', 'Per person'], ['per_vehicle', 'Per vehicle'], ['per_room', 'Per room'], ['per_day', 'Per day'], ['flat', 'Flat (one price)']]
const UNIT_LABEL = Object.fromEntries(UNITS.map(([k, v]) => [k, v]))
const dayStr = (plus = 0) => new Date(new Date().getTime() + plus * 864e5).toISOString().slice(0, 10)
const todayStr = () => dayStr(0)
const rateState = (r) => { const t = todayStr(); return r.valid_to && r.valid_to < t ? 'expired' : r.valid_from && r.valid_from > t ? 'future' : 'ok' }
const money = (r) => (r.currency && r.currency !== 'INR' ? fmt(r.cost_amount, r.currency) : inr(r.cost_amount))
const BLANK_S = { name: '', type: 'hotel', city: '', phone: '', whatsapp: '', email: '', gstin: '', contact_name: '', payment_terms: '', notes: '' }
const BLANK_R = { supplier_id: '', service_name: '', service_type: 'hotel', unit: 'per_night', currency: 'INR', price: '', destination_id: '', valid_from: '', valid_to: '' }
const th = { padding: '10px 14px', textAlign: 'left', color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase', whiteSpace: 'nowrap' }
const td = { padding: '10px 14px', borderTop: '1px solid var(--border)', verticalAlign: 'top' }
const Badge = ({ kind, children }) => <span className={'rp-badge' + (kind ? ' ' + kind : '')}>{children}</span>

export default function SuppliersPage() {
  const [tab, setTab] = useState('rates')
  const [sup, setSup] = useState([]); const [rates, setRates] = useState([]); const [dest, setDest] = useState([])
  const [d, setD] = useState({ name: '', country: 'India', is_domestic: 1 })
  const [form, setForm] = useState(null)         // {kind:'supplier'|'rate', id?, data}
  const [q, setQ] = useState('')
  const [fSup, setFSup] = useState(''); const [fType, setFType] = useState(''); const [fDest, setFDest] = useState(''); const [fState, setFState] = useState('')
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => Promise.all([travel.list('suppliers'), travel.list('rates'), travel.list('destinations')]).then(([a, b, c]) => { setSup(a); setRates(b); setDest(c) }).catch(e => toast.error('Load failed', e.message)), [])
  useEffect(() => { load() }, [load])
  const names = useMemo(() => Object.fromEntries(sup.map(x => [x.id, x.name])), [sup])
  const dnames = useMemo(() => Object.fromEntries(dest.map(x => [x.id, x.name])), [dest])
  const counts = useMemo(() => { const c = {}; rates.forEach(r => { c[r.supplier_id] = (c[r.supplier_id] || 0) + 1 }); return c }, [rates])
  const tabBtn = (k, label) => <button key={k} className={'btn btn-sm ' + (tab === k ? 'btn-primary' : 'btn-ghost')} onClick={() => { setTab(k); setForm(null); setQ('') }}>{label}</button>
  const t = q.trim().toLowerCase()

  async function run(fn, msg) { setBusy(true); try { await fn(); if (msg) toast.success(msg); await load(); return true } catch (er) { toast.error('Could not save', er.message); return false } finally { setBusy(false) } }

  const guard = (fn, msg) => async (e) => { e.preventDefault(); await run(fn, msg) }
  async function delDest(x) { if (await confirmAsk(`Delete the destination ${x.name}?`, { okLabel: 'Delete', danger: true })) run(() => travel.remove('destinations', x.id), 'Destination deleted') }

  // ---- suppliers
  const supRows = sup.filter(x => (!fType || x.type === fType) && (!t || `${x.name} ${x.city} ${x.phone} ${x.gstin} ${x.contact_name}`.toLowerCase().includes(t)))
  async function saveSupplier(e) {
    e.preventDefault()
    const body = { ...form.data }; Object.keys(body).forEach(k => { if (body[k] === '') body[k] = null }); body.name = form.data.name.trim()
    if (!body.name) return toast.error('Enter the supplier name')
    if (body.gstin) body.gstin = String(body.gstin).toUpperCase().trim()
    if (await run(() => (form.id ? travel.update('suppliers', form.id, body) : travel.create('suppliers', body)), form.id ? 'Supplier updated' : 'Supplier added')) setForm(null)
  }
  async function delSupplier(x) {
    const n = counts[x.id] || 0
    if (!(await confirmAsk(`Delete ${x.name}?${n ? `\n\nIts ${n} rate card${n > 1 ? 's' : ''} will be deleted too. Quotes already built keep their prices.` : ''}`, { okLabel: 'Delete', danger: true }))) return
    run(() => travel.remove('suppliers', x.id), 'Supplier deleted')
  }

  // ---- rates
  const rateRows = rates.filter(r => (!fSup || String(r.supplier_id) === fSup) && (!fType || r.service_type === fType) && (!fDest || String(r.destination_id) === fDest) && (!fState || rateState(r) === fState)
    && (!t || `${names[r.supplier_id] || ''} ${r.service_name} ${r.service_type} ${dnames[r.destination_id] || ''}`.toLowerCase().includes(t)))
    .sort((a, b) => (names[a.supplier_id] || '').localeCompare(names[b.supplier_id] || '') || a.service_name.localeCompare(b.service_name))
  const expired = rates.filter(r => rateState(r) === 'expired').length
  const soon = rates.filter(r => r.valid_to && r.valid_to >= todayStr() && r.valid_to <= dayStr(30)).length
  function editRate(r, copy) {
    const cur = r.currency || 'INR'
    setForm({ kind: 'rate', id: copy ? null : r.id, data: { supplier_id: String(r.supplier_id), service_name: copy ? r.service_name + ' (copy)' : r.service_name, service_type: r.service_type, unit: r.unit, currency: cur,
      price: toMajor(r.cost_amount, cur), destination_id: r.destination_id ? String(r.destination_id) : '', valid_from: copy ? '' : r.valid_from || '', valid_to: copy ? '' : r.valid_to || '' } })
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }
  async function saveRate(e) {
    e.preventDefault()
    const f = form.data
    if (!f.supplier_id) return toast.error('Choose the supplier')
    if (!f.service_name.trim()) return toast.error('Enter the service name')
    if (f.price === '' || Number(f.price) < 0) return toast.error('Enter the cost you pay')
    if (f.valid_from && f.valid_to && f.valid_to < f.valid_from) return toast.error('“Valid to” is before “valid from”')
    const body = { supplier_id: Number(f.supplier_id), service_name: f.service_name.trim(), service_type: f.service_type, unit: f.unit, currency: f.currency, cost_amount: toMinor(f.price, f.currency),
      destination_id: f.destination_id ? Number(f.destination_id) : null, valid_from: f.valid_from || null, valid_to: f.valid_to || null }
    if (await run(() => (form.id ? travel.update('rates', form.id, body) : travel.create('rates', body)), form.id ? 'Rate updated' : 'Rate added')) setForm(null)
  }
  async function delRate(r) {
    if (!(await confirmAsk(`Delete the rate “${r.service_name}” (${names[r.supplier_id] || ''})?\n\nQuotes already built keep their prices.`, { okLabel: 'Delete', danger: true }))) return
    run(() => travel.remove('rates', r.id), 'Rate deleted')
  }
  const fd = form?.data; const setFd = (k) => (e) => setForm(s => ({ ...s, data: { ...s.data, [k]: e.target.value } }))

  return (
    <div className="page page-wide">
      <div className="page-header"><h1 className="page-title">Suppliers &amp; Rates</h1>
        <div style={{ display: 'flex', gap: 8 }}>
          {tab === 'suppliers' && <button className="btn btn-primary" onClick={() => setForm({ kind: 'supplier', data: BLANK_S })}>+ New supplier</button>}
          {tab === 'rates' && <button className="btn btn-primary" disabled={!sup.length} title={sup.length ? '' : 'Add a supplier first'} onClick={() => setForm({ kind: 'rate', data: { ...BLANK_R, supplier_id: fSup } })}>+ New rate</button>}
        </div></div>
      <div style={{ display: 'flex', gap: 8, marginBottom: 14, flexWrap: 'wrap' }}>{tabBtn('rates', `Rate cards (${rates.length})`)}{tabBtn('suppliers', `Suppliers (${sup.length})`)}{tabBtn('import', 'Import rates (AI)')}{tabBtn('destinations', `Destinations (${dest.length})`)}</div>

      {tab !== 'import' && tab !== 'destinations' && <StatRow>
        <StatTile icon={Building2} label="Suppliers" value={sup.length} sub={`${sup.filter(x => !counts[x.id]).length} with no rates yet`} />
        <StatTile icon={Tags} label="Rate cards" value={rates.length} />
        <StatTile icon={AlertTriangle} tone="#d92d20" label="Expired rates" value={expired} accent={expired ? '#d92d20' : undefined} sub={expired ? 'click to review' : 'all up to date'} onClick={() => { setTab('rates'); setFState(fState === 'expired' ? '' : 'expired') }} active={fState === 'expired'} />
        <StatTile icon={CalendarClock} tone="#f98f26" label="Ending in 30 days" value={soon} />
      </StatRow>}

      {sup.length === 0 && tab === 'rates' && <div className="card card-body" style={{ marginBottom: 12 }}><b>Start here:</b> add your first supplier on the Suppliers tab, then come back and add its rates. Rates are what you pay; the itinerary builder adds your markup. Press <b>Guide</b> at the top for a walk-through.</div>}

      {form?.kind === 'supplier' && <form className="card card-body" onSubmit={saveSupplier} style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(190px,1fr))', gap: 10, marginBottom: 12 }}>
        <h3 style={{ gridColumn: '1 / -1', margin: 0 }}>{form.id ? 'Edit supplier' : 'New supplier'}</h3>
        <label className="form-label">Name *<input className="form-input" value={fd.name} onChange={setFd('name')} autoFocus required /></label>
        <label className="form-label">Type<select className="form-select" value={fd.type} onChange={setFd('type')}>{STYPES.map(x => <option key={x}>{x}</option>)}</select></label>
        <label className="form-label">City<input className="form-input" value={fd.city || ''} onChange={setFd('city')} /></label>
        <label className="form-label">Contact person<input className="form-input" value={fd.contact_name || ''} onChange={setFd('contact_name')} /></label>
        <label className="form-label">Phone<input className="form-input" value={fd.phone || ''} onChange={setFd('phone')} /></label>
        <label className="form-label">WhatsApp<input className="form-input" value={fd.whatsapp || ''} onChange={setFd('whatsapp')} /></label>
        <label className="form-label">Email<input className="form-input" type="email" value={fd.email || ''} onChange={setFd('email')} /></label>
        <label className="form-label">GSTIN<input className="form-input" value={fd.gstin || ''} onChange={setFd('gstin')} /></label>
        <label className="form-label">Payment terms<input className="form-input" placeholder="e.g. 50% advance, rest 7 days before" value={fd.payment_terms || ''} onChange={setFd('payment_terms')} /></label>
        <label className="form-label" style={{ gridColumn: '1 / -1' }}>Notes<textarea className="form-input" rows={2} value={fd.notes || ''} onChange={setFd('notes')} /></label>
        <div style={{ gridColumn: '1 / -1', display: 'flex', gap: 8 }}><button className="btn btn-primary" disabled={busy}>{form.id ? 'Save changes' : 'Add supplier'}</button><button type="button" className="btn btn-ghost" onClick={() => setForm(null)}>Cancel</button></div>
      </form>}

      {form?.kind === 'rate' && <form className="card card-body" onSubmit={saveRate} style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(190px,1fr))', gap: 10, marginBottom: 12 }}>
        <h3 style={{ gridColumn: '1 / -1', margin: 0 }}>{form.id ? 'Edit rate' : 'New rate'}</h3>
        <label className="form-label">Supplier *<select className="form-select" value={fd.supplier_id} onChange={setFd('supplier_id')} required><option value="">Choose…</option>{sup.map(x => <option key={x.id} value={x.id}>{x.name}</option>)}</select></label>
        <label className="form-label" style={{ gridColumn: 'span 2' }}>Service *<input className="form-input" placeholder="e.g. Deluxe room with breakfast, Innova Crysta airport transfer" value={fd.service_name} onChange={setFd('service_name')} required /></label>
        <label className="form-label">Type<select className="form-select" value={fd.service_type} onChange={setFd('service_type')}>{RTYPES.map(x => <option key={x}>{x}</option>)}</select></label>
        <label className="form-label">Charged<select className="form-select" value={fd.unit} onChange={setFd('unit')}>{UNITS.map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
        <label className="form-label">Cost you pay *
          <span style={{ display: 'flex', gap: 4 }}><select className="form-select" style={{ maxWidth: 84 }} value={fd.currency} onChange={setFd('currency')}><option>INR</option>{Object.keys(CUR).map(c => <option key={c}>{c}</option>)}</select>
            <input className="form-input" type="number" min="0" step="any" value={fd.price} onChange={setFd('price')} required /></span></label>
        <label className="form-label">Destination<select className="form-select" value={fd.destination_id} onChange={setFd('destination_id')}><option value="">Any / not set</option>{dest.map(x => <option key={x.id} value={x.id}>{x.name}</option>)}</select></label>
        <label className="form-label">Valid from<input className="form-input" type="date" value={fd.valid_from} onChange={setFd('valid_from')} /></label>
        <label className="form-label">Valid to<input className="form-input" type="date" value={fd.valid_to} onChange={setFd('valid_to')} /></label>
        <small style={{ gridColumn: '1 / -1', color: 'var(--text-3)' }}>This is what <b>you</b> pay the supplier, not the customer price. Leave the dates empty if the rate has no season. {fd.currency !== 'INR' && 'Foreign-currency rates need an exchange rate under Settings → Exchange rates.'}</small>
        <div style={{ gridColumn: '1 / -1', display: 'flex', gap: 8 }}><button className="btn btn-primary" disabled={busy}>{form.id ? 'Save changes' : 'Add rate'}</button><button type="button" className="btn btn-ghost" onClick={() => setForm(null)}>Cancel</button></div>
      </form>}

      {(tab === 'suppliers' || tab === 'rates') && <div className="card card-body" style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12, padding: 12 }}>
        <input className="form-input" style={{ flex: '2 1 220px' }} placeholder={tab === 'rates' ? 'Search supplier, service, destination…' : 'Search name, city, phone, GSTIN…'} value={q} onChange={e => setQ(e.target.value)} />
        {tab === 'rates' && <select className="form-select" style={{ flex: '1 1 150px' }} value={fSup} onChange={e => setFSup(e.target.value)}><option value="">All suppliers</option>{sup.map(x => <option key={x.id} value={x.id}>{x.name}</option>)}</select>}
        <select className="form-select" style={{ flex: '1 1 120px' }} value={fType} onChange={e => setFType(e.target.value)}><option value="">All types</option>{(tab === 'rates' ? RTYPES : STYPES).map(x => <option key={x}>{x}</option>)}</select>
        {tab === 'rates' && <select className="form-select" style={{ flex: '1 1 140px' }} value={fDest} onChange={e => setFDest(e.target.value)}><option value="">All destinations</option>{dest.map(x => <option key={x.id} value={x.id}>{x.name}</option>)}</select>}
        {tab === 'rates' && <select className="form-select" style={{ flex: '1 1 130px' }} value={fState} onChange={e => setFState(e.target.value)}><option value="">Any validity</option><option value="ok">Valid now</option><option value="expired">Expired</option><option value="future">Not started</option></select>}
      </div>}

      {tab === 'suppliers' && <div className="card" style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse' }}><thead><tr>{['Name', 'Type', 'City', 'Contact', 'GSTIN', 'Rates', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
        <tbody>{supRows.map(x => <tr key={x.id}><td style={td}><b>{x.name}</b>{x.payment_terms && <small style={{ display: 'block', color: 'var(--text-3)' }}>{x.payment_terms}</small>}</td><td style={td}>{x.type}</td><td style={td}>{x.city}</td>
          <td style={td}>{x.contact_name && <div>{x.contact_name}</div>}{x.phone}{x.email && <small style={{ display: 'block', color: 'var(--text-3)' }}>{x.email}</small>}</td><td style={td}>{x.gstin}</td>
          <td style={td}><button className="btn btn-sm btn-ghost" onClick={() => { setFSup(String(x.id)); setTab('rates') }}>{counts[x.id] || 0} rates</button></td>
          <td style={{ ...td, whiteSpace: 'nowrap' }}><button className="btn btn-sm btn-ghost" onClick={() => { setForm({ kind: 'supplier', id: x.id, data: { ...BLANK_S, ...x } }); window.scrollTo({ top: 0, behavior: 'smooth' }) }}>Edit</button><button className="btn btn-sm btn-ghost" onClick={() => delSupplier(x)}>Delete</button></td></tr>)}
          {supRows.length === 0 && <tr><td style={{ ...td, color: 'var(--text-3)' }} colSpan={7}>{sup.length ? 'No supplier matches your search.' : 'No suppliers yet. Click “+ New supplier”.'}</td></tr>}</tbody></table></div>}

      {tab === 'rates' && <div className="card" style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse' }}><thead><tr>{['Supplier', 'Service', 'Charged', 'Cost', 'Destination', 'Valid', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
        <tbody>{rateRows.map(r => { const st = rateState(r); return <tr key={r.id} style={st === 'expired' ? { background: 'rgba(217,45,32,.05)' } : undefined}>
          <td style={td}>{names[r.supplier_id] || <i style={{ color: 'var(--text-3)' }}>deleted supplier</i>}</td><td style={td}><b>{r.service_name}</b><small style={{ display: 'block', color: 'var(--text-3)' }}>{r.service_type}</small></td>
          <td style={td}>{(UNIT_LABEL[r.unit] || r.unit).replace(/ \(.*\)/, '')}</td><td style={td}><b>{money(r)}</b></td><td style={td}>{dnames[r.destination_id] || '—'}</td>
          <td style={td}>{r.valid_from || r.valid_to ? <>{r.valid_from || '…'} → {r.valid_to || '…'}</> : <span style={{ color: 'var(--text-3)' }}>no season</span>} {st === 'expired' && <Badge kind="bad">Expired</Badge>}{st === 'future' && <Badge kind="warn">Not started</Badge>}</td>
          <td style={{ ...td, whiteSpace: 'nowrap' }}><button className="btn btn-sm btn-ghost" onClick={() => editRate(r, false)}>Edit</button><button className="btn btn-sm btn-ghost" title="New rate pre-filled from this one, for the next season" onClick={() => editRate(r, true)}>Copy</button><button className="btn btn-sm btn-ghost" onClick={() => delRate(r)}>Delete</button></td></tr> })}
          {rateRows.length === 0 && <tr><td style={{ ...td, color: 'var(--text-3)' }} colSpan={7}>{rates.length ? 'No rate matches these filters.' : 'No rate cards yet. Click “+ New rate”, or use “Import rates (AI)” to load a whole price list.'}</td></tr>}</tbody></table></div>}

      {tab === 'import' && <RateImportPanel suppliers={sup} destinations={dest} onDone={() => { load(); setTab('rates') }} />}

      {tab === 'destinations' && <>
        <form className="card card-body" onSubmit={guard(() => travel.create('destinations', d).then(() => setD({ ...d, name: '' })), 'Destination added')} style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
          <input className="form-input" style={{ flex: '2 1 180px' }} placeholder="Destination *" value={d.name} onChange={e => setD({ ...d, name: e.target.value })} required />
          <input className="form-input" style={{ flex: '1 1 130px' }} placeholder="Country" value={d.country} onChange={e => setD({ ...d, country: e.target.value })} />
          <select className="form-select" style={{ flex: '1 1 130px' }} value={d.is_domestic} onChange={e => setD({ ...d, is_domestic: Number(e.target.value) })}><option value={1}>Domestic</option><option value={0}>International</option></select>
          <button className="btn btn-primary">Add</button></form>
        <div className="card" style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse' }}><thead><tr>{['Destination', 'Country', 'Type', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>{dest.map(x => <tr key={x.id}><td style={td}><b>{x.name}</b></td><td style={td}>{x.country}</td><td style={td}>{Number(x.is_domestic) ? 'Domestic' : 'International'}</td><td style={td}><button className="btn btn-sm btn-ghost" onClick={() => delDest(x)}>Delete</button></td></tr>)}</tbody></table></div></>}
    </div>
  )
}
