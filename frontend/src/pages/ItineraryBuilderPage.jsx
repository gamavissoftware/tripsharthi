import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { travel, inr, toPaise } from '../api/travel'
import { toast } from '../components/Toast'
import { toMinor, toMajor, fmt, toInrPaise } from '../lib/currency'
import LoadFailed from '../components/LoadFailed'

const TYPE_ICON = { hotel: '🏨', flight: '✈️', train: '🚆', transfer: '🚗', sightseeing: '📸', activity: '🎯', meal: '🍽️', visa: '🛂', insurance: '🛡️', other: '•' }

function AddItem({ itin, day, rates, fx, onAdded }) {
  const [open, setOpen] = useState(false)
  const [f, setF] = useState({ type: 'sightseeing', title: '', cost_rs: '', nights: 1, rate_id: '', currency: 'INR' })
  const fxRate = f.currency !== 'INR' ? fx.find(x => x.currency === f.currency) : null
  const set = (k) => (e) => setF(s => ({ ...s, [k]: e.target.value }))
  function pickRate(e) {
    const r = rates.find(x => String(x.id) === e.target.value)
    if (!r) return setF(s => ({ ...s, rate_id: '' }))
    const foreign = r.currency && r.currency !== 'INR'
    if (foreign && !fx.find(x => x.currency === r.currency)) toast.error(`No ${r.currency} exchange rate`, 'Add it under Settings → Exchange rates, then pick this rate again.')
    // Rate cards store minor units of THEIR currency: $100.00 is 10,000, not ₹100.
    setF(s => ({ ...s, rate_id: r.id, title: r.service_name, type: r.service_type === 'transfer' ? 'transfer' : r.service_type === 'hotel' ? 'hotel' : s.type,
      currency: foreign && fx.find(x => x.currency === r.currency) ? r.currency : 'INR', cost_rs: foreign ? (fx.find(x => x.currency === r.currency) ? toMajor(r.cost_amount, r.currency) : '') : Math.round(r.cost_amount / 100) }))
  }
  async function add(e) {
    e.preventDefault()
    if (!f.title.trim()) return
    const rate = rates.find(x => String(x.id) === String(f.rate_id))
    try {
      const price = f.currency === 'INR' ? { unit_cost: toPaise(f.cost_rs) } : { cost_currency: f.currency, unit_cost_fx: toMinor(f.cost_rs, f.currency) }
      await travel.addItem(itin.id, { day_id: day?.id, type: f.type, title: f.title, ...price, nights: Number(f.nights) || 1, rate_id: rate?.id, supplier_id: rate?.supplier_id })
      setF({ type: 'sightseeing', title: '', cost_rs: '', nights: 1, rate_id: '', currency: 'INR' }); setOpen(false); onAdded()
    } catch (e) { toast.error('Could not add', e.message) }
  }
  if (!open) return <button className="btn btn-sm btn-ghost" onClick={() => setOpen(true)}>+ Add service</button>
  return (
    <form onSubmit={add} style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(120px,1fr))', gap: 8, background: 'var(--surface-2)', padding: 10, borderRadius: 10, marginTop: 8 }}>
      <select className="form-select" onChange={pickRate} value={f.rate_id}><option value="">From rate card…</option>{rates.map(r => <option key={r.id} value={r.id}>{r.supplier_name} – {r.service_name} ({r.currency && r.currency !== 'INR' ? fmt(r.cost_amount, r.currency) : inr(r.cost_amount)})</option>)}</select>
      <select className="form-select" value={f.type} onChange={set('type')}>{Object.keys(TYPE_ICON).map(t => <option key={t}>{t}</option>)}</select>
      <input className="form-input" placeholder="Service title" value={f.title} onChange={set('title')} />
      <div style={{ display: 'flex', gap: 4 }}>
        {fx.length > 0 && <select className="form-select" style={{ maxWidth: 82 }} value={f.currency} onChange={set('currency')} title="Currency the supplier charges in"><option>INR</option>{fx.map(x => <option key={x.currency}>{x.currency}</option>)}</select>}
        <input className="form-input" type="number" step="any" min="0" placeholder={f.currency === 'INR' ? 'Cost ₹ (per unit)' : `Cost ${f.currency} (per unit)`} value={f.cost_rs} onChange={set('cost_rs')} />
      </div>
      {fxRate && f.cost_rs !== '' && <small style={{ gridColumn: '1 / -1', color: 'var(--text-2)' }}>{fmt(toMinor(f.cost_rs, f.currency), f.currency)} ≈ <b>{inr(toInrPaise(toMinor(f.cost_rs, f.currency), f.currency, fxRate.cost_rate))}</b> per unit at ₹{fxRate.cost_rate.toFixed(4)} ({fxRate.rate.toFixed(4)} + {fxRate.buffer_pct}% buffer){fxRate.stale ? <b style={{ color: 'var(--danger)' }}> — this rate is {fxRate.age_days} days old</b> : ''}</small>}
      {f.type === 'hotel' && <input className="form-input" type="number" min="1" placeholder="Nights" value={f.nights} onChange={set('nights')} />}
      <div style={{ display: 'flex', gap: 6 }}><button className="btn btn-sm btn-primary">Add</button><button type="button" className="btn btn-sm btn-ghost" onClick={() => setOpen(false)}>✕</button></div>
    </form>
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
    Promise.all([travel.list('rates'), travel.list('suppliers'), travel.fxRates().catch(() => ({ rates: [] }))]).then(([r, s, f]) => {
      const names = Object.fromEntries(s.map(x => [x.id, x.name]))
      setRates(r.map(x => ({ ...x, supplier_name: names[x.supplier_id] || '' }))); setSup(names); setFx(f.rates)
    }).catch(() => {})
  }, [])

  async function patch(body, msg) {
    try { setIt(await travel.updateItinerary(id, body)); if (msg) toast.success(msg) } catch (e) { toast.error('Update failed', e.message) }
  }
  if (!it && loadErr) return <LoadFailed what="itinerary" message={loadErr} backTo="/trips" backLabel="Back to trips" />
  if (!it) return <div className="page">Loading…</div>

  const lock = !['draft', 'sent', 'viewed'].includes(it.status)
  return (
    <div className="page">
      <div className="page-header">
        <div>
          {it.trip_id && <Link to={`/trips/${it.trip_id}`} style={{ fontSize: 13 }}>← Trip</Link>}
          <h1 className="page-title" style={{ margin: '4px 0' }}>{it.title} <small style={{ color: 'var(--text-3)' }}>v{it.version} · {it.status}</small></h1>
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
            <div key={d.id} className="card card-body" style={{ marginBottom: 12 }}>
              <h3 style={{ margin: 0 }}>Day {d.day_no}: {d.title}</h3>
              {d.city && <small style={{ color: 'var(--text-3)' }}>{d.city}</small>}
              {d.description && <p>{d.description}</p>}
              {d.items.map(i => (
                <div key={i.id} style={{ display: 'flex', justifyContent: 'space-between', gap: 10, padding: '8px 0', borderTop: '1px solid var(--border)', alignItems: 'center' }}>
                  <span>{TYPE_ICON[i.type] || '•'} {i.title}{Number(i.nights) > 1 && i.type === 'hotel' ? ` · ${i.nights}N` : ''}{sup[i.supplier_id] ? <small style={{ color: 'var(--text-3)' }}> — {sup[i.supplier_id]}</small> : null}{Number(i.cost_amount) === 0 && <small style={{ color: 'var(--warning)' }}> · needs price</small>}{i.cost_currency && i.cost_currency !== 'INR' && <small style={{ color: 'var(--text-3)' }}> · {fmt(i.unit_cost_fx, i.cost_currency)} @ ₹{Number(i.fx_rate).toFixed(2)}</small>}</span>
                  <span style={{ display: 'flex', gap: 10, alignItems: 'center' }}><b>{inr(i.cost_amount)}</b>
                    <button className="btn btn-sm btn-ghost" title="Remove" onClick={async () => { await travel.deleteItem(it.id, i.id); load() }}>✕</button></span>
                </div>
              ))}
              <AddItem itin={it} day={d} rates={rates} fx={fx} onAdded={load} />
            </div>
          ))}
          {(it.extras?.length > 0 || it.days.length === 0) && (
            <div className="card card-body" style={{ marginBottom: 12 }}>
              <h3 style={{ margin: 0 }}>Other services <small style={{ color: 'var(--text-3)', fontWeight: 400 }}>(not tied to a day)</small></h3>
              {it.extras?.map(i => (
                <div key={i.id} style={{ display: 'flex', justifyContent: 'space-between', gap: 10, padding: '8px 0', borderTop: '1px solid var(--border)', alignItems: 'center' }}>
                  <span>{TYPE_ICON[i.type] || '•'} {i.title}</span>
                  <span style={{ display: 'flex', gap: 10, alignItems: 'center' }}><b>{inr(i.cost_amount)}</b>
                    <button className="btn btn-sm btn-ghost" title="Remove" onClick={async () => { await travel.deleteItem(it.id, i.id); load() }}>✕</button></span>
                </div>
              ))}
              <AddItem itin={it} day={null} rates={rates} fx={fx} onAdded={load} />
            </div>
          )}
          <button className="btn btn-ghost" onClick={async () => { await travel.addDay(it.id, { title: `Day ${it.days.length + 1}` }); load() }}>+ Add day</button>
          <div className="card card-body" style={{ marginTop: 12 }}>
            <label className="form-label">Inclusions</label>
            <textarea className="form-input" rows={4} defaultValue={it.inclusions || ''} onBlur={e => e.target.value !== (it.inclusions || '') && patch({ inclusions: e.target.value })} />
            <label className="form-label" style={{ marginTop: 10 }}>Exclusions</label>
            <textarea className="form-input" rows={4} defaultValue={it.exclusions || ''} onBlur={e => e.target.value !== (it.exclusions || '') && patch({ exclusions: e.target.value })} />
          </div>
        </div>

        <div className="card card-body" style={{ alignSelf: 'start', position: 'sticky', top: 12 }}>
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
          <div style={{ display: 'flex', justifyContent: 'space-between', borderTop: '2px solid var(--border-strong)', paddingTop: 8, fontSize: 18, fontWeight: 800 }}><span>Total</span><span>{inr(it.grand_total)}</span></div>
          <div style={{ marginTop: 8, color: 'var(--success)', fontWeight: 600 }}>Your margin: {inr(it.margin_amount)}</div>
          <p style={{ fontSize: 12, color: 'var(--text-3)' }}>Customers never see cost or margin. Taxes follow current rules; have your CA confirm the TCS base.</p>
        </div>
      </div>
      <style>{`@media (max-width: 900px){ .itin-grid{ grid-template-columns: 1fr !important; } }`}</style>
    </div>
  )
}
