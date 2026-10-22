import { Platform, Appearance } from 'react-native'
import * as SecureStore from 'expo-secure-store'

// TripSarthi brand: navy #0a1f44, blue #0a6cc4, teal #12a89e, orange #f98f26 (same palette as the website and the web app).
const LIGHT = {
  bg: '#f3f6fb', card: '#ffffff', text: '#0f1b33', sub: '#64748b', faint: '#94a3b8', border: '#e3e9f3', line: '#eef2f8',
  primary: '#0a6cc4', primaryDark: '#08508f', primarySoft: '#e7f1fc',
  navy: '#0a1f44', navy2: '#0d2c63',
  teal: '#12a89e', tealSoft: '#def5f2',
  orange: '#f98f26', orangeSoft: '#fff1df',
  ok: '#0f9d6b', okSoft: '#e0f6ec', warn: '#d97706', warnSoft: '#fff4de', danger: '#d92d3a', dangerSoft: '#fdeaec',
  purple: '#6d5bd0', purpleSoft: '#ece9fb', white: '#fff',
}
const DARK = {
  bg: '#0b1220', card: '#141d30', text: '#e8eef8', sub: '#a3b1c8', faint: '#74839d', border: '#26344d', line: '#1c2a42',
  primary: '#4aa3f0', primaryDark: '#2f86d4', primarySoft: '#14304f',
  navy: '#6f8fc4', navy2: '#16315f',
  teal: '#2cc4b8', tealSoft: '#10332f',
  orange: '#fb9f45', orangeSoft: '#3a2813',
  ok: '#2fc58f', okSoft: '#0f3022', warn: '#f2a33a', warnSoft: '#3a2c10', danger: '#f0616d', dangerSoft: '#3d1a1f',
  purple: '#9a8cf0', purpleSoft: '#262050', white: '#fff',
}

/**
 * The colour theme is chosen ONCE, while the app starts (screens build their styles from these values when they load):
 * "auto" follows the phone's light/dark setting, or the person picks light/dark in Settings (kept in the secure store, read synchronously
 * here). Changing it therefore takes effect the next time the app is opened.
 */
export const THEME_KEY = 'tp_theme'
function savedChoice() {
  try {
    if (Platform.OS === 'web') return typeof localStorage !== 'undefined' ? localStorage.getItem(THEME_KEY) : null
    return SecureStore.getItem(THEME_KEY)
  } catch { return null }
}
export const themeChoice = (['light', 'dark'].includes(savedChoice()) ? savedChoice() : 'auto')
export const isDark = themeChoice === 'dark' || (themeChoice === 'auto' && Appearance.getColorScheme() === 'dark')
export const c = isDark ? DARK : LIGHT
export function saveThemeChoice(v) {
  if (Platform.OS === 'web') { try { localStorage.setItem(THEME_KEY, v) } catch { /* ignore */ } return }
  try { SecureStore.setItem(THEME_KEY, v) } catch { /* ignore */ }
}

export const sp = { xs: 4, s: 8, m: 12, l: 16, xl: 22, xxl: 32 }
export const radius = { s: 8, m: 12, l: 16, xl: 22, pill: 999 }

export const shadow = Platform.select({
  ios: { shadowColor: '#000', shadowOpacity: isDark ? 0.3 : 0.07, shadowRadius: 14, shadowOffset: { width: 0, height: 5 } },
  android: { elevation: 2 },
  default: { boxShadow: '0 4px 14px rgba(10,31,68,0.07)' },
})

export const type = {
  h1: { fontSize: 26, fontWeight: '800', color: c.text, letterSpacing: -0.4 },
  h2: { fontSize: 19, fontWeight: '800', color: c.text, letterSpacing: -0.2 },
  h3: { fontSize: 15.5, fontWeight: '700', color: c.text },
  body: { fontSize: 14.5, color: c.text },
  small: { fontSize: 12.5, color: c.sub },
  label: { fontSize: 11.5, fontWeight: '700', color: c.sub, textTransform: 'uppercase', letterSpacing: 0.6 },
}

// trip / booking status -> [background, text]
const STATUS_LIGHT = {
  enquiry: ['#e7f1fc', '#0a6cc4'], quoted: ['#fff4de', '#b45309'], negotiating: ['#ffedd5', '#c2410c'], booked: ['#e0f6ec', '#0f7a54'],
  travelling: ['#def5f2', '#0e7c74'], completed: ['#eef2f8', '#475569'], lost: ['#fdeaec', '#b4232f'], cancelled: ['#fdeaec', '#b4232f'],
  documents_pending: ['#fff4de', '#b45309'], confirmed: ['#e0f6ec', '#0f7a54'], paid: ['#e0f6ec', '#0f7a54'], pending: ['#fff4de', '#b45309'], overdue: ['#fdeaec', '#b4232f'],
}

const STATUS_DARK = {
  enquiry: ['#14304f', '#6db8f5'], quoted: ['#3a2c10', '#f2b561'], negotiating: ['#3d2512', '#f5a56b'], booked: ['#0f3022', '#4fd6a0'],
  travelling: ['#10332f', '#4fd3c8'], completed: ['#1c2a42', '#a3b1c8'], lost: ['#3d1a1f', '#f58a93'], cancelled: ['#3d1a1f', '#f58a93'],
  documents_pending: ['#3a2c10', '#f2b561'], confirmed: ['#0f3022', '#4fd6a0'], paid: ['#0f3022', '#4fd6a0'], pending: ['#3a2c10', '#f2b561'], overdue: ['#3d1a1f', '#f58a93'],
}
export const STATUS = isDark ? STATUS_DARK : STATUS_LIGHT
