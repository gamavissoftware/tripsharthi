/**
 * Meta Pixel for the customer app (app.tripsarthi.com). The pixel id (public, not a secret) defaults to TripSarthi's own; VITE_META_PIXEL_ID overrides it at build time.
 * Not loaded inside the phone app's WebView, and only for the customer entry (the admin and partner apps never call this).
 * The app routes by URL hash, so every route change is reported as a PageView.
 */
import { isEmbedded } from './embedded'

const PIXEL_ID = String(import.meta.env.VITE_META_PIXEL_ID || '2322314191935538').trim()

export function installMetaPixel() {
  if (!/^\d{8,20}$/.test(PIXEL_ID) || isEmbedded() || typeof window === 'undefined') return
  if (window.fbq) return
  // Standard Meta Pixel loader.
  !function (f, b, e, v, n, t, s) {
    if (f.fbq) return
    n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments) }
    if (!f._fbq) f._fbq = n
    n.push = n; n.loaded = true; n.version = '2.0'; n.queue = []
    t = b.createElement(e); t.async = true; t.src = v
    s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s)
  }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js')
  window.fbq('init', PIXEL_ID)
  window.fbq('track', 'PageView')
  window.addEventListener('hashchange', () => window.fbq('track', 'PageView'))
}

/** Report a Meta standard event. A no-op when the pixel is not loaded. */
export function trackMeta(event, params) {
  try { if (window.fbq) window.fbq('track', event, params) } catch { /* tracking must never break the app */ }
}
