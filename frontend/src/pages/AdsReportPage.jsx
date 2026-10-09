import { useState, useEffect } from 'react'
import { travel, inr } from '../api/travel'
import { toast } from '../components/Toast'

const ico = { meta: '🟦 Meta', google: '🟥 Google', direct: '⚪ Direct' }
export default function AdsReportPage() {
  const [days, setDays] = useState(90)
  const [d, setD] = useState(null)
  useEffect(() => {
    const to = new Date().toISOString().slice(0, 10), from = new Date(Date.now() - days * 864e5).toISOString().slice(0, 10)
    travel.adsReport(from, to).then(setD).catch(e => toast.error('Could not load report', e.message))
  }, [days])
  const kpi = (l, v, s) => <div className="card card-body" style={{ flex: '1 1 160px' }}><div style={{ color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase' }}>{l}</div><div style={{ fontSize: 24, fontWeight: 800 }}>{v}</div>{s && <small style={{ color: 'var(--text-3)' }}>{s}</small>}</div>
  const th = { padding: '10px 14px', textAlign: 'left', color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase' }; const td = { padding: '10px 14px', borderTop: '1px solid var(--border)' }
  const fb = d?.conversion_feedback || {}
  return (
    <div className="page">
      <div className="page-header"><h1 className="page-title">Ads → Bookings</h1>
        <select className="form-select" style={{ maxWidth: 160 }} value={days} onChange={e => setDays(Number(e.target.value))}>{[30, 90, 180, 365].map(n => <option key={n} value={n}>Last {n} days</option>)}</select></div>
      {d && <>
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 16 }}>
          {kpi('Leads', d.totals.leads)}{kpi('Quoted', d.totals.quoted)}{kpi('Bookings', d.totals.bookings, d.totals.leads ? `${Math.round(d.totals.bookings / d.totals.leads * 100)}% of leads` : '')}
          {kpi('Revenue', inr(d.totals.revenue, true))}{kpi('Ad spend', inr(d.ad_spend, true))}{kpi('ROAS', d.roas ? d.roas + '×' : '—', d.ad_spend ? '' : 'connect Meta Ads spend')}
        </div>
        <div className="card" style={{ overflowX: 'auto', marginBottom: 16 }}><table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14 }}>
          <thead><tr>{['Source', 'Campaign', 'Leads', 'Quoted', 'Bookings', 'Revenue', 'Margin'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>{d.campaigns.length === 0 && <tr><td colSpan={7} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No attributed leads yet. Leads from Meta/Google lead ads, Click-to-WhatsApp ads and tagged web forms appear here.</td></tr>}
            {d.campaigns.map((c, i) => <tr key={i}><td style={td}>{ico[c.platform] || c.platform}</td><td style={td}><b>{c.campaign}</b></td><td style={td}>{c.leads}</td><td style={td}>{c.quoted}</td><td style={td}>{c.bookings}</td><td style={td}>{inr(c.revenue)}</td><td style={td}>{inr(c.gross_margin)}</td></tr>)}</tbody></table></div>
        <div className="card card-body"><h3 style={{ marginTop: 0 }}>Closed-loop feedback to ad platforms</h3>
          <p style={{ margin: 0 }}>Sent <b>{fb.sent || 0}</b> · Pending <b>{fb.pending || 0}</b> · Failed <b>{fb.failed || 0}</b> · Skipped <b>{fb.skipped || 0}</b>.
            Quote-sent and booking events are reported back to Meta (Conversions API) and Google (offline conversions) so campaigns optimise for real bookings.</p></div>
      </>}
    </div>
  )
}
