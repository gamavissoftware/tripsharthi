import { useState, useRef, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { imports as importsApi } from '../api/imports'
import { FileText, CheckCircle2, XCircle } from 'lucide-react'

// ── Inject styles once ──────────────────────────────────────────────────────
if (typeof document !== 'undefined' && !document.getElementById('lp-import-css')) {
  const el = document.createElement('style')
  el.id = 'lp-import-css'
  el.textContent = `
    @keyframes lp-imp-in { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
    @keyframes lp-imp-bar { from { background-position: 1rem 0; } to { background-position: 0 0; } }
    .lp-imp-card { background:#fff; border:1px solid #e5e7eb; border-radius:18px; animation:lp-imp-in .25s ease both; }
    .lp-imp-input, .lp-imp-select {
      width:100%; padding:.6rem .8rem; border-radius:10px; border:1.5px solid #e5e7eb; background:#f9fafb;
      font-size:14px; color:#111827; outline:none; box-sizing:border-box; transition:border-color .15s;
    }
    .lp-imp-select { background:#fff; cursor:pointer; }
    .lp-imp-input:focus, .lp-imp-select:focus { border-color:var(--primary,#0a6cc4); }
    .lp-imp-btn { padding:.6rem 1.2rem; border-radius:10px; font-weight:700; font-size:14px; cursor:pointer; border:1.5px solid transparent; display:inline-flex; align-items:center; gap:.4rem; }
    .lp-imp-btn-primary { background:var(--primary,#0a6cc4); color:#fff; box-shadow:0 2px 10px var(--primary-ring,rgba(10,108,196,.35)); }
    .lp-imp-btn-primary:disabled { background:#c7cdd6; box-shadow:none; cursor:not-allowed; }
    .lp-imp-btn-ghost { background:#fff; color:#374151; border-color:#e5e7eb; }
    .lp-imp-drop { border:2px dashed #d1d5db; border-radius:12px; padding:1.6rem; text-align:center; cursor:pointer; transition:border-color .15s, background .15s; background:#f9fafb; display:block; }
    .lp-imp-drop:hover { border-color:var(--primary,#0a6cc4); background:var(--primary-light,#e8f3fc); }
  `
  document.head.appendChild(el)
}

const COUNTRY_CODES = [
  { label: '+91  India', value: '+91' },
  { label: '+1   USA / Canada', value: '+1' },
  { label: '+44  UK', value: '+44' },
  { label: '+971 UAE', value: '+971' },
  { label: '+65  Singapore', value: '+65' },
  { label: 'None (keep as-is)', value: '' },
]
// Mapping targets. `tags` and `company` are not columns on the contact — they
// create/link a tag and an account respectively. Tags are what campaigns
// segment on, so a category column mapped here is what makes category-wise
// broadcasts possible.
const CONTACT_FIELDS = [
  { key: 'wa_number',        label: 'WhatsApp Number (required)' },
  { key: 'name',             label: 'Name' },
  { key: 'email',            label: 'Email' },
  { key: 'tags',             label: 'Tag / Category  → segments campaigns' },
  { key: 'company',          label: 'Company  → links a CRM account' },
  { key: 'company_industry', label: 'Company industry' },
  { key: 'job_title',        label: 'Designation / Job title' },
  { key: 'phone_secondary',  label: 'Alternate number' },
  { key: 'business_type',    label: 'Business type' },
  { key: 'city',             label: 'City' },
  { key: 'state',            label: 'State' },
  { key: 'country',          label: 'Country' },
  { key: 'language',         label: 'Language' },
  { key: 'remarks',          label: 'Remarks / Notes' },
  { key: 'status',           label: 'Status' },
  { key: 'source',           label: 'Source' },
]
const STEPS = ['Upload', 'Map columns', 'Import']
const stepIndex = (step) => ({ upload: 0, map: 1, progress: 2, done: 2, error: 2 }[step] ?? 0)

// ── Stepper ─────────────────────────────────────────────────────────────────
function Stepper({ active }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: '.4rem', marginBottom: '1.25rem' }}>
      {STEPS.map((label, i) => {
        const done = i < active, on = i === active
        return (
          <span key={label} style={{ display: 'flex', alignItems: 'center', gap: '.5rem' }}>
            <span style={{
              width: 26, height: 26, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center',
              fontSize: 13, fontWeight: 700, flexShrink: 0,
              background: done ? '#16a34a' : on ? 'var(--primary,#0a6cc4)' : '#e5e7eb',
              color: done || on ? '#fff' : '#9ca3af',
            }}>{done ? '✓' : i + 1}</span>
            <span style={{ fontSize: 13.5, fontWeight: on ? 700 : 600, color: on ? '#111827' : done ? '#16a34a' : '#9ca3af' }}>{label}</span>
            {i < STEPS.length - 1 && <span style={{ width: 28, height: 2, background: done ? '#16a34a' : '#e5e7eb', margin: '0 .3rem' }} />}
          </span>
        )
      })}
    </div>
  )
}

export default function ImportPage() {
  const navigate = useNavigate()
  const fileRef  = useRef()

  const [step, setStep]           = useState('upload')
  const [fileName, setFileName]   = useState('')
  const [importData, setImport]   = useState(null)
  const [countryCode, setCode]    = useState('+91')
  const [mapping, setMapping]     = useState({})
  const [progress, setProgress]   = useState(null)
  const [error, setError]         = useState(null)
  const [uploading, setUploading] = useState(false)

  async function handleUpload(e) {
    e.preventDefault()
    const file = fileRef.current?.files?.[0]
    if (!file) return setError('Please select a file.')
    setError(null); setUploading(true)
    try {
      const res = await importsApi.upload(file, countryCode)
      if (!res.success) {
        const msgs = res.messages ?? {}
        return setError(msgs.file ?? msgs.error ?? Object.values(msgs)[0] ?? 'Upload failed.')
      }
      const data = res.data
      setImport(data)
      const autoMap = {}
      data.headers.forEach(h => {
        const lower = h.toLowerCase().replace(/\s+/g, '_')
        const guess = CONTACT_FIELDS.find(f => lower.includes(f.key) || f.key.includes(lower))
        if (guess) autoMap[h] = guess.key
      })
      setMapping(autoMap)
      setStep('map')
    } catch (err) {
      setError(err.message ?? 'Upload failed.')
    } finally {
      setUploading(false)
    }
  }

  async function handleMap(e) {
    e.preventDefault()
    setError(null)
    if (!Object.values(mapping).includes('wa_number')) {
      return setError('Map one column to “WhatsApp Number” — it’s required.')
    }
    try {
      await importsApi.map(importData.import_id, mapping)
      await importsApi.start(importData.import_id)
      setProgress({ status: 'queued', imported: 0, updated: 0, failed: 0, total: importData.total ?? 0 })
      setStep('progress')
    } catch (err) {
      const errs = err.errors ?? {}
      setError(errs.mapping ?? errs.error ?? Object.values(errs)[0] ?? err.message ?? 'Failed.')
    }
  }

  useEffect(() => {
    if (step !== 'progress' || !importData?.import_id) return
    const poll = setInterval(async () => {
      try {
        const r = await importsApi.status(importData.import_id)
        const imp = r.data ?? {}
        setProgress({
          status: imp.status, imported: imp.imported ?? 0,
          updated: imp.updated_count ?? 0, failed: imp.failed ?? 0,
          total: imp.total ?? importData.total ?? 0,
        })
        if (imp.status === 'done' || imp.status === 'failed') {
          clearInterval(poll)
          setStep(imp.status === 'done' ? 'done' : 'error')
        }
      } catch (_) {}
    }, 2000)
    return () => clearInterval(poll)
  }, [step, importData])

  const pct = progress?.total > 0
    ? Math.round(((progress.imported + (progress.updated ?? 0) + progress.failed) / progress.total) * 100)
    : 0

  function reset() { setStep('upload'); setImport(null); setProgress(null); setError(null); setFileName('') }

  return (
    <div className="page" style={{ maxWidth: 780 }}>
      {/* Header */}
      <div style={{ marginBottom: '1.4rem' }}>
        <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>Import Contacts</h1>
        <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Upload a CSV or Excel file, map the columns, and we'll dedupe on WhatsApp number.</p>
      </div>

      <Stepper active={stepIndex(step)} />

      {error && (
        <div style={{ background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: 10, padding: '.7rem 1rem', marginBottom: '1rem', fontSize: 13.5, color: '#dc2626' }}>{error}</div>
      )}

      {/* Step 1: Upload */}
      {step === 'upload' && (
        <form onSubmit={handleUpload} className="lp-imp-card" style={{ padding: '1.5rem 1.6rem', display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
          <div>
            <label style={{ fontSize: 13, fontWeight: 700, color: '#374151', display: 'block', marginBottom: '.4rem' }}>File (CSV or Excel)</label>
            <label className="lp-imp-drop">
              <input ref={fileRef} type="file" accept=".csv,.xlsx,.xls" required style={{ display: 'none' }} onChange={e => setFileName(e.target.files?.[0]?.name ?? '')} />
              <div style={{ marginBottom: 6, color: '#9ca3af' }}><FileText size={30} strokeWidth={1.4} /></div>
              <div style={{ fontSize: 14, fontWeight: 600, color: fileName ? '#111827' : '#6b7280' }}>
                {fileName || 'Click to choose a file'}
              </div>
              <div style={{ fontSize: 12, color: '#9ca3af', marginTop: 2 }}>.csv, .xlsx or .xls</div>
            </label>
          </div>

          <div>
            <label style={{ fontSize: 13, fontWeight: 700, color: '#374151', display: 'block', marginBottom: '.4rem' }}>Default country code</label>
            <select className="lp-imp-select" value={countryCode} onChange={e => setCode(e.target.value)}>
              {COUNTRY_CODES.map(c => <option key={c.value} value={c.value}>{c.label}</option>)}
            </select>
            <div style={{ fontSize: 12, color: '#9ca3af', marginTop: 6 }}>Applied to numbers that don't already start with +</div>
          </div>

          <button type="submit" disabled={uploading} className="lp-imp-btn lp-imp-btn-primary" style={{ alignSelf: 'flex-start' }}>
            {uploading ? 'Uploading…' : 'Upload & preview →'}
          </button>
        </form>
      )}

      {/* Step 2: Map columns */}
      {step === 'map' && importData && (
        <form onSubmit={handleMap} className="lp-imp-card" style={{ overflow: 'hidden' }}>
          <div style={{ padding: '.9rem 1.25rem', borderBottom: '1px solid #f0f1f3', background: '#f9fafb' }}>
            <span style={{ fontWeight: 700, color: '#111827', fontSize: 14 }}>{importData.total} rows detected</span>
            <span style={{ color: '#9ca3af', fontSize: 13, marginLeft: '.5rem' }}>— map your columns (WhatsApp Number required)</span>
          </div>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14, minWidth: 520 }}>
              <thead>
                <tr style={{ textAlign: 'left', color: '#6b7280', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em', borderBottom: '1px solid #f0f1f3' }}>
                  <th style={{ padding: '.65rem 1.25rem', fontWeight: 700 }}>CSV column</th>
                  <th style={{ padding: '.65rem .6rem', fontWeight: 700 }}>Sample values</th>
                  <th style={{ padding: '.65rem 1.25rem', fontWeight: 700 }}>Map to field</th>
                </tr>
              </thead>
              <tbody>
                {importData.headers.map(h => (
                  <tr key={h} style={{ borderBottom: '1px solid #f6f7f9' }}>
                    <td style={{ padding: '.6rem 1.25rem', fontWeight: 700, color: '#111827' }}>{h}</td>
                    <td style={{ padding: '.6rem .6rem', color: '#9ca3af', fontSize: 12.5 }}>{importData.preview.slice(0, 3).map(r => r[h]).filter(Boolean).join(' · ') || '—'}</td>
                    <td style={{ padding: '.6rem 1.25rem' }}>
                      <select className="lp-imp-select" style={{ width: 'auto', minWidth: 180 }} value={mapping[h] ?? ''} onChange={e => setMapping(m => ({ ...m, [h]: e.target.value || undefined }))}>
                        <option value="">— skip —</option>
                        {CONTACT_FIELDS.map(f => <option key={f.key} value={f.key}>{f.label}</option>)}
                      </select>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div style={{ display: 'flex', gap: '.7rem', padding: '1rem 1.25rem', borderTop: '1px solid #f0f1f3' }}>
            <button type="button" onClick={() => setStep('upload')} className="lp-imp-btn lp-imp-btn-ghost">← Back</button>
            <button type="submit" className="lp-imp-btn lp-imp-btn-primary">Start import →</button>
          </div>
        </form>
      )}

      {/* Step 3: Progress */}
      {step === 'progress' && progress && (
        <div className="lp-imp-card" style={{ padding: '1.6rem' }}>
          <h3 style={{ fontWeight: 800, margin: '0 0 1.2rem', color: '#111827', fontSize: 16, display: 'flex', alignItems: 'center', gap: '.5rem' }}>
            <span style={{ width: 16, height: 16, border: '2px solid var(--primary-ring,rgba(10,108,196,.35))', borderTopColor: 'var(--primary,#0a6cc4)', borderRadius: '50%', display: 'inline-block', animation: 'lp-imp-in .01s, spin .7s linear infinite' }} />
            Importing…
          </h3>
          <div style={{ height: 10, borderRadius: 999, background: '#f3f4f6', overflow: 'hidden', marginBottom: '.8rem' }}>
            <div style={{ height: '100%', width: `${pct}%`, borderRadius: 999, background: 'linear-gradient(90deg, var(--primary,#0a6cc4), var(--primary-dark,#08569f))', transition: 'width .4s ease' }} />
          </div>
          <p style={{ color: '#374151', fontSize: 14, margin: 0 }}>
            <strong>{pct}%</strong> — {progress.imported} new · {progress.updated ?? 0} updated · {progress.failed} failed / {progress.total} total
          </p>
          <style>{`@keyframes spin { to { transform: rotate(360deg); } }`}</style>
        </div>
      )}

      {/* Done */}
      {step === 'done' && progress && (
        <div className="lp-imp-card" style={{ padding: '2.5rem', textAlign: 'center' }}>
          <div style={{ marginBottom: '.75rem', color: '#16a34a' }}><CheckCircle2 size={40} strokeWidth={1.4} /></div>
          <h3 style={{ fontWeight: 800, fontSize: 18, margin: '0 0 .5rem', color: '#111827' }}>Import complete!</h3>
          <p style={{ color: '#6b7280', margin: '0 0 1.5rem', fontSize: 14 }}>
            <strong style={{ color: '#16a34a' }}>{progress.imported}</strong> new · <strong>{progress.updated ?? 0}</strong> updated · <strong style={{ color: progress.failed ? '#dc2626' : '#6b7280' }}>{progress.failed}</strong> failed of {progress.total} total
          </p>
          <div style={{ display: 'flex', gap: '.7rem', justifyContent: 'center', flexWrap: 'wrap' }}>
            <button onClick={() => navigate('/contacts')} className="lp-imp-btn lp-imp-btn-primary">View contacts →</button>
            <button onClick={reset} className="lp-imp-btn lp-imp-btn-ghost">Import another file</button>
          </div>
        </div>
      )}

      {/* Failed */}
      {step === 'error' && (
        <div className="lp-imp-card" style={{ padding: '2.5rem', textAlign: 'center', borderColor: '#fca5a5' }}>
          <div style={{ marginBottom: '.75rem', color: '#dc2626' }}><XCircle size={40} strokeWidth={1.4} /></div>
          <h3 style={{ fontWeight: 800, fontSize: 18, margin: '0 0 .5rem', color: '#991b1b' }}>Import failed</h3>
          <p style={{ color: '#6b7280', margin: '0 0 1.5rem', fontSize: 14 }}>
            {progress
              ? <>The import stopped after {progress.imported} new · {progress.failed} failed of {progress.total}. Check your file and try again.</>
              : 'Something went wrong while importing. Please check your file and try again.'}
          </p>
          <button onClick={reset} className="lp-imp-btn lp-imp-btn-primary">Try again</button>
        </div>
      )}
    </div>
  )
}
