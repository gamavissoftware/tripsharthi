import { useEffect, useState, useCallback } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { Kpi, Pill } from './AdminUi'
import { th, td, fmtDateTime, QUALITY_COLOR, SEVERITY_COLOR, PLAN_COLOR } from './adminUtil'

const FILTERS = [['', 'All'], ['attention', 'Needs attention'], ['connected', 'Connected'], ['not_connected', 'Not connected'], ['paused', 'Marketing paused']]
const WA_COLOR = { active: 'var(--success)', pending: 'var(--warning)', suspended: 'var(--danger)', none: 'var(--text-3)' }
const WA_LABEL = { none: 'not connected' }
const pct = (v) => (v === null || v === undefined ? '—' : `${v}%`)

export function QualityDot({ q, label }) {
  return <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><span style={{ width: 9, height: 9, borderRadius: '50%', background: QUALITY_COLOR[q] || 'var(--text-3)', display: 'inline-block' }} />{label ?? q}</span>
}

// ── Oversight: every workspace ───────────────────────────────────────────────────────────────────────────────────────
function WorkspacesTab() {
  const nav = useNavigate()
  const [f, setF] = useState({ q: '', filter: '' })
  const [d, setD] = useState(null)
  useEffect(() => {
    const t = setTimeout(() => admin.waOverview(f).then(setD).catch(e => toast.error('Could not load', e.message)), f.q ? 300 : 0)
    return () => clearTimeout(t)
  }, [f])
  const t = d?.totals
  return (
    <div>
      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
        <Kpi label="Connected" value={t ? `${t.connected}/${t.workspaces}` : '…'} sub={t ? `${t.not_connected} not connected · ${t.suspended} suspended` : ''} />
        <Kpi label="Need attention" value={t ? t.attention.critical + t.attention.warning : '…'} sub={t ? `${t.attention.critical} critical · ${t.attention.warning} warnings` : ''} accent={t && t.attention.critical > 0 ? 'var(--danger)' : undefined} />
        <Kpi label="Messages · 30 days" value={t ? t.sent.toLocaleString('en-IN') : '…'} sub={t ? `${pct(t.delivery_pct)} delivered · ${pct(t.failure_pct)} failed` : ''} />
        <Kpi label="Number quality" value={t ? `${t.quality.green} green` : '…'} sub={t ? `${t.quality.yellow} yellow · ${t.quality.red} red` : ''} accent={t && t.quality.red > 0 ? 'var(--danger)' : undefined} />
        <Kpi label="Templates" value={t ? t.templates_pending : '…'} sub={t ? `pending · ${t.templates_rejected} rejected` : ''} />
        <Kpi label="Meta cost · this month" value={t ? `₹${t.cost_this_month.toLocaleString('en-IN')}` : '…'} sub="from synced billing" />
      </div>
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 12, alignItems: 'center' }}>
        {FILTERS.map(([k, label]) => <button key={k} className={'btn btn-sm ' + (f.filter === k ? 'btn-primary' : 'btn-ghost')} onClick={() => setF({ ...f, filter: k })}>{label}</button>)}
        <input className="form-input" style={{ maxWidth: 240, marginLeft: 'auto' }} placeholder="Search workspace…" value={f.q} onChange={e => setF({ ...f, q: e.target.value })} />
      </div>
      <div className="card" style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Workspace', 'WhatsApp', 'Quality', 'Sent', 'Delivered', 'Failed', 'Templates', 'Cost', 'Attention'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {!d && <tr><td colSpan={9} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Loading…</td></tr>}
            {d?.rows.length === 0 && <tr><td colSpan={9} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No workspaces match.</td></tr>}
            {d?.rows.map(r => (
              <tr key={r.id} style={{ cursor: 'pointer' }} onClick={() => nav(`/admin/whatsapp/${r.id}`)}>
                <td style={td}><Link to={`/admin/whatsapp/${r.id}`} onClick={e => e.stopPropagation()}><b>{r.name}</b></Link> <Pill color={PLAN_COLOR[r.plan]}>{r.plan}</Pill></td>
                <td style={td}><Pill color={WA_COLOR[r.whatsapp]}>{WA_LABEL[r.whatsapp] || r.whatsapp}</Pill></td>
                <td style={td}>{r.quality === 'none' ? <span style={{ color: 'var(--text-3)' }}>—</span> : <QualityDot q={r.quality} />}</td>
                <td style={td}>{r.sent.toLocaleString('en-IN')}{r.marketing > 0 && <div style={{ fontSize: 11.5, color: 'var(--text-3)' }}>{r.marketing.toLocaleString('en-IN')} marketing</div>}</td>
                <td style={td}>{r.sent > 0 ? pct(Math.round(r.delivered * 1000 / r.sent) / 10) : '—'}</td>
                <td style={{ ...td, color: r.failure_pct >= 10 ? 'var(--danger)' : undefined, fontWeight: r.failure_pct >= 10 ? 700 : 400 }}>{pct(r.failure_pct)}</td>
                <td style={td}>{r.templates.approved}✓ {r.templates.pending > 0 && <span style={{ color: 'var(--warning)' }}>{r.templates.pending} pending </span>}{r.templates.rejected > 0 && <span style={{ color: 'var(--danger)' }}>{r.templates.rejected} rejected</span>}</td>
                <td style={td}>{r.cost_this_month != null ? `₹${r.cost_this_month.toLocaleString('en-IN')}` : '—'}</td>
                <td style={td}>{r.flags.length === 0 ? <span style={{ color: 'var(--text-3)' }}>—</span> : r.flags.map(fl => <div key={fl.code} style={{ fontSize: 12 }}><Pill color={SEVERITY_COLOR[fl.severity]}>{fl.severity}</Pill> <span title={fl.text}>{fl.text.length > 46 ? fl.text.slice(0, 44) + '…' : fl.text}</span></div>)}</td>
              </tr>))}
          </tbody>
        </table>
      </div>
      <p style={{ fontSize: 12.5, color: 'var(--text-3)', marginTop: 8 }}>Counts only. Message text, contact details and access tokens are never shown here. Failure rates need 50+ messages to count as an alert.</p>
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
    <div style={{ position: 'relative', maxWidth: 420 }}>
      <input className="form-input" value={q} onChange={e => setQ(e.target.value)} placeholder={current ? 'Change workspace — search by name or email' : 'Search the workspace that will run marketing'} />
      {found.length > 0 && <div className="card" style={{ position: 'absolute', zIndex: 5, left: 0, right: 0, top: '100%', maxHeight: 240, overflow: 'auto' }}>
        {found.slice(0, 8).map(t => <button type="button" key={t.id} onClick={() => choose(t.id)} style={{ display: 'block', width: '100%', textAlign: 'left', padding: '8px 12px', background: 'none', border: 0, borderBottom: '1px solid var(--border)', cursor: 'pointer' }}><b>{t.name}</b> <span style={{ color: 'var(--text-3)', fontSize: 12 }}>{t.owner_email} · {t.plan}</span></button>)}
      </div>}
      {current && <button className="btn btn-ghost btn-sm" style={{ marginTop: 6 }} onClick={() => choose(null)}>Clear</button>}
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
    if (!window.confirm(`Add the ${s.count} opted-in people in “${s.label}” to the marketing workspace? Nobody is messaged — this only adds them as contacts (and switches off anyone who has withdrawn consent).`)) return
    setBusy(s.key)
    try { const r = await admin.waSync(s.key); toast.success('Segment synced', `${r.added} added · ${r.already_there} already there${r.skipped ? ` · ${r.skipped} skipped (replied STOP)` : ''}${r.withdrawn ? ` · ${r.withdrawn} withdrawn` : ''}`); load() }
    catch (e) { toast.error('Not synced', e.message) } finally { setBusy('') }
  }
  if (!d) return <p style={{ color: 'var(--text-3)' }}>Loading…</p>
  const st = d.status
  return (
    <div style={{ display: 'grid', gap: 14 }}>
      <div className="card card-body">
        <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', marginBottom: 8 }}>
          <div>
            <div style={{ fontWeight: 700 }}>TripSarthi marketing workspace {st.ready && <Pill color="var(--success)">ready to send</Pill>}</div>
            <div style={{ fontSize: 13, color: 'var(--text-3)', maxWidth: 640 }}>TripSarthi's own WhatsApp campaigns run inside one ordinary workspace, so every WhatsApp rule (approved templates, 24-hour window, STOP, quality) applies exactly as it does for customers. Choose which workspace that is; its WhatsApp number and templates are what customers receive.</div>
          </div>
          {st.workspace && <a className="btn btn-primary" href={st.campaign_url} target="_blank" rel="noreferrer">Open Campaigns in the app ↗</a>}
        </div>
        <WorkspaceChooser current={st.workspace} onChosen={load} />
        <div style={{ marginTop: 12, display: 'grid', gap: 4 }}>
          {st.checks.map(c => <div key={c.key} style={{ fontSize: 13.5, color: c.ok ? 'var(--text)' : 'var(--danger)' }}>{c.ok ? '✓' : '✗'} {c.text}</div>)}
        </div>
        {st.workspace && <div style={{ marginTop: 8, fontSize: 12.5, color: 'var(--text-3)' }}>{st.contacts.opted_in} opted-in contacts ({st.contacts.platform} added from TripSarthi segments) · {st.numbers.map(n => n.number).join(', ') || 'no number'}</div>}
      </div>

      <div className="card">
        <div style={{ padding: '14px 16px 4px', fontWeight: 700 }}>Audiences <span style={{ fontWeight: 400, color: 'var(--text-3)', fontSize: 13 }}>— only people who ticked “WhatsApp me about TripSarthi” and gave a number</span></div>
        <div style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Segment', 'People', 'Last added to marketing', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {d.segments.map(s => (
              <tr key={s.key}>
                <td style={td}><b>{s.label}</b><div style={{ fontSize: 12, color: 'var(--text-3)', maxWidth: 420 }}>{s.description}</div>
                  {pv[s.key] && <div style={{ fontSize: 12.5, marginTop: 6, background: 'var(--bg)', borderRadius: 8, padding: '6px 10px' }}>{pv[s.key].sample.length === 0 ? 'No one yet.' : pv[s.key].sample.map((m, i) => <div key={i}>{m.name} · {m.phone} <span style={{ color: 'var(--text-3)' }}>({m.context})</span></div>)}{pv[s.key].count > pv[s.key].sample.length && <div style={{ color: 'var(--text-3)' }}>…and {pv[s.key].count - pv[s.key].sample.length} more</div>}</div>}
                </td>
                <td style={td}><b style={{ fontSize: 18 }}>{s.count}</b></td>
                <td style={td}>{s.last_sync ? <span style={{ fontSize: 12.5 }}>{fmtDateTime(s.last_sync.at)}<br /><span style={{ color: 'var(--text-3)' }}>+{s.last_sync.added} new · {s.last_sync.already_there} existing{s.last_sync.skipped ? ` · ${s.last_sync.skipped} STOP` : ''}{s.last_sync.withdrawn ? ` · ${s.last_sync.withdrawn} withdrawn` : ''}</span></span> : <span style={{ color: 'var(--text-3)' }}>never</span>}</td>
                <td style={{ ...td, whiteSpace: 'nowrap' }}><button className="btn btn-ghost btn-sm" onClick={() => preview(s.key)}>{pv[s.key] ? 'Hide' : 'Preview'}</button>{' '}<button className="btn btn-primary btn-sm" disabled={!st.workspace || s.count === 0 || busy === s.key} onClick={() => sync(s)}>{busy === s.key ? 'Adding…' : 'Add to marketing'}</button></td>
              </tr>))}
          </tbody>
        </table></div>
        <p style={{ fontSize: 12.5, color: 'var(--text-3)', margin: '8px 16px 14px' }}>Adding people never sends anything and never starts an automation. In the marketing workspace, filter a campaign by the tag <code>ts-owners</code>, <code>ts-owners-free</code>, <code>ts-owners-lapsed</code>, <code>ts-owners-trial-ending</code>, <code>ts-partners</code> or <code>ts-website-leads</code>. Re-run “Add to marketing” before each campaign — it also switches off anyone who has since withdrawn consent.</p>
      </div>

      <div className="card">
        <div style={{ padding: '14px 16px 4px', fontWeight: 700 }}>Recent campaigns in the marketing workspace</div>
        <div style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Campaign', 'Template', 'Status', 'Audience', 'Sent', 'Delivered', 'Failed', 'Created'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {d.campaigns.length === 0 && <tr><td colSpan={8} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No campaigns yet.</td></tr>}
            {d.campaigns.map(c => <tr key={c.id}><td style={td}><b>{c.name}</b></td><td style={td}>{c.template || '—'}</td><td style={td}>{c.status}</td><td style={td}>{c.total_contacts}</td><td style={td}>{c.sent}</td><td style={td}>{c.delivered}</td><td style={{ ...td, color: Number(c.failed) > 0 ? 'var(--danger)' : undefined }}>{c.failed}</td><td style={td}>{fmtDateTime(c.created_at)}</td></tr>)}
          </tbody>
        </table></div>
      </div>
    </div>
  )
}

export default function AdminWhatsappPage() {
  const [tab, setTab] = useState('workspaces')
  return (
    <div className="page">
      <div className="page-header"><h1 className="page-title">WhatsApp</h1></div>
      <div style={{ display: 'flex', gap: 6, marginBottom: 16, flexWrap: 'wrap' }}>
        {[['workspaces', 'Workspaces'], ['marketing', 'Our marketing']].map(([k, label]) => <button key={k} className={'btn btn-sm ' + (tab === k ? 'btn-primary' : 'btn-ghost')} onClick={() => setTab(k)}>{label}</button>)}
      </div>
      {tab === 'workspaces' ? <WorkspacesTab /> : <MarketingTab />}
    </div>
  )
}
