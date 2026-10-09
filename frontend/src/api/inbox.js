import { api } from './client'

export const inbox = {
  list:     (p = {}) => api.get('/inbox' + (Object.keys(p).length ? '?' + new URLSearchParams(p) : '')),
  get:      (id)     => api.get(`/inbox/${id}`),
  messages: (id, since, limit = 50) => {
    const p = new URLSearchParams({ limit })
    if (since) p.set('since', since)
    return api.get(`/inbox/${id}/messages?${p}`)
  },
  send:   (id, body)   => api.post(`/inbox/${id}/messages`, body),
  assign:   (id, userId) => api.patch(`/inbox/${id}/assign`, { user_id: userId }),
  resolve:  (id)         => api.patch(`/inbox/${id}/resolve`, {}),
  reopen:   (id)         => api.patch(`/inbox/${id}/reopen`, {}),
  markRead: (id)         => api.patch(`/inbox/${id}/mark-read`, {}),
}
