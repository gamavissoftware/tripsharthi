import { useState, useEffect } from 'react'
import { travel, inr } from '../api/travel'
import { toast } from '../components/Toast'
import { StatRow, StatTile } from '../components/StatTile'
import { IndianRupee as IcRupee, Users as IcUsers, Plane as IcPlane, Wallet as IcWallet, TrendingUp as IcTrend, FileText as IcFile } from 'lucide-react'

const ico = { meta: <span className="ps-plat"><i className="ps-dot meta" />Meta</span>, google: <span className="ps-plat"><i className="ps-dot google" />Google</span>, direct: <span className="ps-plat"><i className="ps-dot direct" />Direct</span> }
export default function AdsReportPage() {
  const [days, setDays] = useState(90)
  const [d, setD] = useState(null)
  useEffect(() => {
    const to = new Date().toISOString().slice(0, 10), from = new Date(Date.now() - days * 864e5).toISOString().slice(0, 10)
    travel.adsReport(from, to).then(setD).catch(e => toast.error('Could not load report', e.message))
  }, [days])
  const th = { padding: '10px 14px', textAlign: 'left', color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase' }; const td = { padding: '10px 14px', borderTop: '1px solid var(--border)' }
  const fb = d?.conversion_feedback || {}
  return (
    <div className="page">
      <div className="page-header"><h1 className="page-title">Ads → Bookings</h1>
        <select className="form-select" style={{ maxWidth: 160 }} value={days} onChange={e => setDays(Number(e.target.value))}>{[30, 90, 180, 365].map(n => <option key={n} value={n}>Last {n} days</option>)}</select></div>
      {d && <>
        <StatRow>
          <StatTile icon={IcUsers} tone="#0a6cc4" label="Leads" value={d.totals.leads} />
          <StatTile icon={IcFile} tone="#f98f26" label="Quoted" value={d.totals.quoted} />
          <StatTile icon={IcPlane} tone="#1a9d57" label="Bookings" value={d.totals.bookings} sub={d.totals.leads ? `${Math.round(d.totals.bookings / d.totals.leads * 100)}% of leads` : ''} />
          <StatTile icon={IcWallet} tone="#0e8f8c" label="Revenue" value={inr(d.totals.revenue, true)} />
          <StatTile icon={IcRupee} tone="#e5484d" label="Ad spend" value={inr(d.ad_spend, true)} />
          <StatTile icon={IcTrend} tone="#6366f1" label="ROAS" value={d.roas ? d.roas + '×' : '—'} sub={d.ad_spend ? '' : 'connect Meta Ads spend'} />
        </StatRow>
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
