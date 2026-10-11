import { createRoot } from 'react-dom/client'

/**
 * An in-page "are you sure?" dialog that returns a promise: `if (!(await confirmAsk('Delete this?'))) return`.
 * Used instead of window.confirm because native pop-ups are blocked or silently dismissed in some browsers and embedded
 * browser panes - which made buttons look dead. Closes on OK / Cancel / Escape / a click outside.
 */
export function confirmAsk(message, { okLabel = 'Yes, continue', cancelLabel = 'Cancel', danger = false } = {}) {
  return new Promise((resolve) => {
    const host = document.createElement('div')
    document.body.appendChild(host)
    const root = createRoot(host)
    const done = (v) => { root.unmount(); host.remove(); document.removeEventListener('keydown', onKey); resolve(v) }
    const onKey = (e) => { if (e.key === 'Escape') done(false) }
    document.addEventListener('keydown', onKey)
    root.render(
      <div role="presentation" onClick={() => done(false)} style={{ position: 'fixed', inset: 0, background: 'rgba(10,31,68,.45)', display: 'grid', placeItems: 'center', zIndex: 10000, padding: 16 }}>
        <div role="alertdialog" aria-modal="true" onClick={(e) => e.stopPropagation()} style={{ background: 'var(--surface, #fff)', color: 'var(--text, #0f1f3a)', borderRadius: 14, padding: '22px 24px', maxWidth: 440, width: '100%', boxShadow: '0 12px 40px rgba(0,0,0,.25)' }}>
          <div style={{ whiteSpace: 'pre-wrap', lineHeight: 1.5, marginBottom: 18 }}>{message}</div>
          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
            <button type="button" className="btn btn-ghost" onClick={() => done(false)}>{cancelLabel}</button>
            <button type="button" className="btn btn-primary" autoFocus onClick={() => done(true)} style={danger ? { background: 'var(--danger)', borderColor: 'var(--danger)' } : undefined}>{okLabel}</button>
          </div>
        </div>
      </div>
    )
  })
}
