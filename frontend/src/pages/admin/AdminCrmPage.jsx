import { useEffect, useState, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { Users, Megaphone, UserCheck, BadgeCheck, Percent, Timer, Zap, RefreshCw, Mail, MessageCircle, CircleCheck, Circle, ListTodo, Flame, Clock } from 'lucide-react'
import { admin } from '../../api/admin'
import { api } from '../../api/client'
import { StatRow, StatTile } from '../../components/StatTile'
import { toast } from '../../components/Toast'
import { confirmAsk } from '../../components/confirmAsk'
import { Pill } from './AdminUi'
import { th, td, ago } from './adminUtil'

const n0 = (v) => Number(v || 0).toLocaleString('en-IN')
const rs = (paise) => `₹${Math.round(Number(paise || 0) / 100).toLocaleString('en-IN')}`
const WAIT = (w) => (w ? `+${w[0]} ${w[1] === 'days' ? (w[0] === 1 ? 'day' : 'days') : w[1]}` : 'instantly')
const FLOW_COLOR = { active: 'var(--success)', paused: 'var(--warning)', draft: 'var(--text-3)' }

function Funnel({ rows }) {
  const max = Math.max(1, ...rows.map(r => r.count))
  return (
    <div style={{ display: 'grid', gap: 8 }}>
      {rows.map(r => (
        <div key={r.stage} style={{ display: 'grid', gridTemplateColumns: '150px 1fr 44px', alignItems: 'center', gap: 10, fontSize: 14 }}>
          <span>{r.stage}</span>
          <span style={{ background: '#edf1f7', borderRadius: 6, height: 18, overflow: 'hidden' }}>
            <i style={{ display: 'block', height: '100%', width: `${(r.count / max) * 100}%`, background: r.won ? 'var(--success)' : r.lost ? '#9ca3af' : 'var(--primary)', borderRadius: 6 }} />
          </span>
          <b style={{ textAlign: 'right' }}>{r.count}</b>
        </div>
      ))}
    </div>
  )
}

function Daily({ rows }) {
  if (!rows.length) return <div style={{ color: 'var(--text-3)', fontSize: 14 }}>No leads in this period yet.</div>
  const max = Math.max(1, ...rows.map(r => r.count))
  return (
    <div style={{ display: 'flex', alignItems: 'flex-end', gap: 3, height: 90 }}>
      {rows.map(r => <i key={r.date} title={`${r.date}: ${r.count}`} style={{ flex: 1, minWidth: 3, height: `${Math.max(6, (r.count / max) * 100)}%`, background: 'var(--accent)', borderRadius: '3px 3px 0 0' }} />)}
    </div>
  )
}

/** Facebook / Instagram Lead Ads connection for the marketing workspace (pasted Page token; the Facebook-login picker lives in the customer app). */
function FacebookPanel({ onChange }) {
  const [rows, setRows] = useState(null)
  const [hook, setHook] = useState('')
  const [form, setForm] = useState({ page_id: '', page_access_token: '' })
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => api.get('/meta-integrations').then(d => { setRows(d.data ?? []); setHook(d.webhook_url ?? '') }).catch(() => setRows([])), [])
  useEffect(() => { load() }, [load])

  async function connect(e) {
    e.preventDefault()
    if (!/^\d{5,25}$/.test(form.page_id.trim())) { toast.error('Check the Page ID', 'It is a number, e.g. 1384403011425937.'); return }
    if (form.page_access_token.trim().length < 20) { toast.error('Missing token', 'Paste the access token from Meta Graph API Explorer.'); return }
    setBusy(true)
    try {
      const r = (await admin.crmFacebookConnect(form.page_id.trim(), form.page_access_token.trim()))
      toast.success(`Connected: ${r.page_name || r.page_id}`, `${r.forms} lead form(s) holding ${r.leads_in_forms} leads. ${r.subscribed ? 'Real-time delivery is on.' : 'Not subscribed yet.'}${r.long_lived ? '' : ' Token may expire soon.'}`)
      if (r.warning) toast.error('Note', r.warning)
      setForm({ page_id: '', page_access_token: '' }); load(); onChange?.()
    } catch (err) { toast.error('Could not connect', err.message || 'Meta refused the details.') } finally { setBusy(false) }
  }
  async function importPast() {
    if (!(await confirmAsk('Bring in the leads Meta still holds (up to the last 4 months) as contacts? No messages are sent.'))) return
    setBusy(true)
    try {
      const r = await admin.crmFacebookImport(4)
      toast.success(`Imported ${r.inserted} new, ${r.updated} already there`, `${r.deals} added to the funnel${r.skipped ? `, ${r.skipped} without a phone or e-mail skipped` : ''}.`)
      load(); onChange?.()
    } catch (err) { toast.error('Import failed', err.message) } finally { setBusy(false) }
  }
  async function test(id) {
    try { const d = await api.post(`/meta-integrations/${id}/test`, {}); toast.success('Test lead created', `Contact #${d.contact_id} — see Leads.`) } catch (err) { toast.error('Test failed', err.message) }
  }
  async function remove(id) {
    if (!(await confirmAsk('Disconnect this Facebook Page? New leads will stop arriving.'))) return
    try { await api.delete(`/meta-integrations/${id}`); load(); onChange?.() } catch (err) { toast.error('Could not disconnect', err.message) }
  }

  return (
    <div className="card card-body" style={{ marginBottom: 16 }}>
      <h3 style={{ margin: '0 0 4px' }}><Megaphone size={17} style={{ verticalAlign: '-3px' }} /> Facebook &amp; Instagram Lead Ads</h3>
      <p style={{ color: 'var(--text-3)', margin: '0 0 12px', fontSize: 14 }}>Every enquiry from your lead-ad forms lands here instantly as a lead, starts the welcome automation and is put in the Subscriber Funnel. In Meta, set the webhook callback to <code>{hook || '…'}</code> and subscribe the app to “leadgen”.</p>
      {rows === null ? <div style={{ color: 'var(--text-3)' }}>Loading…</div> : rows.length === 0 ? <div className="wa-banner" style={{ marginBottom: 12 }}>No Page connected yet.</div> : (
        <div style={{ display: 'grid', gap: 8, marginBottom: 12 }}>
          {rows.map(r => (
            <div key={r.id} style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', border: '1px solid #edf1f7', borderRadius: 10, padding: '9px 12px' }}>
              <b>{r.config?.page_name || `Page ${r.page_id} (not verified - reconnect)`}</b><span style={{ color: 'var(--text-3)', fontSize: 13 }}>ID {r.page_id}</span>
              <Pill color={r.status === 'active' ? 'var(--success)' : 'var(--text-3)'}>{r.status}</Pill>
              <span style={{ flex: 1 }} />
              <button className="btn btn-secondary btn-sm" onClick={() => test(r.id)}>Send test lead</button>
              <button className="btn btn-secondary btn-sm" disabled={busy} onClick={importPast}>Import past leads</button>
              <button className="btn btn-secondary btn-sm" onClick={() => remove(r.id)}>Disconnect</button>
            </div>
          ))}
        </div>
      )}
      <form onSubmit={connect} noValidate style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
        <div style={{ flex: '1 1 160px' }}><label htmlFor="fbp" style={{ fontSize: 13 }}>Facebook Page ID</label><input id="fbp" className="form-input" value={form.page_id} onChange={e => setForm({ ...form, page_id: e.target.value })} placeholder="e.g. 1384403011425937" /></div>
        <div style={{ flex: '2 1 260px' }}><label htmlFor="fbt" style={{ fontSize: 13 }}>Facebook access token</label><input id="fbt" className="form-input" type="password" autoComplete="off" value={form.page_access_token} onChange={e => setForm({ ...form, page_access_token: e.target.value })} placeholder="Access token from Graph API Explorer (TripSarthi app)" /></div>
        <button className="btn btn-primary" disabled={busy}>{busy ? 'Connecting…' : 'Connect Page'}</button>
      </form>
    </div>
  )
}

export default function AdminCrmPage() {
  const [st, setSt] = useState(null)
  const [dash, setDash] = useState(null)
  const [days, setDays] = useState(30)
  const [busy, setBusy] = useState('')
  const [err, setErr] = useState('')

  const load = useCallback(async () => {
    try {
      const s = await admin.crmStatus(); setSt(s); setErr('')
      if (s.workspace && s.setup_done) setDash(await admin.crmDashboard(days))
    } catch (e) { setErr(e.message || 'Could not load the CRM.') }
  }, [days])
  useEffect(() => { load() }, [load])

  const act = async (key, fn, ok) => {
    setBusy(key)
    try { const r = await fn(); if (ok) toast.success(ok(r)); await load() } catch (e) { toast.error('Not done', e.message) } finally { setBusy('') }
  }

  if (err) return <div className="page"><div className="wa-banner bad"><div className="grow">{err}</div></div></div>
  if (!st) return <div className="page"><div className="card card-body" style={{ color: 'var(--text-3)' }}>Loading…</div></div>
  if (!st.workspace) {
    return (
      <div className="page">
        <div className="page-header"><h1 className="page-title">CRM</h1></div>
        <div className="wa-banner bad"><div className="grow"><b>No marketing workspace chosen yet.</b> TripSarthi's own CRM runs inside one workspace (the same one used for WhatsApp marketing). Choose it first.</div><Link className="btn btn-primary btn-sm" to="/admin/whatsapp">Choose the workspace</Link></div>
      </div>)
  }

  const k = dash?.kpis
  return (
    <div className="page page-wide">
      <div className="page-header">
        <div><h1 className="page-title">CRM — lead to subscriber</h1><div style={{ color: 'var(--text-3)', fontSize: 14 }}>Workspace <b>{st.workspace.name}</b> · Facebook leads are nurtured by e-mail and WhatsApp until they subscribe</div></div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {st.setup_done && <button className="btn btn-secondary" disabled={busy === 'rec'} onClick={() => act('rec', admin.crmReconcile, r => `Checked ${r.checked} leads — ${r.subscribed} subscribed, ${r.signed_up} signed up, ${r.engaged} engaged.`)}><RefreshCw size={15} /> Refresh funnel</button>}
          <button className="btn btn-primary" disabled={busy === 'setup'} onClick={() => act('setup', admin.crmSetup, () => 'CRM set up. Automations are drafts until you switch them on.')}><Zap size={15} /> {st.setup_done ? 'Re-run setup' : 'Set up CRM'}</button>
        </div>
      </div>

      <div className="card card-body" style={{ marginBottom: 16 }}>
        <h3 style={{ margin: '0 0 10px' }}>{st.ready ? 'Everything is live' : 'Get ready to receive leads'}</h3>
        <div style={{ display: 'grid', gap: 7 }}>
          {st.checks.map(c => (
            <div key={c.key} style={{ display: 'flex', gap: 9, alignItems: 'baseline', fontSize: 14 }}>
              {c.ok ? <CircleCheck size={16} color="var(--success)" style={{ flexShrink: 0, alignSelf: 'center' }} /> : <Circle size={16} color="var(--warning)" style={{ flexShrink: 0, alignSelf: 'center' }} />}
              <span><b>{c.label}</b> <span style={{ color: 'var(--text-3)' }}>— {c.detail}</span>
                {c.key === 'whatsapp' && !c.ok && <> <Link to="/admin/whatsapp">Open WhatsApp</Link></>}
                {c.key === 'templates' && !c.ok && <> <Link to="/admin/whatsapp/templates">Open Templates</Link></>}
                {c.key === 'email' && !c.ok && <> <button className="btn btn-secondary btn-sm" onClick={() => act('relay', admin.crmMailRelay, r => r.reason)}>Use TripSarthi mail server</button> or <Link to="/admin/mail">set it up</Link></>}
              </span>
            </div>
          ))}
        </div>
      </div>

      <FacebookPanel onChange={load} />

      {st.setup_done && dash && (
        <>
          <div style={{ display: 'flex', gap: 6, margin: '0 0 10px' }}>
            {[7, 30, 90].map(d => <button key={d} className={'btn btn-sm ' + (days === d ? 'btn-primary' : 'btn-secondary')} onClick={() => setDays(d)}>Last {d} days</button>)}
          </div>
          <StatRow>
            <StatTile icon={Users} label="New leads" value={n0(k.new_leads)} sub={`${n0(k.facebook_leads)} from Facebook`} to="/contacts" />
            <StatTile icon={UserCheck} tone="#0a6cc4" label="Signed up" value={n0(k.signed_up)} sub="created a workspace" />
            <StatTile icon={BadgeCheck} tone="#16a34a" label="Subscribed" value={n0(k.subscribed)} sub={k.revenue_paise ? `${rs(k.revenue_paise)} paid so far` : 'paying customers'} accent="var(--success)" />
            <StatTile icon={Percent} tone="#12a89e" label="Lead → subscriber" value={`${k.conversion_pct}%`} sub={`${n0(k.open)} still open`} />
            <StatTile icon={Timer} tone="#f98f26" label="Avg first touch" value={k.median_first_touch_min === null ? '—' : k.median_first_touch_min < 60 ? `${k.median_first_touch_min} min` : `${Math.round(k.median_first_touch_min / 60)} h`} sub="lead → first e-mail" />
          </StatRow>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(320px,1fr))', gap: 16, margin: '16px 0' }}>
            <div className="card card-body"><h3 style={{ margin: '0 0 12px' }}>Subscriber Funnel</h3><Funnel rows={dash.funnel} /><div style={{ marginTop: 10 }}><Link to="/deals">Open the pipeline board →</Link></div></div>
            <div className="card card-body"><h3 style={{ margin: '0 0 12px' }}>New leads per day</h3><Daily rows={dash.daily} />
              <div style={{ marginTop: 12, display: 'flex', gap: 6, flexWrap: 'wrap' }}>{dash.sources.map(s => <Pill key={s.source}>{s.source.replace(/_/g, ' ')}: {s.count}</Pill>)}</div></div>
            <div className="card card-body">
              <h3 style={{ margin: '0 0 10px' }}>Needs you today</h3>
              <div style={{ display: 'grid', gap: 8, fontSize: 14 }}>
                <div><Clock size={14} style={{ verticalAlign: '-2px' }} /> <b>{dash.attention.untouched}</b> new lead(s) waiting over an hour — <Link to="/contacts">call them</Link></div>
                <div><ListTodo size={14} style={{ verticalAlign: '-2px' }} /> <b>{dash.attention.overdue_tasks}</b> overdue follow-up task(s) — <Link to="/tasks">open tasks</Link></div>
                <div><Flame size={14} style={{ verticalAlign: '-2px' }} /> <b>{dash.attention.replied}</b> lead(s) replied in the last 3 days</div>
                {dash.attention.hot.map(h => <div key={h.id} style={{ paddingLeft: 20 }}><Link to={`/contacts/${h.id}`}>{h.name || h.phone}</Link> <span style={{ color: 'var(--text-3)' }}>· replied {ago(h.last_inbound_at)}</span></div>)}
              </div>
            </div>
          </div>

          <div className="card" style={{ marginBottom: 16, overflowX: 'auto' }}>
            <div className="card-body" style={{ paddingBottom: 0 }}><h3 style={{ margin: 0 }}>Facebook campaigns</h3></div>
            <table className="cu-table plain" style={{ minWidth: 520 }}>
              <thead><tr>{['Campaign', 'Leads', 'Signed up', 'Subscribed'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
              <tbody>
                {dash.campaigns.length === 0 && <tr><td colSpan={4} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No campaign-tagged leads in this period.</td></tr>}
                {dash.campaigns.map(c => <tr key={c.campaign}><td style={td}>{c.campaign}</td><td style={td}>{c.leads}</td><td style={td}>{c.signed_up}</td><td style={td}><b>{c.subscribed}</b></td></tr>)}
              </tbody>
            </table>
          </div>
        </>
      )}

      {st.setup_done && (
        <div className="card card-body">
          <h3 style={{ margin: '0 0 4px' }}>Nurture automations</h3>
          <p style={{ color: 'var(--text-3)', margin: '0 0 12px', fontSize: 14 }}>Each step waits, checks the lead has not already signed up, then sends. WhatsApp steps use a free message when the 24-hour window is open, otherwise your Meta-approved template. Edit any step in the flow editor.</p>
          <div style={{ display: 'grid', gap: 12 }}>
            {st.recipes.map(r => (
              <div key={r.key} style={{ border: '1px solid #edf1f7', borderRadius: 12, padding: 14 }}>
                <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                  <b style={{ fontSize: 15 }}>{r.name}</b>
                  <Pill color={FLOW_COLOR[r.flow_status] || 'var(--text-3)'}>{r.flow_status || 'not installed'}</Pill>
                  <span style={{ color: 'var(--text-3)', fontSize: 13 }}><Mail size={13} style={{ verticalAlign: '-2px' }} /> {r.emails} e-mails · <MessageCircle size={13} style={{ verticalAlign: '-2px' }} /> {r.whatsapps} WhatsApp</span>
                  <span style={{ flex: 1 }} />
                  {r.flow_id && <Link className="btn btn-secondary btn-sm" to={`/flows/${r.flow_id}`}>Edit steps</Link>}
                  {r.flow_id && (r.flow_status === 'active'
                    ? <button className="btn btn-secondary btn-sm" disabled={busy === r.key} onClick={() => act(r.key, () => admin.crmAutomation(r.key, false), () => 'Switched off. Leads already in the flow stop.')}>Switch off</button>
                    : <button className="btn btn-primary btn-sm" disabled={busy === r.key} onClick={async () => { if (await confirmAsk(`Switch on “${r.name}”? New matching leads will start receiving messages.`)) act(r.key, () => admin.crmAutomation(r.key, true), () => 'Switched on.') }}>Switch on</button>)}
                </div>
                <div style={{ color: 'var(--text-2)', fontSize: 14, margin: '6px 0 10px' }}>{r.summary}</div>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                  {r.steps.map((s, i) => (
                    <span key={i} className="pill" style={{ fontSize: 12.5, background: '#f3f6fb', borderRadius: 8, padding: '4px 9px' }} title={s.label}>
                      {s.type === 'email' ? <Mail size={12} style={{ verticalAlign: '-2px' }} /> : s.type === 'whatsapp' ? <MessageCircle size={12} style={{ verticalAlign: '-2px' }} /> : <ListTodo size={12} style={{ verticalAlign: '-2px' }} />} {WAIT(s.wait)}
                    </span>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
