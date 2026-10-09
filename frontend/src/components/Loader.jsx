/**
 * The one loading state for TripSarthi pages and panels.
 *
 * A branded spinner (TripSarthi indigo ring with a pulsing core), a message
 * that says what is being waited on, and optional skeleton bars so the
 * layout does not jump when the content arrives.
 *
 *   <Loader message="Asking Meta for July's leads…" rows={6} />
 *   <Loader size="sm" inline />            // inside a button or a tile
 */
export default function Loader({ message = 'Loading…', hint, rows = 0, size = 'md', inline = false }) {
  const px = size === 'sm' ? 18 : size === 'lg' ? 56 : 40
  const ring = (
    <span className="lp-loader" style={{ width: px, height: px }} aria-hidden="true">
      <span className="lp-loader-core" />
    </span>
  )

  if (inline) {
    return (
      <span role="status" aria-live="polite" style={{ display: 'inline-flex', alignItems: 'center', gap: 8, fontSize: '.8rem', color: 'var(--text-3)' }}>
        {ring}{message}
      </span>
    )
  }

  return (
    <div role="status" aria-live="polite" style={{ padding: rows ? '1rem 0' : '2.5rem 1rem', textAlign: 'center' }}>
      <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 12 }}>
        {ring}
        <div style={{ fontWeight: 600, fontSize: '.9rem', color: 'var(--text)' }}>{message}</div>
        {hint && <div style={{ fontSize: '.78rem', color: 'var(--text-3)', marginTop: -6 }}>{hint}</div>}
      </div>
      {rows > 0 && (
        <div style={{ marginTop: '1.5rem', display: 'flex', flexDirection: 'column', gap: 10 }}>
          {Array.from({ length: rows }).map((_, i) => (
            <div key={i} className="lp-skeleton" style={{ height: 38, width: `${100 - (i % 3) * 6}%` }} />
          ))}
        </div>
      )}
    </div>
  )
}
