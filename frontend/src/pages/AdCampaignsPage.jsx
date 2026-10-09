import { useState, useEffect, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { travel, inr } from '../api/travel'
import { toast } from '../components/Toast'
import AdCampaignWizard from '../components/ads/AdCampaignWizard'
import AdSafeguards from '../components/ads/AdSafeguards'
import { explain } from '../components/ads/explain'

const PLAT = { meta: '🟦 Meta', google: '🟥 Google' }
const ST = { ACTIVE: ['#dcfce7', '#15803d', 'Live'], PAUSED: ['#fef9c3', '#a16207', 'Paused'], DELETED: ['#f1f5f9', '#94a3b8', 'Removed'], ARCHIVED: ['#f1f5f9', '#94a3b8', 'Archived'] }
const money = (p) => (p === null || p === undefined) ? '—' : inr(p)

function StatusPill({ c }) {
  const [bg, fg, label] = ST[c.status] || ['#f1f5f9', '#475569', c.status]
  return (
    <span title={c.effective_status || ''}>
      <span style={{ background: bg, color: fg, padding: '2px 9px', borderRadius: 999, fontSize: 12, fontWeight: 700 }}>{label}</span>
      {c.paused_by_rule && <span title="Paused automatically by one of your rules" style={{ marginLeft: 6 }}>🤖</span>}
      {c.effective_status === 'ERROR' && <span title={c.last_error || ''} style={{ marginLeft: 6, color: 'var(--danger)', fontSize: 12 }}>⚠ failed</span>}
    </span>
  )
}

export default function AdCampaignsPage() {
  const [data, setData] = useState(null)
  const [conn, setConn] = useState(null)
  const [days, setDays] = useState(30)
  const [wizard, setWizard] = useState(false)
  const [busy, setBusy] = useState('')
  const [hist, setHist] = useState(null)

  const load = useCallback(() => travel.adCampaigns(days).then(setData).catch(e => toast.error('Could not load campaigns', e.message)), [days])
  useEffect(() => { load(); travel.adConnection().then(setConn).catch(() => {}) }, [load])

  async function act(key, fn, ok) {
    setBusy(key)
    try { await fn(); if (ok) toast.success(ok); await load() } catch (e) { toast.error('Not done', explain(e)) } finally { setBusy('') }
  }

  async function launch(c) {
    const cap = data.settings.daily_spend_cap
    const msg = `Launch “${c.name}”?\n\nIt will start spending up to ${inr(c.daily_budget)} per day (about ${inr(c.daily_budget * 30)} a month).\nYour daily cap is ${inr(cap)}; currently live: ${inr(data.totals.active_daily_budget)}.`
    if (window.confirm(msg)) act('l' + c.id, () => travel.launchCampaign(c.id), 'Campaign launched — it can take a while for the platform to review and start it.')
  }

  async function editBudget(c) {
    const v = window.prompt(`New daily budget for “${c.name}” in ₹ (now ${inr(c.daily_budget)}).\nIncreases are limited to ${data.settings.max_increase_pct}% per change.`, String(Math.round(c.daily_budget / 100)))
    if (v && Number(v) > 0) act('b' + c.id, () => travel.campaignBudget(c.id, Number(v)), 'Budget updated')
  }

  async function showHistory(c) {
    try {
      const h = await travel.campaignHistory(c.id)
      setHist({ c, rows: h })
    } catch (e) { toast.error('Could not load history', e.message) }
  }

  if (!data) return <div className="page">Loading…</div>
  const T = data.totals
  const capPct = data.settings.daily_spend_cap > 0 ? Math.min(100, Math.round(T.active_daily_budget / data.settings.daily_spend_cap * 100)) : 0
  const noCap = data.settings.daily_spend_cap <= 0
  const kpi = (l, v, s) => <div className="card card-body" style={{ flex: '1 1 150px' }}><div style={{ color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase' }}>{l}</div><div style={{ fontSize: 22, fontWeight: 800 }}>{v}</div>{s && <small style={{ color: 'var(--text-3)' }}>{s}</small>}</div>
  const th = { padding: '9px 10px', textAlign: 'left', color: 'var(--text-3)', fontSize: 11.5, textTransform: 'uppercase', whiteSpace: 'nowrap' }
  const td = { padding: '10px', borderTop: '1px solid var(--border)', verticalAlign: 'top' }

  return (
    <div className="page">
      <div className="page-header">
        <h1 className="page-title">Ad campaigns</h1>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <select className="form-select" value={days} onChange={e => setDays(Number(e.target.value))} style={{ width: 130 }}>{[7, 30, 90].map(n => <option key={n} value={n}>Last {n} days</option>)}</select>
          <Link className="btn btn-ghost" to="/ads/audiences">Audiences</Link>
          <button className="btn btn-ghost" disabled={busy === 'sync'} onClick={() => act('sync', async () => { const r = await travel.syncAds(); const sk = Object.values(r).find(x => x.skipped); if (sk) toast.info?.('Sync note', sk.skipped) }, 'Synced with Meta and Google')}>{busy === 'sync' ? 'Syncing…' : '↻ Sync'}</button>
          <button className="btn btn-primary" onClick={() => setWizard(true)} disabled={noCap} title={noCap ? 'Set a daily spend cap first' : ''}>+ New campaign</button>
        </div>
      </div>

      {conn?.mock && <div className="card card-body" style={{ background: '#fef9c3', marginBottom: 12 }}>🧪 <b>Demo mode</b> — Meta and Google are simulated. Nothing here touches a real ad account.</div>}
      {noCap && <div className="card card-body" style={{ background: '#fee2e2', marginBottom: 12 }}><b>Set a daily spend cap to start.</b> TripSarthi will not create or launch campaigns until you decide the most you are willing to spend per day. <a href="#safeguards">Set it below ↓</a></div>}
      {conn && !conn.meta.connected && !conn.google.connected && <div className="card card-body" style={{ marginBottom: 12 }}>Connect Meta and/or Google first in <Link to="/settings/ads">Settings → Ad Platforms</Link>.</div>}
      {conn?.meta.connected && conn.meta.can_manage === false && <div className="card card-body" style={{ background: '#fef9c3', marginBottom: 12 }}>Meta is connected for reporting only. To create and manage campaigns, reconnect it with campaign permission in <Link to="/settings/ads">Ad Platforms</Link>.</div>}

      {data.issues?.length > 0 && (
        <div className="card card-body" style={{ background: '#fee2e2', border: '1px solid #ef4444', marginBottom: 12 }}>
          <b>⚠ {data.issues.length} ad{data.issues.length > 1 ? 's' : ''} disapproved or limited — {data.issues.length > 1 ? 'they are' : 'it is'} not delivering properly</b>
          {data.issues.slice(0, 6).map(i => {
            const camp = data.campaigns.find(c => c.platform === i.platform && String(c.external_id) === String(i.campaign_external_id))
            return <div key={i.id} style={{ fontSize: 13.5, marginTop: 4 }}><b>{i.platform === 'meta' ? '🟦' : '🟥'} {camp?.name || 'Campaign'}</b>{i.ad_name ? ` · ${i.ad_name}` : ''} — <span style={{ color: i.kind === 'limited' ? 'var(--warning)' : 'var(--danger)', fontWeight: 700 }}>{i.kind === 'limited' ? 'limited' : 'disapproved'}</span>: {i.reason} <small style={{ color: 'var(--text-3)' }}>(since {i.since})</small></div>
          })}
          <small style={{ color: 'var(--text-2)', marginTop: 6 }}>Open the ad in {data.issues[0].platform === 'meta' ? 'Meta Ads Manager' : 'Google Ads'}, fix what the reason says (or replace the ad) and request a new review. This list clears itself after the next sync once the platform approves it.</small>
        </div>)}

      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
        {kpi('Spend', money(T.spend), `last ${days} days`)}
        {kpi('Leads (CRM)', T.crm_leads)}
        {kpi('Bookings', T.bookings)}
        {kpi('Revenue (ex-tax)', inr(T.revenue))}
        {kpi('ROAS', T.roas ? T.roas + '×' : '—', T.spend ? 'revenue ÷ spend' : 'no spend yet')}
        <div className="card card-body" style={{ flex: '1 1 220px' }}>
          <div style={{ color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase' }}>Live daily budget vs cap</div>
          <div style={{ fontSize: 22, fontWeight: 800 }}>{inr(T.active_daily_budget)} <small style={{ fontWeight: 400, color: 'var(--text-3)' }}>/ {noCap ? 'no cap' : inr(data.settings.daily_spend_cap)}</small></div>
          <div style={{ height: 6, background: 'var(--border)', borderRadius: 4, marginTop: 6 }}><div style={{ height: 6, width: capPct + '%', background: capPct > 90 ? 'var(--danger)' : 'var(--primary)', borderRadius: 4 }} /></div>
        </div>
      </div>

      <div className="card" style={{ overflowX: 'auto', marginBottom: 16 }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13.5 }}>
          <thead><tr>{['Campaign', 'Status', 'Budget/day', 'Spend', 'Leads', 'Cost/lead', 'Bookings', 'Revenue', 'ROAS', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {data.campaigns.length === 0 && <tr><td colSpan={10} style={{ ...td, textAlign: 'center', color: 'var(--text-3)', padding: 28 }}>No campaigns yet. Create one, or connect an account that already has campaigns and press Sync.</td></tr>}
            {data.campaigns.map(c => (
              <tr key={c.id}>
                <td style={td}><b>{c.name}</b><div style={{ fontSize: 12, color: 'var(--text-3)' }}>{PLAT[c.platform]} · {c.origin === 'travelpilot' ? 'created here' : 'from your account'}{c.kind ? ` · ${c.kind.replace(/_/g, ' ')}` : ''}</div>
                  {c.effective_status === 'ERROR' && <div style={{ fontSize: 12, color: 'var(--danger)', marginTop: 2 }}>{c.last_error}</div>}</td>
                <td style={td}><StatusPill c={c} /></td>
                <td style={td}>{money(c.daily_budget)}</td>
                <td style={td}>{money(c.spend)}</td>
                <td style={td}>{Math.max(c.crm_leads, c.platform_leads)}{c.platform_leads > c.crm_leads ? <small title="Platform-reported; CRM matched fewer" style={{ color: 'var(--text-3)' }}> ({c.crm_leads} in CRM)</small> : null}</td>
                <td style={td}>{money(c.cpl)}</td>
                <td style={td}>{c.bookings}</td>
                <td style={td}>{money(c.revenue)}</td>
                <td style={td}>{c.roas ? c.roas + '×' : '—'}</td>
                <td style={{ ...td, whiteSpace: 'nowrap' }}>
                  {c.status === 'PAUSED' && c.effective_status !== 'ERROR' && !String(c.external_id).startsWith('pending_') && <button className="btn btn-sm btn-success" disabled={!!busy} onClick={() => launch(c)}>Launch</button>}
                  {c.status === 'ACTIVE' && <button className="btn btn-sm btn-ghost" disabled={!!busy} onClick={() => act('p' + c.id, () => travel.pauseCampaign(c.id), 'Paused')}>Pause</button>}
                  {['ACTIVE', 'PAUSED'].includes(c.status) && <button className="btn btn-sm btn-ghost" disabled={!!busy} onClick={() => editBudget(c)} title="Change daily budget">₹</button>}
                  <button className="btn btn-sm btn-ghost" onClick={() => showHistory(c)} title="History">🕘</button>
                </td>
              </tr>))}
          </tbody>
        </table>
      </div>

      <div id="safeguards"><AdSafeguards settings={data.settings} onSaved={load} /></div>

      {wizard && <AdCampaignWizard conn={conn} onClose={() => setWizard(false)} onDone={() => { setWizard(false); load() }} />}
      {hist && (
        <div onClick={() => setHist(null)} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', justifyContent: 'center', alignItems: 'center', padding: 20 }}>
          <div className="card card-body" onClick={e => e.stopPropagation()} style={{ maxWidth: 560, width: '100%', maxHeight: '80vh', overflowY: 'auto' }}>
            <h3 style={{ marginTop: 0 }}>History · {hist.c.name}</h3>
            {hist.rows.length === 0 ? <p style={{ color: 'var(--text-3)' }}>No actions recorded.</p> : hist.rows.map((r, i) => (
              <div key={i} style={{ padding: '8px 0', borderTop: '1px solid var(--border)', fontSize: 13.5 }}>
                <b>{r.action.replace('ad_campaign.', '').replace(/_/g, ' ')}</b> <span style={{ color: 'var(--text-3)' }}>{r.at}{r.by ? ` · user #${r.by}` : ' · automatic'}</span>
                <div style={{ color: 'var(--text-2)' }}>{r.before ? JSON.stringify(r.before) : ''}{r.before && r.after ? ' → ' : ''}{r.after ? JSON.stringify(r.after) : ''}</div>
              </div>))}
            <button className="btn btn-ghost" style={{ marginTop: 10 }} onClick={() => setHist(null)}>Close</button>
          </div>
        </div>)}
    </div>
  )
}
