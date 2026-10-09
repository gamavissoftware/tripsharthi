/** Small shared components for the platform-admin pages. */
export function Pill({ children, color = 'var(--text-2)' }) {
  return <span style={{ display: 'inline-block', padding: '2px 9px', borderRadius: 999, fontSize: 12, fontWeight: 600, color, background: 'color-mix(in srgb, currentColor 12%, transparent)' }}>{children}</span>
}

export function Kpi({ label, value, sub, accent }) {
  return (
    <div className="card card-body" style={{ flex: '1 1 170px', minWidth: 160 }}>
      <div style={{ color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase', letterSpacing: '.04em' }}>{label}</div>
      <div style={{ fontSize: 28, fontWeight: 800, color: accent || 'var(--text)', marginTop: 4, letterSpacing: '-.02em' }}>{value}</div>
      {sub && <div style={{ color: 'var(--text-3)', fontSize: 12.5, marginTop: 2 }}>{sub}</div>}
    </div>
  )
}
