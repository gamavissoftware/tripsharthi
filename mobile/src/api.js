import * as SecureStore from 'expo-secure-store'
import { API_URL } from './config'
import { cached, cacheClear } from './cache'
import { friendlyError } from './logic'

const KEY = 'tp_token'
let token = null
let onUnauthorized = () => {}

export const setUnauthorizedHandler = (fn) => { onUnauthorized = fn }
export async function loadToken() { token = await SecureStore.getItemAsync(KEY); return token }
export async function saveToken(t) { token = t; await SecureStore.setItemAsync(KEY, t) }
export async function clearToken() { token = null; await SecureStore.deleteItemAsync(KEY) }

const TIMEOUT_MS = 20000   // a dead connection must fail (and fall back to saved data) instead of spinning forever

async function request(method, path, body) {
  const ctl = new AbortController()
  const timer = setTimeout(() => ctl.abort(), TIMEOUT_MS)
  let res
  try {
    res = await fetch(`${API_URL}/api/v1${path}`, {
      method,
      headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
      body: body ? JSON.stringify(body) : undefined,
      signal: ctl.signal,
    })
  } catch (e) {
    // no status: the network (or the timeout) is the problem; callers may serve saved data (see shouldUseCache)
    throw Object.assign(new Error(friendlyError({ message: e && e.name === 'AbortError' ? 'timed out' : e && e.message })), { status: undefined })
  } finally { clearTimeout(timer) }
  const data = await res.json().catch(() => ({}))
  if (res.status === 401) { await clearToken(); await cacheClear(); onUnauthorized() }   // an expired session must not leave this person's customers on the phone
  if (!res.ok) {
    const m = data.message || (data.messages && Object.values(data.messages).find(v => typeof v === 'string'))
    // status lets callers tell "server said no" (422, 409…) from "server/network is down" (see shouldUseCache)
    throw Object.assign(new Error(friendlyError({ status: res.status, code: data.code, message: m, data })), { status: res.status, code: data.code, data })
  }
  return data
}

export const api = {
  login: (email, password) => request('POST', '/auth/login', { email, password }),
  // Reads go through the offline cache: { data, stale, at }. Writes below never do.
  tripsC: (status) => cached(`trips:${status || 'all'}`, () => request('GET', `/trips${status && status !== 'all' ? `?status=${status}` : ''}`).then(r => r.data)),
  tripC: (id) => cached(`trip:${id}`, () => request('GET', `/trips/${id}`).then(r => r.data)),
  trips: (status) => request('GET', `/trips${status && status !== 'all' ? `?status=${status}` : ''}`).then(r => r.data),
  trip: (id) => request('GET', `/trips/${id}`).then(r => r.data),
  quickEnquiry: (b) => request('POST', '/trips/quick', b).then(r => r.data),
  aiItinerary: (tripId) => request('POST', `/trips/${tripId}/ai-itinerary`).then(r => r.data),
  itinerary: (id) => request('GET', `/itineraries/${id}`).then(r => r.data),
  updateItinerary: (id, b) => request('PUT', `/itineraries/${id}`, b).then(r => r.data),
  bookingC: (id) => cached(`booking:${id}`, () => request('GET', `/bookings/${id}`).then(r => r.data)),
  duesC: () => cached('dues', async () => { const [d, b] = await Promise.all([request('GET', '/bookings/dashboard').then(r => r.data), request('GET', '/bookings').then(r => r.data)]); return { dash: d, bookings: b } }),
  // sales targets (cached like the other reads). Agents get their own bars; owners/admins get the team, or one person with a user id.
  targetsC: (userId) => cached(`targets:${userId || 'all'}`, () => request('GET', `/home/targets${userId ? `?user=${userId}` : ''}`).then(r => r.data)),
  setTripStatus: (id, status) => request('POST', `/trips/${id}/status`, { status }).then(r => r.data),
  shareItinerary: (id) => request('POST', `/itineraries/${id}/share`).then(r => r.data),
  aiParse: (text) => request('POST', '/trips/ai-parse', { text }).then(r => r.data),
  createTrip: (b) => request('POST', '/trips', b).then(r => r.data),
  bookings: () => request('GET', '/bookings').then(r => r.data),
  booking: (id) => request('GET', `/bookings/${id}`).then(r => r.data),
  markPaid: (paymentId, mode) => request('POST', `/bookings/payments/${paymentId}/paid`, { mode }).then(r => r.data),
  paymentLink: (paymentId) => request('POST', `/bookings/payments/${paymentId}/link`).then(r => r.data),
  remind: (paymentId) => request('POST', `/bookings/payments/${paymentId}/remind`).then(r => r.data),
  dashboard: () => request('GET', '/bookings/dashboard').then(r => r.data),
}

// ---- WhatsApp inbox ----
export const chatApi = {
  list: () => cached('chats', () => request('GET', '/inbox?per_page=50').then(r => r.data)),
  one: (id) => request('GET', `/inbox/${id}`).then(r => r.data),
  // afterId = the id of the newest message we hold (an exact cursor: a timestamp cannot tell apart messages that arrive in the same second)
  messages: (id, afterId) => request('GET', `/inbox/${id}/messages${afterId ? `?after_id=${Number(afterId)}` : '?limit=60'}`).then(r => r.data),
  messagesC: (id) => cached(`chat:${id}`, () => request('GET', `/inbox/${id}/messages?limit=60`).then(r => r.data)),
  sendText: (id, body) => request('POST', `/inbox/${id}/messages`, { type: 'text', body }).then(r => r.data),
  sendTemplate: (id, tpl, variables) => request('POST', `/inbox/${id}/messages`, { type: 'template', template_id: tpl.id, template_name: tpl.name, language: tpl.language || 'en', variables }).then(r => r.data),
  markRead: (id) => request('PATCH', `/inbox/${id}/mark-read`),
  approvedTemplates: () => cached('tpl:approved', () => request('GET', '/templates?meta_status=approved').then(r => r.data)),
}

// Money is integer paise on the wire.
export const inr = (p) => '₹' + (Number(p || 0) / 100).toLocaleString('en-IN', { maximumFractionDigits: 0 })

// ---- notifications / push ----
export const pushApi = {
  feed: () => request('GET', '/notifications/feed'),                       // { data:[…], unread }
  markRead: (id) => request('POST', `/notifications/${id}/read`),
  markAllRead: () => request('POST', '/notifications/read-all'),
  registerDevice: (b) => request('POST', '/mobile/devices', b).then(r => r.data),
  unregisterDevice: (expo_token) => request('DELETE', '/mobile/devices', { expo_token }),
  devices: () => request('GET', '/mobile/devices').then(r => r.data),
  preferences: () => request('GET', '/mobile/push/preferences').then(r => r.data),
  savePreferences: (b) => request('PUT', '/mobile/push/preferences', b).then(r => r.data),
  test: () => request('POST', '/mobile/push/test').then(r => r.data),
  log: () => request('GET', '/mobile/push/log').then(r => r.data),
}
export const contactApi = {
  get: (id) => request('GET', `/contacts/${id}`).then(r => r.data),
}
