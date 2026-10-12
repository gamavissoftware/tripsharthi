import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { travel, inr } from '../api/travel'
import { toast } from '../components/Toast'
import Pill from '../components/Pill'
import { StatRow, StatTile } from '../components/StatTile'
import { IndianRupee, Wallet, AlertTriangle, CalendarClock, Plane } from 'lucide-react'

export const BOOKING_STATUS = {
  confirmed: { label: 'Confirmed', bg: '#dcfce7', color: '#15803d' }, documents_pending: { label: 'Docs pending', bg: '#fef9c3', color: '#a16207' },
  ready: { label: 'Ready', bg: '#dbeafe', color: '#1d4ed8' }, travelling: { label: 'Travelling', bg: '#e0f2fe', color: '#0369a1' },
  completed: { label: 'Completed', bg: '#f1f5f9', color: '#475569' }, cancelled: { label: 'Cancelled', bg: '#fee2e2', color: '#b91c1c' },
}
const fmt = (d) => d ? new Date(d + 'T00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' }) : '—'

export default function BookingsPage() {
  const nav = useNavigate()
  const [rows, setRows] = useState(null)
  const [dash, setDash] = useState(null)
  useEffect(() => {
    travel.bookings().then(setRows).catch(e => toast.error('Could not load bookings', e.message))
    travel.bookingsDashboard().then(setDash).catch(() => {})
  }, [])
  return (
    <div className="page">
      <div className="page-header"><h1 className="page-title">Bookings</h1></div>
      {dash && <StatRow>
        <StatTile icon={IndianRupee} tone="#0e8f8c" label="Revenue booked" value={inr(dash.totals.revenue, true)} sub={`${dash.totals.n} bookings`} />
        <StatTile icon={Wallet} tone="#1a9d57" label="Collected" value={inr(dash.totals.collected, true)} />
        <StatTile icon={AlertTriangle} tone="#e5484d" label="Overdue" value={inr(dash.receivables?.overdue, true)} sub="needs follow-up" accent={dash.receivables?.overdue > 0 ? 'var(--danger)' : undefined} />
        <StatTile icon={CalendarClock} tone="#f59e0b" label="Upcoming dues" value={inr(dash.receivables?.upcoming, true)} />
        <StatTile icon={Plane} tone="#0a6cc4" label="Departing in 30 days" value={dash.departures_30d.length} />
      </StatRow>}
      <div className="card" style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
          <thead><tr style={{ textAlign: 'left', color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase' }}>{['Ref', 'Trip', 'Travel', 'Total', 'Paid', 'Status'].map(h => <th key={h} style={{ padding: '10px 14px' }}>{h}</th>)}</tr></thead>
          <tbody>
            {rows === null && <tr><td colSpan={6} style={{ padding: 24, textAlign: 'center' }}>Loading…</td></tr>}
            {rows?.length === 0 && <tr><td colSpan={6} style={{ padding: 32, textAlign: 'center', color: 'var(--text-3)' }}>No bookings yet. Confirm a booking from an itinerary.</td></tr>}
            {rows?.map(b => (
              <tr key={b.id} onClick={() => nav(`/bookings/${b.id}`)} style={{ cursor: 'pointer', borderTop: '1px solid var(--border)' }}>
                <td style={{ padding: '12px 14px', fontWeight: 700 }}>{b.booking_ref}</td><td style={{ padding: '12px 14px' }}>{b.title}</td>
                <td style={{ padding: '12px 14px' }}>{fmt(b.travel_start)} → {fmt(b.travel_end)}</td>
                <td style={{ padding: '12px 14px' }}>{inr(b.total_amount)}</td>
                <td style={{ padding: '12px 14px' }}>{inr(b.paid_amount)}</td>
                <td style={{ padding: '12px 14px' }}><Pill map={BOOKING_STATUS} value={b.status} /></td>
              </tr>))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
