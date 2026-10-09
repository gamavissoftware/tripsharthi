/**
 * Month-on-month WhatsApp spend, straight from Meta's billing.
 *
 * Everything here is what Meta reports for the customer's own WABA
 * (pricing_analytics), not TripSarthi's rate-card estimate. The "TripSarthi
 * sent" column exists so a gap between the two is visible: messages sent
 * from elsewhere on the same number, or a sync that has not run yet.
 */
import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { RefreshCw, IndianRupee, TrendingUp, TrendingDown, MessageSquare } from 'lucide-react'
import { analytics as analyticsApi } from '../../api/analytics'
import { toast } from '../Toast'
import Loader from '../Loader'
import { MetricTile, Section, num } from './reportUi'

const CATS = [
  ['marketing',      'Marketing',      '#0a6cc4'],
  ['utility',        'Utility',        '#0ea5e9'],
  ['authentication', 'Authentication', '#12a89e'],
  ['service',        'Service',        '#14b8a6'],
  ['unknown',        'Other',          '#8e8e99'],
]

const money = (v, ccy) => {
  try {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: ccy || 'INR', maximumFractionDigits: 2 }).format(Number(v ?? 0))
  } catch { return `${ccy} ${Number(v ?? 0).toFixed(2)}` }
}
const monthLabel = (m) => {
  const [y, mo] = m.split('-').map(Number)
  return new Date(Date.UTC(y, mo - 1, 1)).toLocaleDateString('en-IN', { month: 'short', year: 'numeric', timeZone: 'UTC' })
}

export default function MetaBillingPanel() {
  const [months, setMonths]   = useState(12)
  const [data, setData]       = useState(null)
  const [ads, setAds]         = useState(null)
  const [loading, setLoading] = useState(true)
  const [syncing, setSyncing] = useState(false)

  const load = useCallback(async () => {
    const [wa, ad] = await Promise.allSettled([analyticsApi.billing(months), analyticsApi.adSpend(months)])
    if (wa.status === 'fulfilled') setData(wa.value?.data ?? null)
    else toast.error('Could not load Meta billing', wa.reason?.message)
    if (ad.status === 'fulfilled') setAds(ad.value?.data ?? null)
    else setAds(null)
  }, [months])

  useEffect(() => { setLoading(true); load().finally(() => setLoading(false)) }, [load])

  async function sync() {
    setSyncing(true)
    try {
      const r = await analyticsApi.billingSync(months)
      setData(r.data ?? null)
      toast.success('Billing refreshed from Meta', `${r.sync?.rows ?? 0} monthly buckets via ${r.sync?.source ?? 'Meta'}`)
    } catch (e) {
      toast.error('Meta refused the billing sync', e.message)
    } finally {
      setSyncing(false)
    }
  }

  const ccy    = data?.currency ?? 'INR'
  const rows   = data?.months ?? []
  const cur    = data?.this_month
  const last   = data?.last_month
  const change = data?.mom_change_pct
  const max    = Math.max(1, ...rows.map(r => Number(r.cost)))
  const neverSynced = !loading && !data?.synced_at

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '.75rem', flexWrap: 'wrap' }}>
        <div style={{ fontSize: '.8rem', color: 'var(--text-3)' }}>
          {neverSynced
            ? 'Not synced from Meta yet — press Refresh from Meta.'
            : data?.synced_at ? `Meta figures as of ${new Date(data.synced_at.replace(' ', 'T') + 'Z').toLocaleString('en-IN')} · ${data.source === 'conversation_analytics' ? 'conversation pricing' : 'per-message pricing'}` : ''}
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
          <select className="form-input" value={months} onChange={e => setMonths(parseInt(e.target.value, 10))} style={{ width: 'auto', padding: '.35rem .6rem' }}>
            {[3, 6, 12].map(n => <option key={n} value={n}>Last {n} months</option>)}
          </select>
          <button className="btn btn-primary btn-sm" onClick={sync} disabled={syncing}>
            <RefreshCw size={14} strokeWidth={2} /> {syncing ? 'Asking Meta…' : 'Refresh from Meta'}
          </button>
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(165px, 1fr))', gap: '.85rem' }}>
        <MetricTile label="WhatsApp · this month" value={money(cur?.cost, ccy)} sub={cur ? `${num(cur.volume)} billed messages · ${monthLabel(cur.month)}` : ''} tone="brand" />
        <MetricTile label="WhatsApp · last month" value={money(last?.cost, ccy)} sub={last ? monthLabel(last.month) : 'no earlier month'} />
        <MetricTile
          label="WhatsApp month on month"
          value={change == null ? '—' : `${change > 0 ? '+' : ''}${change}%`}
          tone={change == null ? 'default' : change > 0 ? 'warn' : 'good'}
          sub={change == null ? 'needs two billed months' : change > 0 ? 'spend went up' : 'spend went down'}
        />
        <MetricTile label={`WhatsApp total · ${months} months`} value={money(data?.totals?.cost, ccy)} sub={`${num(data?.totals?.volume)} messages billed by Meta`} />
      </div>

      {ads?.connected && (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(165px, 1fr))', gap: '.85rem' }}>
            <MetricTile label="Ad spend · this month" value={money(ads.this_month?.spend, ads.currency)} sub={ads.this_month ? `${num(ads.this_month.leads)} leads · ${monthLabel(ads.this_month.month)}` : ''} tone="brand" />
            <MetricTile label="Ad spend · last month" value={money(ads.last_month?.spend, ads.currency)} sub={ads.last_month ? `${num(ads.last_month.leads)} leads · ${monthLabel(ads.last_month.month)}` : 'no earlier month'} />
            <MetricTile
              label="Ads month on month"
              value={ads.mom_change_pct == null ? '—' : `${ads.mom_change_pct > 0 ? '+' : ''}${ads.mom_change_pct}%`}
              tone={ads.mom_change_pct == null ? 'default' : ads.mom_change_pct > 0 ? 'warn' : 'good'}
              sub={ads.mom_change_pct == null ? 'needs two months of spend' : ads.mom_change_pct > 0 ? 'spend went up' : 'spend went down'}
            />
            <MetricTile
              label={`Total marketing · ${months} months`}
              value={money(Number(ads.totals?.spend ?? 0) + Number(data?.totals?.cost ?? 0), ads.currency)}
              sub={`ads ${money(ads.totals?.spend, ads.currency)} + WhatsApp ${money(data?.totals?.cost, ccy)}`}
            />
          </div>

          <Section
            title="Ad spend by month"
            subtitle={`Facebook & Instagram ads, as reported by Meta's Marketing API for ${(ads.ad_accounts ?? []).map(a => a.name).join(', ') || 'the chosen ad accounts'}. Cost per lead uses Meta's own lead count. Click a month's lead count to see those leads and add them to Contacts.`}
          >
            {ads.months?.length ? (
              <div style={{ overflowX: 'auto' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '.82rem' }}>
                  <thead>
                    <tr style={{ color: 'var(--text-3)', textAlign: 'left' }}>
                      <th style={{ padding: '.4rem .5rem' }}>Month</th>
                      <th style={{ padding: '.4rem .5rem', minWidth: 180 }}>Ad spend</th>
                      <th style={{ padding: '.4rem .5rem', textAlign: 'right' }}>WhatsApp</th>
                      <th style={{ padding: '.4rem .5rem', textAlign: 'right' }}>Total</th>
                      <th style={{ padding: '.4rem .5rem', textAlign: 'right' }}>Leads</th>
                      <th style={{ padding: '.4rem .5rem', textAlign: 'right' }}>Cost / lead</th>
                      <th style={{ padding: '.4rem .5rem', textAlign: 'right' }}>Clicks</th>
                      <th style={{ padding: '.4rem .5rem', textAlign: 'right' }}>Impressions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {(() => {
                      const maxAd = Math.max(1, ...ads.months.map(m => Number(m.spend)))
                      const waBy  = Object.fromEntries((data?.months ?? []).map(m => [m.month, Number(m.cost)]))
                      return [...ads.months].reverse().map(m => {
                        const spend = Number(m.spend), wa = waBy[m.month] ?? 0
                        return (
                          <tr key={m.month} style={{ borderTop: '1px solid var(--border)' }}>
                            <td style={{ padding: '.5rem', fontWeight: 600, whiteSpace: 'nowrap' }}>{monthLabel(m.month)}</td>
                            <td style={{ padding: '.5rem' }}>
                              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <div style={{ flex: 1, height: 8, borderRadius: 4, background: 'var(--surface-2)', overflow: 'hidden' }}>
                                  <div style={{ width: `${(spend / maxAd) * 100}%`, height: '100%', background: '#0866ff' }} />
                                </div>
                                <span style={{ fontWeight: 700, whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums' }}>{money(spend, ads.currency)}</span>
                              </div>
                            </td>
                            <td style={{ padding: '.5rem', textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: 'var(--text-3)' }}>{money(wa, ccy)}</td>
                            <td style={{ padding: '.5rem', textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: 700 }}>{money(spend + wa, ads.currency)}</td>
                            <td style={{ padding: '.5rem', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>
                              <Link to={`/analytics/meta-leads/${m.month}`} title="See every lead Meta holds for this month and add them to Contacts" style={{ fontWeight: 700 }}>
                                {num(m.leads)}
                              </Link>
                            </td>
                            <td style={{ padding: '.5rem', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{m.cost_per_lead == null ? '—' : money(m.cost_per_lead, ads.currency)}</td>
                            <td style={{ padding: '.5rem', textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: 'var(--text-3)' }}>{num(m.clicks)}</td>
                            <td style={{ padding: '.5rem', textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: 'var(--text-3)' }}>{num(m.impressions)}</td>
                          </tr>
                        )
                      })
                    })()}
                  </tbody>
                </table>
              </div>
            ) : <div style={{ color: 'var(--text-3)', fontSize: '.85rem' }}>No ad spend synced yet — use “Sync spend now” under Integrations → Meta Ads spend.</div>}
            {ads.last_error && <div style={{ fontSize: '.75rem', color: '#b45309', marginTop: 8 }}>Last sync warning: {ads.last_error}</div>}
          </Section>
        </>
      )}
      {ads && !ads.connected && (
        <div style={{ fontSize: '.8rem', color: 'var(--text-3)', background: 'var(--surface)', border: '1px dashed var(--border)', borderRadius: 'var(--r-md)', padding: '.85rem 1rem' }}>
          Ad spend is not connected. Go to Settings → Integrations → <b>Meta Ads spend</b> and connect with Facebook to see Facebook & Instagram ad spend here, month on month, beside the WhatsApp bill.
        </div>
      )}

      <Section
        title="WhatsApp cost by month"
        subtitle="What Meta charged the WhatsApp Business Account, by message category. Free service replies and free entry-point messages are counted but cost nothing."
      >
        {loading ? (
          <Loader message="Loading Meta's billing…" rows={5} />
        ) : rows.length === 0 ? (
          <div style={{ color: 'var(--text-3)', fontSize: '.85rem' }}>Nothing to show yet.</div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '.82rem' }}>
              <thead>
                <tr style={{ color: 'var(--text-3)', textAlign: 'left' }}>
                  <th style={{ padding: '.4rem .5rem' }}>Month</th>
                  <th style={{ padding: '.4rem .5rem', minWidth: 160 }}>Spend</th>
                  {CATS.map(([k, l]) => <th key={k} style={{ padding: '.4rem .5rem', textAlign: 'right' }}>{l}</th>)}
                  <th style={{ padding: '.4rem .5rem', textAlign: 'right' }}>Billed msgs</th>
                  <th style={{ padding: '.4rem .5rem', textAlign: 'right' }}>Free msgs</th>
                  <th style={{ padding: '.4rem .5rem', textAlign: 'right' }} title="Outbound messages TripSarthi itself sent that month">TripSarthi sent</th>
                </tr>
              </thead>
              <tbody>
                {[...rows].reverse().map(r => {
                  const cost = Number(r.cost)
                  return (
                    <tr key={r.month} style={{ borderTop: '1px solid var(--border)' }}>
                      <td style={{ padding: '.5rem', fontWeight: 600, whiteSpace: 'nowrap' }}>{monthLabel(r.month)}</td>
                      <td style={{ padding: '.5rem' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                          <div style={{ flex: 1, height: 8, borderRadius: 4, background: 'var(--surface-2)', overflow: 'hidden', display: 'flex' }}>
                            {CATS.map(([k, , color]) => {
                              const c = Number(r.by_category?.[k]?.cost ?? 0)
                              return c > 0 ? <div key={k} style={{ width: `${(c / max) * 100}%`, background: color, height: '100%' }} /> : null
                            })}
                          </div>
                          <span style={{ fontWeight: 700, whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums' }}>{money(cost, ccy)}</span>
                        </div>
                      </td>
                      {CATS.map(([k]) => (
                        <td key={k} style={{ padding: '.5rem', textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: r.by_category?.[k] ? 'var(--text)' : 'var(--text-3)' }}>
                          {r.by_category?.[k] ? money(r.by_category[k].cost, ccy) : '—'}
                        </td>
                      ))}
                      <td style={{ padding: '.5rem', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{num(r.volume - (r.free_volume ?? 0))}</td>
                      <td style={{ padding: '.5rem', textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: 'var(--text-3)' }}>{num(r.free_volume)}</td>
                      <td style={{ padding: '.5rem', textAlign: 'right', fontVariantNumeric: 'tabular-nums', color: 'var(--text-3)' }}>{num(r.travelpilot_sent)}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
        <div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap', marginTop: '.85rem', fontSize: '.72rem', color: 'var(--text-3)' }}>
          {CATS.map(([k, l, color]) => (
            <span key={k} style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>
              <span style={{ width: 10, height: 10, borderRadius: 3, background: color, display: 'inline-block' }} /> {l}
            </span>
          ))}
        </div>
      </Section>

      <div style={{ fontSize: '.75rem', color: 'var(--text-3)', display: 'flex', gap: 6, alignItems: 'flex-start' }}>
        <IndianRupee size={13} style={{ flexShrink: 0, marginTop: 2 }} />
        <span>
          Meta bills the WhatsApp Business Account directly; TripSarthi never charges for messages. Figures update automatically once a day
          and on Refresh. Meta's own invoice may differ slightly for the current month until it closes.
        </span>
      </div>
    </div>
  )
}
