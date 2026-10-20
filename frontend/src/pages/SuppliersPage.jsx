import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import { createPortal } from 'react-dom'
import { Building2, Tags, AlertTriangle, CalendarClock, X, Phone, Mail, MessageCircle, MapPin, Pencil, Trash2, Copy, Plus, Search } from 'lucide-react'
import { travel, inr } from '../api/travel'
import { toast } from '../components/Toast'
import { confirmAsk } from '../components/confirmAsk'
import { StatRow, StatTile } from '../components/StatTile'
import Field from '../components/FormField'
import { firstInvalid, shake } from '../lib/formFocus'
import { CUR, toMinor, toMajor, fmt } from '../lib/currency'
import { validGstin } from '../lib/gst'
import RateImportPanel from '../components/RateImportPanel'

const STYPES = ['hotel', 'transport', 'activity', 'dmc', 'airline', 'visa', 'insurance', 'guide', 'cruise', 'other']
const SICON = { hotel: '🏨', transport: '🚐', activity: '🎯', dmc: '🧭', airline: '✈️', visa: '🛂', insurance: '🛡️', guide: '🧑‍🏫', cruise: '🚢', other: '📦' }
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
const td = { padding: '12px 14px', borderTop: '1px solid var(--border)', verticalAlign: 'top' }
const Badge = ({ kind, children }) => <span className={'rp-badge' + (kind ? ' ' + kind : '')}>{children}</span>
const digits = (v) => String(v || '').replace(/\D/g, '')

// ---- validation: one function per form, returns { field: message }. Used live (blur / typing once flagged) and on submit.
function validateSupplier(d, all, editId) {
  const e = {}
  const name = d.name.trim()
  if (!name) e.name = 'Enter the supplier’s name'
  else if (name.length < 2) e.name = 'The name is too short'
  else if (all.some(x => x.id !== editId && x.name.trim().toLowerCase() === name.toLowerCase())) e.name = `You already have a supplier called “${name}”`
  if (d.email && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(d.email.trim())) e.email = 'This does not look like an email address'
  for (const k of ['phone', 'whatsapp']) if (d[k] && (digits(d[k]).length < 8 || digits(d[k]).length > 15)) e[k] = 'Enter 8 to 15 digits, with the country code if outside India'
  if (d.gstin && !validGstin(d.gstin)) e.gstin = String(d.gstin).trim().length < 15 ? 'A GSTIN has 15 characters' : 'This GSTIN is not valid. Please check it'
  return e
}
function validateRate(d, fx) {
  const e = {}
  if (!d.supplier_id) e.supplier_id = 'Choose the supplier'
  if (!d.service_name.trim()) e.service_name = 'Describe the service, e.g. “Deluxe room with breakfast”'
  if (d.price === '' || d.price == null) e.price = 'Enter the cost you pay'
  else if (Number(d.price) <= 0) e.price = 'The cost must be more than 0'
  if (d.valid_from && d.valid_to && d.valid_to < d.valid_from) e.valid_to = 'This date is before “Valid from”'
  if (d.currency !== 'INR' && !e.price && !fx.some(x => x.currency === d.currency)) e.warnFx = `No ${d.currency} exchange rate yet. Add it under Settings → Exchange rates before quoting this rate.`
  return e
}

/** Modern modal: closes on Esc / backdrop, locks nothing else. */
function Dialog({ icon, title, sub, onClose, children, footer, formRef, onSubmit }) {
  useEffect(() => {
    const k = (e) => { if (e.key === 'Escape') onClose() }
    document.addEventListener('keydown', k)
    return () => document.removeEventListener('keydown', k)
  }, [onClose])
  return createPortal(
    <div className="dlg-back" role="presentation" onMouseDown={(e) => { if (e.target === e.currentTarget) onClose() }}>
      <form className="dlg" ref={formRef} onSubmit={onSubmit} noValidate role="dialog" aria-modal="true" aria-label={title}>
        <div className="dlg-h"><span className="dlg-ico">{icon}</span><div><h3>{title}</h3>{sub && <small>{sub}</small>}</div><button type="button" className="dlg-x" aria-label="Close" onClick={onClose}><X size={18} /></button></div>
        <div className="dlg-b">{children}</div>
        <div className="dlg-f">{footer}</div>
      </form>
    </div>, document.body)
}

export default function SuppliersPage() {
  const [tab, setTab] = useState('rates')
  const [loaded, setLoaded] = useState(false)
  const [sup, setSup] = useState([]); const [rates, setRates] = useState([]); const [dest, setDest] = useState([]); const [fx, setFx] = useState([])
  const [d, setD] = useState({ name: '', country: 'India', is_domestic: 1 }); const [dErr, setDErr] = useState('')
  const [form, setForm] = useState(null)         // {kind:'supplier'|'rate', id?, data}
  const [errs, setErrs] = useState({})
  const [q, setQ] = useState('')
  const [fSup, setFSup] = useState(''); const [fType, setFType] = useState(''); const [fDest, setFDest] = useState(''); const [fState, setFState] = useState('')
  const [busy, setBusy] = useState(false)
  const formRef = useRef(null)
  const load = useCallback(() => Promise.all([travel.list('suppliers'), travel.list('rates'), travel.list('destinations'), travel.fxRates().catch(() => ({ rates: [] }))])
    .then(([a, b, c, f]) => { setSup(a); setRates(b); setDest(c); setFx(f.rates || []); setLoaded(true) }).catch(e => { setLoaded(true); toast.error('Could not load your suppliers', e.message) }), [])
  useEffect(() => { load() }, [load])
  const names = useMemo(() => Object.fromEntries(sup.map(x => [x.id, x.name])), [sup])
  const dnames = useMemo(() => Object.fromEntries(dest.map(x => [x.id, x.name])), [dest])
  const counts = useMemo(() => { const c = {}; rates.forEach(r => { c[r.supplier_id] = (c[r.supplier_id] || 0) + 1 }); return c }, [rates])
  const tcounts = useMemo(() => { const c = {}; sup.forEach(x => { c[x.type] = (c[x.type] || 0) + 1 }); return c }, [sup])
  const tabBtn = (k, label) => <button key={k} className={'btn btn-sm ' + (tab === k ? 'btn-primary' : 'btn-ghost')} onClick={() => { setTab(k); closeForm(); setQ('') }}>{label}</button>
  const t = q.trim().toLowerCase()
  const closeForm = useCallback(() => { setForm(null); setErrs({}) }, [])

  async function run(fn, okTitle, okText) {
    setBusy(true)
    try { await fn(); toast.success(okTitle, okText); await load(); return true }
    catch (er) { toast.error('Could not save', er.message || 'Something went wrong. Please try again.'); shake(formRef.current); return false }
    finally { setBusy(false) }
  }
  const fd = form?.data
  const validate = (kind, data) => (kind === 'supplier' ? validateSupplier(data, sup, form?.id) : validateRate(data, fx))
  // typing clears a flagged field as soon as it is fixed; blur flags a bad one
  const change = (k) => (e) => {
    const v = k === 'gstin' ? e.target.value.toUpperCase() : e.target.value
    const data = { ...form.data, [k]: v }
    setForm({ ...form, data })
    if (errs[k] || (k === 'valid_from' && errs.valid_to) || (k === 'currency')) { const n = validate(form.kind, data); setErrs(s => ({ ...s, [k]: n[k], ...(k === 'valid_from' ? { valid_to: n.valid_to } : {}), warnFx: n.warnFx })) }
  }
  const blur = (k) => () => { const n = validate(form.kind, form.data); setErrs(s => ({ ...s, [k]: n[k], warnFx: n.warnFx })) }
  function failed(n) {
    const keys = Object.keys(n).filter(k => k !== 'warnFx')
    setErrs(n); shake(formRef.current); firstInvalid(formRef.current)
    toast.warning(keys.length === 1 ? 'One thing needs fixing' : `${keys.length} things need fixing`, n[keys[0]])
  }

  // ---- suppliers
  const supRows = sup.filter(x => (!fType || x.type === fType) && (!t || `${x.name} ${x.city} ${x.phone} ${x.gstin} ${x.contact_name} ${x.email}`.toLowerCase().includes(t)))
  async function saveSupplier(e) {
    e.preventDefault()
    const n = validateSupplier(form.data, sup, form.id)
    if (Object.keys(n).length) return failed(n)
    const body = { ...form.data }; Object.keys(body).forEach(k => { body[k] = typeof body[k] === 'string' ? body[k].trim() : body[k]; if (body[k] === '') body[k] = null }); if (body.gstin) body.gstin = body.gstin.toUpperCase()
    const editing = !!form.id
    if (await run(() => (editing ? travel.update('suppliers', form.id, body) : travel.create('suppliers', body)), editing ? 'Supplier updated' : 'Supplier added', body.name)) closeForm()
  }
  async function delSupplier(x) {
    const n = counts[x.id] || 0
    if (!(await confirmAsk(`Delete ${x.name}?${n ? `\n\nIts ${n} rate card${n > 1 ? 's' : ''} will be deleted too. Quotes already built keep their prices.` : ''}`, { okLabel: 'Delete', danger: true }))) return
    run(() => travel.remove('suppliers', x.id), 'Supplier deleted', x.name)
  }

  // ---- rates
  const rateRows = rates.filter(r => (!fSup || String(r.supplier_id) === fSup) && (!fType || r.service_type === fType) && (!fDest || String(r.destination_id) === fDest) && (!fState || rateState(r) === fState)
    && (!t || `${names[r.supplier_id] || ''} ${r.service_name} ${r.service_type} ${dnames[r.destination_id] || ''}`.toLowerCase().includes(t)))
    .sort((a, b) => (names[a.supplier_id] || '').localeCompare(names[b.supplier_id] || '') || a.service_name.localeCompare(b.service_name))
  const expired = rates.filter(r => rateState(r) === 'expired').length
  const soon = rates.filter(r => r.valid_to && r.valid_to >= todayStr() && r.valid_to <= dayStr(30)).length
  function openRate(r, copy) {
    setErrs({})
    if (!r) return setForm({ kind: 'rate', data: { ...BLANK_R, supplier_id: fSup } })
    const cur = r.currency || 'INR'
    setForm({ kind: 'rate', id: copy ? null : r.id, data: { supplier_id: String(r.supplier_id), service_name: copy ? r.service_name + ' (copy)' : r.service_name, service_type: r.service_type, unit: r.unit, currency: cur,
      price: toMajor(r.cost_amount, cur), destination_id: r.destination_id ? String(r.destination_id) : '', valid_from: copy ? '' : r.valid_from || '', valid_to: copy ? '' : r.valid_to || '' } })
  }
  async function saveRate(e) {
    e.preventDefault()
    const f = form.data
    const n = validateRate(f, fx)
    const hard = Object.fromEntries(Object.entries(n).filter(([k]) => k !== 'warnFx'))
    if (Object.keys(hard).length) return failed(n)
    const body = { supplier_id: Number(f.supplier_id), service_name: f.service_name.trim(), service_type: f.service_type, unit: f.unit, currency: f.currency, cost_amount: toMinor(f.price, f.currency),
      destination_id: f.destination_id ? Number(f.destination_id) : null, valid_from: f.valid_from || null, valid_to: f.valid_to || null }
    const editing = !!form.id
    if (await run(() => (editing ? travel.update('rates', form.id, body) : travel.create('rates', body)), editing ? 'Rate updated' : 'Rate added', `${body.service_name} · ${names[body.supplier_id] || ''}`)) {
      if (n.warnFx) toast.warning('Exchange rate missing', n.warnFx)
      closeForm()
    }
  }
  async function delRate(r) {
    if (!(await confirmAsk(`Delete the rate “${r.service_name}” (${names[r.supplier_id] || ''})?\n\nQuotes already built keep their prices.`, { okLabel: 'Delete', danger: true }))) return
    run(() => travel.remove('rates', r.id), 'Rate deleted', r.service_name)
  }

  // ---- destinations
  async function addDest(e) {
    e.preventDefault()
    const name = d.name.trim()
    if (!name) { setDErr('Enter the destination name'); toast.warning('One thing needs fixing', 'Enter the destination name'); return }
    if (dest.some(x => x.name.toLowerCase() === name.toLowerCase())) { setDErr(`“${name}” is already in your list`); toast.warning('Already added', `“${name}” is already in your list`); return }
    setDErr('')
    if (await run(() => travel.create('destinations', { ...d, name }), 'Destination added', name)) setD({ ...d, name: '' })
  }
  async function delDest(x) { if (await confirmAsk(`Delete the destination ${x.name}?`, { okLabel: 'Delete', danger: true })) run(() => travel.remove('destinations', x.id), 'Destination deleted', x.name) }

  const hasFilters = !!(q || fSup || fType || fDest || fState)
  const clear = () => { setQ(''); setFSup(''); setFType(''); setFDest(''); setFState('') }
  const E = errs

  return (
    <div className="page page-wide">
      <div className="page-header"><div><h1 className="page-title">Suppliers &amp; Rates</h1><div style={{ color: 'var(--text-3)', fontSize: 13 }}>Your price list. The itinerary builder reads from here.</div></div>
        <div style={{ display: 'flex', gap: 8 }}>
          {tab === 'suppliers' && <button className="btn btn-primary" onClick={() => { setErrs({}); setForm({ kind: 'supplier', data: BLANK_S }) }}><Plus size={15} /> New supplier</button>}
          {tab === 'rates' && <button className="btn btn-primary" onClick={() => { if (!sup.length) { toast.info('Add a supplier first', 'A rate belongs to a supplier. Add one on the Suppliers tab.'); setTab('suppliers'); return } openRate(null) }}><Plus size={15} /> New rate</button>}
        </div></div>
      <div style={{ display: 'flex', gap: 8, marginBottom: 14, flexWrap: 'wrap' }}>{tabBtn('rates', `Rate cards (${rates.length})`)}{tabBtn('suppliers', `Suppliers (${sup.length})`)}{tabBtn('import', 'Import rates (AI)')}{tabBtn('destinations', `Destinations (${dest.length})`)}</div>

      {(tab === 'rates' || tab === 'suppliers') && <StatRow>
        <StatTile icon={Building2} label="Suppliers" value={sup.length} sub={`${sup.filter(x => !counts[x.id]).length} with no rates yet`} />
        <StatTile icon={Tags} label="Rate cards" value={rates.length} />
        <StatTile icon={AlertTriangle} tone="#d92d20" label="Expired rates" value={expired} accent={expired ? '#d92d20' : undefined} sub={expired ? 'click to review' : 'all up to date'} onClick={() => { setTab('rates'); setFState(fState === 'expired' ? '' : 'expired') }} active={fState === 'expired'} />
        <StatTile icon={CalendarClock} tone="#f98f26" label="Ending in 30 days" value={soon} />
      </StatRow>}

      {(tab === 'suppliers' || tab === 'rates') && <div className="card card-body" style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 14, padding: 12, alignItems: 'center' }}>
        <div style={{ position: 'relative', flex: '2 1 240px' }}><Search size={15} style={{ position: 'absolute', left: 10, top: 11, color: 'var(--text-3)' }} />
          <input className="form-input" style={{ paddingLeft: 32 }} placeholder={tab === 'rates' ? 'Search supplier, service, destination…' : 'Search name, city, phone, GSTIN…'} value={q} onChange={e => setQ(e.target.value)} /></div>
        {tab === 'rates' && <select className="form-select" style={{ flex: '1 1 150px' }} value={fSup} onChange={e => setFSup(e.target.value)}><option value="">All suppliers</option>{sup.map(x => <option key={x.id} value={x.id}>{x.name}</option>)}</select>}
        {tab === 'rates' && <select className="form-select" style={{ flex: '1 1 120px' }} value={fType} onChange={e => setFType(e.target.value)}><option value="">All types</option>{RTYPES.map(x => <option key={x}>{x}</option>)}</select>}
        {tab === 'rates' && <select className="form-select" style={{ flex: '1 1 140px' }} value={fDest} onChange={e => setFDest(e.target.value)}><option value="">All destinations</option>{dest.map(x => <option key={x.id} value={x.id}>{x.name}</option>)}</select>}
        {tab === 'rates' && <select className="form-select" style={{ flex: '1 1 130px' }} value={fState} onChange={e => setFState(e.target.value)}><option value="">Any validity</option><option value="ok">Valid now</option><option value="expired">Expired</option><option value="future">Not started</option></select>}
        {tab === 'suppliers' && <div className="chips"><button className={'chip' + (!fType ? ' on' : '')} onClick={() => setFType('')}>All<b>{sup.length}</b></button>{STYPES.filter(x => tcounts[x]).map(x => <button key={x} className={'chip' + (fType === x ? ' on' : '')} onClick={() => setFType(fType === x ? '' : x)}>{SICON[x]} {x}<b>{tcounts[x]}</b></button>)}</div>}
        {hasFilters && <button className="btn btn-sm btn-ghost" onClick={clear}>Clear filters</button>}
      </div>}

      {!loaded && (tab === 'rates' || tab === 'suppliers') && <div className="sdc-grid">{[0, 1, 2].map(i => <div key={i} className="sk" style={{ height: 150 }} />)}</div>}

      {/* ───────── Suppliers: cards ───────── */}
      {loaded && tab === 'suppliers' && (supRows.length === 0
        ? <div className="empty-card"><div className="big">🏨</div><b>{sup.length ? 'No supplier matches your search' : 'Add your first supplier'}</b>
          <p style={{ margin: '6px 0 14px' }}>{sup.length ? 'Try a different word or clear the filters.' : 'Hotels, drivers, activity partners. Once added you can attach their rates and use them in quotes.'}</p>
          {sup.length ? <button className="btn btn-ghost" onClick={clear}>Clear filters</button> : <button className="btn btn-primary" onClick={() => setForm({ kind: 'supplier', data: BLANK_S })}><Plus size={15} /> New supplier</button>}</div>
        : <div className="sdc-grid">{supRows.map(x => {
          const n = counts[x.id] || 0
          return <div key={x.id} className="sdc">
            <div className="sdc-top"><span className="sdc-av">{SICON[x.type] || '📦'}</span>
              <div style={{ minWidth: 0 }}><div className="sdc-name">{x.name}</div><div className="sdc-sub">{x.type}{x.city ? <> · <MapPin size={11} style={{ verticalAlign: -1 }} /> {x.city}</> : ''}</div></div></div>
            <div className="sdc-rows">
              {x.contact_name && <div>👤 {x.contact_name}</div>}
              {x.phone && <div><Phone size={12} style={{ verticalAlign: -1 }} /> <a href={`tel:${x.phone}`}>{x.phone}</a></div>}
              {x.whatsapp && <div><MessageCircle size={12} style={{ verticalAlign: -1 }} /> <a href={`https://wa.me/${digits(x.whatsapp)}`} target="_blank" rel="noreferrer">{x.whatsapp}</a></div>}
              {x.email && <div><Mail size={12} style={{ verticalAlign: -1 }} /> <a href={`mailto:${x.email}`}>{x.email}</a></div>}
              {x.payment_terms && <div style={{ color: 'var(--text-3)' }}>💳 {x.payment_terms}</div>}
              {!x.contact_name && !x.phone && !x.whatsapp && !x.email && <div style={{ color: 'var(--text-3)' }}>No contact details yet</div>}
            </div>
            <div className="sdc-foot">
              <button className="sdc-chip" title="Show this supplier's rate cards" onClick={() => { setFSup(String(x.id)); setTab('rates') }}>{n ? `${n} rate${n > 1 ? 's' : ''}` : 'No rates yet'}</button>
              {x.gstin && <span className="sdc-chip" title="GSTIN">GST ✓</span>}
              <span style={{ marginLeft: 'auto', display: 'flex', gap: 2 }}>
                <button className="btn btn-sm btn-ghost btn-icon" title="Edit" aria-label={`Edit ${x.name}`} onClick={() => { setErrs({}); setForm({ kind: 'supplier', id: x.id, data: { ...BLANK_S, ...Object.fromEntries(Object.entries(x).map(([k, v]) => [k, v ?? ''])) } }) }}><Pencil size={14} /></button>
                <button className="btn btn-sm btn-ghost btn-icon" title="Delete" aria-label={`Delete ${x.name}`} onClick={() => delSupplier(x)}><Trash2 size={14} /></button></span>
            </div>
          </div>
        })}</div>)}

      {/* ───────── Rates: table ───────── */}
      {loaded && tab === 'rates' && (rateRows.length === 0
        ? <div className="empty-card"><div className="big">🏷️</div><b>{rates.length ? 'No rate matches these filters' : 'No rate cards yet'}</b>
          <p style={{ margin: '6px 0 14px' }}>{rates.length ? 'Try clearing a filter.' : 'A rate is what you pay a supplier. Add them one by one, or load a whole price list with Import rates (AI).'}</p>
          {rates.length ? <button className="btn btn-ghost" onClick={clear}>Clear filters</button> : <div style={{ display: 'flex', gap: 8, justifyContent: 'center' }}><button className="btn btn-primary" onClick={() => (sup.length ? openRate(null) : setTab('suppliers'))}><Plus size={15} /> {sup.length ? 'New rate' : 'Add a supplier first'}</button><button className="btn btn-ghost" onClick={() => setTab('import')}>Import rates (AI)</button></div>}</div>
        : <div className="card" style={{ overflowX: 'auto' }}><table className="rt-tbl" style={{ width: '100%', borderCollapse: 'collapse' }}><thead><tr>{['Supplier', 'Service', 'Charged', 'Cost', 'Destination', 'Valid', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>{rateRows.map(r => { const st = rateState(r); return <tr key={r.id} style={st === 'expired' ? { background: 'rgba(217,45,32,.05)' } : undefined}>
            <td style={td}>{names[r.supplier_id] || <i style={{ color: 'var(--text-3)' }}>deleted supplier</i>}</td><td style={td}><b>{r.service_name}</b><small style={{ display: 'block', color: 'var(--text-3)' }}>{r.service_type}</small></td>
            <td style={td}>{(UNIT_LABEL[r.unit] || r.unit).replace(/ \(.*\)/, '')}</td><td style={td}><b>{money(r)}</b></td><td style={td}>{dnames[r.destination_id] || '—'}</td>
            <td style={td}>{r.valid_from || r.valid_to ? <>{r.valid_from || '…'} → {r.valid_to || '…'}</> : <span style={{ color: 'var(--text-3)' }}>no season</span>} {st === 'expired' && <Badge kind="bad">Expired</Badge>}{st === 'future' && <Badge kind="warn">Not started</Badge>}</td>
            <td style={{ ...td, whiteSpace: 'nowrap' }}><button className="btn btn-sm btn-ghost btn-icon" title="Edit" aria-label="Edit rate" onClick={() => openRate(r, false)}><Pencil size={14} /></button><button className="btn btn-sm btn-ghost btn-icon" title="Copy as a new rate (next season)" aria-label="Copy rate" onClick={() => openRate(r, true)}><Copy size={14} /></button><button className="btn btn-sm btn-ghost btn-icon" title="Delete" aria-label="Delete rate" onClick={() => delRate(r)}><Trash2 size={14} /></button></td></tr> })}</tbody></table></div>)}

      {tab === 'import' && <RateImportPanel suppliers={sup} destinations={dest} onDone={() => { load(); setTab('rates') }} />}

      {tab === 'destinations' && <>
        <form className="card card-body" onSubmit={addDest} noValidate style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 12, alignItems: 'flex-start' }}>
          <Field label="Destination" required error={dErr} style={{ flex: '2 1 200px' }}><input className="form-input" placeholder="e.g. Bali" value={d.name} onChange={e => { setD({ ...d, name: e.target.value }); setDErr('') }} /></Field>
          <Field label="Country" style={{ flex: '1 1 140px' }}><input className="form-input" value={d.country} onChange={e => setD({ ...d, country: e.target.value })} /></Field>
          <Field label="Type" style={{ flex: '1 1 140px' }}><select className="form-select" value={d.is_domestic} onChange={e => setD({ ...d, is_domestic: Number(e.target.value) })}><option value={1}>Domestic</option><option value={0}>International</option></select></Field>
          <button className="btn btn-primary" style={{ marginTop: 24 }} disabled={busy}><Plus size={15} /> Add</button></form>
        <div className="card" style={{ overflowX: 'auto' }}><table className="rt-tbl" style={{ width: '100%', borderCollapse: 'collapse' }}><thead><tr>{['Destination', 'Country', 'Type', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>{dest.map(x => <tr key={x.id}><td style={td}><b>{x.name}</b></td><td style={td}>{x.country}</td><td style={td}>{Number(x.is_domestic) ? 'Domestic' : 'International'}</td><td style={td}><button className="btn btn-sm btn-ghost btn-icon" aria-label={`Delete ${x.name}`} onClick={() => delDest(x)}><Trash2 size={14} /></button></td></tr>)}
            {dest.length === 0 && <tr><td colSpan={4} style={{ ...td, color: 'var(--text-3)' }}>No destinations yet. Add the places you sell so rates and quotes can be matched to them.</td></tr>}</tbody></table></div></>}

      {/* ───────── dialogs ───────── */}
      {form?.kind === 'supplier' && <Dialog icon={SICON[fd.type] || '🏨'} title={form.id ? 'Edit supplier' : 'New supplier'} sub="Who you buy from: hotels, drivers, activity partners" onClose={closeForm} formRef={formRef} onSubmit={saveSupplier}
        footer={<><button type="button" className="btn btn-ghost" onClick={closeForm}>Cancel</button><button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : form.id ? 'Save changes' : 'Add supplier'}</button></>}>
        <Field label="Supplier name" required error={E.name}><input className="form-input" autoFocus placeholder="e.g. Azure Atoll Resort" value={fd.name} onChange={change('name')} onBlur={blur('name')} /></Field>
        <Field label="Type"><select className="form-select" value={fd.type} onChange={change('type')}>{STYPES.map(x => <option key={x} value={x}>{SICON[x]} {x}</option>)}</select></Field>
        <Field label="City"><input className="form-input" placeholder="e.g. Munnar" value={fd.city} onChange={change('city')} /></Field>
        <div className="dlg-sec">Contact</div>
        <Field label="Contact person"><input className="form-input" value={fd.contact_name} onChange={change('contact_name')} /></Field>
        <Field label="Phone" error={E.phone}><input className="form-input" inputMode="tel" placeholder="+91 98765 43210" value={fd.phone} onChange={change('phone')} onBlur={blur('phone')} /></Field>
        <Field label="WhatsApp" error={E.whatsapp} hint="Used for the one-tap chat link"><input className="form-input" inputMode="tel" value={fd.whatsapp} onChange={change('whatsapp')} onBlur={blur('whatsapp')} /></Field>
        <Field label="Email" error={E.email}><input className="form-input" type="email" placeholder="reservations@hotel.com" value={fd.email} onChange={change('email')} onBlur={blur('email')} /></Field>
        <div className="dlg-sec">Billing</div>
        <Field label="GSTIN" error={E.gstin} valid={!!fd.gstin && !E.gstin && validGstin(fd.gstin)} validText="Valid GSTIN" hint="Optional. Needed to claim GST input credit"><input className="form-input" maxLength={15} placeholder="15 characters" value={fd.gstin} onChange={change('gstin')} onBlur={blur('gstin')} /></Field>
        <Field label="Payment terms" hint="e.g. 50% advance, rest 7 days before"><input className="form-input" value={fd.payment_terms} onChange={change('payment_terms')} /></Field>
        <Field label="Notes" style={{ gridColumn: '1 / -1' }}><textarea className="form-input" rows={2} value={fd.notes} onChange={change('notes')} /></Field>
      </Dialog>}

      {form?.kind === 'rate' && <Dialog icon="🏷️" title={form.id ? 'Edit rate' : 'New rate'} sub="What you pay the supplier. Markup is added later in the itinerary" onClose={closeForm} formRef={formRef} onSubmit={saveRate}
        footer={<><button type="button" className="btn btn-ghost" onClick={closeForm}>Cancel</button><button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : form.id ? 'Save changes' : 'Add rate'}</button></>}>
        <Field label="Supplier" required error={E.supplier_id}><select className="form-select" autoFocus value={fd.supplier_id} onChange={change('supplier_id')} onBlur={blur('supplier_id')}><option value="">Choose…</option>{sup.map(x => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
        <Field label="Service" required error={E.service_name} style={{ gridColumn: 'span 2' }}><input className="form-input" placeholder="e.g. Deluxe room with breakfast, Innova airport transfer" value={fd.service_name} onChange={change('service_name')} onBlur={blur('service_name')} /></Field>
        <Field label="Type"><select className="form-select" value={fd.service_type} onChange={change('service_type')}>{RTYPES.map(x => <option key={x}>{x}</option>)}</select></Field>
        <Field label="Charged"><select className="form-select" value={fd.unit} onChange={change('unit')}>{UNITS.map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
        <Field label="Cost you pay" required error={E.price} hint={fd.currency !== 'INR' ? `In ${fd.currency}, converted when you quote` : undefined}
          prefix={<select className="form-select" style={{ maxWidth: 88 }} value={fd.currency} onChange={change('currency')} aria-label="Currency"><option>INR</option>{Object.keys(CUR).map(c => <option key={c}>{c}</option>)}</select>}>
          <input className="form-input" type="number" min="0" step="any" placeholder="0" value={fd.price} onChange={change('price')} onBlur={blur('price')} /></Field>
        <Field label="Destination" hint="Helps quotes find this rate"><select className="form-select" value={fd.destination_id} onChange={change('destination_id')}><option value="">Any / not set</option>{dest.map(x => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
        <Field label="Valid from"><input className="form-input" type="date" value={fd.valid_from} onChange={change('valid_from')} /></Field>
        <Field label="Valid to" error={E.valid_to} hint="Leave both dates empty if the rate has no season"><input className="form-input" type="date" value={fd.valid_to} onChange={change('valid_to')} onBlur={blur('valid_to')} /></Field>
        {E.warnFx && <div style={{ gridColumn: '1 / -1', background: '#fff4e0', color: '#8a4b00', borderRadius: 10, padding: '9px 12px', fontSize: 13 }}>⚠ {E.warnFx}</div>}
      </Dialog>}
    </div>
  )
}
