// Partner referral: a visit through a partner's link (?ref=CODE, before or inside the #hash) is remembered for 90 days and sent with the
// sign-up. The server validates the code and decides attribution (first touch wins); this file only remembers and forwards it.
const KEY = 'tp_ref'
const DAYS = 90
const OK = /^[A-Za-z0-9][A-Za-z0-9_-]{2,19}$/

export function captureReferral() {
  try {
    const m = /[?&]ref=([A-Za-z0-9_-]{3,20})(?:&|$)/.exec(window.location.search + '&' + window.location.hash.replace('#', '&'))
    if (!m || !OK.test(m[1])) return
    if (readReferral()) return                                                   // first touch wins: never replace an existing, still-valid referral
    localStorage.setItem(KEY, JSON.stringify({ code: m[1].toUpperCase(), at: Date.now() }))
  } catch { /* private mode / storage blocked: the referral is simply not remembered */ }
}

export function readReferral() {
  try {
    const v = JSON.parse(localStorage.getItem(KEY) || 'null')
    if (!v || !OK.test(v.code) || Date.now() - v.at > DAYS * 86400_000) return null
    return v.code
  } catch { return null }
}

export function clearReferral() { try { localStorage.removeItem(KEY) } catch { /* ignore */ } }
