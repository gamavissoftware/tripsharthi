import { useState, useEffect } from 'react'
import { travel, inr } from '../../api/travel'
import { toast } from '../Toast'

/** Hold seats on a group departure for an enquiry; the draft itinerary it creates flows through the normal quote → booking path. */
export default function ReserveSeatsModal({ trip, onClose, onDone }) {
  const [deps, setDeps] = useState(null)
  const [f, setF] = useState({ departure_id: '', adults: trip.adults || 2, children: trip.children || 0, single_rooms: 0, hold_hours: 24 })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  useEffect(() => { travel.departures('upcoming').then(r => setDeps(r.filter(d => d.state === 'open'))).catch(e => setErr(e.message)) }, [])
  const set = (k) => (e) => setF(x => ({ ...x, [k]: e.target.value }))
  const dep = deps?.find(d => String(d.id) === String(f.departure_id))
  const seats = Number(f.adults) + Number(f.children)
  const est = dep ? Number(f.adults) * dep.price_pax + Number(f.children) * Math.round(dep.price_pax * dep.child_price_pct / 100) + Number(f.single_rooms) * dep.single_supplement : 0

  async function submit(e) {
    e.preventDefault(); setBusy(true); setErr('')
    try {
      const r = await travel.reserveSeats(f.departure_id, { trip_id: trip.id, adults: Number(f.adults), children: Number(f.children), single_rooms: Number(f.single_rooms), hold_hours: Number(f.hold_hours) })
      toast.success(`${r.seats} seat(s) held`, `Hold expires ${r.expires_at}`); onDone(r)
    } catch (er) { setErr(er.message) } finally { setBusy(false) }
  }
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', justifyContent: 'center', padding: 16, overflowY: 'auto' }}>
      <div className="card card-body" onClick={e => e.stopPropagation()} style={{ width: '100%', maxWidth: 520, margin: 'auto' }}>
        <h3 style={{ marginTop: 0 }}>Reserve seats on a group departure</h3>
        {deps === null && !err && <p>Loading departures…</p>}
        {deps && deps.length === 0 && <p style={{ color: 'var(--text-3)' }}>No open departures. Create one under Group Departures first.</p>}
        {deps && deps.length > 0 && (
          <form onSubmit={submit} style={{ display: 'grid', gap: 10 }}>
            <label style={{ fontSize: 12.5, fontWeight: 700 }}>Departure
              <select className="form-select" required value={f.departure_id} onChange={set('departure_id')}>
                <option value="">Choose…</option>
                {deps.map(d => <option key={d.id} value={d.id} disabled={d.available === 0}>{d.start_date} · {d.title} ({d.code}) — {d.available} left</option>)}
              </select></label>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 8 }}>
              {[['adults', 'Adults', 1], ['children', 'Children', 0], ['single_rooms', 'Single rooms', 0], ['hold_hours', 'Hold (hours)', 1]].map(([k, l, min]) => (
                <label key={k} style={{ fontSize: 12.5, fontWeight: 700 }}>{l}<input className="form-input" type="number" min={min} max={k === 'hold_hours' ? 168 : 50} value={f[k]} onChange={set(k)} /></label>))}
            </div>
            {dep && <div style={{ background: 'var(--bg-2, #f8fafc)', padding: 10, borderRadius: 8, fontSize: 13.5 }}>
              {seats} seat(s) · {dep.available} available · price before GST <b>{inr(est)}</b>{dep.is_international ? ' (+ GST and TCS on the quote)' : ' (+ GST on the quote)'}
              {seats > dep.available && <div style={{ color: 'var(--danger)' }}>Only {dep.available} seat(s) left.</div>}</div>}
            <small style={{ color: 'var(--text-3)' }}>Seats are held for the chosen time while you send the quote. They go back on sale automatically if no booking is made.</small>
            {err && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8 }}>{err}</div>}
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button className="btn btn-primary" disabled={busy || !f.departure_id}>{busy ? 'Holding…' : 'Hold seats & draft quote'}</button></div>
          </form>)}
        {err && !deps && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8 }}>{err}</div>}
      </div>
    </div>)
}
