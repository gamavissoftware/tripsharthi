// "Log in as customer": the TripSarthi team opens the customer app from the admin app with a separate, time-limited support token.
// The token lives in sessionStorage (this tab only) and is served to the existing code as if it were the usual `tp_token`, so the owner's own
// login in localStorage is never read, overwritten or removed, and closing the tab ends the visit.
const KEY = 'tp_imp_token'

const ss = () => { try { return window.sessionStorage } catch { return null } }

export function isImpersonating() {
  try { return Boolean(ss()?.getItem(KEY)) } catch { return false }
}

export function endLocalSupportSession() {
  try { ss()?.removeItem(KEY) } catch { /* nothing to clear */ }
  document.body.classList.remove('imp-active')
}

/** Call once, before the app renders. Picks the token out of #/impersonate/<token> (and removes it from the address bar), then redirects reads of `tp_token`. */
export function setupImpersonation() {
  const m = window.location.hash.match(/^#\/impersonate\/([a-f0-9]{64})$/)
  if (m) {
    try { ss()?.setItem(KEY, m[1]) } catch { /* private mode: the session simply will not open */ }
    window.history.replaceState(null, '', window.location.pathname + window.location.search + '#/')
  }
  if (!isImpersonating()) return
  document.body.classList.add('imp-active')
  const proto = Storage.prototype
  const get = proto.getItem, set = proto.setItem, rem = proto.removeItem
  proto.getItem = function (k) { return this === window.localStorage && k === 'tp_token' ? get.call(window.sessionStorage, KEY) : get.call(this, k) }
  proto.setItem = function (k, v) { if (this === window.localStorage && k === 'tp_token') return undefined; return set.call(this, k, v) }
  proto.removeItem = function (k) { if (this === window.localStorage && k === 'tp_token') { rem.call(window.sessionStorage, KEY); return undefined } return rem.call(this, k) }
}

/** The admin app's address, for "back to admin" after ending a session. */
export function adminUrl() {
  const h = window.location.hostname
  if (h === 'localhost' || h === '127.0.0.1') return 'http://localhost:5918'
  return 'https://' + h.replace(/^app\./, 'admin.')
}
