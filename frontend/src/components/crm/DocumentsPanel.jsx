import { useState, useEffect, useRef } from 'react'
import { documents } from '../../api/documents'
import { toast } from '../Toast'

const fmtSize = (b) => {
  b = Number(b || 0)
  if (b < 1024) return `${b} B`
  if (b < 1024 * 1024) return `${(b / 1024).toFixed(0)} KB`
  return `${(b / 1024 / 1024).toFixed(1)} MB`
}
const ICON = { pdf: '📕', doc: '📘', docx: '📘', xls: '📗', xlsx: '📗', csv: '📗', png: '🖼', jpg: '🖼', jpeg: '🖼', gif: '🖼', webp: '🖼' }
const iconFor = (name) => ICON[(name?.split('.').pop() || '').toLowerCase()] || '📄'

/**
 * Document attachments for a CRM record (reference lead-detail Documents section).
 * Upload is multipart, download is an auth-gated blob — both handled by the
 * documents api module.
 */
export default function DocumentsPanel({ relatedType, relatedId }) {
  const [docs, setDocs] = useState(null)
  const [busy, setBusy] = useState(false)
  const fileRef = useRef(null)

  const load = () => documents.list(relatedType, relatedId).then(r => setDocs(r.data ?? [])).catch(() => setDocs([]))
  useEffect(() => { load() }, [relatedType, relatedId])

  async function onPick(e) {
    const file = e.target.files?.[0]
    if (!file) return
    setBusy(true)
    try {
      const r = await documents.upload(relatedType, relatedId, file)
      if (r?.success) { toast.success('File uploaded', file.name); load() }
      else { toast.error('Upload failed', r?.errors?.[0]?.message || r?.messages?.error || 'Check the file type/size (max 10 MB).') }
    } catch (err) { toast.error('Upload failed', err.message) }
    finally { setBusy(false); if (fileRef.current) fileRef.current.value = '' }
  }

  async function remove(d) {
    if (!confirm(`Remove "${d.filename}"?`)) return
    try { await documents.remove(d.id); load() }
    catch (err) { toast.error('Delete failed', err.message) }
  }

  return (
    <div className="lp-cd-card">
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '.85rem' }}>
        <div style={{ fontSize: 15, fontWeight: 800, color: '#111827', display: 'flex', alignItems: 'center', gap: 6 }}>📎 Documents</div>
        <label style={{ fontSize: 13, fontWeight: 700, color: 'var(--primary,#0a6cc4)', cursor: busy ? 'default' : 'pointer', opacity: busy ? .5 : 1 }}>
          {busy ? 'Uploading…' : '⬆ Upload file'}
          <input ref={fileRef} type="file" hidden disabled={busy} onChange={onPick}
            accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.png,.jpg,.jpeg,.gif,.webp" />
        </label>
      </div>
      {docs === null ? (
        <div style={{ color: '#9ca3af', fontSize: 13.5 }}>Loading…</div>
      ) : docs.length === 0 ? (
        <div style={{ color: '#9ca3af', fontSize: 13.5 }}>No documents yet. PDF, Word, Excel, CSV or images, up to 10 MB.</div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
          {docs.map(d => (
            <div key={d.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '.5rem .6rem', border: '1px solid #eef0f3', borderRadius: 9 }}>
              <span style={{ fontSize: 18 }}>{iconFor(d.filename)}</span>
              <button onClick={() => documents.download(d.id, d.filename).catch(e => toast.error('Download failed', e.message))}
                title="Download" style={{ flex: 1, minWidth: 0, textAlign: 'left', background: 'none', border: 'none', cursor: 'pointer', padding: 0 }}>
                <div style={{ fontWeight: 600, fontSize: 13.5, color: '#1d4ed8', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{d.filename}</div>
                <div style={{ fontSize: 11.5, color: '#9ca3af' }}>{fmtSize(d.size_bytes)}{d.created_at ? ` · ${String(d.created_at).slice(0, 10)}` : ''}</div>
              </button>
              <button onClick={() => remove(d)} title="Remove" style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#b91c1c', fontSize: 15, padding: '0 .2rem' }}>✕</button>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
