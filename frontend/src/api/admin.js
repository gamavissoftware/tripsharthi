import { api } from './client'

const unwrap = (r) => r.data
const qs = (o) => Object.entries(o).filter(([, v]) => v !== '' && v != null).map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&')

/** TripSarthi platform-admin API (owner of the product, not a customer workspace). */
export const admin = {
  overview:      ()          => api.get('/admin/overview').then(unwrap),
  dashboard:     ()          => api.get('/admin/dashboard').then(unwrap),
  offersSummary: ()          => api.get('/admin/offers/summary').then(unwrap),
  tenants:       (p = {})    => api.get('/admin/tenants?' + qs(p)).then(unwrap),
  tenant:        (id)        => api.get(`/admin/tenants/${id}`).then(unwrap),
  setPlan:       (id, b)     => api.put(`/admin/tenants/${id}/plan`, b).then(unwrap),
  setStatus:     (id, b)     => api.post(`/admin/tenants/${id}/status`, b).then(unwrap),
  impersonate:   (id, b)     => api.post(`/admin/tenants/${id}/impersonate`, b).then(unwrap),
  invoices:        (p = {})  => api.get('/admin/invoices?' + qs(p)).then(unwrap),
  invoiceSummary:  (p = {})  => api.get('/admin/invoices/summary?' + qs(p)).then(unwrap),
  invoiceMissing:  ()        => api.get('/admin/invoices/missing').then(unwrap),
  issueMissing:    (ids)     => api.post('/admin/invoices/issue-missing', { ids }).then(unwrap),
  invoiceSettings: ()        => api.get('/admin/invoices/settings').then(unwrap),
  saveInvoiceSettings: (b)   => api.put('/admin/invoices/settings', b).then(unwrap),
  resendInvoice:   (id)      => api.post(`/admin/invoices/${id}/resend`, {}).then(unwrap),
  creditNote:      (id, b)   => api.post(`/admin/invoices/${id}/credit-note`, b).then(unwrap),
  creditNotesFor:  (id)      => api.get(`/admin/invoices/${id}/credit-notes`).then(unwrap),
  creditNotes:     (p = {})  => api.get('/admin/credit-notes?' + qs(p)).then(unwrap),
  resendCreditNote:(id)      => api.post(`/admin/credit-notes/${id}/resend`, {}).then(unwrap),
  impersonations:(p = {})    => api.get('/admin/impersonations?' + qs(p)).then(unwrap),
  endImpersonation: (id)     => api.post(`/admin/impersonations/${id}/end`, {}).then(unwrap),
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

  // Payments: TripSarthi's own Razorpay account (secrets are write-only)
  razorpay:           ()        => api.get('/admin/payments/razorpay').then(unwrap),
  saveRazorpay:       (b)       => api.put('/admin/payments/razorpay', b).then(unwrap),
  testRazorpay:       ()        => api.post('/admin/payments/razorpay/test', {}).then(unwrap),
  clearRazorpay:      ()        => api.delete('/admin/payments/razorpay').then(unwrap),

  // Phone-app download links shown on the login screen and the website (nothing shows until one is saved)
  mobileApp:          ()        => api.get('/admin/mobile-app').then(unwrap),
  saveMobileApp:      (b)       => api.put('/admin/mobile-app', b).then(unwrap),
  // Mail server for TripSarthi's own e-mails (password is write-only)
  mail:               ()        => api.get('/admin/mail').then(unwrap),
  saveMail:           (b)       => api.put('/admin/mail', b).then(unwrap),
  testMail:           ()        => api.post('/admin/mail/test', {}).then(unwrap),
  clearMail:          ()        => api.delete('/admin/mail').then(unwrap),

  // Support tickets (customers -> the team)
  supportList:        (p = {})  => api.get('/admin/support?' + qs(p)).then(unwrap),
  supportTicket:      (id)      => api.get(`/admin/support/${id}`).then(unwrap),
  supportReply:       (id, message, status) => api.post(`/admin/support/${id}/reply`, { message, status }).then(unwrap),
  supportNote:        (id, message) => api.post(`/admin/support/${id}/note`, { message }).then(unwrap),
  supportUpdate:      (id, b)   => api.put(`/admin/support/${id}`, b).then(unwrap),

  // E-mail marketing (TripSarthi's own, consent-based)
  emailOverview:      ()        => api.get('/admin/email').then(unwrap),
  emailCampaign:      (id)      => api.get(`/admin/email/campaigns/${id}`).then(unwrap),
  emailSave:          (id, b)   => (id ? api.put(`/admin/email/campaigns/${id}`, b) : api.post('/admin/email/campaigns', b)).then(unwrap),
  emailDelete:        (id)      => api.delete(`/admin/email/campaigns/${id}`).then(unwrap),
  emailPreview:       (id)      => api.get(`/admin/email/campaigns/${id}/preview`).then(unwrap),
  emailTest:          (id)      => api.post(`/admin/email/campaigns/${id}/test`, {}).then(unwrap),
  emailSend:          (id, n)   => api.post(`/admin/email/campaigns/${id}/send`, { confirm_count: n }).then(unwrap),
  emailCancel:        (id)      => api.post(`/admin/email/campaigns/${id}/cancel`, {}).then(unwrap),

  // Partner program
  partners:           ()        => api.get('/admin/partners').then(unwrap),
  partner:            (id)      => api.get(`/admin/partners/${id}`).then(unwrap),
  createPartner:      (b)       => api.post('/admin/partners', b).then(unwrap),
  updatePartner:      (id, b)   => api.put(`/admin/partners/${id}`, b).then(unwrap),
  partnerStatus:      (id, st)  => api.post(`/admin/partners/${id}/status`, { status: st }).then(unwrap),
  partnerInvite:      (id, sendEmail = false) => api.post(`/admin/partners/${id}/invite`, { send_email: sendEmail }).then(unwrap),
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
  // TripSarthi's own CRM (Subscriber Funnel)
  crmStatus:          ()        => api.get('/admin/crm/status').then(unwrap),
  crmDashboard:       (days)    => api.get('/admin/crm/dashboard?days=' + days).then(unwrap),
  crmSetup:           ()        => api.post('/admin/crm/setup', {}).then(unwrap),
  crmReconcile:       ()        => api.post('/admin/crm/reconcile', {}).then(unwrap),
  crmFacebookConnect: (page_id, token) => api.post('/admin/crm/facebook/connect', { page_id, token }).then(unwrap),
  crmFacebookImport:  (months)  => api.post('/admin/crm/facebook/import', { months }).then(unwrap),
  crmMailRelay:       ()        => api.post('/admin/crm/mail-relay', {}).then(unwrap),
  crmAutomation:      (key, on) => api.post(`/admin/crm/automations/${key}/${on ? 'on' : 'off'}`, {}).then(unwrap),
}
