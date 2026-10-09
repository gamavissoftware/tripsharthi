import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { travel, inr, TRIP_STATUS } from '../api/travel'
import { toast } from '../components/Toast'
import Pill from '../components/Pill'
import ReserveSeatsModal from '../components/travel/ReserveSeatsModal'

const fmt = (d) => d ? new Date(d + 'T00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—'
const IT_STATUS = { draft: { label: 'Draft', bg: '#f1f5f9', color: '#475569' }, sent: { label: 'Sent', bg: '#dbeafe', color: '#1d4ed8' }, viewed: { label: 'Viewed', bg: '#fef9c3', color: '#a16207' }, accepted: { label: 'Accepted', bg: '#dcfce7', color: '#15803d' }, rejected: { label: 'Rejected', bg: '#fee2e2', color: '#b91c1c' }, expired: { label: 'Expired', bg: '#f1f5f9', color: '#475569' }, superseded: { label: 'Superseded', bg: '#f1f5f9', color: '#94a3b8' } }

export default function TripDetailPage() {
  const { id } = useParams()
  const nav = useNavigate()
  const [trip, setTrip] = useState(null)
  const [busy, setBusy] = useState('')
  const [reserve, setReserve] = useState(false)

  const load = useCallback(() => travel.trip(id).then(setTrip).catch(e => toast.error('Could not load trip', e.message)), [id])
  useEffect(() => { load() }, [load])

  async function run(key, fn, ok) {
    setBusy(key)
    try { const r = await fn(); if (ok) toast.success(ok); return r } catch (e) { toast.error('Failed', e.message) } finally { setBusy('') }
  }

  if (!trip) return <div className="page">Loading…</div>
  const row = (k, v) => v ? <div style={{ display: 'flex', justifyContent: 'space-between', padding: '6px 0', borderBottom: '1px solid var(--border)' }}><span style={{ color: 'var(--text-3)' }}>{k}</span><b>{v}</b></div> : null

  return (
    <div className="page">
      {reserve && <ReserveSeatsModal trip={trip} onClose={() => setReserve(false)} onDone={(r) => { setReserve(false); nav(`/itineraries/${r.itinerary_id}`) }} />}
      <div className="page-header">
        <div>
          <Link to="/trips" style={{ fontSize: 13 }}>← Trips</Link>
          <h1 className="page-title" style={{ margin: '4px 0' }}>{trip.title} <Pill map={TRIP_STATUS} value={trip.status} /></h1>
          {trip.contact && <div style={{ color: 'var(--text-2)' }}>{trip.contact.name} · {trip.contact.wa_number}</div>}
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button className="btn btn-ghost" disabled={busy === 'ai'} onClick={async () => {
            const it = await run('ai', () => travel.aiItinerary(trip.id), 'AI itinerary drafted')
            if (it) nav(`/itineraries/${it.id}`)
          }}>{busy === 'ai' ? 'Drafting…' : '✨ Draft with AI'}</button>
          <button className="btn btn-ghost" onClick={() => setReserve(true)} title="Hold seats on a fixed group departure">👥 Group departure</button>
          <button className="btn btn-primary" disabled={busy === 'new'} onClick={async () => {
            const it = await run('new', () => travel.createItinerary({ trip_id: trip.id }))
            if (it) nav(`/itineraries/${it.id}`)
          }}>+ Build itinerary</button>
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(300px,1fr))', gap: 16 }}>
        <div className="card card-body">
          <h3 style={{ marginTop: 0 }}>Requirements</h3>
          {row('Type', trip.trip_type?.replace(/_/g, ' '))}
          {row('Destination', trip.destination_text)}
          {row('From', trip.origin_city)}
          {row('Dates', trip.start_date ? `${fmt(trip.start_date)} → ${fmt(trip.end_date)}` : trip.travel_month)}
          {row('Nights', trip.nights)}
          {row('Travellers', `${trip.adults} adults${Number(trip.children) ? `, ${trip.children} children` : ''}`)}
          {row('Budget', trip.budget_max ? inr(trip.budget_max) + (trip.budget_basis === 'per_person' ? ' / person' : ' total') : '')}
          {row('Hotel', trip.hotel_category !== 'any' ? trip.hotel_category.replace('_', ' ') : '')}
          {row('Passport / Visa', `${trip.passport_status} / ${trip.visa_status}`)}
          {trip.requirements && <p style={{ whiteSpace: 'pre-wrap', marginBottom: 0 }}>{trip.requirements}</p>}
        </div>

        <div className="card card-body">
          <h3 style={{ marginTop: 0 }}>Itineraries &amp; quotes</h3>
          {trip.itineraries.length === 0 && <p style={{ color: 'var(--text-3)' }}>No itinerary yet.</p>}
          {trip.itineraries.map(it => (
            <Link key={it.id} to={`/itineraries/${it.id}`} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 0', borderBottom: '1px solid var(--border)', color: 'inherit', textDecoration: 'none' }}>
              <span><b>v{it.version}</b> · {it.title}<br /><small style={{ color: 'var(--text-3)' }}>{Number(it.view_count) ? `Viewed ${it.view_count}×` : 'Not viewed'}</small></span>
              <span style={{ textAlign: 'right' }}><b>{inr(it.grand_total)}</b><br /><Pill map={IT_STATUS} value={it.status} /></span>
            </Link>
          ))}
        </div>

        <div className="card card-body">
          <h3 style={{ marginTop: 0 }}>Pipeline</h3>
          {trip.booking && <p>Booking <Link to={`/bookings/${trip.booking.id}`}><b>{trip.booking.booking_ref}</b></Link></p>}
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
            {['enquiry', 'quoted', 'negotiating', 'travelling', 'completed'].map(s => (
              <button key={s} className={'btn btn-sm ' + (trip.status === s ? 'btn-primary' : 'btn-ghost')} disabled={trip.status === s || !!busy}
                onClick={() => run('st', () => travel.setTripStatus(trip.id, s).then(load), `Moved to ${TRIP_STATUS[s].label}`)}>{TRIP_STATUS[s].label}</button>
            ))}
            <button className="btn btn-sm btn-danger" disabled={trip.status === 'lost'} onClick={() => {
              const r = window.prompt('Reason for losing this trip?')
              if (r !== null) run('st', () => travel.setTripStatus(trip.id, 'lost', r).then(load), 'Marked lost')
            }}>Lost</button>
          </div>
          <p style={{ fontSize: 12.5, color: 'var(--text-3)' }}>Status changes move the linked deal and report the outcome to your ad platforms (Meta / Google) when this lead came from an ad.</p>
        </div>
      </div>
    </div>
  )
}
