import { api, saveToken, clearToken } from './client'

/** The referral-partner portal API (its own login + session; never a customer or admin token). */
export const partnerApi = {
  login:      async (email, password) => { const r = await api.post('/partner-auth/login', { email, password }); saveToken(r.token); return r },
  inviteInfo: (token) => api.get(`/partner-auth/invite/${encodeURIComponent(token)}`).then(r => r.data),
  accept:     async (token, password) => { const r = await api.post('/partner-auth/accept', { token, password }); if (r.token) saveToken(r.token); return r },
  me:         () => api.get('/partner-auth/me').then(r => r.partner),
  logout:     async () => { try { await api.post('/partner-auth/logout', {}) } catch { /* the session may already be gone */ } clearToken() },
  dashboard:  () => api.get('/partner/dashboard').then(r => r.data),
  savePayout: (body) => api.put('/partner/payout-details', body).then(r => r.data),
  changePassword: (current, next) => api.put('/partner/password', { current, new: next }).then(r => r.data),
}
