import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { inr, TRIP_STATUS } from '../../api/travel'
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

export default function TripBoard({ rows, onMove }) {
  const nav = useNavigate()
  const [dragId, setDragId] = useState(null)
  const [over, setOver] = useState(null)
  const [lostFor, setLostFor] = useState(null)

  const dragTrip = dragId ? rows.find(t => t.id === dragId) : null

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
                          onChange={e => { const c = COLUMNS.find(x => x.key === e.target.value); requestMove(t, c) }}>
                          <option value="">Move to…</option>
                          {COLUMNS.filter(c => c.drop && !c.statuses.includes(t.status)).map(c => <option key={c.key} value={c.key}>{TRIP_STATUS[c.key].label}</option>)}
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
      {lostFor && <LostModal trip={lostFor} onCancel={() => setLostFor(null)} onConfirm={reason => { const t = lostFor; setLostFor(null); onMove(t, 'lost', reason) }} />}
    </>
  )
}
