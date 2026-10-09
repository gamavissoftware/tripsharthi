import { api } from './client'

const B = '/email-marketing'

export const emailMarketing = {
  overview:        ()          => api.get(`${B}/overview`),
  audienceCount:   (segment)   => api.post(`${B}/audience-count`, { segment }),
  preview:         (data)      => api.post(`${B}/preview`, data),
  testSend:        (data)      => api.post(`${B}/test`, data),

  templates:       ()          => api.get(`${B}/templates`),
  template:        (id)        => api.get(`${B}/templates/${id}`),
  createTemplate:  (data)      => api.post(`${B}/templates`, data),
  updateTemplate:  (id, data)  => api.put(`${B}/templates/${id}`, data),
  deleteTemplate:  (id)        => api.delete(`${B}/templates/${id}`),

  campaigns:       ()          => api.get(`${B}/campaigns`),
  campaign:        (id)        => api.get(`${B}/campaigns/${id}`),
  createCampaign:  (data)      => api.post(`${B}/campaigns`, data),
  updateCampaign:  (id, data)  => api.put(`${B}/campaigns/${id}`, data),
  deleteCampaign:  (id)        => api.delete(`${B}/campaigns/${id}`),
  duplicate:       (id)        => api.post(`${B}/campaigns/${id}/duplicate`),
  send:            (id)        => api.post(`${B}/campaigns/${id}/send`),
  schedule:        (id, data)  => api.post(`${B}/campaigns/${id}/schedule`, data),
  unschedule:      (id)        => api.post(`${B}/campaigns/${id}/unschedule`),
  pause:           (id)        => api.post(`${B}/campaigns/${id}/pause`),
  resume:          (id)        => api.post(`${B}/campaigns/${id}/resume`),
  cancel:          (id)        => api.post(`${B}/campaigns/${id}/cancel`),
  /** Download the recipient log as CSV (needs the bearer token, so not a plain link). */
  async exportRecipients(id, filter = 'all', search = '') {
    const token = localStorage.getItem('tp_token')
    const res = await fetch(`/api/v1${B}/campaigns/${id}/recipients/export?` + new URLSearchParams({ filter, search }), {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    })
    if (!res.ok) throw new Error(`Export failed (HTTP ${res.status})`)
    const name = (res.headers.get('Content-Disposition') ?? '').match(/filename="([^"]+)"/)?.[1] ?? `email-campaign-${id}.csv`
    const url  = URL.createObjectURL(await res.blob())
    const a    = Object.assign(document.createElement('a'), { href: url, download: name })
    document.body.appendChild(a); a.click(); a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  },
  sequence:        (id, data)  => api.post(`${B}/campaigns/${id}/sequence`, data),
  recipients:      (id, q = {}) => api.get(`${B}/campaigns/${id}/recipients?` + new URLSearchParams(q).toString()),

  suppressions:      (q = {})  => api.get(`${B}/suppressions?` + new URLSearchParams(q).toString()),
  addSuppressions:   (data)    => api.post(`${B}/suppressions`, data),
  removeSuppression: (id)      => api.delete(`${B}/suppressions/${id}`),

  smtpConfig:      ()          => api.get('/email/config'),
  saveSmtpConfig:  (data)      => api.post('/email/config', data),
  testSmtp:        (to)        => api.post('/email/config/test', { to }),
}
