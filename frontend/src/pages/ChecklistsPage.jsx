import { useState, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { travel } from '../api/travel'

export default function ChecklistsPage() {
  const [days, setDays] = useState(60)
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  useEffect(() => {
    let live = true
    travel.checklistOverview(days).then(r => { if (live) { setD(r); setErr('') } }).catch(e => { if (live) setErr(e.message) })
    return () => { live = false }
  }, [days])
  return (
    <div className="page" style={{ maxWidth: 920 }}>
      <div className="page-header"><h1 className="page-title">Documents & visas</h1></div>
      <p style={{ color: 'var(--text-2)' }}>Upcoming departures that are not document-ready yet — soonest first. Open a booking to tick items off as customers send them.</p>
      <label style={{ fontSize: 12.5, fontWeight: 700 }}>Departing within <select className="form-select" style={{ width: 'auto', display: 'inline-block' }} value={days} onChange={e => setDays(Number(e.target.value))}>{[30, 60, 90, 180].map(n => <option key={n} value={n}>{n} days</option>)}</select></label>
      {err && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8, margin: '12px 0' }}>{err}</div>}
      {d && (d.bookings.length === 0 ? <div className="card card-body" style={{ marginTop: 14, color: 'var(--text-3)' }}>Every departure in this window is document-ready. 🎉</div> :
        d.bookings.map(b => (
          <div key={b.booking_id} className="card card-body" style={{ marginTop: 12 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap' }}>
              <div><Link to={`/bookings/${b.booking_id}`}><b>{b.booking_ref}</b></Link> · {b.title} <small style={{ color: 'var(--text-3)' }}>{b.customer} {b.international ? '· international' : ''}</small></div>
              <div style={{ color: b.days_to_departure <= 14 ? 'var(--danger)' : 'var(--text-2)', fontWeight: 700 }}>Departs in {b.days_to_departure} day{b.days_to_departure === 1 ? '' : 's'} · {b.travel_start}</div>
            </div>
            {!b.generated ? <small style={{ color: 'var(--warning)' }}>No checklist yet — open the booking to create it.</small> : <>
              <div style={{ height: 6, background: 'var(--border)', borderRadius: 999, margin: '8px 0' }}><div style={{ width: `${b.progress.percent}%`, height: '100%', background: 'var(--primary, #08569f)', borderRadius: 999 }} /></div>
              <small style={{ color: 'var(--text-3)' }}>{b.progress.done}/{b.progress.total} collected{b.overdue_count > 0 && <b style={{ color: 'var(--danger)' }}> · {b.overdue_count} overdue</b>}</small>
              <ul style={{ margin: '6px 0 0 18px', fontSize: 13 }}>{b.missing.map((m, i) => <li key={i} style={{ color: m.overdue ? 'var(--danger)' : 'inherit' }}>{m.label}{m.due_date ? ` — by ${m.due_date}` : ''}</li>)}</ul></>}
          </div>)))}
    </div>
  )
}
