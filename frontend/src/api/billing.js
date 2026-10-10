import { api } from './client'

export const billing = {
  // Billing
  billingStatus: () =>
    api.get('/billing/status'),

  billingHistory: () =>
    api.get('/billing/history'),

  subscribe: (plan) =>
    api.post('/billing/subscribe', { plan }),

  cancelBilling: () =>
    api.post('/billing/cancel'),

  // Razorpay embedded checkout
  createOrder: (plan, billingType, coupon = '') =>
    api.post('/billing/create-order', { plan, billing: billingType, ...(coupon ? { coupon } : {}) }),

  // What each plan costs THIS customer now (promotions + an optional typed code). Display only; the server re-prices at checkout.
  quote: (billingType, code = '') =>
    api.post('/billing/quote', { billing: billingType, ...(code ? { code } : {}) }),

  verifyPayment: (data) =>
    api.post('/billing/verify-payment', data),

  // License
  licenseStatus: () =>
    api.get('/license/status'),

  activateLicense: (key) =>
    api.post('/license/activate', { key }),

  // Meta integrations
  listIntegrations: () =>
    api.get('/meta-integrations'),

  connectIntegration: (data) =>
    api.post('/meta-integrations', data),

  // Facebook Login path: { state, page_id } from the picker, or
  // { social_account_id } for a Page the Social Planner already holds.
  // No token ever passes through here.
  linkMetaPage: (data) =>
    api.post('/meta-integrations/link-page', data),

  deleteIntegration: (id) =>
    api.delete(`/meta-integrations/${id}`),

  subscribeIntegration: (id) =>
    api.post(`/meta-integrations/${id}/subscribe`),

  // Google Ads Lead Forms integrations
  listGoogleIntegrations: () =>
    api.get('/google-integrations'),

  connectGoogleIntegration: (data) =>
    api.post('/google-integrations', data),

  deleteGoogleIntegration: (id) =>
    api.delete(`/google-integrations/${id}`),

  // Auth
  register: (data) =>
    api.post('/auth/register', data),
}
