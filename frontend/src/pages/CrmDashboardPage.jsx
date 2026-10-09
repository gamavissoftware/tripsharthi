import { useState, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { crm, rupees } from '../api/crm'
import { toast } from '../components/Toast'

const LIFECYCLE_LABEL = { subscriber: 'Subscriber', lead: 'Lead', mql: 'MQL', sql: 'SQL', opportunity: 'Opportunity', customer: 'Customer', evangelist: 'Evangelist', other: 'Other' }
const MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
const monthLabel = (ym) => MON[(parseInt(ym.slice(5), 10) || 1) - 1] ?? ym
const CAMP_TINT = { sent: { bg: '#dcfce7', c: '#15803d' }, sending: { bg: '#dbeafe', c: '#1d4ed8' }, processing: { bg: '#dbeafe', c: '#1d4ed8' }, scheduled: { bg: '#fef3c7', c: '#92400e' }, draft: { bg: '#f1f5f9', c: '#475569' }, failed: { bg: '#fee2e2', c: '#b91c1c' } }
const timeLeft = (dt) => {
  const mins = Math.round((new Date(String(dt).replace(' ', 'T')) - Date.now()) / 60000)
  if (mins <= 0) return { label: 'closing', urgent: true }
  const h = Math.floor(mins / 60), m = mins % 60
  return { label: h > 0 ? `${h}h ${m}m` : `${m}m`, urgent: mins < 120 }
}
// Deal/revenue amounts are stored in paise — convert to rupees, then abbreviate.
const compact = (paise) => {
  const n = Number(paise || 0) / 100
  if (n >= 1e7) return `₹${(n / 1e7).toFixed(2)}Cr`
  if (n >= 1e5) return `₹${(n / 1e5).toFixed(1)}L`
  if (n >= 1e3) return `₹${(n / 1e3).toFixed(0)}K`
  return rupees(paise)
}

function Metric({ label, value, sub, accent, to }) {
  const inner = (
    <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, padding: '1rem 1.1rem', height: '100%' }}>
      <div style={{ fontSize: '.72rem', color: '#94a3b8', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.05em' }}>{label}</div>
      <div style={{ fontSize: '1.5rem', fontWeight: 800, color: accent ?? '#111827', marginTop: 4 }}>{value}</div>
      {sub && <div style={{ fontSize: '.74rem', color: '#94a3b8', marginTop: 2 }}>{sub}</div>}
    </div>
  )
  return to ? <Link to={to} style={{ textDecoration: 'none' }}>{inner}</Link> : inner
}

function Card({ title, action, children }) {
  return (
    <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, padding: '1.1rem 1.25rem' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', margin: '0 0 1rem' }}>
        <h3 style={{ fontSize: 15, fontWeight: 800, margin: 0 }}>{title}</h3>
        {action}
      </div>
      {children}
    </div>
  )
}

function BarRow({ label, count, value, display, max, color }) {
  return (
    <div style={{ marginBottom: '.7rem' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '.8rem', marginBottom: 3 }}>
        <span style={{ fontWeight: 600, color: '#374151' }}>{label}{count != null && <span style={{ color: '#94a3b8', fontWeight: 400 }}> · {count}</span>}</span>
        <span style={{ fontWeight: 700, color: '#475569' }}>{display}</span>
      </div>
      <div style={{ height: 8, background: '#f1f5f9', borderRadius: 999 }}>
        <div style={{ height: '100%', width: `${Math.max(2, (value / max) * 100)}%`, background: color, borderRadius: 999 }} />
      </div>
    </div>
  )
}

// Six-month won-revenue bar chart.
function TrendChart({ data }) {
  const max = Math.max(1, ...data.map(p => p.value))
  const W = 560, H = 130, pad = 26, n = data.length
  const slot = (W - pad * 2) / n
  const bw = slot * 0.55
  const empty = data.every(p => p.value === 0)
  return (
    <div>
      <svg viewBox={`0 0 ${W} ${H}`} style={{ width: '100%', height: 'auto', display: 'block' }}>
        {data.map((p, i) => {
          const cx = pad + slot * (i + 0.5)
          const h = (p.value / max) * (H - 38)
          return (
            <g key={p.month}>
              <rect x={cx - bw / 2} y={H - 22 - h} width={bw} height={Math.max(2, h)} rx={3} fill="var(--primary,#0a6cc4)" opacity={p.value ? 1 : 0.25} />
              {p.value > 0 && <text x={cx} y={H - 26 - h} fontSize="8.5" fill="#64748b" textAnchor="middle" fontWeight="700">{compact(p.value)}</text>}
              <text x={cx} y={H - 7} fontSize="9.5" fill="#94a3b8" textAnchor="middle">{monthLabel(p.month)}</text>
            </g>
          )
        })}
      </svg>
      {empty && <div style={{ fontSize: '.78rem', color: '#94a3b8', textAlign: 'center', marginTop: 4 }}>No deals won in the last 6 months yet.</div>}
    </div>
  )
}

// 14-day inbound vs outbound message volume.
function VolumeChart({ data }) {
  const max = Math.max(1, ...data.map(p => Math.max(p.out, p.in)))
  const W = 560, H = 132, pad = 22, n = data.length
  const slot = (W - pad * 2) / n, bw = Math.min(8, slot * 0.34)
  const empty = data.every(p => p.out === 0 && p.in === 0)
  return (
    <div>
      <svg viewBox={`0 0 ${W} ${H}`} style={{ width: '100%', height: 'auto', display: 'block' }}>
        {data.map((p, i) => {
          const cx = pad + slot * (i + 0.5)
          const ho = (p.out / max) * (H - 34), hi = (p.in / max) * (H - 34)
          return (
            <g key={p.date}>
              <rect x={cx - bw - 1} y={H - 20 - ho} width={bw} height={Math.max(1, ho)} rx={2} fill="var(--primary,#0a6cc4)" />
              <rect x={cx + 1} y={H - 20 - hi} width={bw} height={Math.max(1, hi)} rx={2} fill="#22c55e" />
              {i % 2 === 0 && <text x={cx} y={H - 6} fontSize="8" fill="#94a3b8" textAnchor="middle">{p.date.slice(8)}/{p.date.slice(5, 7)}</text>}
            </g>
          )
        })}
      </svg>
      <div style={{ display: 'flex', gap: 16, fontSize: '.72rem', color: '#64748b', marginTop: 4 }}>
        <span><span style={{ display: 'inline-block', width: 9, height: 9, borderRadius: 2, background: 'var(--primary,#0a6cc4)', marginRight: 5, verticalAlign: 'middle' }} />Outbound</span>
        <span><span style={{ display: 'inline-block', width: 9, height: 9, borderRadius: 2, background: '#22c55e', marginRight: 5, verticalAlign: 'middle' }} />Inbound</span>
      </div>
      {empty && <div style={{ fontSize: '.78rem', color: '#94a3b8', textAlign: 'center', marginTop: 4 }}>No messages in the last 14 days.</div>}
    </div>
  )
}

export default function CrmDashboardPage() {
  const [d, setD] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    crm.dashboard().then(r => setD(r.data)).catch(e => toast.error('Failed to load dashboard', e.message)).finally(() => setLoading(false))
  }, [])

  if (loading || !d) return <div className="page" style={{ maxWidth: 1180 }}><div style={{ height: 300, borderRadius: 14, background: '#f3f4f6', animation: 'lp-pulse 1.2s infinite' }} /><style>{`@keyframes lp-pulse{0%,100%{opacity:1}50%{opacity:.45}}`}</style></div>

  const maxFunnel = Math.max(1, ...d.funnel.map(s => s.value))
  const maxLife   = Math.max(1, ...d.lifecycle.map(s => s.count))
  const leads     = d.leads ?? { total: 0, new_30d: 0, hot: 0 }
  const sources   = d.leads_by_source ?? []
  const maxSource = Math.max(1, ...sources.map(s => s.count))
  const tiers     = d.score_tiers ?? { hot: 0, warm: 0, cold: 0 }
  const maxTier   = Math.max(1, tiers.hot, tiers.warm, tiers.cold)
  const trend     = d.revenue_trend ?? []
  const topDeals  = d.top_deals ?? []
  const maxTop    = Math.max(1, ...topDeals.map(t => t.value))
  const wa        = d.whatsapp ?? { open_windows: 0, expiring_soon: 0, inbound_today: 0, sent_7d: 0, delivered_7d: 0, read_7d: 0, failed_7d: 0 }
  const maxWa     = Math.max(1, wa.sent_7d, wa.failed_7d)
  const readRate  = wa.sent_7d ? Math.round((wa.read_7d / wa.sent_7d) * 100) : 0
  const closing   = d.closing_windows ?? []
  const tpl       = d.templates ?? { approved: 0, pending: 0, rejected: 0 }
  const campaigns = d.campaigns ?? []
  const volume    = d.message_volume ?? []
  const topAgents = d.top_agents ?? []

  return (
    <div className="page" style={{ maxWidth: 1180 }}>
      <div style={{ marginBottom: '1.25rem' }}>
        <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>📊 CRM Dashboard</h1>
        <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Your whole business at a glance — WhatsApp, leads, pipeline, revenue, service and tasks.</p>
      </div>

      {/* Metric cards */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(158px,1fr))', gap: '.75rem', marginBottom: '1.5rem' }}>
        <Metric label="Open windows" value={wa.open_windows} sub={wa.expiring_soon ? `⏳ ${wa.expiring_soon} expiring soon` : '24h free-message'} accent={wa.open_windows ? '#16a34a' : undefined} to="/inbox" />
        <Metric label="Replies today" value={wa.inbound_today} sub="inbound on WhatsApp" accent={wa.inbound_today ? '#16a34a' : undefined} to="/inbox" />
        <Metric label="Total leads" value={leads.total} sub={`+${leads.new_30d} in 30 days`} to="/contacts" />
        <Metric label="Hot leads" value={leads.hot} sub="high score" accent={leads.hot ? '#dc2626' : undefined} to="/contacts" />
        <Metric label="Open pipeline" value={rupees(d.deals.open_value)} sub={`${d.deals.open_count} open deals`} to="/deals" />
        <Metric label="Revenue won" value={rupees(d.deals.won_value)} sub={`${d.deals.won_count} won`} accent="#15803d" />
        {d.recurring?.count > 0 && (
          <Metric label="MRR" value={rupees(d.recurring.mrr)} sub={`${rupees(d.recurring.arr)} ARR · ${d.recurring.count} subs`} accent="#074a8c" to="/deals" />
        )}
        <Metric label="Templates" value={tpl.approved} sub={tpl.pending ? `${tpl.pending} pending review` : 'all approved'} accent={tpl.pending ? '#c2410c' : '#15803d'} to="/templates" />
        <Metric label="Win rate" value={`${d.deals.win_rate}%`} sub={`${d.deals.won_count}W · ${d.deals.lost_count}L`} accent="#08569f" />
        <Metric label="Open tickets" value={d.tickets.open + d.tickets.pending} sub={d.tickets.sla_breached ? `⚠ ${d.tickets.sla_breached} SLA breached` : 'all within SLA'} accent={d.tickets.sla_breached ? '#dc2626' : undefined} to="/tickets" />
        <Metric label="Tasks due" value={d.tasks.overdue + d.tasks.due_today} sub={`${d.tasks.overdue} overdue · ${d.tasks.due_today} today`} accent={d.tasks.overdue ? '#c2410c' : undefined} to="/tasks" />
      </div>

      {/* Message volume + revenue trend — full width */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(340px,1fr))', gap: '1rem', marginBottom: '1rem' }}>
        <Card title="💬 Message volume — last 14 days" action={<Link to="/inbox" style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--primary,#0a6cc4)', textDecoration: 'none' }}>Inbox →</Link>}>
          <VolumeChart data={volume} />
        </Card>
        <Card title="📈 Revenue won — last 6 months">
          <TrendChart data={trend} />
        </Card>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(320px,1fr))', gap: '1rem' }}>
        {/* WhatsApp delivery funnel — the messaging core of the product */}
        <Card title="💬 WhatsApp delivery — last 7 days" action={<Link to="/inbox" style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--primary,#0a6cc4)', textDecoration: 'none' }}>Inbox →</Link>}>
          {wa.sent_7d === 0 && wa.failed_7d === 0 ? (
            <div style={{ color: '#94a3b8', fontSize: '.85rem' }}>No outbound messages in the last 7 days.</div>
          ) : (
            <>
              <BarRow label="Sent"      value={wa.sent_7d}      display={wa.sent_7d}      max={maxWa} color="#86efac" />
              <BarRow label="Delivered" value={wa.delivered_7d} display={wa.delivered_7d} max={maxWa} color="#22c55e" />
              <BarRow label="Read"      value={wa.read_7d}      display={wa.read_7d}      max={maxWa} color="#15803d" />
              {wa.failed_7d > 0 && <BarRow label="Failed" value={wa.failed_7d} display={wa.failed_7d} max={maxWa} color="#dc2626" />}
              <div style={{ fontSize: '.78rem', color: '#64748b', marginTop: 8, paddingTop: 8, borderTop: '1px solid #f1f5f9' }}>
                Read rate <strong style={{ color: '#15803d' }}>{readRate}%</strong> · {wa.open_windows} open 24h window{wa.open_windows === 1 ? '' : 's'} right now
              </div>
            </>
          )}
        </Card>

        {/* Windows closing soon — reach them before the 24h free-message window shuts */}
        <Card title="⏳ Windows closing soon" action={<Link to="/inbox" style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--primary,#0a6cc4)', textDecoration: 'none' }}>Inbox →</Link>}>
          {closing.length === 0 ? (
            <div style={{ color: '#94a3b8', fontSize: '.85rem' }}>No open 24h windows. Send an approved template to start a conversation.</div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
              {closing.map(c => {
                const t = timeLeft(c.expires_at)
                return (
                  <Link key={c.contact_id} to={`/contacts/${c.contact_id}`} style={{ textDecoration: 'none', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, padding: '.4rem .55rem', border: '1px solid #eef0f3', borderRadius: 9 }}>
                    <div style={{ minWidth: 0 }}>
                      <div style={{ fontWeight: 600, fontSize: 13.5, color: '#111827', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{c.name}</div>
                      <div style={{ fontSize: 11.5, color: '#9ca3af', fontFamily: 'monospace' }}>{c.wa_number}</div>
                    </div>
                    <span style={{ flexShrink: 0, fontSize: 12, fontWeight: 800, color: t.urgent ? '#dc2626' : '#15803d', background: t.urgent ? '#fee2e2' : '#dcfce7', borderRadius: 999, padding: '.12rem .55rem' }}>{t.label} left</span>
                  </Link>
                )
              })}
            </div>
          )}
        </Card>

        {/* Recent broadcast campaigns */}
        <Card title="📢 Recent campaigns" action={<Link to="/campaigns" style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--primary,#0a6cc4)', textDecoration: 'none' }}>All →</Link>}>
          {campaigns.length === 0 ? (
            <div style={{ color: '#94a3b8', fontSize: '.85rem' }}>No campaigns yet.</div>
          ) : campaigns.map(c => {
            const pct = c.total ? Math.round((c.sent / c.total) * 100) : 0
            const tint = CAMP_TINT[c.status] ?? CAMP_TINT.draft
            return (
              <div key={c.id} style={{ marginBottom: '.7rem' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: '.8rem', marginBottom: 3, gap: 8 }}>
                  <span style={{ fontWeight: 600, color: '#374151', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{c.name}</span>
                  <span style={{ flexShrink: 0, display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                    <span style={{ color: '#475569', fontWeight: 700 }}>{c.sent}/{c.total || '—'}{c.failed > 0 ? <span style={{ color: '#dc2626' }}> · {c.failed}✕</span> : null}</span>
                    <span style={{ fontSize: 10.5, fontWeight: 800, textTransform: 'uppercase', background: tint.bg, color: tint.c, borderRadius: 999, padding: '.05rem .4rem' }}>{c.status}</span>
                  </span>
                </div>
                <div style={{ height: 7, background: '#f1f5f9', borderRadius: 999 }}>
                  <div style={{ height: '100%', width: `${Math.max(2, pct)}%`, background: '#25D366', borderRadius: 999 }} />
                </div>
              </div>
            )
          })}
        </Card>

        {/* Pipeline funnel */}
        <Card title="💼 Pipeline by stage">
          {d.funnel.map(s => (
            <BarRow key={s.name} label={s.name} count={s.count} value={s.value} display={rupees(s.value)} max={maxFunnel} color="var(--primary,#0a6cc4)" />
          ))}
        </Card>

        {/* Top open deals */}
        <Card title="🏆 Top open deals" action={<Link to="/deals" style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--primary,#0a6cc4)', textDecoration: 'none' }}>View all →</Link>}>
          {topDeals.length === 0 ? <div style={{ color: '#94a3b8', fontSize: '.85rem' }}>No open deals.</div>
            : topDeals.map(t => (
              <Link key={t.id} to={`/deals/${t.id}`} style={{ textDecoration: 'none' }}>
                <BarRow label={t.title} value={t.value} display={rupees(t.value)} max={maxTop} color="#0ea5e9" />
              </Link>
            ))}
        </Card>

        {/* Leads by source */}
        <Card title="🎯 Leads by source">
          {sources.length === 0 ? <div style={{ color: '#94a3b8', fontSize: '.85rem' }}>No leads yet.</div>
            : sources.map(s => (
              <BarRow key={s.source} label={s.source.replace(/_/g, ' ')} value={s.count} display={s.count} max={maxSource} color="#14b8a6" />
            ))}
        </Card>

        {/* Lead score tiers */}
        <Card title="🔥 Lead score">
          <BarRow label="Hot"  value={tiers.hot}  display={tiers.hot}  max={maxTier} color="#dc2626" />
          <BarRow label="Warm" value={tiers.warm} display={tiers.warm} max={maxTier} color="#f59e0b" />
          <BarRow label="Cold" value={tiers.cold} display={tiers.cold} max={maxTier} color="#3b82f6" />
        </Card>

        {/* Top agents this month */}
        <Card title="🏅 Top agents this month" action={<Link to="/leaderboard" style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--primary,#0a6cc4)', textDecoration: 'none' }}>Full →</Link>}>
          {topAgents.length === 0 ? <div style={{ color: '#94a3b8', fontSize: '.85rem' }}>No ranked activity yet this month.</div>
            : topAgents.map((a, i) => (
              <div key={a.name + i} style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, padding: '.45rem 0', borderBottom: i < topAgents.length - 1 ? '1px solid #f1f5f9' : 'none' }}>
                <span style={{ display: 'flex', alignItems: 'center', gap: 8, minWidth: 0 }}>
                  <span style={{ fontSize: 15, width: 22, textAlign: 'center' }}>{['🥇', '🥈', '🥉'][i] ?? `#${i + 1}`}</span>
                  <span style={{ fontWeight: 600, fontSize: 13.5, color: '#111827', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{a.name}</span>
                </span>
                <span style={{ flexShrink: 0, textAlign: 'right' }}>
                  <span style={{ fontWeight: 800, color: '#08569f' }}>{a.score} pts</span>
                  {a.won_revenue > 0 && <span style={{ fontSize: 11.5, color: '#15803d', marginLeft: 8 }}>{rupees(a.won_revenue)}</span>}
                </span>
              </div>
            ))}
        </Card>

        {/* Lifecycle breakdown */}
        <Card title="👥 Contacts by lifecycle">
          {d.lifecycle.length === 0 ? <div style={{ color: '#94a3b8', fontSize: '.85rem' }}>No contacts yet.</div>
            : d.lifecycle.map(s => (
              <BarRow key={s.stage} label={LIFECYCLE_LABEL[s.stage] ?? s.stage} value={s.count} display={s.count} max={maxLife} color="#12a89e" />
            ))}
        </Card>
      </div>
      <style>{`@keyframes lp-pulse{0%,100%{opacity:1}50%{opacity:.45}}`}</style>
    </div>
  )
}
