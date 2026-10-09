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

module.exports = { shouldUseCache, ageLabel, needsLock, digits, validPhone, validateEnquiry, composerMode, windowLabel, templateVarCount }
