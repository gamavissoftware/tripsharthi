import { api } from './client'

const unwrap = (r) => r.data
const qs = (o) => Object.entries(o).filter(([, v]) => v !== '' && v != null).map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&')

/** TripSarthi platform-admin API (owner of the product, not a customer workspace). */
export const admin = {
  overview:      ()          => api.get('/admin/overview').then(unwrap),
  tenants:       (p = {})    => api.get('/admin/tenants?' + qs(p)).then(unwrap),
  tenant:        (id)        => api.get(`/admin/tenants/${id}`).then(unwrap),
  setPlan:       (id, b)     => api.put(`/admin/tenants/${id}/plan`, b).then(unwrap),
  setStatus:     (id, b)     => api.post(`/admin/tenants/${id}/status`, b).then(unwrap),
  subscriptions: (p = {})    => api.get('/admin/subscriptions?' + qs(p)).then(unwrap),
  enquiries:     (status)    => api.get('/admin/enquiries?' + qs({ status })).then(unwrap),
  enquiryStatus: (id, status) => api.put(`/admin/enquiries/${id}`, { status }).then(unwrap),
  chats:         (status)    => api.get('/admin/chats?' + qs({ status })).then(unwrap),
  chat:          (id, after = 0) => api.get(`/admin/chats/${id}?after=${after}`).then(unwrap),
  chatReply:     (id, body)  => api.post(`/admin/chats/${id}/reply`, { body }).then(unwrap),
  chatClose:     (id)        => api.post(`/admin/chats/${id}/close`, {}).then(unwrap),
}
