import { useEffect, useMemo, useState, useCallback } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { MessageCircle, Plug, AlertTriangle, Gauge, FileText, IndianRupee, Users, Megaphone, CheckCheck, Search, Send, Inbox } from 'lucide-react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { Kpi, Pill } from './AdminUi'
import { th, td, fmtDateTime, ago, QUALITY_COLOR, SEVERITY_COLOR, PLAN_COLOR } from './adminUtil'
import { confirmAsk } from '../../components/confirmAsk'

const WA_COLOR = { active: 'var(--success)', pending: 'var(--warning)', suspended: 'var(--danger)', none: 'var(--text-3)' }
const WA_LABEL = { none: 'not connected' }
const pct = (v) => (v === null || v === undefined ? '—' : `${v}%`)
const n0 = (v) => Number(v || 0).toLocaleString('en-IN')
const rs = (v) => `₹${Number(v || 0).toLocaleString('en-IN')}`
const ratio = (a, b) => (Number(b) > 0 ? Math.round(Number(a) * 1000 / Number(b)) / 10 : null)

export function QualityDot({ q, label }) {
  return <span className="wa-q" style={{ '--c': QUALITY_COLOR[q] || 'var(--text-3)' }}><i />{label ?? q}</span>
}

export function Meter({ value, tone }) {
  return <span className="wa-meter"><i className={tone || ''} style={{ width: `${Math.max(0, Math.min(100, Number(value) || 0))}%` }} /></span>
}

const CAMPAIGN_COLOR = { completed: 'var(--success)', sent: 'var(--success)', sending: 'var(--warning)', running: 'var(--warning)', scheduled: 'var(--primary)', draft: 'var(--text-3)', failed: 'var(--danger)', paused: 'var(--warning)', cancelled: 'var(--text-3)' }

/** Recent campaigns of one workspace: audience, sent, delivered share and failures, with a delivery bar. */
export function CampaignTable({ rows, empty }) {
  return (
    <div style={{ overflowX: 'auto' }}>
      <table className="cu-table plain" style={{ minWidth: 760 }}>
        <thead><tr>{['Campaign', 'Status', 'Audience', 'Sent', 'Delivered', 'Failed', 'Created'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={7} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>{empty}</td></tr>}
          {rows.map(c => {
            const dl = ratio(c.delivered, c.sent)
            return (
              <tr key={c.id}>
                <td style={td}><div className="cu-name">{c.name}</div><div className="cu-sub">{c.template ? `Template: ${c.template}` : 'No template'}</div></td>
                <td style={td}><Pill color={CAMPAIGN_COLOR[c.status]}>{c.status}</Pill></td>
                <td style={td}>{n0(c.total_contacts)}</td>
                <td style={td}>{n0(c.sent)}</td>
                <td style={td}>{dl === null ? '—' : <>{dl}%<Meter value={dl} tone="blue" /></>}</td>
                <td style={{ ...td, color: Number(c.failed) > 0 ? 'var(--danger)' : undefined, fontWeight: Number(c.failed) > 0 ? 700 : 400 }}>{n0(c.failed)}</td>
                <td style={td}>{fmtDateTime(c.created_at)}</td>
              </tr>)
          })}
        </tbody>
      </table>
    </div>
  )
}

// ── Oversight: every workspace ───────────────────────────────────────────────────────────────────────────────────────
function WorkspacesTab() {
  const nav = useNavigate()
  const [q, setQ] = useState('')
  const [filter, setFilter] = useState('')
  const [sort, setSort] = useState('attention')
  const [d, setD] = useState(null)
  // one fetch per search text; the chips filter the rows already loaded so their counts stay visible
  useEffect(() => {
    const t = setTimeout(() => admin.waOverview({ q }).then(setD).catch(e => toast.error('Could not load', e.message)), q ? 300 : 0)
    return () => clearTimeout(t)
  }, [q])
  const t = d?.totals
  const counts = useMemo(() => {
    const rows = d?.rows || []
    return { '': rows.length, attention: rows.filter(r => r.attention).length, connected: rows.filter(r => r.whatsapp === 'active').length, not_connected: rows.filter(r => r.whatsapp === 'none').length, paused: rows.filter(r => r.paused).length }
  }, [d])
  const shown = useMemo(() => {
    const rows = (d?.rows || []).filter(r => filter === '' ? true : filter === 'attention' ? r.attention : filter === 'connected' ? r.whatsapp === 'active' : filter === 'not_connected' ? r.whatsapp === 'none' : r.paused)
    const by = { attention: null, sent: (a, b) => b.sent - a.sent, failed: (a, b) => (b.failure_pct ?? -1) - (a.failure_pct ?? -1), cost: (a, b) => (b.cost_this_month ?? 0) - (a.cost_this_month ?? 0), name: (a, b) => a.name.localeCompare(b.name) }[sort]
    return by ? [...rows].sort(by) : rows
  }, [d, filter, sort])
  const CHIPS = [['', 'All'], ['attention', 'Needs attention'], ['connected', 'Connected'], ['not_connected', 'Not connected'], ['paused', 'Marketing paused']]
  const bad = t && t.attention.critical > 0
  return (
    <div>
      <div className="cu-kpis">
        <Kpi icon={Plug} tone="#1a9d57" label="Connected" value={t ? `${t.connected}/${t.workspaces}` : '…'} sub={t ? `${t.not_connected} not connected · ${t.suspended} suspended` : ''} />
        <Kpi icon={AlertTriangle} tone={bad ? '#e5484d' : '#f59e0b'} label="Need attention" value={t ? t.attention.critical + t.attention.warning : '…'} sub={t ? `${t.attention.critical} critical · ${t.attention.warning} warnings` : ''} accent={bad ? 'var(--danger)' : undefined} />
        <Kpi icon={MessageCircle} tone="#0a6cc4" label="Messages · 30 days" value={t ? n0(t.sent) : '…'} sub={t ? `${pct(t.delivery_pct)} delivered · ${pct(t.failure_pct)} failed · ${n0(t.marketing)} marketing` : ''} />
        <Kpi icon={Gauge} tone={t && t.quality.red > 0 ? '#e5484d' : '#12a89e'} label="Number quality" value={t ? `${t.quality.green} green` : '…'} sub={t ? `${t.quality.yellow} yellow · ${t.quality.red} red` : ''} accent={t && t.quality.red > 0 ? 'var(--danger)' : undefined} />
        <Kpi icon={FileText} tone="#f98f26" label="Templates waiting" value={t ? t.templates_pending : '…'} sub={t ? `${t.templates_rejected} rejected by Meta` : ''} />
        <Kpi icon={IndianRupee} tone="#0e8f8c" label="Meta cost · this month" value={t ? rs(t.cost_this_month) : '…'} sub="from synced billing" />
      </div>

      <div className="cu-chips">
        {CHIPS.map(([k, label]) => <button key={k} className={'cu-chip' + (filter === k ? ' on' : '')} onClick={() => setFilter(k)}>{label} <b>{d ? counts[k] : '…'}</b></button>)}
      </div>
      <div className="cu-filters">
        <label className="cu-search"><Search size={16} /><input placeholder="Search a workspace by name or #id" value={q} onChange={e => setQ(e.target.value)} /></label>
        <select className="form-select" style={{ maxWidth: 210 }} value={sort} onChange={e => setSort(e.target.value)} aria-label="Sort">
          <option value="attention">Most urgent first</option><option value="sent">Most messages</option><option value="failed">Highest failure rate</option><option value="cost">Highest Meta cost</option><option value="name">Name A–Z</option>
        </select>
      </div>

      <div className="card" style={{ overflowX: 'auto' }}>
        <table className="cu-table">
          <thead><tr>{['Workspace', 'WhatsApp', 'Quality', 'Sent · 30 d', 'Delivered', 'Failed', 'Templates', 'Meta cost'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {!d && <tr><td colSpan={8} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Loading…</td></tr>}
            {d && shown.length === 0 && <tr><td colSpan={8} style={{ ...td, textAlign: 'center', color: 'var(--text-3)', padding: 28 }}>{d.rows.length === 0 ? 'No workspaces match this search.' : 'Nothing in this view — good news.'}</td></tr>}
            {shown.map(r => {
              const dl = ratio(r.delivered, r.sent)
              return (
                <tr key={r.id} onClick={() => nav(`/admin/whatsapp/${r.id}`)}>
                  <td style={td}>
                    <div className="cu-name"><Link to={`/admin/whatsapp/${r.id}`} onClick={e => e.stopPropagation()}>{r.name}</Link> <Pill color={PLAN_COLOR[r.plan]}>{r.plan}</Pill></div>
                    {r.flags.length > 0 && <div className="cu-flags">{r.flags.map(fl => <span key={fl.code} className="cu-flag" style={{ '--c': SEVERITY_COLOR[fl.severity] }} title={fl.text}>{fl.text.length > 52 ? fl.text.slice(0, 50) + '…' : fl.text}</span>)}</div>}
                  </td>
                  <td style={td}><Pill color={WA_COLOR[r.whatsapp]}>{WA_LABEL[r.whatsapp] || r.whatsapp}</Pill></td>
                  <td style={td}>{r.quality === 'none' ? <span style={{ color: 'var(--text-3)' }}>—</span> : <QualityDot q={r.quality} />}</td>
                  <td style={td}><b>{n0(r.sent)}</b>{r.marketing > 0 && <div className="cu-sub">{n0(r.marketing)} marketing</div>}</td>
                  <td style={td}>{dl === null ? '—' : <>{dl}%<Meter value={dl} /></>}</td>
                  <td style={{ ...td, color: r.failure_pct >= 10 ? 'var(--danger)' : undefined, fontWeight: r.failure_pct >= 10 ? 700 : 400 }}>{pct(r.failure_pct)}{r.failure_pct > 0 && <Meter value={Math.min(100, r.failure_pct * 2)} tone={r.failure_pct >= 10 ? 'bad' : 'warn'} />}</td>
                  <td style={td}>{r.templates.approved}✓{r.templates.pending > 0 && <span style={{ color: 'var(--warning)' }}> · {r.templates.pending} pending</span>}{r.templates.rejected > 0 && <span style={{ color: 'var(--danger)' }}> · {r.templates.rejected} rejected</span>}</td>
                  <td style={td}>{r.cost_this_month != null ? rs(r.cost_this_month) : '—'}</td>
                </tr>)
            })}
          </tbody>
        </table>
      </div>
      <p style={{ fontSize: 12.5, color: 'var(--text-3)', marginTop: 8 }}>Counts only — message text, contact details and access tokens are never shown here. A failure rate needs 50+ messages before it counts as an alert.</p>
    </div>
  )
}

// ── TripSarthi's own marketing ───────────────────────────────────────────────────────────────────────────────────────
function WorkspaceChooser({ current, onChosen }) {
  const [q, setQ] = useState('')
  const [found, setFound] = useState([])
  useEffect(() => {
    if (q.trim().length < 2) return undefined
    const t = setTimeout(() => admin.tenants({ q }).then(r => setFound(r.rows || [])).catch(() => setFound([])), 300)
    return () => clearTimeout(t)
  }, [q])
  async function choose(id) { try { await admin.waSetMarketing(id); toast.success(id ? 'Marketing workspace set' : 'Marketing workspace cleared'); setQ(''); setFound([]); onChosen() } catch (e) { toast.error('Not changed', e.message) } }
  return (
    <div style={{ position: 'relative', maxWidth: 440 }}>
      <label className="cu-search"><Search size={16} /><input value={q} onChange={e => setQ(e.target.value)} placeholder={current ? 'Change workspace — search by name or e-mail' : 'Search the workspace that will run marketing'} /></label>
      {found.length > 0 && q.trim().length >= 2 && <div className="card" style={{ position: 'absolute', zIndex: 5, left: 0, right: 0, top: '100%', marginTop: 4, maxHeight: 260, overflow: 'auto' }}>
        {found.slice(0, 8).map(t => <button type="button" key={t.id} onClick={() => choose(t.id)} style={{ display: 'block', width: '100%', textAlign: 'left', padding: '9px 14px', background: 'none', border: 0, borderBottom: '1px solid var(--border)', cursor: 'pointer' }}><b>{t.name}</b> <span style={{ color: 'var(--text-3)', fontSize: 12 }}>{t.owner_email} · {t.plan}</span></button>)}
      </div>}
      {current && <button className="btn btn-ghost btn-sm" style={{ marginTop: 8 }} onClick={() => choose(null)}>Clear workspace</button>}
    </div>
  )
}

function MarketingTab() {
  const [d, setD] = useState(null)
  const [pv, setPv] = useState({})
  const [busy, setBusy] = useState('')
  const load = useCallback(() => admin.waMarketing().then(setD).catch(e => toast.error('Could not load', e.message)), [])
  useEffect(() => { load() }, [load])
  async function preview(key) {
    if (pv[key]) { setPv(p => { const n = { ...p }; delete n[key]; return n }); return }
    try { const r = await admin.waSegment(key); setPv(p => ({ ...p, [key]: r })) } catch (e) { toast.error('Could not preview', e.message) }
  }
  async function sync(s) {
    if (!(await confirmAsk(`Add the ${s.count} opted-in people in “${s.label}” to the marketing workspace? Nobody is messaged — this only adds them as contacts (and switches off anyone who has withdrawn consent).`))) return
    setBusy(s.key)
    try { const r = await admin.waSync(s.key); toast.success('Segment synced', `${r.added} added · ${r.already_there} already there${r.skipped ? ` · ${r.skipped} skipped (replied STOP)` : ''}${r.withdrawn ? ` · ${r.withdrawn} withdrawn` : ''}`); load() }
    catch (e) { toast.error('Not synced', e.message) } finally { setBusy('') }
  }
  if (!d) return <p style={{ color: 'var(--text-3)' }}>Loading…</p>
  const st = d.status
  const okCount = st.checks.filter(c => c.ok).length
  const people = d.segments.reduce((a, s) => a + s.count, 0)
  const sent = d.campaigns.reduce((a, c) => a + Number(c.sent || 0), 0)
  const delivered = d.campaigns.reduce((a, c) => a + Number(c.delivered || 0), 0)
  const failed = d.campaigns.reduce((a, c) => a + Number(c.failed || 0), 0)
  const firstFail = st.checks.find(c => !c.ok)
  return (
    <div style={{ display: 'grid', gap: 14 }}>
      <div className={'wa-banner ' + (st.ready ? 'ok' : st.workspace ? '' : 'bad')}>
        <div className="grow">
          {st.ready
            ? <><b>Ready to send.</b> {st.workspace.name} can run WhatsApp campaigns for TripSarthi.</>
            : st.workspace ? <><b>Not ready yet:</b> {firstFail?.text}.</> : <><b>No marketing workspace chosen.</b> Pick the workspace whose WhatsApp number TripSarthi will use.</>}
        </div>
        {st.workspace && <><Link className="btn btn-ghost btn-sm" to="/admin/whatsapp/inbox">Inbox</Link><Link className="btn btn-primary btn-sm" to="/admin/whatsapp/campaigns">Campaigns</Link></>}
      </div>

      <div className="cu-kpis" style={{ margin: 0 }}>
        <Kpi icon={Users} tone="#0a6cc4" label="Consenting people" value={n0(people)} sub="across the audiences below" />
        <Kpi icon={Inbox} tone="#12a89e" label="Contacts in workspace" value={st.workspace ? n0(st.contacts.opted_in) : '—'} sub={st.workspace ? `${n0(st.contacts.platform)} added from TripSarthi segments` : 'choose a workspace first'} />
        <Kpi icon={Megaphone} tone="#f98f26" label="Campaigns" value={d.campaigns.length} sub={d.campaigns.length ? `latest ${ago(d.campaigns[0].created_at)}` : 'none yet'} />
        <Kpi icon={Send} tone="#1a9d57" label="Messages sent" value={n0(sent)} sub="across these campaigns" />
        <Kpi icon={CheckCheck} tone="#0e8f8c" label="Delivered" value={pct(ratio(delivered, sent))} sub={failed ? `${n0(failed)} failed` : 'no failures'} accent={failed > 0 && ratio(failed, sent) >= 10 ? 'var(--danger)' : undefined} />
      </div>

      <div className="wa-ready">
        <div className="card card-body">
          <div style={{ fontWeight: 800, color: '#0a1f44', fontSize: 16 }}>Marketing workspace</div>
          <div style={{ fontSize: 13.5, color: 'var(--text-3)', margin: '4px 0 12px', maxWidth: 560 }}>TripSarthi's own WhatsApp campaigns run inside one ordinary workspace, so every WhatsApp rule (approved templates, 24-hour window, STOP, quality) applies exactly as it does for customers. Its number and templates are what people receive.</div>
          <WorkspaceChooser current={st.workspace} onChosen={load} />
          {st.workspace && <div style={{ marginTop: 12, fontSize: 13, color: 'var(--text-3)' }}>Using <b style={{ color: '#0a1f44' }}>{st.workspace.name}</b> · {st.numbers.map(n => n.number).join(', ') || 'no number connected'}</div>}
        </div>
        <div className="card card-body">
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
            <div style={{ fontWeight: 800, color: '#0a1f44', fontSize: 16 }}>Readiness</div>
            <Pill color={st.ready ? 'var(--success)' : 'var(--warning)'}>{okCount} of {st.checks.length}</Pill>
          </div>
          <Meter value={okCount * 100 / Math.max(1, st.checks.length)} tone={st.ready ? '' : 'warn'} />
          <div style={{ marginTop: 8 }}>
            {st.checks.map(c => <div key={c.key} className={'wa-check' + (c.ok ? '' : ' no')}><span>{c.ok ? '✓' : '!'}</span>{c.text}</div>)}
          </div>
        </div>
      </div>

      <div>
        <div style={{ fontWeight: 800, color: '#0a1f44', fontSize: 16, marginBottom: 2 }}>Audiences</div>
        <div style={{ fontSize: 13, color: 'var(--text-3)', marginBottom: 10 }}>Only people who ticked “WhatsApp me about TripSarthi” and gave a number. Adding people never sends anything and never starts an automation.</div>
        <div className="card" style={{ overflowX: 'auto' }}>
          <table className="cu-table plain" style={{ minWidth: 820 }}>
            <thead><tr>{['Segment', 'People', 'Last added to marketing', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
            <tbody>
              {d.segments.map(s => (
                <tr key={s.key}>
                  <td style={td}><div className="cu-name">{s.label}</div><div className="cu-sub" style={{ maxWidth: 430 }}>{s.description}</div>
                    {pv[s.key] && <div style={{ fontSize: 12.5, marginTop: 8, background: '#f3f8fd', borderRadius: 10, padding: '8px 12px' }}>{pv[s.key].sample.length === 0 ? 'No one yet.' : pv[s.key].sample.map((m, i) => <div key={i}>{m.name} · {m.phone} <span style={{ color: 'var(--text-3)' }}>({m.context})</span></div>)}{pv[s.key].count > pv[s.key].sample.length && <div style={{ color: 'var(--text-3)' }}>…and {pv[s.key].count - pv[s.key].sample.length} more</div>}</div>}
                  </td>
                  <td style={td}><b style={{ fontSize: 20, color: '#0a1f44' }}>{s.count}</b></td>
                  <td style={td}>{s.last_sync ? <span style={{ fontSize: 13 }}>{fmtDateTime(s.last_sync.at)} <span style={{ color: 'var(--text-3)' }}>({ago(s.last_sync.at)})</span><br /><span style={{ color: 'var(--text-3)', fontSize: 12.5 }}>+{s.last_sync.added} new · {s.last_sync.already_there} existing{s.last_sync.skipped ? ` · ${s.last_sync.skipped} STOP` : ''}{s.last_sync.withdrawn ? ` · ${s.last_sync.withdrawn} withdrawn` : ''}</span></span> : <span style={{ color: 'var(--text-3)' }}>never</span>}</td>
                  <td style={{ ...td, whiteSpace: 'nowrap', textAlign: 'right' }}><button className="btn btn-ghost btn-sm" onClick={() => preview(s.key)}>{pv[s.key] ? 'Hide' : 'Preview'}</button>{' '}<button className="btn btn-primary btn-sm" disabled={!st.workspace || s.count === 0 || busy === s.key} title={!st.workspace ? 'Choose a marketing workspace first' : undefined} onClick={() => sync(s)}>{busy === s.key ? 'Adding…' : 'Add to marketing'}</button></td>
                </tr>))}
            </tbody>
          </table>
        </div>
        <p style={{ fontSize: 12.5, color: 'var(--text-3)', margin: '8px 2px 0' }}>In the Campaigns page, filter a campaign by the tag <code>ts-owners</code>, <code>ts-owners-free</code>, <code>ts-owners-lapsed</code>, <code>ts-owners-trial-ending</code>, <code>ts-partners</code> or <code>ts-website-leads</code>. Re-run “Add to marketing” before each campaign — it also switches off anyone who has since withdrawn consent.</p>
      </div>

      <div>
        <div style={{ fontWeight: 800, color: '#0a1f44', fontSize: 16, marginBottom: 10 }}>Recent campaigns in the marketing workspace</div>
        <div className="card"><CampaignTable rows={d.campaigns} empty="No campaigns yet. Add an audience above, then create one in the app's Campaigns page." /></div>
      </div>
    </div>
  )
}

export default function AdminWhatsappPage() {
  const [tab, setTab] = useState('workspaces')
  return (
    <div className="page">
      <div className="page-header">
        <div><h1 className="page-title">WhatsApp</h1><div style={{ color: 'var(--text-3)', fontSize: 13.5 }}>How every customer's WhatsApp is doing, and TripSarthi's own consent-based marketing.</div></div>
      </div>
      <div className="wa-tabs">
        {[['workspaces', 'Customer workspaces', MessageCircle], ['marketing', 'Our marketing', Megaphone]].map(([k, label, Icon]) => <button key={k} className={tab === k ? 'on' : ''} onClick={() => setTab(k)}><Icon size={16} />{label}</button>)}
      </div>
      {tab === 'workspaces' ? <WorkspacesTab /> : <MarketingTab />}
    </div>
  )
}
