import { useEffect, useState } from 'react'
import { ArrowLeft, MousePointerClick, AlertTriangle, IndianRupee, Split } from 'lucide-react'
import { analytics as analyticsApi } from '../../api/analytics'
import { toast } from '../Toast'
import DeliveryLog from './DeliveryLog'
import {
  MetricTile, FunnelBars, TrendChart, Section, Bars, num, pct, inr, dateTime,
} from './reportUi'

const SKIP_LABELS = {
  skipped_opt_out:   'Skipped — not opted in',
  skipped_duplicate: 'Skipped — already messaged by this campaign',
  missing_variables: 'Sent with a missing variable',
  free_sends:        'Sent free (inside an open window)',
}

/**
 * Everything one broadcast did, on one screen — and underneath it, the
 * recipient list that backs every number above.
 */
export default function CampaignReportPanel({ campaignId, onBack }) {
  const [report, setReport]   = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let live = true
    setLoading(true)
    analyticsApi.campaignReport(campaignId)
      .then(res => { if (live) setReport(res?.data ?? null) })
      .catch(e => { if (live) toast.error(e.message || 'Could not load the campaign report') })
      .finally(() => { if (live) setLoading(false) })
    return () => { live = false }
  }, [campaignId])

  if (loading) {
    return <div className="empty-state"><div className="empty-state-text">Loading report…</div></div>
  }
  if (!report) {
    return (
      <div className="empty-state">
        <div className="empty-state-text">That campaign report could not be loaded.</div>
        <button className="btn btn-ghost btn-sm" onClick={onBack}>Back to campaigns</button>
      </div>
    )
  }

  const { campaign, funnel, timeseries, failures, buttons, variants, send_stats: skips, est_cost_paise: cost } = report
  const template = campaign.template

  const skipRows = Object.entries(skips ?? {})
    .filter(([key, value]) => SKIP_LABELS[key] && Number(value) > 0)
    .map(([key, value]) => ({ label: SKIP_LABELS[key], count: Number(value) }))

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
      {/* ── Header ── */}
      <div>
        <button className="btn btn-ghost btn-sm" onClick={onBack} style={{ marginBottom: '.75rem' }}>
          <ArrowLeft size={14} strokeWidth={2} /> All campaigns
        </button>
        <h2 style={{ fontSize: '1.3rem', fontWeight: 750, letterSpacing: '-.02em' }}>{campaign.name}</h2>
        <div style={{ fontSize: '.8rem', color: 'var(--text-3)', marginTop: 2 }}>
          {template
            ? <>Template <strong style={{ color: 'var(--text-2)' }}>{template.name}</strong> · {template.category} · {template.language}</>
            : 'Template no longer available'}
          {campaign.created_at && <> · created {dateTime(campaign.created_at)}</>}
        </div>
      </div>

      {/* ── Headline numbers ── */}
      <div style={{
        display: 'grid', gap: '.85rem',
        gridTemplateColumns: 'repeat(auto-fit, minmax(165px, 1fr))',
      }}>
        <MetricTile
          label="Recipients" value={num(funnel.recipients)}
          sub={`${num(campaign.total_contacts)} in the audience`}
          title="Contacts this campaign produced a message for"
        />
        <MetricTile
          label="Delivered" value={pct(funnel.delivery_rate)} tone="brand"
          sub={`${num(funnel.delivered)} of ${num(funnel.sent)} sent`}
        />
        <MetricTile
          label="Read" value={pct(funnel.read_rate)}
          tone={funnel.read_rate >= 50 ? 'good' : funnel.read_rate >= 20 ? 'warn' : 'bad'}
          sub={`${num(funnel.read)} opened it`}
        />
        <MetricTile
          label="Replied" value={num(funnel.replied)} tone="good"
          sub={`within ${funnel.reply_window_hours}h of the send`}
          title="Recipients who wrote back inside the WhatsApp service window"
        />
        <MetricTile
          label="Failed" value={num(funnel.failed)} tone={funnel.failed > 0 ? 'bad' : 'default'}
          sub={funnel.failed > 0 ? `${pct(funnel.failure_rate)} of the batch` : 'none'}
        />
        <MetricTile
          label="Est. Meta cost" value={inr(cost)}
          sub={`${num(funnel.billable)} billable messages`}
          title="Estimated, billed by Meta to your own WABA — not by TripSarthi"
        />
      </div>

      {/* ── Funnel + trend ── */}
      <div style={{ display: 'flex', gap: '1.25rem', flexWrap: 'wrap' }}>
        <Section
          title="Delivery funnel" style={{ flex: '1 1 380px' }}
          subtitle="Each step as a share of what actually left TripSarthi."
        >
          <FunnelBars funnel={funnel} />
        </Section>

        <Section
          title="Over time" style={{ flex: '1 1 420px' }}
          subtitle="When the batch went out and when people opened it."
        >
          <TrendChart series={timeseries} height={170} />
        </Section>
      </div>

      {/* ── A/B, clicks, failures ── */}
      <div style={{ display: 'flex', gap: '1.25rem', flexWrap: 'wrap' }}>
        {variants?.length > 0 && (
          <Section
            title={<span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}><Split size={15} strokeWidth={2} /> A/B split</span>}
            style={{ flex: '1 1 340px' }}
          >
            <div style={{ overflowX: 'auto' }}><table className="data-table">
              <thead>
                <tr><th>Variant</th><th>Sent</th><th>Delivered</th><th>Read</th><th>Clicks</th></tr>
              </thead>
              <tbody>
                {variants.map(v => (
                  <tr key={v.variant} style={{ cursor: 'default' }}>
                    <td className="cell-name">{v.variant}</td>
                    <td>{num(v.sent)}</td>
                    <td>{pct(v.delivery_rate)}</td>
                    <td><strong style={{ color: 'var(--text)' }}>{pct(v.read_rate)}</strong></td>
                    <td>{num(v.clicks)}</td>
                  </tr>
                ))}
              </tbody>
            </table></div>
          </Section>
        )}

        {buttons?.length > 0 && (
          <Section
            title={<span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}><MousePointerClick size={15} strokeWidth={2} /> Button taps</span>}
            subtitle={`${num(funnel.clicks)} taps from ${num(funnel.unique_clickers)} people`}
            style={{ flex: '1 1 300px' }}
          >
            <Bars rows={buttons} getLabel={r => r.button} getValue={r => r.clicks} colorFor={() => '#0ea5e9'} />
          </Section>
        )}

        {failures?.length > 0 && (
          <Section
            title={<span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}><AlertTriangle size={15} strokeWidth={2} /> Why sends failed</span>}
            subtitle="Meta's own reason codes — these are what you act on."
            style={{ flex: '1 1 340px' }}
          >
            <Bars rows={failures} getLabel={r => r.reason} colorFor={() => 'var(--danger)'} />
          </Section>
        )}

        {skipRows.length > 0 && (
          <Section
            title="Audience adjustments"
            subtitle="Contacts the sender deliberately skipped — they produce no message row, so they are not in the funnel above."
            style={{ flex: '1 1 300px' }}
          >
            <Bars rows={skipRows} getLabel={r => r.label} colorFor={() => 'var(--text-3)'} />
          </Section>
        )}
      </div>

      {/* ── Recipients ── */}
      <div>
        <div style={{ fontWeight: 700, fontSize: '1rem', marginBottom: '.2rem' }}>Every recipient</div>
        <div style={{ fontSize: '.78rem', color: 'var(--text-3)', marginBottom: '.85rem' }}>
          Who it went to and what happened on their phone. Filter to “No reply” to get your follow-up list.
        </div>
        <DeliveryLog lockedCampaignId={campaignId} compact />
      </div>

      <div style={{
        display: 'flex', gap: '.5rem', alignItems: 'flex-start',
        fontSize: '.76rem', color: 'var(--text-3)', paddingBottom: '1rem',
      }}>
        <IndianRupee size={13} strokeWidth={2} style={{ flexShrink: 0, marginTop: 2 }} />
        <span>
          Cost is an estimate from Meta's India per-message rates applied to billable messages.
          Meta bills your WABA directly; TripSarthi never charges for messages.
        </span>
      </div>
    </div>
  )
}
