import { api, saveToken, clearToken } from './client'

/** Sign-in for the separate platform-admin app (its own session; never a customer token). */
export const adminAuth = {
  login:  async (email, password) => { const r = await api.post('/admin-auth/login', { email, password }); saveToken(r.token); return r },
  me:     () => api.get('/admin-auth/me').then(r => r.user),
  logout: async () => { try { await api.post('/admin-auth/logout', {}) } catch { /* the session may already be gone */ } clearToken() },
}
