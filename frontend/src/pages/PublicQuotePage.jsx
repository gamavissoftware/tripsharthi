import { useState, useEffect } from 'react'
import { travel, inr } from '../api/travel'

const ICON = { hotel: '🏨', flight: '✈️', train: '🚆', transfer: '🚗', sightseeing: '📸', activity: '🎯', meal: '🍽️', visa: '🛂', insurance: '🛡️', other: '•' }

/** Customer-facing quote. Rendered outside the authenticated shell; sees no cost or margin. */
export default function PublicQuotePage({ token }) {
  const [it, setIt] = useState(null)
  const [err, setErr] = useState('')
  const [done, setDone] = useState(false)
  useEffect(() => { travel.publicQuote(token).then(r => r.success ? setIt(r.data) : setErr(r.message || 'This quote link is invalid.')).catch(() => setErr('Could not load this quote.')) }, [token])
  if (err) return <div style={{ padding: 40, textAlign: 'center' }}><h2>{err}</h2></div>
  if (!it) return <div style={{ padding: 40, textAlign: 'center' }}>Loading your itinerary…</div>
  const accepted = done || it.status === 'accepted'
  return (
    <div style={{ maxWidth: 760, margin: '0 auto', padding: '24px 16px 120px', fontFamily: 'var(--font)' }}>
      {it.cover_image && <img src={it.cover_image} alt="" style={{ width: '100%', borderRadius: 16, maxHeight: 280, objectFit: 'cover' }} />}
      <h1 style={{ marginBottom: 4 }}>{it.title}</h1>
      <p style={{ marginTop: 0 }}>{it.nights}N · {it.adults} adults{Number(it.children) ? `, ${it.children} children` : ''}</p>
      {it.days.map(d => (
        <div key={d.id} className="card card-body" style={{ marginBottom: 12 }}>
          <h3 style={{ margin: 0 }}>Day {d.day_no}: {d.title}</h3>
          {d.description && <p>{d.description}</p>}
          {d.items.map(i => <div key={i.id} style={{ padding: '4px 0' }}>{ICON[i.type] || '•'} {i.title}{i.details ? <small style={{ color: 'var(--text-3)' }}> — {i.details}</small> : null}</div>)}
        </div>))}
      {it.extras?.length > 0 && <div className="card card-body" style={{ marginBottom: 12 }}><h3 style={{ margin: 0 }}>Also included</h3>
        {it.extras.map(i => <div key={i.id} style={{ padding: '4px 0' }}>{ICON[i.type] || '•'} {i.title}</div>)}</div>}
      {(it.inclusions || it.exclusions) && <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(260px,1fr))', gap: 12 }}>
        {it.inclusions && <div className="card card-body"><h3 style={{ marginTop: 0 }}>✅ Inclusions</h3><div style={{ whiteSpace: 'pre-wrap' }}>{it.inclusions}</div></div>}
        {it.exclusions && <div className="card card-body"><h3 style={{ marginTop: 0 }}>❌ Exclusions</h3><div style={{ whiteSpace: 'pre-wrap' }}>{it.exclusions}</div></div>}</div>}
      <div style={{ position: 'fixed', left: 0, right: 0, bottom: 0, background: '#fff', borderTop: '1px solid var(--border)', padding: '12px 16px', display: 'flex', justifyContent: 'center', gap: 16, alignItems: 'center', flexWrap: 'wrap' }}>
        <div><div style={{ fontSize: 22, fontWeight: 800 }}>{inr(it.grand_total)}</div><small style={{ color: 'var(--text-3)' }}>incl. GST{Number(it.tcs_amount) > 0 ? ` + TCS ${inr(it.tcs_amount)}` : ''}{it.valid_until ? ` · valid till ${it.valid_until}` : ''}</small>{it.fx_display && <div style={{ fontSize: 13, color: 'var(--text-2)' }} title={it.fx_display.note}>≈ {it.fx_display.formatted} <small style={{ color: 'var(--text-3)' }}>indicative · you pay in ₹</small></div>}</div>
        <a className="btn btn-ghost" href={`/api/v1/public/quotes/${token}/pdf`} target="_blank" rel="noreferrer">⬇ Download PDF</a>
        {accepted ? <b style={{ color: 'var(--success)' }}>✓ Accepted — your travel expert will contact you</b>
          : it.expired ? <b>This quote has expired</b>
          : <button className="btn btn-primary btn-lg" onClick={async () => { const r = await travel.acceptQuote(token); if (r.success) setDone(true); else alert(r.message || 'Could not accept') }}>Accept this quote</button>}
      </div>
    </div>
  )
}
