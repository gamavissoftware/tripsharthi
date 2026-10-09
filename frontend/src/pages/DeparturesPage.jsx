import { useState, useEffect, useCallback } from 'react'
import { travel, inr } from '../api/travel'
import { toast } from '../components/Toast'

const STATE = { open: ['#dcfce7', '#15803d', 'Open'], full: ['#fef3c7', '#b45309', 'Full'], closed: ['#e0f2fe', '#0369a1', 'Sales closed'], completed: ['#f1f5f9', '#475569', 'Completed'], cancelled: ['#fee2e2', '#b91c1c', 'Cancelled'] }
const rs = (p) => (p ?? 0) / 100
const blank = { code: '', title: '', start_date: '', end_date: '', total_seats: 20, min_pax: 10, price_pax_rs: '', cost_pax_rs: '', child_price_pct: 100, single_supplement_rs: 0, single_supplement_cost_rs: 0, is_international: false, gst_rate: 5, sell_cutoff: '', inclusions: '', exclusions: '', notes: '' }
const lbl = { fontSize: 12.5, fontWeight: 700, display: 'block' }

function Modal({ onClose, children, wide }) {
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', justifyContent: 'center', padding: 16, overflowY: 'auto' }}>
      <div className="card card-body" onClick={e => e.stopPropagation()} style={{ width: '100%', maxWidth: wide ? 760 : 560, margin: 'auto' }}>{children}</div>
    </div>)
}

function EditModal({ dep, onClose, onDone }) {
  const [f, setF] = useState(dep ? { ...blank, code: dep.code, title: dep.title, start_date: dep.start_date, end_date: dep.end_date, total_seats: dep.total_seats, min_pax: dep.min_pax, price_pax_rs: rs(dep.price_pax), cost_pax_rs: dep.cost_pax != null ? rs(dep.cost_pax) : '',
    child_price_pct: dep.child_price_pct, single_supplement_rs: rs(dep.single_supplement), single_supplement_cost_rs: dep.single_supplement_cost != null ? rs(dep.single_supplement_cost) : 0, is_international: dep.is_international, gst_rate: dep.gst_rate, sell_cutoff: dep.sell_cutoff || '',
    inclusions: dep.inclusions || '', exclusions: dep.exclusions || '', notes: dep.notes || '' } : blank)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const set = (k) => (e) => setF(x => ({ ...x, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }))
  async function submit(e) {
    e.preventDefault(); setBusy(true); setErr('')
    try { dep ? await travel.updateDeparture(dep.id, f) : await travel.createDeparture(f); toast.success('Saved'); onDone() } catch (er) { setErr(er.message) } finally { setBusy(false) }
  }
  const num = (k, label, extra = {}) => <label style={lbl}>{label}<input className="form-input" type="number" step="any" min="0" value={f[k]} onChange={set(k)} {...extra} /></label>
  return (
    <Modal onClose={onClose} wide>
      <h3 style={{ marginTop: 0 }}>{dep ? `Edit ${dep.code}` : 'New group departure'}</h3>
      <form onSubmit={submit} style={{ display: 'grid', gap: 10 }}>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 2fr', gap: 8 }}>
          <label style={lbl}>Code *<input className="form-input" required disabled={!!dep} value={f.code} onChange={set('code')} placeholder="BALI-JAN27" /></label>
          <label style={lbl}>Title *<input className="form-input" required value={f.title} onChange={set('title')} placeholder="Bali Group Tour — 6N/7D" /></label>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 8 }}>
          <label style={lbl}>Starts *<input className="form-input" type="date" required value={f.start_date} onChange={set('start_date')} /></label>
          <label style={lbl}>Ends *<input className="form-input" type="date" required value={f.end_date} onChange={set('end_date')} /></label>
          {num('total_seats', 'Total seats *', { min: 1, required: true })}{num('min_pax', 'Min. to operate', { min: 0 })}
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 8 }}>
          {num('price_pax_rs', 'Price / person ₹ (before GST) *', { required: true })}{num('cost_pax_rs', 'Your cost / person ₹')}
          {num('single_supplement_rs', 'Single supplement ₹')}{num('single_supplement_cost_rs', 'Single suppl. cost ₹')}
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 8 }}>
          {num('child_price_pct', 'Child price % of adult', { max: 100 })}
          <label style={lbl}>GST<select className="form-select" value={f.gst_rate} onChange={set('gst_rate')}>{[0, 5, 12, 18].map(r => <option key={r} value={r}>{r}%</option>)}</select></label>
          <label style={lbl}>Stop selling on<input className="form-input" type="date" value={f.sell_cutoff} onChange={set('sell_cutoff')} /></label>
          <label style={{ ...lbl, alignSelf: 'end' }}><input type="checkbox" checked={f.is_international} onChange={set('is_international')} /> International (TCS applies)</label>
        </div>
        <label style={lbl}>Inclusions<textarea className="form-input" rows={2} value={f.inclusions} onChange={set('inclusions')} /></label>
        <label style={lbl}>Exclusions<textarea className="form-input" rows={2} value={f.exclusions} onChange={set('exclusions')} /></label>
        {err && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8 }}>{err}</div>}
        <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button></div>
      </form>
    </Modal>)
}

function ManifestModal({ id, onClose, onChanged }) {
  const [m, setM] = useState(null)
  const load = useCallback(() => travel.manifest(id).then(setM).catch(e => toast.error('Could not load', e.message)), [id])
  useEffect(() => { load() }, [load])
  if (!m) return <Modal onClose={onClose}>Loading…</Modal>
  const d = m.departure
  const release = async (g) => { if (!window.confirm('Release these held seats back to sale?')) return; try { await travel.releaseSeats(g.hold_id); load(); onChanged() } catch (e) { toast.error('Not released', e.message) } }
  return (
    <Modal onClose={onClose} wide>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap' }}>
        <h3 style={{ margin: 0 }}>{d.title} <small style={{ color: 'var(--text-3)' }}>{d.code} · {d.start_date} → {d.end_date}</small></h3>
        <span><button className="btn btn-sm btn-ghost" onClick={() => travel.manifestCsv(id).catch(e => toast.error('Could not download', e.message))}>⬇ Rooming list (CSV)</button> <button className="btn btn-sm btn-ghost" onClick={onClose}>✕</button></span>
      </div>
      <p style={{ color: 'var(--text-2)' }}>{d.confirmed} confirmed · {d.held} held · {d.available} available of {d.total_seats}{d.min_pax ? ` · minimum ${d.min_pax} to operate` : ''}</p>
      {m.groups.length === 0 ? <p style={{ color: 'var(--text-3)' }}>No bookings or holds yet.</p> : m.groups.map(g => (
        <div key={g.hold_id} style={{ borderTop: '1px solid var(--border)', padding: '8px 0' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap' }}>
            <span><b>{g.customer || 'Customer'}</b> {g.booking_ref ? <small>· {g.booking_ref}</small> : null} <small style={{ color: 'var(--text-3)' }}>{g.adults} adult{g.adults > 1 ? 's' : ''}{g.children ? `, ${g.children} child` : ''}{g.single_rooms ? `, ${g.single_rooms} single` : ''}</small></span>
            <span>{g.status === 'held' ? <><span style={{ background: '#fef3c7', color: '#b45309', padding: '1px 8px', borderRadius: 999, fontSize: 11.5, fontWeight: 700 }}>Held until {g.expires_at}</span> <button className="btn btn-sm btn-ghost" onClick={() => release(g)}>Release</button></> :
              <><span style={{ background: '#dcfce7', color: '#15803d', padding: '1px 8px', borderRadius: 999, fontSize: 11.5, fontWeight: 700 }}>Confirmed</span>{g.due > 0 && <small style={{ color: 'var(--danger)' }}> · {inr(g.due)} due</small>}</>}</span>
          </div>
          {g.travellers.length > 0 ? <div style={{ fontSize: 13, color: 'var(--text-2)', marginLeft: 12 }}>{g.travellers.map((t, i) => <div key={i}>{t.name} <small>({t.type}{t.passport_expiry ? ` · passport ${t.passport_expiry}` : ''})</small></div>)}</div>
            : g.status === 'confirmed' && <div style={{ fontSize: 12.5, color: 'var(--warning)', marginLeft: 12 }}>Traveller names not entered yet ({g.seats} seat{g.seats > 1 ? 's' : ''}).</div>}
        </div>))}
    </Modal>)
}

export default function DeparturesPage() {
  const [scope, setScope] = useState('upcoming')
  const [list, setList] = useState(null)
  const [err, setErr] = useState('')
  const [edit, setEdit] = useState(null)      // null | {} (new) | dep
  const [manifest, setManifest] = useState(0)
  const load = useCallback(() => travel.departures(scope).then(r => { setList(r); setErr('') }).catch(e => { setList(null); setErr(e.message) }), [scope])
  useEffect(() => { load() }, [load])
  const setStatus = async (d, st) => { if (st === 'cancelled' && !window.confirm(`Cancel ${d.code}? Held seats are released.`)) return; try { await travel.departureStatus(d.id, st); load() } catch (e) { toast.error('Not changed', e.message) } }

  return (
    <div className="page" style={{ maxWidth: 1040 }}>
      <div className="page-header"><h1 className="page-title">Group departures</h1><button className="btn btn-primary" onClick={() => setEdit({})}>+ New departure</button></div>
      <p style={{ color: 'var(--text-2)' }}>Fixed-date group tours with limited seats. Agents hold seats from an enquiry (Trip → Group departure); the quote, booking, payments and GST invoice then work as usual, and a seat can never be sold twice.</p>
      <div style={{ display: 'flex', gap: 6, marginBottom: 12 }}>{[['upcoming', 'Upcoming'], ['past', 'Past'], ['all', 'All']].map(([k, l]) => <button key={k} className={'btn btn-sm ' + (scope === k ? 'btn-primary' : 'btn-ghost')} onClick={() => setScope(k)}>{l}</button>)}</div>
      {err && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8 }}>{err}</div>}
      {list && list.length === 0 && <div className="card card-body" style={{ color: 'var(--text-3)' }}>No departures here yet.</div>}
      {list && list.map(d => { const [bg, fg, label] = STATE[d.state] || STATE.open; const pct = d.total_seats ? Math.round(((d.confirmed + d.held) / d.total_seats) * 100) : 0; return (
        <div key={d.id} className="card card-body" style={{ marginBottom: 12 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
            <div><b>{d.title}</b> <small style={{ color: 'var(--text-3)' }}>{d.code} · {d.start_date} → {d.end_date}</small>{' '}
              <span style={{ background: bg, color: fg, padding: '1px 8px', borderRadius: 999, fontSize: 11.5, fontWeight: 700 }}>{label}</span>
              {d.at_risk && <span style={{ background: '#fee2e2', color: '#b91c1c', padding: '1px 8px', borderRadius: 999, fontSize: 11.5, fontWeight: 700, marginLeft: 6 }}>⚠ below minimum ({d.confirmed}/{d.min_pax})</span>}</div>
            <div style={{ fontWeight: 700 }}>{inr(d.price_pax)} <small style={{ fontWeight: 400, color: 'var(--text-3)' }}>/ person + GST</small></div>
          </div>
          <div style={{ height: 8, background: 'var(--border)', borderRadius: 999, margin: '10px 0 6px', overflow: 'hidden', display: 'flex' }}>
            <div style={{ width: `${(d.confirmed / d.total_seats) * 100}%`, background: 'var(--success, #16a34a)' }} /><div style={{ width: `${(d.held / d.total_seats) * 100}%`, background: '#f59e0b' }} /></div>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap', fontSize: 13 }}>
            <span>{d.confirmed} confirmed · {d.held} held · <b>{d.available} available</b> of {d.total_seats} ({pct}% sold/held)</span>
            <span style={{ display: 'flex', gap: 6 }}>
              <button className="btn btn-sm btn-ghost" onClick={() => setManifest(d.id)}>Manifest</button>
              <button className="btn btn-sm btn-ghost" onClick={() => setEdit(d)}>Edit</button>
              {d.status === 'open' && <button className="btn btn-sm btn-ghost" onClick={() => setStatus(d, 'closed')}>Close sales</button>}
              {d.status === 'closed' && <button className="btn btn-sm btn-ghost" onClick={() => setStatus(d, 'open')}>Reopen</button>}
              {!['cancelled', 'completed'].includes(d.status) && <button className="btn btn-sm btn-ghost" onClick={() => setStatus(d, 'cancelled')}>Cancel</button>}
            </span>
          </div>
        </div>) })}
      {edit && <EditModal dep={edit.id ? edit : null} onClose={() => setEdit(null)} onDone={() => { setEdit(null); load() }} />}
      {manifest > 0 && <ManifestModal id={manifest} onClose={() => setManifest(0)} onChanged={load} />}
    </div>
  )
}
