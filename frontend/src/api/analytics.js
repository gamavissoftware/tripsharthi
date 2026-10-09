import { api } from './client'

// Drop empty params so the URL says only what the operator actually filtered on
// — and so an empty search box doesn't look like a filter to the server.
function qs(params = {}) {
  const clean = Object.entries(params).filter(
    ([, v]) => v !== '' && v != null && !(Array.isArray(v) && v.length === 0)
  )
  const s = new URLSearchParams(
    clean.map(([k, v]) => [k, Array.isArray(v) ? v.join(',') : String(v)])
  ).toString()
  return s ? `?${s}` : ''
}

// Minutes east of UTC. The server stores timestamps in UTC but the operator
// picks calendar days in their own zone, so every report call carries this.
export const tzOffset = () => -new Date().getTimezoneOffset()

export const analytics = {
  summary:        () => api.get('/analytics/summary'),
  campaigns:      () => api.get('/analytics/campaigns'),
  campaignClicks: (id) => api.get(`/analytics/campaigns/${id}/clicks`),
  flows:          () => api.get('/analytics/flows'),

  // Delivery reporting
  overview:       (params = {}) => api.get('/analytics/overview' + qs({ tz_offset: tzOffset(), ...params })),
  messages:       (params = {}) => api.get('/analytics/messages' + qs({ tz_offset: tzOffset(), ...params })),
  campaignReport: (id, params = {}) =>
    api.get(`/analytics/campaigns/${id}/report` + qs({ tz_offset: tzOffset(), ...params })),

  /**
   * CSV of the delivery log. Bypasses the JSON client because the response is a
   * file, and reads back the server's own filename and truncation headers — an
   * export capped at the row limit must say so rather than hand back a short
   * file that looks complete.
   */
  exportMessages: async (params = {}) => {
    const token = localStorage.getItem('tp_token')
    const res = await fetch(
      '/api/v1/analytics/messages/export' + qs({ tz_offset: tzOffset(), ...params }),
      { headers: token ? { Authorization: `Bearer ${token}` } : {} }
    )
    if (!res.ok) throw new Error(`Export failed (HTTP ${res.status})`)

    const disposition = res.headers.get('Content-Disposition') ?? ''
    const match = /filename="([^"]+)"/.exec(disposition)

    return {
      blob:      await res.blob(),
      filename:  match?.[1] ?? `whatsapp-delivery-report-${new Date().toISOString().slice(0, 10)}.csv`,
      rows:      Number(res.headers.get('X-Export-Rows') ?? 0),
      total:     Number(res.headers.get('X-Export-Total') ?? 0),
      truncated: res.headers.get('X-Export-Truncated') === '1',
    }
  },

  // Meta's real WhatsApp bill, month on month (waba_billing, synced from Graph).
  billing:     (months = 12) => api.get(`/analytics/billing?months=${months}`),
  billingSync: (months = 12) => api.post('/analytics/billing/sync', { months }),
  // Meta ad spend (Facebook/Instagram ads), month on month (ad_spend, synced from the Marketing API).
  adSpend:     (months = 12) => api.get(`/analytics/ad-spend?months=${months}`),
}
