import { useCallback, useEffect, useState } from 'react'
import {
  Users, Send, Zap, Megaphone, RefreshCw, BarChart3, ListChecks,
  AlertTriangle, IndianRupee, LayoutTemplate, MousePointerClick, ChevronRight,
} from 'lucide-react'
import { analytics as analyticsApi } from '../api/analytics'
import { toast } from '../components/Toast'
import DeliveryLog from '../components/analytics/DeliveryLog'
import CampaignReportPanel from '../components/analytics/CampaignReportPanel'
import MetaBillingPanel from '../components/analytics/MetaBillingPanel'
import {
  MetricTile, FunnelBars, TrendChart, RangePicker, Section, Bars,
  num, pct, inr, dateTime, today, daysAgo,
} from '../components/analytics/reportUi'

const TABS = [
  { key: 'overview',  label: 'Overview',     Icon: BarChart3 },
  { key: 'log',       label: 'Delivery log', Icon: ListChecks },
  { key: 'campaigns', label: 'Campaigns',    Icon: Megaphone },
  { key: 'flows',     label: 'Flows',        Icon: Zap },
  { key: 'billing',   label: 'Meta cost',    Icon: IndianRupee },
]

const CATEGORY_LABELS = {
  marketing: 'Marketing', utility: 'Utility', authentication: 'Authentication',
  service: 'Service', free_form: 'Free-form (in window)', uncategorised: 'Uncategorised',
}

const CATEGORY_COLORS = {
  marketing: '#0a6cc4', utility: '#0ea5e9', authentication: '#12a89e',
  service: '#14b8a6', free_form: '#10b981', uncategorised: '#8e8e99',
}

const SOURCE_LABELS = {
  manual: 'Manual', csv_import: 'CSV Import', web_form: 'Web Form',
  meta_lead_ads: 'Meta Lead Ads', whatsapp_inbound: 'WhatsApp Inbound',
  google_lead_forms: 'Google Lead Forms', email_inbound: 'Email',
}

const SOURCE_COLORS = {
  manual: '#0a6cc4', csv_import: '#3b82f6', web_form: '#10b981',
  meta_lead_ads: '#f59e0b', whatsapp_inbound: '#ec4899',
}

// A grid, not a wrapping flex row: with flex, the last tile on a row stretches
// to fill the leftover width and a five-tile row renders as four neat cards
// plus one banner.
const TILE_GRID = {
  display: 'grid',
  gridTemplateColumns: 'repeat(auto-fit, minmax(165px, 1fr))',
  gap: '.85rem',
}

export default function AnalyticsPage() {
  const [tab, setTab]     = useState('overview')
  const [range, setRange] = useState({ preset: '30d', from: daysAgo(29), to: today() })

  const [summary, setSummary]     = useState(null)
  const [overview, setOverview]   = useState(null)
  const [campaigns, setCampaigns] = useState([])
  const [flows, setFlows]         = useState([])

  const [loading, setLoading]       = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [lastUpdated, setLastUpdated] = useState(null)
  const [openCampaign, setOpenCampaign] = useState(null)

  const loadAll = useCallback(async () => {
    const [summaryRes, overviewRes, campaignsRes, flowsRes] = await Promise.allSettled([
      analyticsApi.summary(),
      analyticsApi.overview({ from: range.from, to: range.to }),
      analyticsApi.campaigns(),
      analyticsApi.flows(),
    ])

    if (summaryRes.status === 'fulfilled') setSummary(summaryRes.value?.data ?? null)
    else toast.error('Could not load account totals')

    if (overviewRes.status === 'fulfilled') setOverview(overviewRes.value?.data ?? null)
    else toast.error('Could not load delivery stats')

    setCampaigns(campaignsRes.status === 'fulfilled' ? (campaignsRes.value?.data ?? []) : [])
    setFlows(flowsRes.status === 'fulfilled' ? (flowsRes.value?.data ?? []) : [])

    setLastUpdated(new Date())
  }, [range.from, range.to])

  useEffect(() => {
    setLoading(true)
    loadAll().finally(() => setLoading(false))
  }, [loadAll])

  const handleRefresh = () => {
    setRefreshing(true)
    loadAll().finally(() => setRefreshing(false))
  }

  const funnel = overview?.funnel
  const estCost = (overview?.categories ?? []).reduce((s, c) => s + Number(c.est_cost_paise ?? 0), 0)

  return (
    <div className="page" style={{ maxWidth: 1280, margin: '0 auto' }}>

      {/* ── Header ── */}
      <div className="page-header" style={{ alignItems: 'flex-start', flexWrap: 'wrap', gap: '.75rem' }}>
        <div>
          <h1 className="page-title">Analytics</h1>
          <div style={{ fontSize: '.8rem', color: 'var(--text-3)' }}>
            {lastUpdated ? `Updated ${lastUpdated.toLocaleTimeString()}` : loading ? 'Loading…' : ''}
          </div>
        </div>
        <button className="btn btn-ghost btn-sm" onClick={handleRefresh} disabled={refreshing || loading}>
          <RefreshCw size={14} strokeWidth={2} /> {refreshing ? 'Refreshing…' : 'Refresh'}
        </button>
      </div>

      {/* ── Tabs ── */}
      <div style={{
        display: 'flex', gap: '.25rem', borderBottom: '1px solid var(--border)',
        marginBottom: '1.5rem', overflowX: 'auto',
      }}>
        {TABS.map(({ key, label, Icon }) => {
          const active = tab === key
          return (
            <button
              key={key}
              onClick={() => { setTab(key); if (key !== 'campaigns') setOpenCampaign(null) }}
              style={{
                display: 'inline-flex', alignItems: 'center', gap: 6,
                padding: '.6rem 1rem', border: 'none', background: 'none', cursor: 'pointer',
                fontSize: '.875rem', fontWeight: 600, whiteSpace: 'nowrap',
                color: active ? 'var(--primary)' : 'var(--text-3)',
                borderBottom: `2px solid ${active ? 'var(--primary)' : 'transparent'}`,
                marginBottom: -1,
              }}
            >
              <Icon size={15} strokeWidth={2} /> {label}
            </button>
          )
        })}
      </div>

      {tab === 'overview' && (
        <Overview
          summary={summary} overview={overview} funnel={funnel} estCost={estCost}
          range={range} onRange={setRange} loading={loading}
        />
      )}

      {tab === 'log' && <DeliveryLog campaigns={campaigns} />}

      {tab === 'campaigns' && (
        openCampaign
          ? <CampaignReportPanel campaignId={openCampaign} onBack={() => setOpenCampaign(null)} />
          : <CampaignList campaigns={campaigns} loading={loading} onOpen={setOpenCampaign} />
      )}

      {tab === 'flows' && <FlowList flows={flows} loading={loading} />}

      {tab === 'billing' && <MetaBillingPanel />}
    </div>
  )
}

// ── Overview ─────────────────────────────────────────────────────────────────
function Overview({ summary, overview, funnel, estCost, range, onRange, loading }) {
  const sources = summary?.contact_sources ?? []
  const sourceTotal = sources.reduce((s, r) => s + Number(r.count ?? 0), 0)

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1.5rem' }}>

      {/* Account totals — not affected by the date range. */}
      <div style={TILE_GRID}>
        <MetricTile label="Contacts"      value={num(summary?.contacts_total)} sub="in your account" />
        <MetricTile label="Messages sent" value={num(summary?.messages_sent)}  sub="all time, outbound" />
        <MetricTile label="Active flows"  value={num(summary?.active_flows)}   sub="running automations" />
        <MetricTile label="Campaigns"     value={num(summary?.campaigns_total)} sub="broadcasts created" />
        <MetricTile label="WhatsApp numbers" value={num(summary?.waba_total)}  sub="connected" />
      </div>

      <div style={{
        display: 'flex', alignItems: 'center', justifyContent: 'space-between',
        gap: '.75rem', flexWrap: 'wrap',
      }}>
        <div>
          <div style={{ fontWeight: 700, fontSize: '1.05rem' }}>Delivery performance</div>
          <div style={{ fontSize: '.78rem', color: 'var(--text-3)' }}>
            Every outbound message in the period — broadcasts, flows and inbox replies alike.
          </div>
        </div>
        <RangePicker value={range} onChange={onRange} />
      </div>

      {/* Rates for the selected period. */}
      <div style={TILE_GRID}>
        <MetricTile
          label="Sent" value={num(funnel?.sent)} tone="brand"
          sub={`to ${num(funnel?.recipients)} contacts`}
          title="Messages WhatsApp accepted from us"
        />
        <MetricTile
          label="Delivered" value={pct(funnel?.delivery_rate)}
          sub={`${num(funnel?.delivered)} reached a phone`}
        />
        <MetricTile
          label="Read" value={pct(funnel?.read_rate)}
          tone={(funnel?.read_rate ?? 0) >= 50 ? 'good' : (funnel?.read_rate ?? 0) >= 20 ? 'warn' : 'bad'}
          sub={`${num(funnel?.read)} opened`}
        />
        <MetricTile
          label="Replied" value={num(funnel?.replied)} tone="good"
          sub={`within ${funnel?.reply_window_hours ?? 24}h`}
          title="Contacts who wrote back inside the WhatsApp service window"
        />
        <MetricTile
          label="Failed" value={num(funnel?.failed)}
          tone={(funnel?.failed ?? 0) > 0 ? 'bad' : 'default'}
          sub={`${pct(funnel?.failure_rate)} of attempts`}
        />
        <MetricTile
          label="Est. Meta cost" value={inr(estCost)}
          sub={`${num(funnel?.billable)} billable`}
          title="Estimated — Meta bills your own WABA directly"
        />
      </div>

      <div style={{ display: 'flex', gap: '1.25rem', flexWrap: 'wrap' }}>
        <Section
          title="Funnel" style={{ flex: '1 1 360px' }}
          subtitle="Each step as a share of what actually left TripSarthi."
        >
          <FunnelBars funnel={funnel} loading={loading} />
        </Section>

        <Section
          title="Daily trend" style={{ flex: '1 1 440px' }}
          subtitle="Click a legend item to show or hide a line."
        >
          <TrendChart series={overview?.timeseries ?? []} />
        </Section>
      </div>

      <div style={{ display: 'flex', gap: '1.25rem', flexWrap: 'wrap' }}>
        <Section
          title={<Titled Icon={IndianRupee} text="Cost by category" />}
          subtitle="Meta charges per message, by conversation category. Free-form replies inside an open 24-hour window cost nothing — that is the cheapest way to talk to a lead."
          style={{ flex: '1 1 340px' }}
        >
          {(overview?.categories ?? []).length === 0
            ? <Empty text="No messages in this period" />
            : (
              <Bars
                rows={overview.categories}
                getLabel={r => CATEGORY_LABELS[r.category] ?? r.category}
                getValue={r => r.messages}
                colorFor={r => CATEGORY_COLORS[r.category] ?? 'var(--primary)'}
                suffix={r => r.est_cost_paise > 0
                  ? <span style={{ color: 'var(--text-3)', fontWeight: 500 }}> · {inr(r.est_cost_paise)}</span>
                  : null}
              />
            )}
        </Section>

        <Section
          title={<Titled Icon={AlertTriangle} text="Why sends failed" />}
          subtitle="Meta's own reason codes. #131049 is frequency capping, #131047 needs re-engagement, #132000 is a template variable mismatch."
          style={{ flex: '1 1 340px' }}
        >
          {(overview?.failures ?? []).length === 0
            ? <Empty text="No failures in this period" />
            : <Bars rows={overview.failures} getLabel={r => r.reason} colorFor={() => 'var(--danger)'} />}
        </Section>

        <Section
          title={<Titled Icon={Users} text="Where contacts came from" />}
          style={{ flex: '1 1 300px' }}
        >
          {sources.length === 0
            ? <Empty text="No contacts yet" />
            : (
              <Bars
                rows={sources}
                getLabel={r => SOURCE_LABELS[r.source] ?? String(r.source).replace(/_/g, ' ')}
                colorFor={r => SOURCE_COLORS[r.source] ?? 'var(--text-3)'}
                suffix={r => sourceTotal > 0
                  ? <span style={{ color: 'var(--text-3)', fontWeight: 500 }}> · {pct((r.count / sourceTotal) * 100)}</span>
                  : null}
              />
            )}
        </Section>
      </div>

      <Section
        title={<Titled Icon={LayoutTemplate} text="Template performance" />}
        subtitle="Broadcast sends only — a flow or inbox message carries no template to attribute."
      >
        {(overview?.templates ?? []).length === 0
          ? <Empty text="No broadcasts in this period" />
          : (
            <div style={{ overflowX: 'auto' }}>
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Template</th><th>Category</th><th>Sent</th>
                    <th>Delivered</th><th>Read</th><th>Failed</th>
                  </tr>
                </thead>
                <tbody>
                  {overview.templates.map(t => (
                    <tr key={t.template_id} style={{ cursor: 'default' }}>
                      <td className="cell-name">{t.template_name}</td>
                      <td>{CATEGORY_LABELS[t.category] ?? t.category ?? '—'}</td>
                      <td>{num(t.sent)}</td>
                      <td>{pct(t.delivery_rate)}</td>
                      <td><strong style={{ color: 'var(--text)' }}>{pct(t.read_rate)}</strong></td>
                      <td style={{ color: t.failed > 0 ? 'var(--danger)' : undefined }}>{num(t.failed)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
      </Section>
    </div>
  )
}

// ── Campaigns ────────────────────────────────────────────────────────────────
function CampaignList({ campaigns, loading, onOpen }) {
  if (loading) return <Empty text="Loading campaigns…" />
  if (campaigns.length === 0) {
    return (
      <div className="empty-state">
        <div className="empty-state-icon"><Megaphone /></div>
        <div className="empty-state-text">No campaigns yet — send a broadcast and its report appears here.</div>
      </div>
    )
  }

  return (
    <div className="card" style={{ overflow: 'hidden' }}>
      <div style={{ overflowX: 'auto' }}>
        <table className="data-table">
          <thead>
            <tr>
              <th>Campaign</th><th>Sent</th><th>Delivered</th><th>Read</th>
              <th>Failed</th><th>Clicks</th><th>Sent on</th><th />
            </tr>
          </thead>
          <tbody>
            {campaigns.map(c => {
              const s = c.stats ?? {}
              const sent = Number(s.sent_count ?? 0)
              const readRate = sent > 0 ? Math.round((Number(s.read_count ?? 0) / sent) * 100) : 0
              return (
                <tr key={c.id} onClick={() => onOpen(c.id)}>
                  <td className="cell-name">{c.name}</td>
                  <td>{num(sent)}</td>
                  <td>{num(s.delivered_count)}</td>
                  <td>
                    <strong style={{
                      color: readRate >= 50 ? 'var(--success)' : readRate >= 20 ? 'var(--warning)' : 'var(--text-2)',
                    }}>{num(s.read_count)}</strong>
                    <span style={{ color: 'var(--text-3)' }}> · {readRate}%</span>
                  </td>
                  <td style={{ color: Number(s.failed_count) > 0 ? 'var(--danger)' : undefined }}>
                    {num(s.failed_count)}
                  </td>
                  <td>
                    {Number(s.total_clicks) > 0
                      ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, color: '#0ea5e9', fontWeight: 600 }}>
                          <MousePointerClick size={13} strokeWidth={2} />{num(s.total_clicks)}
                        </span>
                      : '—'}
                  </td>
                  <td className="cell-mono">{dateTime(c.created_at) ?? '—'}</td>
                  <td style={{ textAlign: 'right', color: 'var(--text-3)' }}>
                    <ChevronRight size={16} strokeWidth={2} />
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}

// ── Flows ────────────────────────────────────────────────────────────────────
function FlowList({ flows, loading }) {
  if (loading) return <Empty text="Loading flows…" />
  if (flows.length === 0) {
    return (
      <div className="empty-state">
        <div className="empty-state-icon"><Zap /></div>
        <div className="empty-state-text">No flows yet</div>
      </div>
    )
  }

  return (
    <div className="card" style={{ overflow: 'hidden' }}>
      <div style={{ overflowX: 'auto' }}>
        <table className="data-table">
          <thead>
            <tr><th>Flow</th><th>Trigger</th><th>Status</th><th>Runs</th><th>Completed</th><th>Stopped</th></tr>
          </thead>
          <tbody>
            {flows.map(f => {
              const done = f.total_runs > 0 ? Math.round((f.completed_runs / f.total_runs) * 100) : 0
              return (
                <tr key={f.id} style={{ cursor: 'default' }}>
                  <td className="cell-name">{f.name}</td>
                  <td style={{ color: 'var(--text-3)' }}>{String(f.trigger_type ?? '—').replace(/_/g, ' ')}</td>
                  <td><span className={`badge badge-${f.status}`}>{f.status}</span></td>
                  <td>{num(f.total_runs)}</td>
                  <td>
                    <strong style={{ color: 'var(--success)' }}>{num(f.completed_runs)}</strong>
                    <span style={{ color: 'var(--text-3)' }}> · {done}%</span>
                  </td>
                  <td style={{ color: f.stopped_runs > 0 ? 'var(--danger)' : undefined }}>{num(f.stopped_runs)}</td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}

// ── Bits ─────────────────────────────────────────────────────────────────────
function Titled({ Icon, text }) {
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
      <Icon size={15} strokeWidth={2} /> {text}
    </span>
  )
}

function Empty({ text }) {
  return (
    <div style={{ padding: '2rem 0', textAlign: 'center', color: 'var(--text-3)', fontSize: '.85rem' }}>
      {text}
    </div>
  )
}
