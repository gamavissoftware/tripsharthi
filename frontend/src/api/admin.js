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

  // WhatsApp: oversight + TripSarthi's own marketing
  waOverview:         (p = {})  => api.get('/admin/whatsapp/overview?' + qs(p)).then(unwrap),
  waWorkspace:        (id)      => api.get(`/admin/whatsapp/workspaces/${id}`).then(unwrap),
  waPause:            (id, paused, reason) => api.post(`/admin/whatsapp/workspaces/${id}/marketing-pause`, { paused, reason }).then(unwrap),
  waMarketing:        ()        => api.get('/admin/whatsapp/marketing').then(unwrap),
  waSetMarketing:     (tenantId) => api.put('/admin/whatsapp/marketing', { tenant_id: tenantId }).then(unwrap),
  waSegment:          (key)     => api.get(`/admin/whatsapp/marketing/segments/${key}`).then(unwrap),
  waSync:             (key)     => api.post(`/admin/whatsapp/marketing/segments/${key}/sync`, {}).then(unwrap),

  // Partner program
  partners:           ()        => api.get('/admin/partners').then(unwrap),
  partner:            (id)      => api.get(`/admin/partners/${id}`).then(unwrap),
  createPartner:      (b)       => api.post('/admin/partners', b).then(unwrap),
  updatePartner:      (id, b)   => api.put(`/admin/partners/${id}`, b).then(unwrap),
  partnerStatus:      (id, st)  => api.post(`/admin/partners/${id}/status`, { status: st }).then(unwrap),
  partnerInvite:      (id)      => api.post(`/admin/partners/${id}/invite`, {}).then(unwrap),
  partnerPayout:      (id, b)   => api.post(`/admin/partners/${id}/payout`, b).then(unwrap),
  voidCommission:     (id, reason) => api.post(`/admin/commissions/${id}/void`, { reason }).then(unwrap),

  // Offers
  coupons:            ()        => api.get('/admin/coupons').then(unwrap),
  createCoupon:       (b)       => api.post('/admin/coupons', b).then(unwrap),
  updateCoupon:       (id, b)   => api.put(`/admin/coupons/${id}`, b).then(unwrap),
  couponRedemptions:  (id)      => api.get(`/admin/coupons/${id}/redemptions`).then(unwrap),
  promotions:         ()        => api.get('/admin/promotions').then(unwrap),
  createPromotion:    (b)       => api.post('/admin/promotions', b).then(unwrap),
  updatePromotion:    (id, b)   => api.put(`/admin/promotions/${id}`, b).then(unwrap),
  grants:             ()        => api.get('/admin/grants').then(unwrap),
  grant:              (b)       => api.post('/admin/grants', b).then(unwrap),
}
