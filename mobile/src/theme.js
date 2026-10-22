import { Platform } from 'react-native'

// TripSarthi brand: navy #0a1f44, blue #0a6cc4, teal #12a89e, orange #f98f26 (same palette as the website and the web app).
export const c = {
  bg: '#f3f6fb', card: '#ffffff', text: '#0f1b33', sub: '#64748b', faint: '#94a3b8', border: '#e3e9f3', line: '#eef2f8',
  primary: '#0a6cc4', primaryDark: '#08508f', primarySoft: '#e7f1fc',
  navy: '#0a1f44', navy2: '#0d2c63',
  teal: '#12a89e', tealSoft: '#def5f2',
  orange: '#f98f26', orangeSoft: '#fff1df',
  ok: '#0f9d6b', okSoft: '#e0f6ec', warn: '#d97706', warnSoft: '#fff4de', danger: '#d92d3a', dangerSoft: '#fdeaec',
  purple: '#6d5bd0', purpleSoft: '#ece9fb', white: '#fff',
}

export const sp = { xs: 4, s: 8, m: 12, l: 16, xl: 22, xxl: 32 }
export const radius = { s: 8, m: 12, l: 16, xl: 22, pill: 999 }

export const shadow = Platform.select({
  ios: { shadowColor: '#0a1f44', shadowOpacity: 0.07, shadowRadius: 14, shadowOffset: { width: 0, height: 5 } },
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
export const STATUS = {
  enquiry: ['#e7f1fc', '#0a6cc4'], quoted: ['#fff4de', '#b45309'], negotiating: ['#ffedd5', '#c2410c'], booked: ['#e0f6ec', '#0f7a54'],
  travelling: ['#def5f2', '#0e7c74'], completed: ['#eef2f8', '#475569'], lost: ['#fdeaec', '#b4232f'], cancelled: ['#fdeaec', '#b4232f'],
  documents_pending: ['#fff4de', '#b45309'], confirmed: ['#e0f6ec', '#0f7a54'], paid: ['#e0f6ec', '#0f7a54'], pending: ['#fff4de', '#b45309'], overdue: ['#fdeaec', '#b4232f'],
}
