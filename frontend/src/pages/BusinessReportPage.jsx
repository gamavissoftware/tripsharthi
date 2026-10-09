import { useState, useEffect } from 'react'
import { travel, inr } from '../api/travel'
import { fmt } from '../lib/currency'

const iso = (d) => d.toISOString().slice(0, 10)
const th = { padding: '6px 8px', textAlign: 'left', color: 'var(--text-3)', fontSize: 11.5, textTransform: 'uppercase' }
const R = { padding: '6px 8px', textAlign: 'right' }

function Bar({ value, max, color = 'var(--primary, #08569f)' }) {
  return <div style={{ height: 8, background: 'var(--border)', borderRadius: 999 }}><div style={{ width: `${max > 0 ? Math.max(2, (value / max) * 100) : 0}%`, height: '100%', background: color, borderRadius: 999 }} /></div>
}
function Stat({ label, value, sub, bad }) {
  return <div className="card card-body"><div style={{ fontSize: 12, color: 'var(--text-3)' }}>{label}</div><div style={{ fontSize: 22, fontWeight: 800, color: bad ? 'var(--danger)' : 'inherit' }}>{value}</div>{sub && <div style={{ fontSize: 12, color: 'var(--text-3)' }}>{sub}</div>}</div>
}
function Table({ title, cols, rows, empty = 'No data in this period.' }) {
  return (
    <div className="card card-body" style={{ overflowX: 'auto' }}>
      <h3 style={{ marginTop: 0 }}>{title}</h3>
      {rows.length === 0 ? <p style={{ color: 'var(--text-3)', margin: 0 }}>{empty}</p> :
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13.5 }}>
          <thead><tr>{cols.map((c, i) => <th key={c} style={i === 0 ? th : { ...th, textAlign: 'right' }}>{c}</th>)}</tr></thead>
          <tbody>{rows.map((r, i) => <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>{r.map((v, j) => <td key={j} style={j === 0 ? { padding: '6px 8px' } : R}>{v}</td>)}</tr>)}</tbody>
        </table>}
    </div>)
}

export default function BusinessReportPage() {
  const [range, setRange] = useState(() => { const t = new Date(); return { from: iso(new Date(t.getTime() - 29 * 864e5)), to: iso(t) } })
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  useEffect(() => {
    let live = true
    travel.travelReport(range.from, range.to).then(r => { if (live) { setD(r); setErr('') } }).catch(e => { if (live) { setD(null); setErr(e.message) } })
    return () => { live = false }
  }, [range])
  const preset = (days) => { const t = new Date(); setRange({ from: iso(new Date(t.getTime() - (days - 1) * 864e5)), to: iso(t) }) }
  const m = d?.money, f = d?.funnel
  const maxTrend = d ? Math.max(1, ...d.trend.map(t => t.revenue)) : 1

  return (
    <div className="page" style={{ maxWidth: 1040 }}>
      <div className="page-header"><h1 className="page-title">Business report</h1></div>
      <div className="card card-body" style={{ marginBottom: 14, display: 'flex', gap: 8, alignItems: 'flex-end', flexWrap: 'wrap' }}>
        <label style={{ fontSize: 12.5, fontWeight: 700 }}>From<input className="form-input" type="date" value={range.from} max={range.to} onChange={e => e.target.value && setRange(r => ({ ...r, from: e.target.value }))} /></label>
        <label style={{ fontSize: 12.5, fontWeight: 700 }}>To<input className="form-input" type="date" value={range.to} min={range.from} onChange={e => e.target.value && setRange(r => ({ ...r, to: e.target.value }))} /></label>
        {[[30, '30 days'], [90, '90 days'], [365, '12 months']].map(([n, l]) => <button key={n} className="btn btn-sm btn-ghost" onClick={() => preset(n)}>{l}</button>)}
      </div>
      {err && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8, marginBottom: 14 }}>{err}</div>}
      {d && <div style={{ display: 'grid', gap: 14 }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(170px,1fr))', gap: 12 }}>
          <Stat label="Bookings" value={m.bookings} sub={d.cancelled ? `${d.cancelled} cancelled (excluded)` : null} />
          <Stat label="Revenue (ex-tax)" value={inr(m.revenue)} sub={`avg ${inr(m.avg_booking)} / booking`} />
          <Stat label="Gross margin" value={inr(m.margin)} sub={`${m.margin_pct}% of revenue`} />
          <Stat label="Enquiry → booking" value={`${f.win_rate}%`} sub={`${f.booked} of ${f.enquiries} enquiries`} />
        </div>
        {m.bookings_without_cost > 0 && <div style={{ background: '#fef3c7', border: '1px solid #f59e0b', padding: 10, borderRadius: 8 }}>{m.bookings_without_cost} booking(s) have no supplier cost entered yet, so their margin is overstated.</div>}

        <div className="card card-body">
          <h3 style={{ marginTop: 0 }}>Funnel</h3>
          {[['Enquiries', f.enquiries, f.enquiries], ['Quoted', f.quoted, f.enquiries], ['Booked', f.booked, f.enquiries]].map(([l, v, mx]) => (
            <div key={l} style={{ marginBottom: 8 }}><div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13 }}><span>{l}</span><b>{v}</b></div><Bar value={v} max={mx} /></div>))}
          <small style={{ color: 'var(--text-3)' }}>{f.quote_rate}% of enquiries get a quote · {f.quote_to_book}% of quotes convert · {f.lost} marked lost</small>
        </div>

        <div className="card card-body">
          <h3 style={{ marginTop: 0 }}>Revenue — last 6 months</h3>
          {d.trend.map(t => (
            <div key={t.month} style={{ display: 'grid', gridTemplateColumns: '70px 1fr 150px', gap: 8, alignItems: 'center', marginBottom: 6, fontSize: 13 }}>
              <span>{t.month}</span><Bar value={t.revenue} max={maxTrend} /><span style={{ textAlign: 'right' }}>{inr(t.revenue)} · {t.bookings}</span></div>))}
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(420px,1fr))', gap: 14 }}>
          <Table title="Top destinations" cols={['Destination', 'Bookings', 'Revenue', 'Margin']} rows={d.destinations.map(x => [x.name, x.bookings, inr(x.revenue), inr(x.margin)])} />
          <Table title="Lead sources" cols={['Source', 'Enquiries', 'Booked', 'Win %', 'Revenue']} rows={d.sources.map(x => [x.source.replace(/_/g, ' '), x.enquiries, x.booked, `${x.win_rate}%`, inr(x.revenue)])} />
          <Table title="Agents" cols={['Agent', 'Enquiries', 'Booked', 'Win %', 'Revenue']} rows={d.agents.map(x => [x.name, x.enquiries, x.booked, `${x.win_rate}%`, inr(x.revenue)])} />
        </div>

        {(d.fx?.exposure?.length > 0 || d.fx?.settled_services > 0) && (
          <div className="card card-body">
            <h3 style={{ marginTop: 0 }}>Foreign currency</h3>
            <div style={{ display: 'flex', gap: 24, flexWrap: 'wrap' }}>
              {d.fx.exposure.map(e => <div key={e.currency}><div style={{ fontSize: 12, color: 'var(--text-3)' }}>Still owed in {e.currency}</div><div style={{ fontSize: 20, fontWeight: 800 }}>{fmt(e.outstanding_fx, e.currency)}</div><small style={{ color: 'var(--text-3)' }}>≈ {inr(e.inr_estimate)} at today's rate</small></div>)}
              {d.fx.settled_services > 0 && <div><div style={{ fontSize: 12, color: 'var(--text-3)' }}>Forex {d.fx.realised_variance > 0 ? 'loss' : 'gain'} on {d.fx.settled_services} settled supplier payment{d.fx.settled_services > 1 ? 's' : ''}</div><div style={{ fontSize: 20, fontWeight: 800, color: d.fx.realised_variance > 0 ? 'var(--danger)' : 'var(--success)' }}>{inr(Math.abs(d.fx.realised_variance))}</div><small style={{ color: 'var(--text-3)' }}>already reflected in your margin</small></div>}
            </div>
          </div>)}

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(340px,1fr))', gap: 14 }}>
          <div className="card card-body">
            <h3 style={{ marginTop: 0 }}>Cash position (today)</h3>
            <div style={{ display: 'grid', gap: 4, fontSize: 14 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}><span>Customers owe you — overdue</span><b style={{ color: d.cash.receivable_overdue > 0 ? 'var(--danger)' : 'inherit' }}>{inr(d.cash.receivable_overdue)}</b></div>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}><span>Customers owe you — upcoming</span><b>{inr(d.cash.receivable_upcoming)}</b></div>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}><span>You owe suppliers</span><b>{inr(d.cash.payable_outstanding)}</b></div>
              <div style={{ display: 'flex', justifyContent: 'space-between', borderTop: '1px solid var(--border)', paddingTop: 4 }}><span>Net position</span><b style={{ color: d.cash.net_position < 0 ? 'var(--danger)' : 'var(--success)' }}>{inr(d.cash.net_position)}</b></div>
            </div>
          </div>
          <div className="card card-body">
            <h3 style={{ marginTop: 0 }}>Looking ahead</h3>
            <div style={{ display: 'grid', gap: 4, fontSize: 14 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}><span>Departures in 90 days</span><b>{d.forecast.departures_90d.count} · {inr(d.forecast.departures_90d.revenue)}</b></div>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}><span>Collections due in 30 days</span><b>{inr(d.forecast.collections_30d)}</b></div>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}><span>Open quotes ({d.forecast.open_quotes.count})</span><b>{inr(d.forecast.open_quotes.value)}</b></div>
              <div style={{ display: 'flex', justifyContent: 'space-between' }}><span>…weighted at {d.forecast.open_quotes.win_rate_used}% close rate</span><b>{inr(d.forecast.open_quotes.weighted_value)}</b></div>
            </div>
            <small style={{ color: 'var(--text-3)' }}>The weighted figure is a rough guide based on this period's quote-to-booking rate, not a promise.</small>
          </div>
        </div>
      </div>}
    </div>
  )
}
