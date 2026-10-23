// Pure helpers (CommonJS, no React Native imports) so they can be unit-tested with plain Node: `npm test`.

/** Serve saved data only when the network (or the server) is the problem — never for a 4xx the user must act on (401, 422…). */
function shouldUseCache(err) {
  const st = err && err.status
  return st === undefined || st === null || st === 0 || st >= 500
}

/** "just now", "5 min ago", "3 h ago", "2 days ago" — for the "Offline — saved data from…" banner. */
function ageLabel(ms, now = Date.now()) {
  const m = Math.floor(Math.max(0, now - ms) / 60000)
  if (m < 1) return 'just now'
  if (m < 60) return m + ' min ago'
  const h = Math.floor(m / 60)
  if (h < 24) return h + ' h ago'
  const d = Math.floor(h / 24)
  return d + (d === 1 ? ' day ago' : ' days ago')
}

/** Lock when the app comes back after being away longer than the grace period. leftAt = null means a cold start (always lock). */
function needsLock(enabled, leftAt, now = Date.now(), graceMs = 60000) {
  if (!enabled) return false
  if (leftAt === null || leftAt === undefined) return true
  return now - leftAt >= graceMs
}

const digits = (s) => String(s || '').replace(/\D/g, '')

/** Same rule as the server: an Indian 10-digit mobile (6-9…), 91+10 digits, or an explicit + international number. */
function validPhone(raw) {
  const plus = String(raw || '').trim().startsWith('+')
  let d = digits(raw)
  if (!d) return false
  if (!plus) { d = d.replace(/^0+/, ''); return (d.length === 10 && d[0] >= '6') || (d.length === 12 && d.startsWith('91') && d[2] >= '6') }
  return d.length >= 8 && d.length <= 15
}

/** Client-side check before posting an enquiry (the server re-validates). Returns { field: message } — empty when fine. */
function validateEnquiry(f, today = new Date().toISOString().slice(0, 10)) {
  const e = {}
  if (!String(f.name || '').trim()) e.name = 'Enter the customer’s name'
  if (!validPhone(f.phone)) e.phone = 'Enter a valid mobile number'
  if (f.start_date && (!/^\d{4}-\d{2}-\d{2}$/.test(f.start_date) || f.start_date < today)) e.start_date = 'Use YYYY-MM-DD, today or later'
  if (f.nights !== '' && f.nights != null && !(Number(f.nights) >= 1 && Number(f.nights) <= 90)) e.nights = '1 to 90'
  if (!(Number(f.adults) >= 1)) e.adults = 'At least 1'
  return e
}

/** What the composer may do for a conversation (server is the authority; this only decides which controls to show). */
function composerMode(conv) {
  if (!conv) return 'none'
  if (conv.status === 'resolved') return 'resolved'
  return conv.send_mode === 'free_form' ? 'text' : 'template'
}

/** "23 h 10 min left" for the 24-hour window. */
function windowLabel(seconds) {
  const s = Number(seconds) || 0
  if (s <= 0) return 'Window closed — templates only'
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60)
  return h > 0 ? h + ' h ' + m + ' min left to reply freely' : m + ' min left to reply freely'
}

/** Template variables {{1}}.. in a body → count (to render one input per variable). */
function templateVarCount(body) {
  const nums = (String(body || '').match(/\{\{\s*(\d+)\s*\}\}/g) || []).map((x) => Number(x.replace(/\D/g, '')))
  return nums.length ? Math.max(...nums) : 0
}

// ---- sales targets (the numbers come from the server; these only decide how to show them) ----

/** Bar width: 0-100 even when the person is over target (the label still says 130%). */
function clampPct(p) { const n = Number(p); return Number.isFinite(n) ? Math.min(100, Math.max(0, n)) : 0 }

const TARGET_STATUS = {
  achieved: { label: 'Target hit', tone: 'ok' },
  ahead:    { label: 'Ahead of pace', tone: 'ok' },
  on_track: { label: 'On track', tone: 'info' },
  behind:   { label: 'Behind pace', tone: 'bad' },
}
/** Label + colour tone for a server pace status; unknown / none -> null (nothing to draw). */
function targetStatus(status) { return TARGET_STATUS[status] || null }

/** "₹1.9L to go · 21 days left" — fmt formats one amount (money) or is String for counts. */
function toGoText(p, fmt, daysLeft) {
  const left = Number(daysLeft)
  const days = Number.isFinite(left) ? ' · ' + (left <= 0 ? 'last day' : left + (left === 1 ? ' day left' : ' days left')) : ''
  return (p && p.to_go > 0 ? fmt(p.to_go) + ' to go' : 'Over target') + days
}

/** True when a pace block has something to draw. */
function hasTarget(p) { return !!p && p.status !== 'none' && Number(p.target) > 0 }

// ---- quotes ----

/** The API lists a trip's quotes oldest-first, so "the latest" is the highest id (a new version always has a higher id). */
function latestQuote(list) {
  if (!Array.isArray(list) || !list.length) return null
  return list.reduce((a, b) => (Number(b.id) > Number(a.id) ? b : a))
}

/** True when the quote's markup is a flat amount (set in the web app), which the phone's % box cannot show. */
function isFlatMarkup(it) { return !!it && !!it.markup_type && it.markup_type !== 'percent' }

/**
 * What to send when the person presses Update on the quote screen. A blank markup box on a FLAT-markup quote means "leave the markup
 * alone": sending 0 % there used to wipe a flat markup and cut the price. Returns { patch } or { error: [title, text] }.
 */
function quotePatch(it, markupText, discountText) {
  const d = Number(String(discountText || '').trim() === '' ? 0 : discountText)
  if (!(d >= 0)) return { error: ['Discount', 'Discount cannot be negative.'] }
  const patch = { discount_amount: Math.round(d * 100) }
  const typed = String(markupText == null ? '' : markupText).trim() !== ''
  if (typed) {
    const m = Number(markupText)
    if (!(m >= 0 && m <= 200)) return { error: ['Markup', 'Enter a markup between 0 and 200 %.'] }
    patch.markup_type = 'percent'; patch.markup_value = m
  } else if (!isFlatMarkup(it)) {
    return { error: ['Markup', 'Enter a markup between 0 and 200 %.'] }
  }
  return { patch }
}

// ---- errors ----

const LIMIT_NAMES = { ai_replies: 'AI drafts', agents: 'team members', contacts: 'contacts', active_flows: 'automations', waba_numbers: 'WhatsApp numbers', deals: 'deals' }

/** Words a travel agent can act on, instead of a code like "plan_limit_exceeded" or "Network request failed". */
function friendlyError({ status, code, message, data } = {}) {
  const raw = String(message || '')
  if (raw === 'plan_limit_exceeded' || (data && data.messages && data.messages.error === 'plan_limit_exceeded')) {
    const m = (data && data.messages) || {}
    const what = LIMIT_NAMES[m.limit_type] || 'this feature'
    return Number(m.limit) === 0
      ? `Your plan does not include ${what}. Upgrade in the web app (Billing) to use it.`
      : `Your plan's limit for ${what} (${m.limit}) is used up. Upgrade in the web app (Billing).`
  }
  if (status === undefined || status === null) return /abort|timed? ?out/i.test(raw) ? 'This is taking too long. Check your connection and try again.' : 'Cannot reach TripSarthi. Check your internet connection and try again.'
  if (status === 403 && (code === 'tenant_suspended' || /suspended|cancelled/i.test(raw))) return raw || 'This workspace is suspended. Please contact TripSarthi support.'
  if (status === 429) return 'Too many tries. Please wait a minute and try again.'
  if (status >= 500) return 'TripSarthi had a problem on our side. Please try again in a moment.'
  return raw || `Something went wrong (${status}).`
}

// ---- display helpers (pure) ----

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

/** Rupees from paise with Indian grouping: 12345678 -> "₹1,23,457". */
function inrFull(paise) { const rs = Math.round(Number(paise || 0) / 100); return (rs < 0 ? '-' : '') + '₹' + Math.abs(rs).toLocaleString('en-IN') }

/** Compact rupees for tiles: ₹2.78 Cr, ₹1.9 L, ₹45K, ₹900. Negative values keep their sign. */
function inrShort(paise) {
  const rs = Number(paise || 0) / 100, a = Math.abs(rs), sign = rs < 0 ? '-' : ''
  const f = (n, d) => String(Number(n.toFixed(d)))
  if (a >= 1e7) return sign + '₹' + f(a / 1e7, 2) + ' Cr'
  if (a >= 1e5) return sign + '₹' + f(a / 1e5, 2) + ' L'
  if (a >= 1e3) return sign + '₹' + f(a / 1e3, 1) + 'K'
  return sign + '₹' + Math.round(a)
}

/** Change against last month as a whole percent; null when there is nothing to compare with. */
function deltaPct(value, prev) {
  const v = Number(value), p = Number(prev)
  if (!Number.isFinite(v) || !Number.isFinite(p)) return null
  if (p === 0) return v === 0 ? 0 : null
  return Math.round(((v - p) / Math.abs(p)) * 100)
}

/** "30 Oct" / "30 Oct 2027" (year only when it is not the current year) from "2026-10-30" or "2026-10-30 09:00:00". */
function fmtDay(s, now = new Date()) {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''))
  if (!m) return ''
  const label = Number(m[3]) + ' ' + MONTHS[Number(m[2]) - 1]
  return Number(m[1]) === now.getFullYear() ? label : label + ' ' + m[1]
}

/** Whole days from today (India date) to a "YYYY-MM-DD": 0 today, negative = past. */
function daysFrom(dateStr, todayStr) {
  const a = Date.UTC(...String(dateStr).slice(0, 10).split('-').map((x, i) => (i === 1 ? Number(x) - 1 : Number(x))))
  const b = Date.UTC(...String(todayStr).slice(0, 10).split('-').map((x, i) => (i === 1 ? Number(x) - 1 : Number(x))))
  return Math.round((a - b) / 86400000)
}

/** "today" / "tomorrow" / "in 5 days" / "2 days ago" for a due date. */
function dueLabel(dateStr, todayStr) {
  if (!dateStr) return ''
  const n = daysFrom(dateStr, todayStr)
  if (n === 0) return 'today'
  if (n === 1) return 'tomorrow'
  if (n === -1) return 'yesterday'
  return n > 0 ? 'in ' + n + ' days' : Math.abs(n) + ' days ago'
}

/** Today in India as "YYYY-MM-DD" regardless of the phone's time zone. */
function indiaToday(now = new Date()) { return new Date(now.getTime() + 330 * 60000).toISOString().slice(0, 10) }

/** "Good morning / afternoon / evening" by India time. */
function greeting(now = new Date()) {
  const h = new Date(now.getTime() + 330 * 60000).getUTCHours()
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening'
}

/** Up to two capital letters for an avatar. */
function initials(name) {
  const w = String(name || '').trim().split(/\s+/).filter(Boolean)
  if (!w.length) return '?'
  return (w[0][0] + (w.length > 1 ? w[w.length - 1][0] : '')).toUpperCase()
}

/** Percent of `part` in `whole`, 0-100, safe for zero. */
function pctOf(part, whole) { const w = Number(whole); return w > 0 ? Math.max(0, Math.min(100, Math.round((Number(part) / w) * 100))) : 0 }

// ---- app updates ----

/** -1 / 0 / 1 comparing "1.10.0" with "1.9.2" numerically (a string comparison would get 1.10 wrong). Unparseable = 0. */
function compareVersions(a, b) {
  const pa = String(a || '').split('.').map(Number), pb = String(b || '').split('.').map(Number)
  if (pa.some(Number.isNaN) || pb.some(Number.isNaN)) return 0
  for (let i = 0; i < 3; i++) { const x = pa[i] || 0, y = pb[i] || 0; if (x !== y) return x < y ? -1 : 1 }
  return 0
}

/** What to tell this phone about updates: 'required' (older than the oldest allowed), 'available' (older than the newest), or 'none'. */
function updateState(current, info) {
  if (!info) return 'none'
  if (info.min_version && compareVersions(current, info.min_version) < 0) return 'required'
  if (info.latest_version && compareVersions(current, info.latest_version) < 0) return 'available'
  return 'none'
}

/** The link to download the new version: the Android link the team saved (Play Store or a direct file). */
function updateLink(info) { return (info && info.android) || null }

// ---- chat attachments ----

/** WhatsApp message type for a picked file, from its mime type: image | video | audio | document. */
function mediaKind(mime) {
  const m = String(mime || '').toLowerCase()
  if (m.startsWith('image/')) return 'image'
  if (m.startsWith('video/')) return 'video'
  if (m.startsWith('audio/')) return 'audio'
  return 'document'
}

/** Short size text for an attachment: "340 KB", "2.4 MB". */
function fileSize(bytes) {
  const n = Number(bytes)
  if (!Number.isFinite(n) || n <= 0) return ''
  if (n < 1024 * 1024) return Math.max(1, Math.round(n / 1024)) + ' KB'
  return (Math.round((n / 1048576) * 10) / 10) + ' MB'
}


/** What to do with a saved-offline item whose send failed: keep it for later, wait for sign-in, or give up (the server said no). */
function outboxVerdict(err) {
  const st = err && err.status
  if (st === 401) return 'auth'
  if (st === undefined || st === null || st === 0 || st >= 500 || st === 429 || st === 408) return 'retry'
  if (st === 409) return 'retry'          // the first copy of this very request is still being processed: ask again later
  return 'drop'
}

/** A random key that makes a retried request count once on the server (Idempotency-Key header). */
function newIdemKey(now = Date.now(), rnd = Math.random) {
  return 'tp-' + now.toString(36) + '-' + Math.floor(rnd() * 1e12).toString(36) + Math.floor(rnd() * 1e12).toString(36)
}


const ITEM_TYPES = ['hotel', 'transfer', 'sightseeing', 'activity', 'flight', 'visa', 'insurance', 'meal', 'other']
const UNIT_LABEL = { per_night: 'per night', per_pax: 'per person', per_vehicle: 'per vehicle', per_day: 'per day', per_room: 'per room', flat: 'flat' }

/** A rate card's service type as a quote-line type (anything unknown is "other"). */
function itemTypeOf(serviceType) { return ITEM_TYPES.includes(serviceType) ? serviceType : 'other' }

/** What to suggest in Quantity for a rate, from the trip's travellers (the agent can always change it). Same rule as the web builder. */
function suggestQty(unit, trip) {
  const adults = Number(trip && trip.adults) || 0, kids = Number(trip && trip.children) || 0
  if (unit === 'per_pax') return Math.max(1, adults + kids)
  if (unit === 'per_room' || unit === 'per_night') return Math.max(1, Math.ceil((adults || 1) / 2))
  return 1
}

/** ok / expired / future, from the rate card's validity dates (YYYY-MM-DD). */
function rateState(r, today = new Date().toISOString().slice(0, 10)) {
  if (r.valid_to && String(r.valid_to).slice(0, 10) < today) return 'expired'
  if (r.valid_from && String(r.valid_from).slice(0, 10) > today) return 'future'
  return 'ok'
}

/** A line's total in paise: hotels multiply by nights as well. The server recomputes this; the phone only previews it. */
function itemTotal(type, unitPaise, qty, nights) {
  const q = Number(qty) || 0, n = Math.max(1, Number(nights) || 1)
  return Math.round((Number(unitPaise) || 0) * (type === 'hotel' ? q * n : q))
}

/** Rupees typed in a box -> paise (null when it is not a valid non-negative amount). */
function rupeesToPaise(v) {
  const t = String(v == null ? '' : v).replace(/,/g, '').trim()
  if (t === '' || !/^\d*\.?\d{0,2}$/.test(t) || t === '.') return null
  return Math.round(parseFloat(t) * 100)
}

/** Check an add/edit line form. Returns { field: message }, empty when fine. canCost = owners/admins, who also set the price. */
function validateItem(f, { canCost = false } = {}) {
  const e = {}
  if (!String(f.title || '').trim()) e.title = 'Give the service a title'
  if (!(Number(f.quantity) > 0) || Number(f.quantity) > 9999) e.quantity = 'Enter a quantity of 1 or more'
  if (f.type === 'hotel' && !(Number.isInteger(Number(f.nights)) && Number(f.nights) >= 1 && Number(f.nights) <= 365)) e.nights = 'Enter whole nights, 1 or more'
  if (canCost && !f.priceLocked && rupeesToPaise(f.price) === null) e.price = 'Enter the price per unit (0 if not known yet)'
  return e
}

/** "₹4,500 per night × 2 × 3N" under a line, or '' when there is nothing to explain. */
function itemWorking(i, unit) {
  const qty = Number(i.quantity) || 1, nights = Number(i.nights) || 1
  if (!(Number(i.unit_cost) > 0) || (qty === 1 && !(i.type === 'hotel' && nights > 1))) return ''
  return `₹${(Number(i.unit_cost) / 100).toLocaleString('en-IN', { maximumFractionDigits: 2 })}${unit && UNIT_LABEL[unit] ? ' ' + UNIT_LABEL[unit] : ''} × ${qty}${i.type === 'hotel' ? ` × ${nights}N` : ''}`
}


const addMonthsIso = (iso, m) => { const d = new Date(String(iso).slice(0, 10) + 'T00:00:00Z'); d.setUTCMonth(d.getUTCMonth() + m); return d.toISOString().slice(0, 10) }

/**
 * Is this traveller's passport fine for the trip? International trips need a number on file and a passport valid for 6 months AFTER the trip
 * ends (most countries refuse entry otherwise). Returns { key, label, tone } where tone is a Pill tone.
 */
function passportStatus(t, { tripEnd, today = new Date().toISOString().slice(0, 10), international = true } = {}) {
  const exp = t.passport_expiry ? String(t.passport_expiry).slice(0, 10) : ''
  if (!t.has_passport && !exp) return international ? { key: 'missing', label: 'No passport', tone: 'warn' } : { key: 'none', label: 'No passport needed', tone: 'neutral' }
  if (exp && exp < today) return { key: 'expired', label: 'Expired', tone: 'danger' }
  if (exp && addMonthsIso(tripEnd || today, 6) > exp) return { key: 'short', label: 'Under 6 months', tone: 'warn' }
  if (!t.has_passport) return { key: 'no_number', label: 'No number', tone: 'warn' }
  if (!exp) return { key: 'no_expiry', label: 'No expiry date', tone: 'warn' }
  return { key: 'ok', label: 'Passport OK', tone: 'ok' }
}

const DOC_STATUS = { pending: ['Missing', 'warn'], uploaded: ['Check it', 'info'], received: ['Received', 'ok'], not_applicable: ['Not needed', 'neutral'] }
/** [label, Pill tone] for a checklist item (overdue pending items are shown as such). */
function docStatus(i) { return i.overdue ? ['Overdue', 'danger'] : (DOC_STATUS[i.status] || [i.status, 'neutral']) }

/** Checklist items grouped per traveller (booking-wide items under "Everyone"), in the order the travellers were added. */
function groupChecklist(items, travelers) {
  const by = new Map()
  for (const i of items || []) { const k = Number(i.traveler_id) || 0; if (!by.has(k)) by.set(k, []); by.get(k).push(i) }
  const out = []
  for (const t of travelers || []) { if (by.has(Number(t.id))) { out.push({ traveler: t, name: t.full_name, items: by.get(Number(t.id)) }); by.delete(Number(t.id)) } }
  for (const [k, list] of by) out.push({ traveler: null, name: k === 0 ? 'Everyone' : 'Traveller', items: list })
  return out
}

module.exports = {
  passportStatus, docStatus, groupChecklist,
  ITEM_TYPES, UNIT_LABEL, itemTypeOf, suggestQty, rateState, itemTotal, rupeesToPaise, validateItem, itemWorking,
  outboxVerdict, newIdemKey, shouldUseCache, ageLabel, needsLock, digits, validPhone, validateEnquiry, composerMode, windowLabel, templateVarCount, clampPct, targetStatus, toGoText, hasTarget, latestQuote, isFlatMarkup, quotePatch, friendlyError, inrFull, inrShort, deltaPct, fmtDay, daysFrom, dueLabel, indiaToday, greeting, initials, pctOf, compareVersions, updateState, updateLink, mediaKind, fileSize }
