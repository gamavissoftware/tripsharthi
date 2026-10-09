import { api } from './client'

export const imports = {
  list:     ()                         => api.get('/imports'),
  get:      (id)                       => api.get(`/imports/${id}`),
  // Phase 5: status() polls the import row for worker-driven progress
  status:   (id)                       => api.get(`/imports/${id}`),
  map:      (id, mapping)              => api.post(`/imports/${id}/map`, { mapping }),
  start:    (id)                       => api.post(`/imports/${id}/start`, {}),
  // continue() kept for backward compat but no longer drives batches
  continue: (id)                       => api.post(`/imports/${id}/continue`, {}),

  /** Upload file (multipart). Returns {import_id, headers, preview, total}. */
  upload(file, defaultCountryCode = '') {
    const token = localStorage.getItem('tp_token')
    const form  = new FormData()
    form.append('file', file)
    if (defaultCountryCode) form.append('default_country_code', defaultCountryCode)
    return fetch('/api/v1/imports', {
      method: 'POST',
      headers: token ? { Authorization: `Bearer ${token}` } : {},
      body: form,
    }).then(r => r.json())
  },
}
