/** Shared styles and formatters for the platform-admin pages. */
export const th = { padding: '10px 14px', textAlign: 'left', color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase', whiteSpace: 'nowrap' }
export const td = { padding: '10px 14px', borderTop: '1px solid var(--border)', verticalAlign: 'middle' }

export const PARTNER_STATUS_COLOR = { active: 'var(--success)', invited: 'var(--warning)', suspended: 'var(--danger)' }
export const PLAN_COLOR = { free: '#64748b', starter: '#0891b2', growth: '#0a6cc4', pro: '#0e8f8c' }
export const STATUS_COLOR = { active: 'var(--success)', suspended: 'var(--danger)', cancelled: 'var(--text-3)', halted: 'var(--warning)', created: 'var(--text-3)', authenticated: 'var(--text-3)', downgraded: 'var(--text-3)', new: 'var(--warning)', handled: 'var(--success)', spam: 'var(--text-3)' }

// The API stores UTC "YYYY-MM-DD HH:MM:SS" — read it as UTC, show it in the viewer's time zone.
const parse = (s) => new Date(String(s).replace(' ', 'T') + 'Z')
// Date inputs are India (IST) days: show a stored UTC timestamp as its IST calendar date (for editing).
export const istDate = (s) => (s ? new Date(parse(s).getTime() + 19_800_000).toISOString().slice(0, 10) : '')
export const fmtDate = (s) => (s ? parse(s).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')
export const fmtDateTime = (s) => (s ? parse(s).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')
export const ago = (s) => {
  if (!s) return 'never'
  const m = Math.max(0, Math.round((Date.now() - parse(s).getTime()) / 60000))
  return m < 1 ? 'just now' : m < 60 ? `${m} min ago` : m < 1440 ? `${Math.round(m / 60)} h ago` : `${Math.round(m / 1440)} d ago`
}
