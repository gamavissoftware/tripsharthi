/**
 * True when the web app runs INSIDE the TripSarthi phone app (a WebView): the phone supplies the menu, the title bar and the sign-in, so the web
 * menu and top bar are hidden and only the page itself is shown. `?embed=1` switches it on in a normal browser too (for testing).
 */
export function isEmbedded() {
  try {
    if (typeof window !== 'undefined' && window.ReactNativeWebView) return true
    if (/[?&]embed=1/.test(window.location.href)) sessionStorage.setItem('ts_embed', '1')
    return sessionStorage.getItem('ts_embed') === '1'
  } catch { return false }
}

/** Tell the phone app something happened (it listens with onMessage). A no-op in a normal browser. */
export function tellPhone(msg) {
  try { if (window.ReactNativeWebView) window.ReactNativeWebView.postMessage(JSON.stringify(msg)) } catch { /* not embedded */ }
}
