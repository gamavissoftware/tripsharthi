import { useState } from 'react'
import { crm } from '../api/crm'
import { toast } from './Toast'

// ── Generic CSV import wizard (Phase G4) ─────────────────────────────────────
// Three steps: upload → map columns → process. Used for accounts / deals /
// custom-object records (contacts keep their own dedicated import page).

const inp = { width: '100%', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.9rem', boxSizing: 'border-box' }
const btn = (primary) => ({ padding: '.55rem 1rem', borderRadius: 9, fontWeight: 700, fontSize: 13.5, cursor: 'pointer',
  border: '1.5px solid ' + (primary ? 'transparent' : '#e5e7eb'), background: primary ? 'var(--primary,#0a6cc4)' : '#fff', color: primary ? '#fff' : '#374151' })

export default function CrmImportModal({ entity, customObjectId, onClose, onDone }) {
  const [step, setStep] = useState('upload')   // upload | map | done
  const [busy, setBusy] = useState(false)
  const [imp, setImp] = useState(null)         // { import_id, headers, preview, total, fields }
  const [mapping, setMapping] = useState({})   // header -> field_key
  const [result, setResult] = useState(null)

  async function upload(e) {
    const file = e.target.files?.[0]
    if (!file) return
    setBusy(true)
    try {
      const res = await crm.importStart(entity, file, customObjectId)
      setImp(res.data)
      // Auto-guess mapping: header matching a field key/label (case-insensitive).
      const guess = {}
      const fields = res.data.fields
      res.data.headers.forEach(h => {
        const key = Object.keys(fields).find(k => k.toLowerCase() === h.toLowerCase().replace(/\s+/g, '_') || fields[k].toLowerCase() === h.toLowerCase())
        if (key) guess[h] = key
      })
      setMapping(guess)
      setStep('map')
    } catch (err) { toast.error('Upload failed', err?.message) }
    setBusy(false)
  }

  async function process() {
    setBusy(true)
    try {
      await crm.importMapping(imp.import_id, mapping)
      const res = await crm.importProcess(imp.import_id)
      setResult(res.data)
      setStep('done')
      toast.success('Import complete', `${res.data.imported} added, ${res.data.updated} updated`)
    } catch (err) { toast.error('Import failed', err?.message) }
    setBusy(false)
  }

  const fields = imp?.fields ?? {}

  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, backdropFilter: 'blur(3px)' }}>
      <div onClick={e => e.stopPropagation()} style={{ background: '#fff', borderRadius: 16, width: '100%', maxWidth: 560, maxHeight: '85vh', overflow: 'auto', padding: '1.5rem', boxShadow: '0 24px 64px rgba(0,0,0,.22)' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
          <h3 style={{ margin: 0, fontWeight: 800, fontSize: '1.05rem' }}>📥 Import {entity}s from CSV</h3>
          <button onClick={onClose} style={{ background: 'none', border: 'none', fontSize: '1.2rem', cursor: 'pointer', color: '#94a3b8' }}>✕</button>
        </div>

        {step === 'upload' && (
          <div>
            <p style={{ fontSize: 13.5, color: '#6b7280', marginTop: 0 }}>Upload a CSV file. The first row must be column headers.</p>
            <label style={{ display: 'block', border: '2px dashed #bcdcf6', borderRadius: 12, padding: '2rem', textAlign: 'center', cursor: 'pointer', background: '#f8faff' }}>
              <div style={{ fontSize: '2rem', marginBottom: 6 }}>📄</div>
              <div style={{ fontWeight: 700, color: '#074a8c' }}>{busy ? 'Uploading…' : 'Choose a CSV file'}</div>
              <input type="file" accept=".csv" onChange={upload} disabled={busy} style={{ display: 'none' }} />
            </label>
          </div>
        )}

        {step === 'map' && imp && (
          <div>
            <p style={{ fontSize: 13.5, color: '#6b7280', marginTop: 0 }}>Map your CSV columns to fields. {imp.total} row(s) detected.</p>
            <div style={{ display: 'flex', flexDirection: 'column', gap: '.55rem', maxHeight: '42vh', overflow: 'auto' }}>
              {imp.headers.map(h => (
                <div key={h} style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                  <div style={{ flex: 1, minWidth: 0, fontWeight: 600, fontSize: 13.5, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                    {h}
                    <span style={{ color: '#9ca3af', fontWeight: 400, fontSize: 12 }}> · e.g. {imp.preview[0]?.[h] || '—'}</span>
                  </div>
                  <span style={{ color: '#cbd5e1' }}>→</span>
                  <select style={{ ...inp, flex: 1, width: 'auto' }} value={mapping[h] ?? ''} onChange={e => setMapping(m => ({ ...m, [h]: e.target.value }))}>
                    <option value="">— skip —</option>
                    {Object.entries(fields).map(([k, label]) => <option key={k} value={k}>{label}</option>)}
                  </select>
                </div>
              ))}
            </div>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '.5rem', marginTop: '1.25rem' }}>
              <button style={btn(false)} onClick={() => setStep('upload')}>Back</button>
              <button style={btn(true)} disabled={busy} onClick={process}>{busy ? 'Importing…' : `Import ${imp.total} row(s)`}</button>
            </div>
          </div>
        )}

        {step === 'done' && result && (
          <div>
            <div style={{ display: 'flex', gap: 10, marginBottom: '1rem' }}>
              {[['Added', result.imported, '#15803d'], ['Updated', result.updated, '#1d4ed8'], ['Failed', result.failed, '#b91c1c']].map(([l, n, c]) => (
                <div key={l} style={{ flex: 1, textAlign: 'center', background: '#f8fafc', border: '1px solid #eef0f3', borderRadius: 10, padding: '.8rem' }}>
                  <div style={{ fontSize: '1.4rem', fontWeight: 800, color: c }}>{n}</div>
                  <div style={{ fontSize: 12, color: '#6b7280', fontWeight: 600 }}>{l}</div>
                </div>
              ))}
            </div>
            {result.errors?.length > 0 && (
              <div style={{ maxHeight: '30vh', overflow: 'auto', background: '#fff7f7', border: '1px solid #fecaca', borderRadius: 10, padding: '.6rem .8rem' }}>
                <div style={{ fontWeight: 700, fontSize: 12.5, color: '#b91c1c', marginBottom: 4 }}>Skipped rows</div>
                {result.errors.slice(0, 50).map((e, i) => (
                  <div key={i} style={{ fontSize: 12.5, color: '#7f1d1d' }}>Row {e.row}: {e.reason}</div>
                ))}
              </div>
            )}
            <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: '1.25rem' }}>
              <button style={btn(true)} onClick={() => { onDone?.(); onClose() }}>Done</button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
