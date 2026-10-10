import { toast } from '../../components/Toast'

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

/** The one-time set-password link: shown once after creating a partner / issuing a new link. */
export function InviteBox({ url, onClose }) {
  async function copy() { try { await navigator.clipboard.writeText(url); toast.success('Link copied') } catch { toast.error('Could not copy', 'Select the link and copy it by hand.') } }
  return (
    <div className="card card-body" style={{ marginBottom: 14, borderColor: 'var(--warning)' }}>
      <div style={{ fontWeight: 700, marginBottom: 4 }}>Send this one-time link to the partner</div>
      <div style={{ fontSize: 13, color: 'var(--text-3)', marginBottom: 8 }}>They use it to set their password (valid 7 days). It is shown only now — if it is lost, issue a new link.</div>
      <div style={{ display: 'flex', gap: 8 }}>
        <input className="form-input" readOnly value={url} onFocus={e => e.target.select()} style={{ fontFamily: 'ui-monospace,monospace', fontSize: 12 }} />
        <button className="btn btn-primary" onClick={copy}>Copy</button>
        <button className="btn btn-ghost" onClick={onClose}>Done</button>
      </div>
    </div>
  )
}
