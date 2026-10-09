import { createContext, useContext, useEffect, useState, useCallback } from 'react'
import { settings as settingsApi } from '../api/settings'

/**
 * App-wide white-label branding.
 *
 * Loads the tenant brand once after login and exposes it to the whole shell so
 * the sidebar shows the custom name/logo and the accent colour is applied to the
 * shared --primary CSS variables. The Branding page calls `applyBranding()` on
 * save so changes take effect instantly without a reload.
 */

export const BRANDING_DEFAULTS = {
  app_name:      'TripSarthi',
  primary_color: '#0a6cc4',
  logo_url:      null,
  support_email: null,
  custom_domain: null,
}

const BrandingContext = createContext({
  branding: BRANDING_DEFAULTS,
  loading: true,
  applyBranding: () => {},
  reload: () => {},
})

// ── colour helpers ──────────────────────────────────────────────────────────
function clamp(n) { return Math.max(0, Math.min(255, n)) }

function parseHex(hex) {
  if (typeof hex !== 'string') return null
  const m = /^#?([0-9a-f]{6})$/i.exec(hex.trim())
  if (!m) return null
  const int = parseInt(m[1], 16)
  return { r: (int >> 16) & 255, g: (int >> 8) & 255, b: int & 255 }
}

export function shade(hex, amt) {
  const c = parseHex(hex)
  if (!c) return hex
  const f = v => clamp(Math.round(v + 255 * amt))
  const h = v => f(v).toString(16).padStart(2, '0')
  return `#${h(c.r)}${h(c.g)}${h(c.b)}`
}

export function rgba(hex, alpha) {
  const c = parseHex(hex)
  if (!c) return hex
  return `rgba(${c.r},${c.g},${c.b},${alpha})`
}

/** Apply the accent colour to the shared CSS variables used across the app. */
export function applyPrimaryColor(color) {
  if (!parseHex(color)) return
  const root = document.documentElement.style
  root.setProperty('--primary',       color)
  root.setProperty('--primary-dark',  shade(color, -0.12))
  root.setProperty('--primary-light', shade(color, 0.42))
  root.setProperty('--primary-ring',  rgba(color, 0.35))
}

export function BrandingProvider({ children }) {
  const [branding, setBranding] = useState(BRANDING_DEFAULTS)
  const [loading, setLoading]   = useState(true)

  const applyBranding = useCallback((next) => {
    const merged = { ...BRANDING_DEFAULTS, ...(next || {}) }
    setBranding(merged)
    applyPrimaryColor(merged.primary_color)
    if (merged.app_name) document.title = merged.app_name
  }, [])

  const reload = useCallback(() => {
    setLoading(true)
    settingsApi.getBranding()
      .then(res => applyBranding(res?.data ?? res))
      .catch(() => {})            // non-owners or errors fall back to defaults
      .finally(() => setLoading(false))
  }, [applyBranding])

  useEffect(() => { reload() }, [reload])

  return (
    <BrandingContext.Provider value={{ branding, loading, applyBranding, reload }}>
      {children}
    </BrandingContext.Provider>
  )
}

export function useBranding() {
  return useContext(BrandingContext)
}
