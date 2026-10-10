import { useState, useEffect, useCallback } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Check, RefreshCw } from 'lucide-react'
import { travel, inr, TRIP_STATUS } from '../api/travel'
import { crm } from '../api/crm'
import { api } from '../api/client'
import { toast } from '../components/Toast'
import TargetBar from '../components/travel/TargetBar'
import CrmDashboardPage from './CrmDashboardPage'
import './HomeDashboardPage.css'

const STAGE_COLOR = { enquiry: '#0a6cc4', quoted: '#d99a14', negotiating: '#ee7d1a', booked: '#1f8f55', travelling: '#0369a1' }
const TYPE_LABEL = { call: 'Call', whatsapp: 'WhatsApp', email: 'Email', meeting: 'Meeting', todo: 'To-do' }
const nice = (s) => String(s || 'unknown').replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

const pctChange = (v, p) => (p > 0 ? Math.round(((v - p) / p) * 100) : null)
const parseLocal = (s) => (s ? new Date(String(s).replace(' ', 'T')) : null)
const daysFromToday = (d) => { const a = new Date(d + 'T00:00'); const t = new Date(); t.setHours(0, 0, 0, 0); return Math.round((a - t) / 86400000) }
const dueText = (d) => { const n = daysFromToday(d); return n === 0 ? 'today' : n === 1 ? 'tomorrow' : n === -1 ? 'yesterday' : n < 0 ? `${-n} days ago` : `in ${n} days` }
const shortDate = (d) => new Date(d + 'T00:00').toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })
const timeOf = (s) => { const d = parseLocal(s); return d ? d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit' }) : '' }
const monthName = (m) => (m ? new Date(m + '-01T00:00').toLocaleDateString('en-IN', { month: 'long' }) : '')
const greeting = () => { const h = new Date().getHours(); return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening' }

function Kpi({ label, value, prev, money = true, sub, to }) {
  const ch = pctChange(Number(value), Number(prev))
  const body = (
    <div className="hd-kpi">
      <div className="hd-kpi-label">{label}</div>
      <div className="hd-kpi-val">{money ? inr(value, true) : value}</div>
      <div className="hd-kpi-foot">
        {ch === null ? <span className="hd-delta flat">{Number(prev) === 0 && Number(value) > 0 ? 'new this month' : '—'}</span>
          : <span className={'hd-delta ' + (ch > 0 ? 'up' : ch < 0 ? 'down' : 'flat')}>{ch > 0 ? '▲' : ch < 0 ? '▼' : '•'} {Math.abs(ch)}%</span>}
        <span title={`Last month: ${money ? inr(prev) : prev}`}>vs last month</span>
        {sub && <span>· {sub}</span>}
      </div>
    </div>
  )
  return to ? <Link to={to} style={{ textDecoration: 'none', color: 'inherit' }}>{body}</Link> : body
}

function Card({ title, link, to, children }) {
  return (
    <section className="hd-card">
      <div className="hd-card-h"><h3>{title}</h3>{link && <Link to={to}>{link}</Link>}</div>
      <div className="hd-card-b">{children}</div>
    </section>
  )
}

function AttentionStrip({ a }) {
  const chips = [
    a.overdue_tasks > 0 && { k: 'ot', tone: 'bad', n: a.overdue_tasks, t: 'overdue tasks', to: '/tasks' },
    a.payments_overdue.count > 0 && { k: 'po', tone: 'bad', n: a.payments_overdue.count, t: `payments overdue · ${inr(a.payments_overdue.amount, true)}`, to: '/bookings' },
    a.docs_pending_7d > 0 && { k: 'dp', tone: 'bad', n: a.docs_pending_7d, t: 'departures this week with documents pending', to: '/checklists' },
    a.tasks_today > 0 && { k: 'td', tone: 'warn', n: a.tasks_today, t: 'tasks due later today', to: '/tasks' },
    a.no_activity > 0 && { k: 'na', tone: 'warn', n: a.no_activity, t: 'open trips with no next step', to: '/trips' },
    a.quotes_to_chase > 0 && { k: 'qc', tone: 'warn', n: a.quotes_to_chase, t: 'quotes viewed but not accepted', to: '/trips' },
    a.payments_week.count > 0 && { k: 'pw', tone: 'warn', n: a.payments_week.count, t: `payments due this week · ${inr(a.payments_week.amount, true)}`, to: '/bookings' },
    a.departures_7d > 0 && { k: 'd7', tone: 'ok', n: a.departures_7d, t: 'departures in the next 7 days', to: '/departures' },
  ].filter(Boolean)
  if (!chips.length) return <div className="hd-attn"><span className="hd-chip ok">✓ All clear — nothing needs attention right now</span></div>
  return <div className="hd-attn">{chips.map(c => <Link key={c.k} to={c.to} className={'hd-chip ' + c.tone}><b>{c.n}</b> {c.t}</Link>)}</div>
}

function Pipeline({ rows }) {
  const max = Math.max(1, ...rows.map(r => r.count))
  const any = rows.some(r => r.count > 0)
  if (!any) return <div className="hd-empty">No trips in the pipeline yet. <Link to="/trips">Create the first enquiry →</Link></div>
  return rows.map(r => (
    <Link key={r.status} to="/trips" className="hd-stage">
      <span className="hd-stage-name">{TRIP_STATUS[r.status]?.label || r.status}</span>
      <span className="hd-bar"><i style={{ width: `${Math.max(r.count ? 6 : 0, (r.count / max) * 100)}%`, background: STAGE_COLOR[r.status] }} /><span>{r.count}</span></span>
      <span className="hd-stage-val">{r.value ? inr(r.value, true) : '—'}{r.stale > 0 && <span className="hd-stale">{r.stale} untouched 7d+</span>}</span>
    </Link>
  ))
}

function TrendChart({ data }) {
  const W = 520, H = 190, padL = 6, padB = 22, padT = 14
  const max = Math.max(1, ...data.flatMap(d => [d.revenue, d.collected]))
  const gw = (W - padL) / data.length, bw = Math.min(26, gw / 2 - 5)
  const y = (v) => H - padB - (v / max) * (H - padB - padT)
  if (data.every(d => d.revenue === 0 && d.collected === 0)) return <div className="hd-empty">No bookings or payments in the last 6 months yet.</div>
  return (
    <>
      <svg className="hd-chart" viewBox={`0 0 ${W} ${H}`} role="img" aria-label="Revenue and cash collected, last six months">
        <line x1={padL} x2={W} y1={H - padB} y2={H - padB} stroke="#e3e5e8" />
        {data.map((d, i) => {
          const cx = padL + gw * i + gw / 2
          const m = new Date(d.month + '-01T00:00').toLocaleDateString('en-IN', { month: 'short' })
          return (
            <g key={d.month}>
              <title>{`${m}: revenue ${inr(d.revenue)} (${d.bookings} bookings), collected ${inr(d.collected)}`}</title>
              <rect x={cx - bw - 1} y={y(d.revenue)} width={bw} height={Math.max(1, H - padB - y(d.revenue))} rx="2" fill="#0a6cc4" />
              <rect x={cx + 1} y={y(d.collected)} width={bw} height={Math.max(1, H - padB - y(d.collected))} rx="2" fill="#12a89e" />
              {d.revenue > 0 && <text x={cx - bw / 2 - 1} y={y(d.revenue) - 3} fontSize="8.5" textAnchor="middle" fill="#53585e">{inr(d.revenue, true)}</text>}
              <text x={cx} y={H - 7} fontSize="10" textAnchor="middle" fill="#7d838b">{m}</text>
            </g>
          )
        })}
      </svg>
      <div className="hd-legend"><span><i style={{ background: '#0a6cc4' }} />Revenue booked (ex-tax)</span><span><i style={{ background: '#12a89e' }} />Cash collected</span></div>
    </>
  )
}

function TaskRow({ t, onDone }) {
  const d = parseLocal(t.due_at); const over = d && d < new Date()
  return (
    <div className="hd-row">
      <button type="button" className="hd-done" title="Mark as done" aria-label={`Mark "${t.title}" as done`} onClick={() => onDone(t.id)}><Check size={13} /></button>
      <div className="hd-row-main">
        <div className="hd-row-title">{t.title}</div>
        <div className="hd-row-sub">{TYPE_LABEL[t.type] || 'Task'}{t.trip_title ? ` · ${t.trip_title}` : ''}</div>
      </div>
      <span className={'hd-tag ' + (over ? 'bad' : 'info')}>{over ? 'Overdue' : timeOf(t.due_at)}</span>
    </div>
  )
}

export default function HomeDashboardPage({ user }) {
  const nav = useNavigate()
  const manager = !!user && ['owner', 'admin'].includes(user.role)
  const [tab, setTab] = useState('overview')
  const [focus, setFocus] = useState('')          // '' = everyone (managers); a user id = one person
  const [team, setTeam] = useState([])
  const [d, setD] = useState(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => travel.home(manager && focus ? focus : null).then(setD).catch(e => toast.error('Could not load the dashboard', e.message)), [manager, focus])
  function refresh() { setBusy(true); load().finally(() => setBusy(false)) }
  useEffect(() => { if (user) load() }, [user, load])
  useEffect(() => { const t = setInterval(() => { if (!document.hidden && user) load() }, 120000); return () => clearInterval(t) }, [user, load])
  useEffect(() => {
    if (!manager) return undefined
    let alive = true
    api.get('/team').then(r => { if (alive) setTeam(r.data ?? []) }).catch(() => {})
    return () => { alive = false }
  }, [manager])

  async function completeTask(id) {
    try { await crm.completeTask(id); toast.success('Marked done'); load() } catch (e) { toast.error('Could not complete', e.message) }
  }

  const everyone = manager && !focus
  const who = manager ? (focus ? (team.find(m => String(m.id) === String(focus))?.name || 'this person') : 'your whole business') : 'your work'
  const first = (user?.name || '').split(' ')[0]

  return (
    <div className="hd">
      <div className="hd-head">
        <div>
          <h1 className="hd-title">{greeting()}{first ? `, ${first}` : ''}</h1>
          <div className="hd-sub">{new Date().toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })} · {d?.month ? `${d.month} so far, for ${who}` : 'Loading…'}</div>
        </div>
        <div className="hd-tools">
          {manager && tab === 'overview' && (
            <select className="form-select" style={{ width: 'auto', maxWidth: 220 }} value={focus} onChange={e => setFocus(e.target.value)} aria-label="Show numbers for">
              <option value="">Everyone</option>
              {user && <option value={user.id}>Only me</option>}
              {team.filter(m => String(m.id) !== String(user?.id)).map(m => <option key={m.id} value={m.id}>{m.name}</option>)}
            </select>
          )}
          {tab === 'overview' && <button className="btn btn-ghost btn-sm" onClick={refresh} disabled={busy} title="Refresh"><RefreshCw size={14} /> Refresh</button>}
          <div className="hd-tabs" role="tablist">
            <button className={tab === 'overview' ? 'is-on' : ''} onClick={() => setTab('overview')} role="tab" aria-selected={tab === 'overview'}>Overview</button>
            <button className={tab === 'crm' ? 'is-on' : ''} onClick={() => setTab('crm')} role="tab" aria-selected={tab === 'crm'}>Messaging &amp; CRM</button>
          </div>
        </div>
      </div>

      {tab === 'crm' && <div style={{ margin: '0 -1.5rem' }}><CrmDashboardPage /></div>}

      {tab === 'overview' && !d && <div style={{ height: 320, borderRadius: 6, background: '#eceef1', animation: 'lp-pulse 1.2s infinite' }} />}

      {tab === 'overview' && d && (<>
        <div className="hd-kpis">
          <Kpi label="New enquiries" value={d.kpis.enquiries.value} prev={d.kpis.enquiries.prev} money={false} to="/trips" />
          <Kpi label="Quotes sent" value={d.kpis.quotes_sent.value} prev={d.kpis.quotes_sent.prev} money={false} to="/trips" />
          <Kpi label="Bookings" value={d.kpis.bookings.value} prev={d.kpis.bookings.prev} money={false} to="/bookings" />
          <Kpi label="Revenue (ex-tax)" value={d.kpis.revenue.value} prev={d.kpis.revenue.prev} to="/business-report" />
          <Kpi label="Cash collected" value={d.kpis.collected.value} prev={d.kpis.collected.prev} to="/bookings" />
          {d.kpis.margin && <Kpi label="Gross margin" value={d.kpis.margin.value} prev={d.kpis.margin.prev} sub={`${d.kpis.margin.pct}%`} to="/business-report" />}
        </div>
        {d.kpis.bookings_without_cost?.value > 0 && manager && <p className="hd-muted" style={{ margin: '-.35rem 0 .8rem' }}>⚠ {d.kpis.bookings_without_cost.value} booking{d.kpis.bookings_without_cost.value === 1 ? '' : 's'} this month {d.kpis.bookings_without_cost.value === 1 ? 'has' : 'have'} no supplier cost entered, so margin is overstated.</p>}

        <AttentionStrip a={d.attention} />

        <div className="hd-grid">
          <div className="hd-col">
            <Card title="Pipeline" link="Open board →" to="/trips"><Pipeline rows={d.pipeline} /></Card>
            <Card title="Revenue and cash — last 6 months" link="Business report →" to="/business-report"><TrendChart data={d.trend} /></Card>

            <Card title="Collections — overdue and due this week" link="Bookings →" to="/bookings">
              {d.collections.length === 0 ? <div className="hd-empty">No customer payments are overdue or due this week.</div> : (<>
                <div className="hd-muted" style={{ marginBottom: 4 }}>Overdue {inr(d.attention.payments_overdue.amount)} · due this week {inr(d.attention.payments_week.amount)}</div>
                {d.collections.map(c => (
                  <Link key={c.id} to={`/bookings/${c.booking_id}`} className="hd-row">
                    <div className="hd-row-main">
                      <div className="hd-row-title">{c.customer || c.title}</div>
                      <div className="hd-row-sub">{c.booking_ref} · {c.label}</div>
                    </div>
                    <span className={'hd-tag ' + (c.overdue ? 'bad' : 'warn')}>{c.overdue ? `Overdue since ${shortDate(c.due_date)}` : `Due ${dueText(c.due_date)}`}</span>
                    <span className="hd-amt">{inr(c.amount)}</span>
                  </Link>
                ))}
              </>)}
            </Card>

            <Card title="Departures — next 14 days" link="All departures →" to="/departures">
              {d.departures.length === 0 ? <div className="hd-empty">No departures in the next two weeks.</div> : d.departures.map(b => {
                const bal = Number(b.total_amount) - Number(b.paid_amount)
                return (
                  <Link key={b.id} to={`/bookings/${b.id}`} className="hd-row">
                    <div className="hd-row-main">
                      <div className="hd-row-title">{b.customer || b.title}</div>
                      <div className="hd-row-sub">{b.booking_ref} · {b.title} · leaves {shortDate(b.travel_start)} ({dueText(b.travel_start)})</div>
                    </div>
                    {Number(b.docs_pending) > 0 && <span className="hd-tag warn">{b.docs_pending} docs pending</span>}
                    {bal > 0 ? <span className="hd-tag bad">{inr(bal, true)} unpaid</span> : <span className="hd-tag ok">Paid</span>}
                  </Link>
                )
              })}
            </Card>
          </div>

          <div className="hd-col">
            {!everyone && (
              <Card title={focus && manager ? 'Target — this month' : 'My target — this month'} link={manager ? 'Set targets →' : undefined} to="/settings/targets">
                {d.target ? (<>
                  <TargetBar label="Revenue (ex-tax)" p={d.target.revenue} daysLeft={d.month_progress.days_left} />
                  <TargetBar label="Bookings" p={d.target.bookings} money={false} daysLeft={d.month_progress.days_left} />
                  <p className="hd-muted" style={{ marginTop: 6 }}>{d.target.source === 'carried' ? `Carried over from ${monthName(d.target.from)}. ` : ''}The tick marks where you would be today on an even pace.</p>
                </>) : (
                  <div className="hd-empty">{manager ? <>No target set for {focus ? 'this person' : 'you'}. <Link to="/settings/targets">Set targets →</Link></> : 'No target set for you yet. Your manager can set one under Settings → Sales targets.'}</div>
                )}
              </Card>
            )}
            <Card title={everyone ? 'Tasks due today and overdue' : 'My day'} link="All tasks →" to="/tasks">
              {d.tasks.length === 0 ? <div className="hd-empty">Nothing due. Use the spare time to follow up on open enquiries below.</div> : d.tasks.map(t => <TaskRow key={t.id} t={t} onDone={completeTask} />)}
            </Card>

            <Card title="Needs a next step" link="Open board →" to="/trips">
              {d.followups.length === 0 ? <div className="hd-empty">Every open trip has a next step scheduled. 👏</div> : d.followups.map(t => (
                <Link key={t.id} to={`/trips/${t.id}`} className="hd-row">
                  <div className="hd-row-main">
                    <div className="hd-row-title">{t.title}</div>
                    <div className="hd-row-sub">{TRIP_STATUS[t.status]?.label || t.status}{t.destination_text ? ` · ${t.destination_text}` : ''}</div>
                  </div>
                  {t.budget_max ? <span className="hd-amt">{inr(t.budget_max, true)}</span> : null}
                </Link>
              ))}
            </Card>

            <Card title="Quotes to chase" link="Trips →" to="/trips">
              {d.quotes_to_chase.length === 0 ? <div className="hd-empty">No viewed-but-unanswered quotes.</div> : d.quotes_to_chase.map(q => (
                <Link key={q.trip_id} to={`/trips/${q.trip_id}`} className="hd-row">
                  <div className="hd-row-main">
                    <div className="hd-row-title">{q.title}</div>
                    <div className="hd-row-sub">Opened {parseLocal(q.viewed_at)?.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })} — not accepted yet</div>
                  </div>
                  <span className="hd-amt">{inr(q.total, true)}</span>
                </Link>
              ))}
            </Card>

            <Card title="Lead sources — this month" link="Report →" to="/business-report">
              {d.sources.length === 0 ? <div className="hd-empty">No enquiries this month yet.</div> : (
                <table className="hd-table"><thead><tr><th>Source</th><th className="r">Enquiries</th><th className="r">Booked</th></tr></thead>
                  <tbody>{d.sources.map(s => <tr key={s.source}><td>{nice(s.source)}</td><td className="r">{s.enquiries}</td><td className="r">{s.booked}</td></tr>)}</tbody></table>
              )}
            </Card>

            {manager && d.payables && (
              <Card title="Owed to suppliers" link="Payables →" to="/payables">
                <div className="hd-two">
                  <div><div className="hd-muted">Outstanding</div><div className="hd-amt" style={{ fontSize: '1.15rem' }}>{inr(d.payables.outstanding, true)}</div></div>
                  <div><div className="hd-muted">Overdue</div><div className="hd-amt" style={{ fontSize: '1.15rem', color: d.payables.overdue > 0 ? '#b3261e' : undefined }}>{inr(d.payables.overdue, true)}</div></div>
                </div>
                {d.payables.due_7d > 0 && <p className="hd-muted" style={{ marginTop: 6 }}>{inr(d.payables.due_7d)} more falls due in the next 7 days.</p>}
              </Card>
            )}
          </div>
        </div>

        {everyone && d.team && d.team.length > 0 && (
          <div style={{ marginTop: '1rem' }}>
            <Card title="Team and targets — this month" link="Set targets →" to="/settings/targets">
              {d.team_target ? (
                <div className="hd-two" style={{ marginBottom: '.9rem' }}>
                  <TargetBar label={`Team revenue (${d.team_target.people} with a target)`} p={d.team_target.revenue} daysLeft={d.month_progress.days_left} />
                  <TargetBar label="Team bookings" p={d.team_target.bookings} money={false} daysLeft={d.month_progress.days_left} />
                </div>
              ) : <p className="hd-muted" style={{ marginBottom: '.6rem' }}>No targets set yet — <Link to="/settings/targets">set them</Link> to see progress bars here and on each agent's own dashboard.</p>}
              <table className="hd-table">
                <thead><tr><th>Person</th><th className="r">Open trips</th><th className="r">New enquiries</th><th className="r">Bookings</th><th className="r">Revenue</th><th style={{ minWidth: 150 }}>Revenue vs target</th><th className="r">Overdue tasks</th></tr></thead>
                <tbody>
                  {d.team.map(m => (
                    <tr key={m.id} className="is-click" onClick={() => setFocus(String(m.id))} title="Show this person's dashboard">
                      <td><b style={{ fontWeight: 500 }}>{m.name}</b> <span className="hd-muted">{m.role}</span></td>
                      <td className="r">{m.open_trips}</td><td className="r">{m.enquiries}</td><td className="r">{m.bookings}</td>
                      <td className="r">{inr(m.revenue, true)}</td>
                      <td>{m.target && m.target.revenue.status !== 'none' ? <TargetBar compact p={m.target.revenue} label={m.name} /> : <span className="hd-muted">no target</span>}</td>
                      <td className="r">{m.overdue_tasks > 0 ? <span className="hd-tag bad">{m.overdue_tasks}</span> : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </Card>
          </div>
        )}
        {!d.kpis.enquiries.value && !d.pipeline.some(p => p.count) && (
          <p className="hd-muted" style={{ marginTop: 12 }}>Nothing here yet? <a href="#/trips" onClick={e => { e.preventDefault(); nav('/trips') }}>Create your first enquiry</a> and this page fills up on its own.</p>
        )}
      </>)}
      <style>{`@keyframes lp-pulse{0%,100%{opacity:1}50%{opacity:.45}}`}</style>
    </div>
  )
}
