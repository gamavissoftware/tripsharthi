import { useState } from 'react'
import { X } from 'lucide-react'

/** A small in-page dialog that asks for a written reason (no window.prompt: native pop-ups can be silently dismissed). */
export default function AdminReasonDialog({ title, body, label = 'Reason', placeholder = '', confirmLabel = 'Confirm', danger = false, minLength = 5, onConfirm, onClose }) {
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  async function go() {
    setBusy(true)
    try { await onConfirm(reason.trim()) } finally { setBusy(false) }
  }
  return (
    <div className="iv-backdrop" onClick={onClose}>
      <div className="iv-modal" style={{ maxWidth: 480 }} onClick={e => e.stopPropagation()} role="dialog" aria-label={title}>
        <div className="iv-modal-head"><b>{title}</b><button type="button" className="btn btn-ghost btn-sm" onClick={onClose} aria-label="Close"><X size={16} /></button></div>
        <div className="iv-modal-body">
          {body && <p style={{ margin: '0 0 6px', fontSize: 14 }}>{body}</p>}
          <label className="iv-lbl">{label}</label>
          <textarea className="form-input" rows={3} maxLength={300} autoFocus value={reason} onChange={e => setReason(e.target.value)} placeholder={placeholder} />
          <div className="cu-sub" style={{ marginTop: 4 }}>At least {minLength} characters. It is kept in the log.</div>
        </div>
        <div className="iv-modal-foot">
          <button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button>
          <button type="button" className="btn btn-primary" style={danger ? { background: 'var(--danger)', borderColor: 'var(--danger)' } : undefined} disabled={busy || reason.trim().length < minLength} onClick={go}>{busy ? 'Working…' : confirmLabel}</button>
        </div>
      </div>
    </div>
  )
}
