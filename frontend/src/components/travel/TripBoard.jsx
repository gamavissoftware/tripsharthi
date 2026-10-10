import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { travel, inr, TRIP_STATUS } from '../../api/travel'
import { toast } from '../Toast'
import './TripBoard.css'

// Board columns, left to right. `drop` = a card may be dropped here by hand.
// Booked / Travelling / Completed are reached through a booking (or the trip page), never by dragging:
// a trip must not read "booked" without a booking behind it.
const COLUMNS = [
  { key: 'enquiry',     statuses: ['enquiry'],              drop: true },
  { key: 'quoted',      statuses: ['quoted'],               drop: true },
  { key: 'negotiating', statuses: ['negotiating'],          drop: true },
  { key: 'booked',      statuses: ['booked'],               drop: false },
  { key: 'travelling',  statuses: ['travelling'],           drop: false },
  { key: 'completed',   statuses: ['completed'],            drop: false },
  { key: 'lost',        statuses: ['lost', 'cancelled'],    drop: true },
]
const MOVABLE = new Set(['enquiry', 'quoted', 'negotiating', 'lost', 'cancelled'])
const fmtDate = (d) => d ? new Date(d + 'T00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) : null
const OPEN = ['enquiry', 'quoted', 'negotiating']
// Colour by how long a trip has sat untouched (days since its last update): fresh -> amber -> orange -> red.
const ageTone = (d) => d >= 14 ? 'hot' : d >= 7 ? 'warm' : d >= 3 ? 'mild' : 'fresh'
const initialsOf = (n) => String(n || '').trim().split(/\s+/).map(w => w[0]).join('').substring(0, 2).toUpperCase()
const ageDays = (c) => { if (!c) return null; const d = Math.floor((Date.now() - new Date(String(c).replace(' ', 'T') + 'Z').getTime()) / 86400000); return d < 0 ? 0 : d }

function LostModal({ trip, onCancel, onConfirm }) {
  const [reason, setReason] = useState('')
  return (
    <div className="modal-backdrop" onClick={onCancel}>
      <form className="modal" onClick={e => e.stopPropagation()} onSubmit={e => { e.preventDefault(); onConfirm(reason.trim()) }}>
        <div className="modal-header">Mark as lost</div>
        <div className="modal-body">
          <p style={{ marginBottom: 10 }}>{trip.title}</p>
          <div className="form-group">
            <label className="form-label">Why was this trip lost?</label>
            <input className="form-input" autoFocus value={reason} onChange={e => setReason(e.target.value)} placeholder="e.g. booked with another agent, budget too low" />
          </div>
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-ghost" onClick={onCancel}>Cancel</button>
          <button className="btn btn-danger">Mark lost</button>
        </div>
      </form>
    </div>
  )
}


// "Mark as won": a trip becomes Booked only by creating a booking from one of its quotes (itineraries).
function WonModal({ trip, onCancel, onBooked }) {
  const nav = useNavigate()
  const [its, setIts] = useState(null)
  const [pick, setPick] = useState(null)
  const [deposit, setDeposit] = useState(30)
  const [inst, setInst] = useState(2)
  const [ack, setAck] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => {
    let alive = true
    travel.trip(trip.id).then(t => {
      if (!alive) return
      const list = (t.itineraries || []).slice().sort((a, b) => Number(b.version) - Number(a.version))
      setIts(list)
      const best = list.find(i => i.status === 'accepted') || list.find(i => ['sent', 'viewed'].includes(i.status)) || list[0]
      setPick(best ? Number(best.id) : null)
    }).catch(e => { if (alive) { setIts([]); setErr(e.message) } })
    return () => { alive = false }
  }, [trip.id])

  const it = its?.find(i => Number(i.id) === pick)
  const accepted = it?.status === 'accepted'
  const total = Number(it?.grand_total) || 0
  const dep = Math.round(total * Math.min(100, Math.max(0, Number(deposit) || 0)) / 100)
  const rest = total - dep
  const n = Math.max(1, Number(inst) || 1)

  async function create() {
    setBusy(true); setErr('')
    try {
      const b = await travel.createBooking({ itinerary_id: pick, deposit_pct: Number(deposit) || 0, instalments: n })
      toast.success('Booking created', b.booking_ref)
      onBooked(trip, b)
    } catch (e) { setErr(e.message) } finally { setBusy(false) }
  }

  return (
    <div className="modal-backdrop" onClick={onCancel}>
      <div className="modal" style={{ width: 520 }} onClick={e => e.stopPropagation()}>
        <div className="modal-header">Mark as won — create booking</div>
        <div className="modal-body">
          <p style={{ marginBottom: 10, fontWeight: 500 }}>{trip.title}</p>
          {its === null && <p>Loading quotes…</p>}
          {its?.length === 0 && (
            <p style={{ color: 'var(--text-2)' }}>This trip has no quote yet. A booking is created from a quote, so build one first — then come back and mark it won.</p>
          )}
          {its?.length > 0 && (<>
            <div className="form-group">
              <label className="form-label">Quote to book</label>
              <select className="form-select" value={pick ?? ''} onChange={e => { setPick(Number(e.target.value)); setAck(false) }}>
                {its.map(i => <option key={i.id} value={i.id}>v{i.version} · {i.status} · {inr(Number(i.grand_total) || 0)}</option>)}
              </select>
            </div>
            {!accepted && (
              <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start', margin: '10px 0', fontSize: '.85rem', background: '#fff8e6', border: '1px solid #f1d98a', borderRadius: 4, padding: '.5rem .6rem' }}>
                <input type="checkbox" checked={ack} onChange={e => setAck(e.target.checked)} style={{ marginTop: 3 }} />
                <span>The customer has not accepted this quote in the system. I confirm they agreed to book it (for example on a call or WhatsApp).</span>
              </label>
            )}
            <div className="form-grid-2" style={{ marginTop: 10 }}>
              <div className="form-group"><label className="form-label">Booking amount (%)</label><input className="form-input" type="number" min="0" max="100" value={deposit} onChange={e => setDeposit(e.target.value)} /></div>
              <div className="form-group"><label className="form-label">Balance in … instalments</label><input className="form-input" type="number" min="1" max="12" value={inst} onChange={e => setInst(e.target.value)} /></div>
            </div>
            <p className="text-muted" style={{ marginTop: 8 }}>
              {total ? <>Total {inr(total)}: {inr(dep)} today{rest > 0 ? <>, then {inr(Math.floor(rest / n))} × {n} (last one takes the rounding), the balance due 15 days before travel</> : null}.</> : 'This quote has no price yet.'}
            </p>
          </>)}
          {err && <div className="form-error" style={{ marginTop: 10 }}>{err}</div>}
        </div>
        <div className="modal-footer">
          <button type="button" className="btn btn-ghost" onClick={onCancel}>Cancel</button>
          {its?.length === 0 && <button type="button" className="btn btn-primary" onClick={() => nav(`/trips/${trip.id}`)}>Open trip</button>}
          {its?.length > 0 && <button type="button" className="btn btn-success" disabled={busy || !pick || (!accepted && !ack)} onClick={create}>{busy ? 'Creating…' : 'Create booking'}</button>}
        </div>
      </div>
    </div>
  )
}

export default function TripBoard({ rows, onMove, onBooked }) {
  const nav = useNavigate()
  const [dragId, setDragId] = useState(null)
  const [over, setOver] = useState(null)
  const [lostFor, setLostFor] = useState(null)
  const [wonFor, setWonFor] = useState(null)
  const [overWon, setOverWon] = useState(false)

  const dragTrip = dragId ? rows.find(t => t.id === dragId) : null
  const canWin = (t) => !!t && OPEN.includes(t.status)

  function requestMove(trip, col) {
    if (!trip || !col.drop || col.statuses.includes(trip.status)) return
    const target = col.statuses[0]
    if (target === 'lost') setLostFor(trip)
    else onMove(trip, target)
  }

  return (
    <>
      <div className="tboard-legend" aria-label="Card colour legend">
        <span>Days since last update:</span>
        <span><i style={{ background: '#2e9d62' }} />0–2</span>
        <span><i style={{ background: '#e0a526' }} />3–6</span>
        <span><i style={{ background: '#ee7d1a' }} />7–13</span>
        <span><i style={{ background: '#d6362d' }} />14+</span>
      </div>
      <div className="tboard">
        {COLUMNS.map(col => {
          const items = rows.filter(t => col.statuses.includes(t.status))
          const total = items.reduce((s, t) => s + (Number(t.budget_max) || 0), 0)
          const st = TRIP_STATUS[col.key]
          const canDrop = !!dragTrip && col.drop && !col.statuses.includes(dragTrip.status)
          return (
            <section key={col.key}
              className={'tboard-col' + (over === col.key && canDrop ? ' is-over' : '') + (dragTrip && !canDrop && !col.statuses.includes(dragTrip.status) ? ' is-blocked' : '')}
              onDragOver={e => { if (canDrop) { e.preventDefault(); setOver(col.key) } }}
              onDragLeave={() => setOver(o => (o === col.key ? null : o))}
              onDrop={e => { e.preventDefault(); setOver(null); requestMove(dragTrip, col); setDragId(null) }}>
              <header className="tboard-head">
                <span className="tboard-dot" style={{ background: st.color }} />
                <span className="tboard-title">{st.label}</span>
                <span className="tboard-count">{items.length}</span>
                <span className="tboard-sum">{total ? inr(total, true) : ''}</span>
              </header>
              {!col.drop && <div className="tboard-note">Created from a booking</div>}
              <div className="tboard-cards">
                {items.length === 0 && <div className="tboard-empty">{col.drop ? 'Drop a trip here' : 'Nothing here yet'}</div>}
                {items.map(t => {
                  const movable = MOVABLE.has(t.status)
                  const age = OPEN.includes(t.status) ? ageDays(t.updated_at || t.created_at) : null
                  return (
                    <article key={t.id}
                      className={'tcard' + (age != null ? ' tone-' + ageTone(age) : '') + (dragId === t.id ? ' is-dragging' : '')}
                      draggable={movable}
                      onDragStart={e => { setDragId(t.id); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(t.id)) }}
                      onDragEnd={() => { setDragId(null); setOver(null) }}
                      onClick={() => nav(`/trips/${t.id}`)}>
                      <div className="tcard-title">{t.title}</div>
                      <div className="tcard-line">{t.destination_text || '—'} {Number(t.is_international) ? '🌍' : ''}</div>
                      <div className="tcard-meta">
                        {fmtDate(t.start_date) && <span>{fmtDate(t.start_date)}{t.nights ? ` · ${t.nights}N` : ''}</span>}
                        <span>{t.adults}A{Number(t.children) ? ` ${t.children}C` : ''}</span>
                        {t.budget_max ? <span className="tcard-budget">{inr(t.budget_max, true)}</span> : null}
                        {age != null && <span className="tcard-age" title={age === 0 ? 'Updated today' : `No update for ${age} day${age === 1 ? '' : 's'}`}>{age}d</span>}
                      </div>
                      <span className={'tcard-owner' + (t.owner_name ? '' : ' is-none')} title={t.owner_name ? `Owner: ${t.owner_name}` : 'No owner assigned'}>{t.owner_name ? initialsOf(t.owner_name) : '?'}</span>
                      {movable && (
                        <select className="tcard-move form-select" value="" aria-label={`Move ${t.title}`}
                          onClick={e => e.stopPropagation()}
                          onChange={e => { if (e.target.value === '__won') { setWonFor(t); return } const c = COLUMNS.find(x => x.key === e.target.value); requestMove(t, c) }}>
                          <option value="">Move to…</option>
                          {COLUMNS.filter(c => c.drop && !c.statuses.includes(t.status)).map(c => <option key={c.key} value={c.key}>{TRIP_STATUS[c.key].label}</option>)}
                          {canWin(t) && <option value="__won">Won — create booking</option>}
                        </select>
                      )}
                    </article>
                  )
                })}
              </div>
            </section>
          )
        })}
      </div>
      {canWin(dragTrip) && (
        <div className={'tboard-won' + (overWon ? ' is-over' : '')}
          onDragOver={e => { e.preventDefault(); setOverWon(true) }}
          onDragLeave={() => setOverWon(false)}
          onDrop={e => { e.preventDefault(); setOverWon(false); setWonFor(dragTrip); setDragId(null) }}>
          Drop here to mark as won — create booking
        </div>
      )}
      {wonFor && <WonModal trip={wonFor} onCancel={() => setWonFor(null)} onBooked={(t, b) => { setWonFor(null); onBooked(t, b) }} />}
      {lostFor && <LostModal trip={lostFor} onCancel={() => setLostFor(null)} onConfirm={reason => { const t = lostFor; setLostFor(null); onMove(t, 'lost', reason) }} />}
    </>
  )
}
