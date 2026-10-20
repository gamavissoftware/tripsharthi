import { useState, useEffect, useCallback, useMemo } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { travel, inr, toPaise } from '../api/travel'
import { toast } from '../components/Toast'
import { toMinor, toMajor, fmt, toInrPaise } from '../lib/currency'
import LoadFailed from '../components/LoadFailed'
import { confirmAsk } from '../components/confirmAsk'
import './ItineraryBuilderPage.css'

const TYPE_ICON = { hotel: '🏨', flight: '✈️', train: '🚆', transfer: '🚗', sightseeing: '📸', activity: '🎯', meal: '🍽️', visa: '🛂', insurance: '🛡️', other: '•' }

const UNIT_LABEL = { per_night: 'per night', per_pax: 'per person', per_vehicle: 'per vehicle', per_day: 'per day', per_room: 'per room', flat: 'flat' }
const today = () => new Date().toISOString().slice(0, 10)
/** Ok / expired / not started, from the rate card's validity dates. */
function rateState(r) {
  const t = today()
  if (r.valid_to && r.valid_to < t) return 'expired'
  if (r.valid_from && r.valid_from > t) return 'future'
  return 'ok'
}
/** What to put in Quantity for a rate, from the trip's travellers. The agent can always change it. */
function suggestQty(unit, itin) {
  const pax = Math.max(1, (Number(itin.adults) || 0) + (Number(itin.children) || 0))
  if (unit === 'per_pax') return pax
  if (unit === 'per_room' || unit === 'per_night') return Math.max(1, Math.ceil((Number(itin.adults) || 1) / 2))
  return 1
}
const mult = (type, qty, nights) => (type === 'hotel' ? qty * nights : qty)

function AddItem({ itin, day, rates, fx, onAdded }) {
  const blank = { type: 'sightseeing', title: '', cost_rs: '', nights: 1, quantity: 1, rate_id: '', currency: 'INR', unit: '' }
  const [open, setOpen] = useState(false)
  const [f, setF] = useState(blank)
  const [q, setQ] = useState('')
  const [ft, setFt] = useState('')
  const [list, setList] = useState(false)
  const fxRate = f.currency !== 'INR' ? fx.find(x => x.currency === f.currency) : null
  const set = (k) => (e) => setF(s => ({ ...s, [k]: e.target.value }))

  const shown = useMemo(() => {
    const t = q.trim().toLowerCase()
    return rates
      .filter(r => (!ft || r.service_type === ft) && (!t || `${r.supplier_name} ${r.service_name} ${r.service_type} ${r.destination_name || ''}`.toLowerCase().includes(t)))
      .map(r => ({ r, state: rateState(r), here: itin.destination_id && String(r.destination_id) === String(itin.destination_id) }))
      // rates for this trip's destination first, valid before expired, then A-Z
      .sort((a, b) => (b.here - a.here) || ((a.state === 'expired') - (b.state === 'expired')) || (a.r.supplier_name + a.r.service_name).localeCompare(b.r.supplier_name + b.r.service_name))
      .slice(0, 60)
  }, [rates, q, ft, itin.destination_id])

  function pickRate(r) {
    const foreign = r.currency && r.currency !== 'INR'
    const haveFx = foreign && fx.find(x => x.currency === r.currency)
    if (foreign && !haveFx) toast.error(`No ${r.currency} exchange rate`, 'Add it under Settings → Exchange rates, then pick this rate again.')
    const type = ['transfer', 'hotel', 'flight', 'visa', 'insurance', 'meal', 'activity', 'sightseeing'].includes(r.service_type) ? r.service_type : 'other'
    // Rate cards store minor units of THEIR currency: $100.00 is 10,000, not ₹100.
    setF(s => ({ ...s, rate_id: r.id, title: r.service_name, type, unit: r.unit, quantity: suggestQty(r.unit, itin),
      nights: type === 'hotel' ? (Number(itin.nights) || 1) : 1,
      currency: haveFx ? r.currency : 'INR', cost_rs: foreign ? (haveFx ? toMajor(r.cost_amount, r.currency) : '') : Math.round(r.cost_amount / 100) }))
    setList(false); setQ('')
  }
  const qty = Number(f.quantity) || 0
  const nights = Math.max(1, Number(f.nights) || 1)
  const unitInr = f.currency === 'INR' ? toPaise(f.cost_rs) : (fxRate ? toInrPaise(toMinor(f.cost_rs, f.currency), f.currency, fxRate.cost_rate) : 0)
  const total = Math.round(unitInr * mult(f.type, qty, nights))
  const paxWarn = f.unit === 'per_pax' && qty === 1 && (Number(itin.adults) || 0) + (Number(itin.children) || 0) > 1

  async function add(e) {
    e.preventDefault()
    if (!f.title.trim()) return toast.error('Give the service a title')
    if (qty <= 0) return toast.error('Quantity must be at least 1')
    const rate = rates.find(x => String(x.id) === String(f.rate_id))
    try {
      const price = f.currency === 'INR' ? { unit_cost: toPaise(f.cost_rs) } : { cost_currency: f.currency, unit_cost_fx: toMinor(f.cost_rs, f.currency) }
      await travel.addItem(itin.id, { day_id: day?.id, type: f.type, title: f.title, ...price, quantity: qty, nights, rate_id: rate?.id, supplier_id: rate?.supplier_id, meta: f.unit ? { unit: f.unit } : undefined })
      setF(blank); setOpen(false); onAdded()
    } catch (er) { toast.error('Could not add', er.message) }
  }
  if (!open) return <button className="btn btn-sm btn-ghost" onClick={() => setOpen(true)}>+ Add service</button>
  return (
    <form onSubmit={add} style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(120px,1fr))', gap: 8, background: 'var(--surface-2)', padding: 10, borderRadius: 10, marginTop: 8 }}>
      <div className="rp-box">
        <div className="rp-filters">
          <input className="form-input" style={{ flex: '2 1 200px' }} placeholder={rates.length ? `Search ${rates.length} rate cards: hotel, city, service…` : 'No rate cards yet: type the service below, or add rates under Suppliers & Rates'} value={q}
            onFocus={() => setList(true)} onChange={e => { setQ(e.target.value); setList(true) }} />
          <select className="form-select" style={{ flex: '1 1 120px' }} value={ft} onChange={e => { setFt(e.target.value); setList(true) }}>
            <option value="">All types</option>{['hotel', 'transfer', 'sightseeing', 'activity', 'flight', 'visa', 'insurance', 'meal', 'other'].map(t => <option key={t}>{t}</option>)}</select>
        </div>
        {list && rates.length > 0 && (
          <div className="rp-list" role="listbox">
            {shown.length === 0 && <div style={{ padding: 12, color: 'var(--text-3)', fontSize: 13 }}>No rate card matches. You can still type the service and price below.</div>}
            {shown.map(({ r, state, here }) => (
              <button type="button" key={r.id} className="rp-item" onClick={() => pickRate(r)}>
                <span><b>{r.service_name}</b><small>{r.supplier_name} · {r.service_type}{r.destination_name ? ` · ${r.destination_name}` : ''}{here ? ' · this trip' : ''}</small></span>
                <span style={{ textAlign: 'right' }}><b>{r.currency && r.currency !== 'INR' ? fmt(r.cost_amount, r.currency) : inr(r.cost_amount)}</b><small>{UNIT_LABEL[r.unit] || r.unit}</small>
                  {state === 'expired' && <span className="rp-badge bad">expired {r.valid_to}</span>}{state === 'future' && <span className="rp-badge warn">starts {r.valid_from}</span>}</span>
              </button>
            ))}
          </div>)}
      </div>
      <select className="form-select" value={f.type} onChange={set('type')}>{Object.keys(TYPE_ICON).map(t => <option key={t}>{t}</option>)}</select>
      <input className="form-input" placeholder="Service title" value={f.title} onChange={set('title')} />
      <div style={{ display: 'flex', gap: 4 }}>
        {fx.length > 0 && <select className="form-select" style={{ maxWidth: 82 }} value={f.currency} onChange={set('currency')} title="Currency the supplier charges in"><option>INR</option>{fx.map(x => <option key={x.currency}>{x.currency}</option>)}</select>}
        <input className="form-input" type="number" step="any" min="0" placeholder={f.currency === 'INR' ? `Cost ₹ ${UNIT_LABEL[f.unit] || 'per unit'}` : `Cost ${f.currency} per unit`} value={f.cost_rs} onChange={set('cost_rs')} />
      </div>
      <label style={{ display: 'grid', gap: 2, fontSize: 11, color: 'var(--text-3)' }}>{f.unit === 'per_pax' ? 'Number of people' : f.unit === 'per_room' || f.unit === 'per_night' ? 'Number of rooms' : f.unit === 'per_vehicle' ? 'Number of vehicles' : 'Quantity'}
        <input className="form-input" type="number" min="1" step="any" value={f.quantity} onChange={set('quantity')} /></label>
      {f.type === 'hotel' && <label style={{ display: 'grid', gap: 2, fontSize: 11, color: 'var(--text-3)' }}>Nights<input className="form-input" type="number" min="1" value={f.nights} onChange={set('nights')} /></label>}
      {fxRate && f.cost_rs !== '' && <small style={{ gridColumn: '1 / -1', color: 'var(--text-2)' }}>{fmt(toMinor(f.cost_rs, f.currency), f.currency)} ≈ <b>{inr(toInrPaise(toMinor(f.cost_rs, f.currency), f.currency, fxRate.cost_rate))}</b> per unit at ₹{fxRate.cost_rate.toFixed(4)} ({fxRate.rate.toFixed(4)} + {fxRate.buffer_pct}% buffer){fxRate.stale ? <b style={{ color: 'var(--danger)' }}> — this rate is {fxRate.age_days} days old</b> : ''}</small>}
      {f.cost_rs !== '' && <small style={{ gridColumn: '1 / -1' }} className="il-calc">
        {inr(unitInr)} × {qty}{f.type === 'hotel' ? ` × ${nights} night${nights > 1 ? 's' : ''}` : ''} = <b style={{ color: 'var(--text)' }}>{inr(total)}</b> your cost
        {f.unit && f.rate_id ? ' · quantity suggested from the trip’s travellers, change it if needed' : ''}</small>}
      {paxWarn && <small style={{ gridColumn: '1 / -1', color: 'var(--warning)' }}>⚠ This rate is per person but quantity is 1 and the trip has {(Number(itin.adults) || 0) + (Number(itin.children) || 0)} travellers.</small>}
      <div style={{ display: 'flex', gap: 6 }}><button className="btn btn-sm btn-primary">Add</button><button type="button" className="btn btn-sm btn-ghost" onClick={() => { setOpen(false); setList(false) }}>✕</button></div>
    </form>
  )
}

/** One service line: shows the working (unit x qty x nights), and edits in place. */
function ItemRow({ itin, item: i, sup, rates, lock, onChanged }) {
  const [edit, setEdit] = useState(false)
  const foreign = i.cost_currency && i.cost_currency !== 'INR'
  const cur = foreign ? i.cost_currency : 'INR'
  const [f, setF] = useState(null)
  const rate = rates.find(r => String(r.id) === String(i.rate_id))
  const unit = rate?.unit || i.meta?.unit
  const qty = Number(i.quantity) || 1
  const nights = Number(i.nights) || 1
  const opt = Number(i.is_optional) === 1
  const startEdit = () => { setF({ title: i.title, quantity: qty, nights, price: foreign ? toMajor(i.unit_cost_fx, cur) : Number(i.unit_cost) / 100, is_optional: opt }); setEdit(true) }
  async function save() {
    try {
      const price = foreign ? { unit_cost_fx: toMinor(f.price, cur), cost_currency: cur } : { unit_cost: toPaise(f.price) }
      await travel.updateItem(itin.id, i.id, { title: f.title, quantity: Number(f.quantity), nights: Number(f.nights), is_optional: f.is_optional ? 1 : 0, ...price })
      setEdit(false); onChanged()
    } catch (er) { toast.error('Could not save', er.message) }
  }
  const working = (qty !== 1 || (i.type === 'hotel' && nights > 1)) && Number(i.unit_cost) > 0
    ? `${inr(i.unit_cost)}${unit ? ' ' + (UNIT_LABEL[unit] || '') : ''} × ${qty}${i.type === 'hotel' ? ` × ${nights}N` : ''}` : ''
  const st = rate ? rateState(rate) : 'ok'
  const paxWarn = unit === 'per_pax' && qty === 1 && (Number(itin.adults) || 0) + (Number(itin.children) || 0) > 1
  return (
    <div className="itin-row" style={{ padding: '8px 0', borderTop: '1px solid var(--border)', opacity: opt ? 0.75 : 1 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, alignItems: 'center' }}>
        <span>{TYPE_ICON[i.type] || '•'} {i.title}{Number(i.nights) > 1 && i.type === 'hotel' ? ` · ${i.nights}N` : ''}{sup[i.supplier_id] ? <small style={{ color: 'var(--text-3)' }}> — {sup[i.supplier_id]}</small> : null}
          {opt && <small className="rp-badge warn" style={{ marginLeft: 6 }}>optional · not in total</small>}
          {Number(i.cost_amount) === 0 && <small style={{ color: 'var(--warning)' }}> · needs price</small>}
          {st === 'expired' && <small className="rp-badge bad" style={{ marginLeft: 6 }}>rate card expired</small>}
          {foreign && <small style={{ color: 'var(--text-3)' }}> · {fmt(i.unit_cost_fx, i.cost_currency)} @ ₹{Number(i.fx_rate).toFixed(2)}</small>}
          {working && <small className="il-calc" style={{ display: 'block' }}>{working}</small>}
          {paxWarn && <small style={{ display: 'block', color: 'var(--warning)' }}>⚠ per-person rate with quantity 1. Edit if this is for everyone.</small>}</span>
        <span style={{ display: 'flex', gap: 6, alignItems: 'center', whiteSpace: 'nowrap' }}><b>{inr(i.cost_amount)}</b>
          {!lock && <button className="btn btn-sm btn-ghost" title="Edit quantity, nights or price" onClick={() => (edit ? setEdit(false) : startEdit())}>✎</button>}
          {!lock && <button className="btn btn-sm btn-ghost" title="Remove" onClick={async () => { if (!(await confirmAsk(`Remove “${i.title}” from this quote?`, { okLabel: 'Remove', danger: true }))) return; try { await travel.deleteItem(itin.id, i.id); onChanged() } catch (er) { toast.error('Could not remove', er.message) } }}>✕</button>}</span>
      </div>
      {edit && f && (
        <div className="il-edit">
          <label style={{ flex: '2 1 160px' }}>Title<input className="form-input" style={{ width: '100%' }} value={f.title} onChange={e => setF({ ...f, title: e.target.value })} /></label>
          <label>{unit === 'per_pax' ? 'People' : unit === 'per_room' || unit === 'per_night' ? 'Rooms' : unit === 'per_vehicle' ? 'Vehicles' : 'Quantity'}<input className="form-input" type="number" min="1" step="any" value={f.quantity} onChange={e => setF({ ...f, quantity: e.target.value })} /></label>
          {i.type === 'hotel' && <label>Nights<input className="form-input" type="number" min="1" value={f.nights} onChange={e => setF({ ...f, nights: e.target.value })} /></label>}
          <label>Price per unit ({cur})<input className="form-input" type="number" min="0" step="any" value={f.price} onChange={e => setF({ ...f, price: e.target.value })} /></label>
          <label style={{ flexDirection: 'row', display: 'flex', alignItems: 'center', gap: 6, paddingBottom: 8 }}><input type="checkbox" checked={f.is_optional} onChange={e => setF({ ...f, is_optional: e.target.checked })} /> Optional add-on</label>
          <button className="btn btn-sm btn-primary" onClick={save}>Save</button><button className="btn btn-sm btn-ghost" onClick={() => setEdit(false)}>Cancel</button>
        </div>)}
    </div>
  )
}

export default function ItineraryBuilderPage() {
  const { id } = useParams()
  const nav = useNavigate()
  const [it, setIt] = useState(null)
  const [rates, setRates] = useState([])
  const [sup, setSup] = useState({})
  const [share, setShare] = useState(null)
  const [fx, setFx] = useState([])
  const [loadErr, setLoadErr] = useState('')
  const load = useCallback(() => travel.itinerary(id).then(x => { setIt(x); setLoadErr('') }).catch(e => { setLoadErr(e.message || 'Not found'); toast.error('Could not load', e.message) }), [id])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    Promise.all([travel.list('rates'), travel.list('suppliers'), travel.fxRates().catch(() => ({ rates: [] })), travel.list('destinations').catch(() => [])]).then(([r, s, f, d]) => {
      const names = Object.fromEntries(s.map(x => [x.id, x.name]))
      const dn = Object.fromEntries(d.map(x => [x.id, x.name]))
      setRates(r.map(x => ({ ...x, supplier_name: names[x.supplier_id] || '', destination_name: dn[x.destination_id] || '' }))); setSup(names); setFx(f.rates)
    }).catch(() => {})
  }, [])

  async function patch(body, msg) {
    try { setIt(await travel.updateItinerary(id, body)); if (msg) toast.success(msg) } catch (e) { toast.error('Update failed', e.message) }
  }
  if (!it && loadErr) return <LoadFailed what="itinerary" message={loadErr} backTo="/trips" backLabel="Back to trips" />
  if (!it) return <div className="page">Loading…</div>

  const lock = !['draft', 'sent', 'viewed'].includes(it.status)
  return (
    <div className="page itin">
      <div className="page-header">
        <div>
          {it.trip_id && <Link to={`/trips/${it.trip_id}`} className="itin-back">← Back to trip</Link>}
          <h1 className="page-title" style={{ margin: '6px 0 0' }}>{it.title} <small className={'itin-status st-' + it.status}>v{it.version} · {it.status}</small></h1>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button className="btn btn-ghost" onClick={() => travel.openQuotePdf(it.id).catch(e => toast.error('Could not open PDF', e.message))}>⬇ PDF</button>
          <button className="btn btn-ghost" onClick={async () => { try { const v = await travel.newVersion(it.id); nav(`/itineraries/${v.id}`); toast.success('New version created') } catch (e) { toast.error('Failed', e.message) } }}>Duplicate as new version</button>
          <button className="btn btn-primary" onClick={async () => { try { const s = await travel.share(it.id); setShare(s.url); navigator.clipboard?.writeText(s.url); toast.success('Quote link copied'); load() } catch (e) { toast.error('Failed', e.message) } }}>Share quote link</button>
          {it.status === 'accepted' || it.trip_id ? <button className="btn btn-success" onClick={async () => { try { const b = await travel.createBooking({ itinerary_id: it.id }); toast.success('Booking created', b.booking_ref); nav(`/bookings/${b.id}`) } catch (e) { toast.error('Could not create booking', e.message) } }}>Confirm booking</button> : null}
        </div>
      </div>
      {share && <div className="card card-body" style={{ marginBottom: 12, wordBreak: 'break-all' }}>🔗 Customer link (copied): <a href={share} target="_blank" rel="noreferrer">{share}</a></div>}

      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0,2fr) minmax(280px,1fr)', gap: 16 }} className="itin-grid">
        <div>
          {it.days.map(d => (
            <div key={d.id} className="card card-body itin-day" style={{ marginBottom: 14 }}>
              <h3 style={{ margin: 0 }}><span className="itin-daynum">{d.day_no}</span>{d.title}</h3>
              {d.city && <small className="itin-city">📍 {d.city}</small>}
              {d.description && <p>{d.description}</p>}
              {d.items.map(i => <ItemRow key={i.id} itin={it} item={i} sup={sup} rates={rates} lock={lock} onChanged={load} />)}
              {!lock && <AddItem itin={it} day={d} rates={rates} fx={fx} onAdded={load} />}
            </div>
          ))}
          {(it.extras?.length > 0 || it.days.length === 0) && (
            <div className="card card-body itin-day" style={{ marginBottom: 14 }}>
              <h3 style={{ margin: 0 }}><span className="itin-daynum extra">+</span>Other services <small style={{ color: 'var(--text-3)', fontWeight: 400 }}>(not tied to a day)</small></h3>
              {it.extras?.map(i => <ItemRow key={i.id} itin={it} item={i} sup={sup} rates={rates} lock={lock} onChanged={load} />)}
              {!lock && <AddItem itin={it} day={null} rates={rates} fx={fx} onAdded={load} />}
            </div>
          )}
          <button className="itin-addday" onClick={async () => { await travel.addDay(it.id, { title: `Day ${it.days.length + 1}` }); load() }}>+ Add day</button>
          <div className="card card-body" style={{ marginTop: 12 }}>
            <label className="form-label">Inclusions</label>
            <textarea className="form-input" rows={4} defaultValue={it.inclusions || ''} onBlur={e => e.target.value !== (it.inclusions || '') && patch({ inclusions: e.target.value })} />
            <label className="form-label" style={{ marginTop: 10 }}>Exclusions</label>
            <textarea className="form-input" rows={4} defaultValue={it.exclusions || ''} onBlur={e => e.target.value !== (it.exclusions || '') && patch({ exclusions: e.target.value })} />
          </div>
        </div>

        <div className="card card-body itin-price" style={{ alignSelf: 'start', position: 'sticky', top: 70 }}>
          <h3 style={{ marginTop: 0 }}>Pricing</h3>
          <div className="form-group"><label className="form-label">Markup</label>
            <div style={{ display: 'flex', gap: 6 }}>
              <select className="form-select" style={{ maxWidth: 90 }} value={it.markup_type} disabled={lock} onChange={e => patch({ markup_type: e.target.value, markup_value: e.target.value === 'flat' ? 0 : 15 })}><option value="percent">%</option><option value="flat">₹</option></select>
              <input className="form-input" type="number" defaultValue={it.markup_type === 'flat' ? Number(it.markup_value) / 100 : it.markup_value} key={it.markup_type + it.id} disabled={lock}
                onBlur={e => patch({ markup_value: it.markup_type === 'flat' ? toPaise(e.target.value) : e.target.value })} /></div></div>
          <div className="form-group"><label className="form-label">Discount (₹)</label>
            <input className="form-input" type="number" defaultValue={Number(it.discount_amount) / 100} disabled={lock} onBlur={e => patch({ discount_amount: toPaise(e.target.value) })} /></div>
          <div className="form-group"><label className="form-label">GST rate</label>
            <select className="form-select" value={Number(it.gst_rate)} disabled={lock} onChange={e => patch({ gst_rate: e.target.value })}><option value="5">5% (no ITC)</option><option value="18">18% (with ITC)</option><option value="0">0%</option></select></div>
          <label style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 12 }}><input type="checkbox" checked={!!Number(it.is_international)} disabled={lock} onChange={e => patch({ is_international: e.target.checked ? 1 : 0 })} /> International (TCS)</label>
          {fx.length > 0 && <div className="form-group"><label className="form-label">Show the customer an equivalent in</label>
            <select className="form-select" value={it.display_currency || ''} onChange={e => patch({ display_currency: e.target.value }, 'Saved')}><option value="">Rupees only</option>{fx.map(x => <option key={x.currency} value={x.currency}>{x.currency}</option>)}</select>
            <small style={{ color: 'var(--text-3)' }}>Indicative only — the customer still pays in rupees.</small></div>}
          {(it.days.some(d => d.items.some(i => i.cost_currency && i.cost_currency !== 'INR')) || it.extras?.some(i => i.cost_currency && i.cost_currency !== 'INR')) && !['accepted'].includes(it.status) &&
            <button className="btn btn-sm btn-ghost" style={{ marginBottom: 10 }} title="Foreign-currency lines keep the rate they were priced at. Press to re-price them at today's rates."
              onClick={async () => { try { const r = await travel.relockFx(it.id); setIt(r.itinerary); toast.success(r.changed ? `${r.changed} line(s) re-priced` : 'Rates unchanged', r.changed ? `Total ${inr(r.old_total)} → ${inr(r.new_total)}` : undefined); if (r.stale?.length) toast.error('Old rates', `${r.stale.join(', ')} rate is more than 3 days old — refresh it in Exchange rates.`) } catch (e) { toast.error('Could not re-price', e.message) } }}>↻ Re-price foreign lines at today's rates</button>}
          {[['Supplier cost', it.cost_total, true], ['Selling price', it.sell_subtotal], [`GST ${Number(it.gst_rate)}%`, it.gst_amount], Number(it.tcs_amount) > 0 && [`TCS ${Number(it.tcs_rate)}%`, it.tcs_amount]].filter(Boolean).map(([k, v, dim]) => (
            <div key={k} style={{ display: 'flex', justifyContent: 'space-between', padding: '5px 0', color: dim ? 'var(--text-3)' : 'inherit' }}><span>{k}</span><span>{inr(v)}</span></div>
          ))}
          <div className="itin-total"><span>Total</span><span>{inr(it.grand_total)}</span></div>
          <div className="itin-margin">Your margin <b>{inr(it.margin_amount)}</b></div>
          <p style={{ fontSize: 12, color: 'var(--text-3)', marginBottom: 0 }}>Customers never see cost or margin. Taxes follow current rules; have your CA confirm the TCS base.</p>
        </div>
      </div>
      <style>{`@media (max-width: 900px){ .itin-grid{ grid-template-columns: 1fr !important; } }`}</style>
    </div>
  )
}
