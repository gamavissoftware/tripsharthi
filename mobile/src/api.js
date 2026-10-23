import { getItem, setItem, deleteItem } from './storage'
import { API_URL } from './config'
import { cached, cacheClear } from './cache'
import { friendlyError } from './logic'

const KEY = 'tp_token'
let token = null
let onUnauthorized = () => {}

export const setUnauthorizedHandler = (fn) => { onUnauthorized = fn }
// ---- the TripSarthi team (platform admin) has its OWN session, separate from a customer sign-in ----
const ADMIN_KEY = 'tp_admin_token'
let adminToken = null
let onAdminUnauthorized = () => {}
export const setAdminUnauthorizedHandler = (fn) => { onAdminUnauthorized = fn }
export const adminSessionEnded = () => { adminToken = null; deleteItem(ADMIN_KEY).catch(() => {}); onAdminUnauthorized() }
export async function loadAdminToken() { adminToken = await getItem(ADMIN_KEY); return adminToken }
export const getAdminToken = () => adminToken
export async function clearAdminToken() { adminToken = null; await deleteItem(ADMIN_KEY) }
export const adminAuth = {
  /** Signs in as the TripSarthi team. 401 = wrong details or not an admin (the same words for both, on purpose). */
  async login(email, password) {
    let res
    try { res = await fetch(`${API_URL}/api/v1/admin-auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-TS-Client': 'mobile' }, body: JSON.stringify({ email, password }) }) }
    catch (e) { throw new Error(friendlyError({ message: e && e.message })) }
    const data = await res.json().catch(() => ({}))
    if (!res.ok || !data.token) throw new Error(friendlyError({ status: res.status, message: data.message || (data.messages && Object.values(data.messages).find(v => typeof v === 'string')) }))
    adminToken = data.token; await setItem(ADMIN_KEY, data.token)
    return data
  },
  async logout() {
    const t = adminToken
    await clearAdminToken()
    if (t) fetch(`${API_URL}/api/v1/admin-auth/logout`, { method: 'POST', headers: { Authorization: `Bearer ${t}` } }).catch(() => {})
  },
}

export async function loadToken() { token = await getItem(KEY); return token }
/** The signed-in token, for the web pages shown inside the app (they sign in with the same session). */
export const getToken = () => token
export async function saveToken(t) { token = t; await setItem(KEY, t) }
export async function clearToken() { token = null; await deleteItem(KEY) }

const TIMEOUT_MS = 20000   // a dead connection must fail (and fall back to saved data) instead of spinning forever

export async function request(method, path, body, extraHeaders) {
  const ctl = new AbortController()
  const timer = setTimeout(() => ctl.abort(), TIMEOUT_MS)
  let res
  try {
    res = await fetch(`${API_URL}/api/v1${path}`, {
      method,
      headers: { 'Content-Type': 'application/json', 'X-TS-Client': 'mobile', ...(extraHeaders || {}), ...(token ? { Authorization: `Bearer ${token}` } : {}) },
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

/** Multipart upload of one picked file ({ uri, name, mimeType }): fetch builds the form boundary itself. */
async function upload(path, file) {
  const form = new FormData()
  form.append('file', { uri: file.uri, name: file.name || 'file', type: file.mimeType || 'application/octet-stream' })
  const ctl = new AbortController(); const timer = setTimeout(() => ctl.abort(), 90000)       // big photos on slow data need longer than a normal call
  let res
  try { res = await fetch(`${API_URL}/api/v1${path}`, { method: 'POST', headers: { 'X-TS-Client': 'mobile', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: form, signal: ctl.signal }) }
  catch (e) { throw Object.assign(new Error(friendlyError({ message: e && e.name === 'AbortError' ? 'timed out' : e && e.message })), { status: undefined }) }
  finally { clearTimeout(timer) }
  const data = await res.json().catch(() => ({}))
  if (res.status === 401) { await clearToken(); await cacheClear(); onUnauthorized() }
  if (!res.ok || data.success === false) throw Object.assign(new Error(friendlyError({ status: res.status, message: data.error || data.message || (data.messages && Object.values(data.messages).find(v => typeof v === 'string')), data })), { status: res.status })
  return data
}

export const api = {
  login: (email, password) => request('POST', '/auth/login', { email, password }),
  // Reads go through the offline cache: { data, stale, at }. Writes below never do.
  tripsC: (status) => cached(`trips:${status || 'all'}`, () => request('GET', `/trips${status && status !== 'all' ? `?status=${status}` : ''}`).then(r => r.data)),
  // the enquiry list is paged (40 at a time, newest first): `before` = the smallest id already on screen
  tripsPageC: (status, q) => cached(`trips40:${status || 'all'}:${q || ''}`, () => request('GET', `/trips?limit=40${status && status !== 'all' ? `&status=${status}` : ''}${q ? `&q=${encodeURIComponent(q)}` : ''}`).then(r => r.data)),
  tripsMore: (status, q, before) => request('GET', `/trips?limit=40&before_id=${Number(before)}${status && status !== 'all' ? `&status=${status}` : ''}${q ? `&q=${encodeURIComponent(q)}` : ''}`).then(r => r.data),
  tripC: (id) => cached(`trip:${id}`, () => request('GET', `/trips/${id}`).then(r => r.data)),
  trips: (status) => request('GET', `/trips${status && status !== 'all' ? `?status=${status}` : ''}`).then(r => r.data),
  trip: (id) => request('GET', `/trips/${id}`).then(r => r.data),
  quickEnquiry: (b) => request('POST', '/trips/quick', b).then(r => r.data),
  aiItinerary: (tripId) => request('POST', `/trips/${tripId}/ai-itinerary`).then(r => r.data),
  itinerary: (id) => request('GET', `/itineraries/${id}`).then(r => r.data),
  // quote lines: add (day_id omitted = "extras" outside any day), edit, remove; every one answers with the repriced quote on the next read
  rateCards: () => cached('ratecards', () => Promise.all([request('GET', '/travel/rates'), request('GET', '/travel/suppliers').catch(() => ({ data: [] }))]).then(([r, sp]) => { const names = {}; (sp.data || []).forEach(x => { names[x.id] = x.name }); return (r.data || []).map(x => ({ ...x, supplier_name: names[x.supplier_id] || '' })) })),
  createItinerary: (b) => request('POST', '/itineraries', b).then(r => r.data),
  addDay: (itId, b) => request('POST', `/itineraries/${itId}/days`, b).then(r => r.data),
  addItem: (itId, b) => request('POST', `/itineraries/${itId}/items`, b).then(r => r.data),
  editItem: (itId, itemId, b) => request('PUT', `/itineraries/${itId}/items/${itemId}`, b).then(r => r.data),
  removeItem: (itId, itemId) => request('DELETE', `/itineraries/${itId}/items/${itemId}`).then(r => r.data),
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
  markPaid: (paymentId, mode, reference) => request('POST', `/bookings/payments/${paymentId}/paid`, { mode, ...(reference ? { reference } : {}) }).then(r => r.data),
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
  assign: (id, userId) => request('PATCH', `/inbox/${id}/assign`, { user_id: userId || 0 }),
  resolve: (id) => request('PATCH', `/inbox/${id}/resolve`),
  reopen: (id) => request('PATCH', `/inbox/${id}/reopen`),
  // a picked file: first it goes to WhatsApp (returns a media id), then it is sent as a message
  uploadMedia: (id, file) => upload(`/inbox/${id}/upload-media`, file),
  sendMedia: (id, m) => request('POST', `/inbox/${id}/messages`, { type: m.type, media_id: m.media_id, caption: m.caption || undefined, filename: m.filename }).then(r => r.data),
  mediaUrl: (mediaId) => `${API_URL}/api/v1/inbox/media/${encodeURIComponent(mediaId)}?token=${encodeURIComponent(token || '')}`,
  // find a word in the messages of every conversation (3+ characters)
  search: (q) => request('GET', `/inbox/search?q=${encodeURIComponent(q)}`).then(r => r.data),
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
// ---- travellers, passports and the document checklist ----
export const docsApi = {
  addTraveler: (bookingId, b) => request('POST', `/bookings/${bookingId}/travelers`, b).then(r => r.data),
  updateTraveler: (id, b) => request('PATCH', `/bookings/travelers/${id}`, b).then(r => r.data),
  deleteTraveler: (id) => request('DELETE', `/bookings/travelers/${id}`).then(r => r.data),
  /** The passport number itself. The server writes every look to the audit log. */
  passport: (id) => request('GET', `/bookings/travelers/${id}/passport`).then(r => r.data.passport_no),
  checklist: (bookingId) => request('GET', `/checklists/bookings/${bookingId}`).then(r => r.data),
  setItem: (itemId, status, notes) => request('PATCH', `/checklists/items/${itemId}`, { status, ...(notes != null ? { notes } : {}) }).then(r => r.data),
  uploads: (itemId) => request('GET', `/portal/items/${itemId}/uploads`).then(r => r.data),
  review: (uploadId, accept, reason) => request('POST', `/portal/uploads/${uploadId}/review`, { decision: accept ? 'accept' : 'reject', ...(reason ? { reason } : {}) }),
  /** A picked photo / PDF attached by staff (counts as received at once). */
  attach: (itemId, file) => upload(`/portal/items/${itemId}/staff-upload`, file),
  portalLink: (bookingId) => request('POST', `/portal/bookings/${bookingId}/link`).then(r => r.data),
  /** An <Image> source for an uploaded photo (needs the sign-in header; PDFs are opened on the web screen). */
  fileSource: (uploadId) => ({ uri: `${API_URL}/api/v1/portal/uploads/${uploadId}/file`, headers: { Authorization: `Bearer ${token}`, 'X-TS-Client': 'mobile' } }),
}

export const contactApi = {
  get: (id) => request('GET', `/contacts/${id}`).then(r => r.data),
  // q searches name / number / e-mail; the server pages 25 at a time
  listC: (q, page = 1) => cached(`contacts:${q || ''}:${page}`, () => request('GET', `/contacts?per_page=25&page=${page}${q ? `&q=${encodeURIComponent(q)}` : ''}`).then(r => ({ rows: r.data, pager: r.pager }))),
  summaryC: () => cached('contacts:summary', () => request('GET', '/contacts/summary').then(r => r.data)),
  create: (b) => request('POST', '/contacts', b).then(r => r.data),
  trips: (id) => request('GET', `/trips?contact_id=${id}`).then(r => r.data),
}

// ---- the dashboard, tasks, meetings, departures, reports: the same figures the web app shows ----
export const biz = {
  me: () => request('GET', '/auth/me').then(r => r.user),
  // owners/admins may pass a user id to look at one person; agents are always limited to their own numbers by the server
  homeC: (userId) => cached(`home:${userId || 'all'}`, () => request('GET', `/home${userId ? `?user=${userId}` : ''}`).then(r => r.data)),
  tasksC: (status = 'open') => cached(`tasks:${status}`, () => request('GET', `/tasks?status=${status}`).then(r => r.data)),
  completeTask: (id) => request('POST', `/tasks/${id}/complete`).then(r => r.data),
  createTask: (b) => request('POST', '/tasks', b).then(r => r.data),
  meetingsC: (from, to) => cached(`meetings:${from}:${to}`, () => request('GET', `/meetings?from=${from}&to=${to}`).then(r => r.data)),
  departuresC: () => cached('departures', () => request('GET', '/departures').then(r => r.data)),
  bookingsC: () => cached('bookings', () => request('GET', '/bookings').then(r => r.data)),
  tripsSearchC: (q, status) => cached(`tripsq:${q}:${status || 'all'}`, () => request('GET', `/trips?q=${encodeURIComponent(q)}${status && status !== 'all' ? `&status=${status}` : ''}`).then(r => r.data)),
  reportC: (from, to) => cached(`report:${from}:${to}`, () => request('GET', `/travel-reports?from=${from}&to=${to}`).then(r => r.data)),
}

// ---- app info (public) and crash reports ----
export const appInfo = {
  /** { latest_version, min_version, android, ios, available } or null when the server cannot be reached */
  get: () => fetch(`${API_URL}/api/v1/public/mobile-app`, { headers: { Accept: 'application/json' } }).then(r => (r.ok ? r.json() : null)).then(j => (j && j.data) || null).catch(() => null),
  report: (p) => fetch(`${API_URL}/api/v1/public/app-error`, { method: 'POST', headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: JSON.stringify(p) }).catch(() => {}),
}
