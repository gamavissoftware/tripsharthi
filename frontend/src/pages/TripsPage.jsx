import { useState, useEffect, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { travel, inr, toPaise, TRIP_STATUS } from '../api/travel'
import { contacts as contactsApi } from '../api/contacts'
import { toast } from '../components/Toast'
import Pill from '../components/Pill'
import TripBoard from '../components/travel/TripBoard'
import { activityState } from '../components/travel/tripActivity'
import { LayoutGrid, List } from 'lucide-react'

const TYPES = ['leisure', 'honeymoon', 'family', 'friends', 'solo', 'pilgrimage', 'adventure', 'corporate', 'group_departure', 'student', 'umrah_hajj', 'visa_only', 'flight_only', 'hotel_only', 'other']
const FILTERS = ['all', 'enquiry', 'quoted', 'negotiating', 'booked', 'travelling', 'completed', 'lost']
const fmtDate = (d) => d ? new Date(d + 'T00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : '—'

function NewTripModal({ onClose, onCreated }) {
  const [f, setF] = useState({ cust_name: '', cust_mobile: '', trip_type: 'leisure', destination_text: '', is_international: false, origin_city: '', start_date: '', end_date: '', nights: '', adults: 2, children: 0, budget_rs: '', hotel_category: 'any', requirements: '' })
  const [paste, setPaste] = useState('')
  const [parsing, setParsing] = useState(false)
  const [saving, setSaving] = useState(false)
  const set = (k) => (e) => setF(s => ({ ...s, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }))

  async function aiFill() {
    if (!paste.trim()) return
    setParsing(true)
    try {
      const p = await travel.aiParse(paste)
      setF(s => ({
        ...s,
        destination_text: p.destination_text ?? s.destination_text,
        is_international: p.is_international != null ? !!Number(p.is_international) : s.is_international,
        adults: p.adults ?? s.adults, children: p.children ?? s.children, nights: p.nights ?? s.nights,
        trip_type: TYPES.includes(p.trip_type) ? p.trip_type : s.trip_type,
        hotel_category: p.hotel_category ?? s.hotel_category,
        budget_rs: p.budget_max ? Math.round(p.budget_max / 100) : s.budget_rs,
        origin_city: p.origin_city ?? s.origin_city,
        requirements: [s.requirements, p.requirements, p.travel_month ? `Travel month: ${p.travel_month}` : ''].filter(Boolean).join('\n'),
      }))
      toast.success('Filled from enquiry', p._source === 'ai' ? 'Parsed with AI — please review' : 'Parsed offline — please review')
    } catch (e) { toast.error('Could not parse', e.message) } finally { setParsing(false) }
  }

  async function save(e) {
    e.preventDefault()
    if (!f.destination_text.trim()) { toast.error('Destination is required'); return }
    setSaving(true)
    try {
      let contactId
      if (f.cust_mobile.trim() || f.cust_name.trim()) {
        const c = await contactsApi.create({ name: f.cust_name.trim() || null, wa_number: f.cust_mobile.trim() || null, source: 'manual' })
        contactId = c?.data?.id
      }
      const trip = await travel.createTrip({
        title: `${f.destination_text} ${f.trip_type === 'leisure' ? 'trip' : f.trip_type}${f.cust_name ? ' – ' + f.cust_name : ''}`,
        contact_id: contactId, trip_type: f.trip_type, destination_text: f.destination_text, is_international: f.is_international ? 1 : 0,
        origin_city: f.origin_city, start_date: f.start_date, end_date: f.end_date, nights: f.nights ? Number(f.nights) : undefined,
        adults: Number(f.adults), children: Number(f.children), hotel_category: f.hotel_category,
        budget_max: f.budget_rs ? toPaise(f.budget_rs) : undefined, budget_basis: 'total', requirements: f.requirements,
      })
      toast.success('Enquiry created')
      onCreated(trip)
    } catch (e) { toast.error('Could not create enquiry', e.message) } finally { setSaving(false) }
  }

  const two = { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', justifyContent: 'center', padding: 20, overflowY: 'auto' }}>
      <form onClick={e => e.stopPropagation()} onSubmit={save} className="card" style={{ width: '100%', maxWidth: 680, margin: 'auto', padding: '1.5rem' }}>
        <h3 style={{ marginTop: 0 }}>✈️ New travel enquiry</h3>
        <div className="form-group" style={{ background: 'var(--primary-light)', padding: 12, borderRadius: 10 }}>
          <label className="form-label">✨ Paste the customer's message — AI fills the form</label>
          <textarea className="form-input" rows={2} value={paste} onChange={e => setPaste(e.target.value)} placeholder="e.g. 2 adults + 1 kid, Bali honeymoon, 5 nights in December, budget around 3.5 lakh" />
          <button type="button" className="btn btn-sm btn-primary" style={{ marginTop: 8 }} disabled={parsing || !paste.trim()} onClick={aiFill}>{parsing ? 'Reading…' : 'Fill with AI'}</button>
        </div>
        <div style={two}>
          <div className="form-group"><label className="form-label">Customer name</label><input className="form-input" value={f.cust_name} onChange={set('cust_name')} /></div>
          <div className="form-group"><label className="form-label">WhatsApp / mobile</label><input className="form-input" value={f.cust_mobile} onChange={set('cust_mobile')} placeholder="+91…" /></div>
          <div className="form-group"><label className="form-label">Destination *</label><input className="form-input" value={f.destination_text} onChange={set('destination_text')} autoFocus /></div>
          <div className="form-group"><label className="form-label">Trip type</label><select className="form-select" value={f.trip_type} onChange={set('trip_type')}>{TYPES.map(t => <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>)}</select></div>
          <div className="form-group"><label className="form-label">Travelling from</label><input className="form-input" value={f.origin_city} onChange={set('origin_city')} /></div>
          <div className="form-group" style={{ alignSelf: 'end' }}><label style={{ display: 'flex', gap: 8, alignItems: 'center' }}><input type="checkbox" checked={f.is_international} onChange={set('is_international')} /> International (TCS applies)</label></div>
          <div className="form-group"><label className="form-label">Start date</label><input className="form-input" type="date" value={f.start_date} onChange={set('start_date')} /></div>
          <div className="form-group"><label className="form-label">End date</label><input className="form-input" type="date" value={f.end_date} onChange={set('end_date')} /></div>
          <div className="form-group"><label className="form-label">Adults / Children</label>
            <div style={{ display: 'flex', gap: 8 }}><input className="form-input" type="number" min="1" value={f.adults} onChange={set('adults')} /><input className="form-input" type="number" min="0" value={f.children} onChange={set('children')} /></div></div>
          <div className="form-group"><label className="form-label">Nights</label><input className="form-input" type="number" min="1" value={f.nights} onChange={set('nights')} placeholder="auto from dates" /></div>
          <div className="form-group"><label className="form-label">Total budget (₹)</label><input className="form-input" type="number" value={f.budget_rs} onChange={set('budget_rs')} /></div>
          <div className="form-group"><label className="form-label">Hotel category</label><select className="form-select" value={f.hotel_category} onChange={set('hotel_category')}>{['any', 'budget', '3_star', '4_star', '5_star', 'luxury'].map(h => <option key={h} value={h}>{h.replace('_', ' ')}</option>)}</select></div>
        </div>
        <div className="form-group"><label className="form-label">Special requirements</label><textarea className="form-input" rows={2} value={f.requirements} onChange={set('requirements')} /></div>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button>
          <button className="btn btn-primary" disabled={saving}>{saving ? 'Creating…' : 'Create enquiry'}</button>
        </div>
      </form>
    </div>
  )
}

export default function TripsPage() {
  const nav = useNavigate()
  const [rows, setRows] = useState(null)
  const [filter, setFilter] = useState('all')
  const [q, setQ] = useState('')
  const [showNew, setShowNew] = useState(false)
  const [act, setAct] = useState('all')   // board filter: all | overdue | none (nothing scheduled)
  // Board (pipeline columns) is the default; the choice is remembered in this browser.
  const [view, setView] = useState(() => { try { return localStorage.getItem('ts_trips_view') === 'list' ? 'list' : 'board' } catch { return 'board' } })
  function pickView(v) { setView(v); try { localStorage.setItem('ts_trips_view', v) } catch { /* private mode */ } }

  const load = useCallback(() => {
    const qs = new URLSearchParams()
    if (view === 'list' && filter !== 'all') qs.set('status', filter)
    if (q.trim()) qs.set('q', q.trim())
    travel.trips(qs.toString()).then(setRows).catch(e => toast.error('Could not load trips', e.message))
  }, [filter, q, view])
  useEffect(() => { const t = setTimeout(load, 200); return () => clearTimeout(t) }, [load])

  // Counts for the filter chips, and the rows the board shows. Booked-and-later trips carry no activity chip, so they only appear under "All".
  const now = new Date()
  const states = (rows || []).map(r => activityState(r, now))
  const nOverdue = states.filter(x => x === 'overdue').length
  const nNone = states.filter(x => x === 'none').length
  const boardRows = rows && (act === 'all' ? rows : rows.filter((r, i) => states[i] === (act === 'overdue' ? 'overdue' : 'none')))

  // Move a card: show it in the new column at once, then tell the server; put it back if the server says no.
  async function moveTrip(trip, status, reason) {
    const before = rows
    setRows(r => r.map(t => t.id === trip.id ? { ...t, status } : t))
    try {
      await travel.setTripStatus(trip.id, status, reason)
      toast.success(`Moved to ${TRIP_STATUS[status].label}`)
    } catch (e) {
      setRows(before)
      toast.error('Could not move the trip', e.message)
    }
  }

  return (
    <div className="page">
      <div className="page-header">
        <h1 className="page-title">Trips &amp; Enquiries</h1>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          <div className="view-switch" role="group" aria-label="View">
            <button className={view === 'board' ? 'is-on' : ''} onClick={() => pickView('board')} aria-pressed={view === 'board'}><LayoutGrid size={14} /> Board</button>
            <button className={view === 'list' ? 'is-on' : ''} onClick={() => pickView('list')} aria-pressed={view === 'list'}><List size={14} /> List</button>
          </div>
          <button className="btn btn-primary" onClick={() => setShowNew(true)}>+ New enquiry</button>
        </div>
      </div>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 14, alignItems: 'center' }}>
        {view === 'list' && FILTERS.map(s => <button key={s} className={'btn btn-sm ' + (filter === s ? 'btn-primary' : 'btn-ghost')} onClick={() => setFilter(s)}>{s === 'all' ? 'All' : TRIP_STATUS[s].label}</button>)}
        <input className="form-input" style={{ maxWidth: 240, marginLeft: 'auto' }} placeholder="Search destination or title…" value={q} onChange={e => setQ(e.target.value)} />
      </div>
      {view === 'board' && rows !== null && (
        <div className="tboard-filters" role="group" aria-label="Filter by activity">
          <button className={'btn btn-sm ' + (act === 'all' ? 'btn-primary' : 'btn-ghost')} onClick={() => setAct('all')}>All trips</button>
          <button className={'btn btn-sm ' + (act === 'overdue' ? 'btn-primary' : 'btn-ghost')} onClick={() => setAct('overdue')}>Overdue activity <span className={'tboard-fcount' + (nOverdue ? ' is-red' : '')}>{nOverdue}</span></button>
          <button className={'btn btn-sm ' + (act === 'none' ? 'btn-primary' : 'btn-ghost')} onClick={() => setAct('none')}>No activity scheduled <span className={'tboard-fcount' + (nNone ? ' is-amber' : '')}>{nNone}</span></button>
          {act !== 'all' && <span className="text-muted">Showing open enquiries that need a next step</span>}
        </div>
      )}
      {view === 'board' && (rows === null
        ? <div style={{ padding: 24, textAlign: 'center' }}>Loading…</div>
        : <TripBoard rows={boardRows} onMove={moveTrip} onBooked={(trip, b) => nav(`/bookings/${b.id}`)} onChanged={load} />)}
      {view === 'list' && <div className="card" style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
          <thead><tr style={{ textAlign: 'left', color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase' }}>
            {['Trip', 'Destination', 'Dates', 'Pax', 'Budget', 'Status'].map(h => <th key={h} style={{ padding: '10px 14px' }}>{h}</th>)}</tr></thead>
          <tbody>
            {rows === null && <tr><td colSpan={6} style={{ padding: 24, textAlign: 'center' }}>Loading…</td></tr>}
            {rows?.length === 0 && <tr><td colSpan={6} style={{ padding: 32, textAlign: 'center', color: 'var(--text-3)' }}>No trips yet — create your first enquiry.</td></tr>}
            {rows?.map(t => (
              <tr key={t.id} onClick={() => nav(`/trips/${t.id}`)} style={{ cursor: 'pointer', borderTop: '1px solid var(--border)' }}>
                <td style={{ padding: '12px 14px', fontWeight: 600 }}>{t.title}</td>
                <td style={{ padding: '12px 14px' }}>{t.destination_text || '—'} {Number(t.is_international) ? '🌍' : ''}</td>
                <td style={{ padding: '12px 14px' }}>{fmtDate(t.start_date)}{t.nights ? ` · ${t.nights}N` : ''}</td>
                <td style={{ padding: '12px 14px' }}>{t.adults}A{Number(t.children) ? ` ${t.children}C` : ''}</td>
                <td style={{ padding: '12px 14px' }}>{t.budget_max ? inr(t.budget_max, true) : '—'}</td>
                <td style={{ padding: '12px 14px' }}><Pill map={TRIP_STATUS} value={t.status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>}
      {showNew && <NewTripModal onClose={() => setShowNew(false)} onCreated={(t) => { setShowNew(false); nav(`/trips/${t.id}`) }} />}
    </div>
  )
}
