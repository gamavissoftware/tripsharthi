/**
 * ValidationBanner — surfaces FlowValidator's server-authoritative output.
 *
 * validation.errors   → red, blocks Activate button
 * validation.warnings → orange, allows Activate but warns
 *
 * The server's validation is the source of truth. Client-side checks
 * (e.g. "no trigger node visible") are convenience only.
 *
 * The free-form-window warning gets special callout text with suggested fix.
 */
import { OctagonAlert, AlertTriangle } from 'lucide-react'

export default function ValidationBanner({ errors = [], warnings = [] }) {
  if (errors.length === 0 && warnings.length === 0) return null

  return (
    <div style={{ padding: '8px 16px', display: 'flex', flexDirection: 'column', gap: 6 }}>
      {errors.map((err, i) => (
        <div key={i} style={{
          background: '#fef2f2', border: '1px solid #fca5a5',
          borderLeft: '4px solid #dc2626', borderRadius: 6,
          padding: '8px 12px', fontSize: 13, color: '#991b1b',
          display: 'flex', alignItems: 'flex-start', gap: 8,
        }}>
          <OctagonAlert size={15} strokeWidth={2} style={{ flexShrink: 0, marginTop: 1 }} />
          <span>{err}</span>
        </div>
      ))}
      {warnings.map((w, i) => {
        const isFreeformWarning = w.includes('send_freeform') || w.includes('send_media')
        return (
          <div key={i} style={{
            background: isFreeformWarning ? '#fff7ed' : '#fffbeb',
            border: `1px solid ${isFreeformWarning ? '#fed7aa' : '#fde68a'}`,
            borderLeft: `4px solid ${isFreeformWarning ? '#f97316' : '#d97706'}`,
            borderRadius: 6, padding: '8px 12px', fontSize: 13,
            color: isFreeformWarning ? '#9a3412' : '#92400e',
          }}>
            <div style={{ display:'flex', alignItems:'flex-start', gap:8 }}>
              <AlertTriangle size={15} strokeWidth={2} style={{ flexShrink: 0, marginTop: 1 }} />
              <span>{w}</span>
            </div>
            {isFreeformWarning && (
              <div style={{ marginTop: 6, fontSize: 12, color: '#b45309', paddingLeft: 22 }}>
                <strong>Fix:</strong> Add a <code>window_check</code> node before this node and wire
                its "closed" handle to a <code>send_template</code> fallback.
                Or use a <code>keyword_reply</code> / <code>inbound_message</code> trigger (window guaranteed open).
              </div>
            )}
          </div>
        )
      })}
    </div>
  )
}
