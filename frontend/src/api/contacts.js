import { api } from './client'

export const contacts = {
  list:     (params = {}) => api.get('/contacts?' + new URLSearchParams(params)),
  // Distinct company categories present in this tenant, for the list filter.
  categories: ()          => api.get('/contacts/categories'),
  get:      (id)          => api.get(`/contacts/${id}`),
  create:   (data)        => api.post('/contacts', data),
  update:   (id, data)    => api.put(`/contacts/${id}`, data),
  remove:   (id)          => api.delete(`/contacts/${id}`),
  attachTag:(id, tagId)   => api.post(`/contacts/${id}/tags/${tagId}`, {}),
  detachTag:(id, tagId)   => api.delete(`/contacts/${id}/tags/${tagId}`),
  messaging:    (id)       => api.get(`/contacts/${id}/messaging`),
  sendTemplate: (id, data) => api.post(`/contacts/${id}/send-template`, data),

  // CSV export — returns a Blob honouring the current list filters.
  // Bypasses the JSON client because the response is a file download.
  exportCsv: async (params = {}) => {
    const token = localStorage.getItem('tp_token')
    const clean = Object.fromEntries(
      Object.entries(params).filter(([, v]) => v !== '' && v != null)
    )
    const qs  = new URLSearchParams(clean).toString()
    const res = await fetch(`/api/v1/contacts/export${qs ? '?' + qs : ''}`, {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    })
    if (!res.ok) throw new Error(`Export failed (HTTP ${res.status})`)
    return res.blob()
  },
}
