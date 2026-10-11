import { toast } from '../../components/Toast'

/** Small shared components for the platform-admin pages. */
export function Pill({ children, color = 'var(--text-2)' }) {
  return <span style={{ display: 'inline-block', padding: '3px 10px', borderRadius: 999, fontSize: 12, fontWeight: 700, color, background: 'color-mix(in srgb, currentColor 13%, transparent)' }}>{children}</span>
}

export function Kpi({ label, value, sub, accent, icon: Icon, tone }) {
  const color = tone || accent || '#0a6cc4'
  return (
    <div className="ad-kpi" style={{ '--kpi': color }}>
      {Icon && <div className="ico"><Icon size={19} /></div>}
      <div className="l">{label}</div>
      <div className="v" style={accent ? { color: accent } : undefined}>{value}</div>
      {sub && <div className="n">{sub}</div>}
    </div>
  )
}

/** The one-time set-password link: shown once after creating a partner / issuing a new link. */
export function InviteBox({ url, onClose, emailed = false, emailTo = null, emailError = null }) {
  async function copy() { try { await navigator.clipboard.writeText(url); toast.success('Link copied') } catch { toast.error('Could not copy', 'Select the link and copy it by hand.') } }
  return (
    <div className="card card-body" style={{ marginBottom: 14, borderColor: 'var(--warning)' }}>
      <div style={{ fontWeight: 700, marginBottom: 4 }}>{emailed ? 'Invite e-mailed — here is the link too' : 'Send this one-time link to the partner'}</div>
      {emailed && <div style={{ fontSize: 13, color: 'var(--success)', marginBottom: 6 }}>✓ E-mailed to {emailTo}. If it lands in spam, you can still send this link yourself.</div>}
      {!emailed && emailError && <div style={{ fontSize: 13, color: 'var(--danger)', marginBottom: 6 }}>✗ Could not e-mail it: {emailError} Copy the link below and send it yourself.</div>}
      <div style={{ fontSize: 13, color: 'var(--text-3)', marginBottom: 8 }}>They use it to set their password (valid 7 days). It is shown only now — if it is lost, issue a new link.</div>
      <div style={{ display: 'flex', gap: 8 }}>
        <input className="form-input" readOnly value={url} onFocus={e => e.target.select()} style={{ fontFamily: 'ui-monospace,monospace', fontSize: 12 }} />
        <button className="btn btn-primary" onClick={copy}>Copy</button>
        <button className="btn btn-ghost" onClick={onClose}>Done</button>
      </div>
    </div>
  )
}
