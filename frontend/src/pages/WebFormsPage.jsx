import { useState, useEffect } from 'react'
import { User, Smartphone, Mail, FileText, Link2, X, Pencil, Trash2, Clipboard, ClipboardList, ExternalLink, Zap, Check, Plus, GripVertical, Puzzle } from 'lucide-react'
import { webforms as webformsApi } from '../api/webforms'
import { api } from '../api/client'
import { toast } from '../components/Toast'

// ── Field config ──────────────────────────────────────────────────────────
const FIELD_META = {
  name:      { label: 'Name',             icon: <User size={14} strokeWidth={2} />,       bg: '#eff6ff', color: '#1d4ed8' },
  wa_number: { label: 'WhatsApp Number',  icon: <Smartphone size={14} strokeWidth={2} />, bg: '#f0fdf4', color: '#15803d' },
  email:     { label: 'Email',            icon: <Mail size={14} strokeWidth={2} />,       bg: '#fdf4ff', color: '#0e8f8c' },
}
const ALL_FIELDS = ['name', 'wa_number', 'email']
const labelFor = (k) => FIELD_META[k]?.label ?? k.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())

// Parse a stored fields value into [{field_key,label,required,custom}], tolerating
// legacy string arrays and always guaranteeing wa_number is present + required.
function parseFields(raw) {
  const std = new Set(ALL_FIELDS)
  let out = Array.isArray(raw) && raw.length
    ? raw.map(f => typeof f === 'string'
        ? { field_key: f, label: labelFor(f), required: f === 'wa_number', custom: !std.has(f) }
        : { field_key: f.field_key, label: f.label || labelFor(f.field_key), required: !!f.required || f.field_key === 'wa_number', custom: !std.has(f.field_key) })
    : [
        { field_key: 'wa_number', label: 'WhatsApp Number', required: true, custom: false },
        { field_key: 'name', label: 'Name', required: false, custom: false },
        { field_key: 'email', label: 'Email', required: false, custom: false },
      ]
  if (!out.some(f => f.field_key === 'wa_number')) {
    out = [{ field_key: 'wa_number', label: 'WhatsApp Number', required: true, custom: false }, ...out]
  }
  return out
}

// ── Toggle switch ─────────────────────────────────────────────────────────
function Toggle({ on, onChange, disabled }) {
  return (
    <button
      type="button"
      onClick={() => !disabled && onChange(!on)}
      disabled={disabled}
      style={{
        width: 44, height: 24, borderRadius: 12, border: 'none', cursor: disabled ? 'not-allowed' : 'pointer',
        background: on ? 'var(--primary,#0a6cc4)' : '#d1d5db', position: 'relative', flexShrink: 0, transition: 'background .2s',
        opacity: disabled ? .6 : 1,
      }}
    >
      <div style={{
        position: 'absolute', top: 2, left: on ? 22 : 2, width: 20, height: 20,
        borderRadius: 10, background: '#fff', transition: 'left .2s',
        boxShadow: '0 1px 3px rgba(0,0,0,.25)',
      }} />
    </button>
  )
}

// ── Snippet Modal ─────────────────────────────────────────────────────────
function SnippetModal({ formId, onClose }) {
  const [data, setData]   = useState(null)
  const [error, setError] = useState(null)
  const [copied, setCopied] = useState(null)

  useEffect(() => {
    webformsApi.snippet(formId).then(setData).catch(e => setError(e.message ?? 'Failed to load.'))
  }, [formId])

  async function copy(text, key) {
    try { await navigator.clipboard.writeText(text); setCopied(key); setTimeout(() => setCopied(null), 2000) }
    catch { toast.error('Copy failed', 'Please copy manually') }
  }

  return (
    <div onClick={onClose} style={{
      position:'fixed', inset:0, background:'rgba(0,0,0,.5)', zIndex:10000,
      display:'flex', alignItems:'center', justifyContent:'center', padding:20, backdropFilter:'blur(3px)',
    }}>
      <div onClick={e=>e.stopPropagation()} style={{
        background:'#fff', borderRadius:18, width:'100%', maxWidth:580,
        maxHeight:'90vh', overflow:'auto', boxShadow:'0 24px 64px rgba(0,0,0,.22)',
      }}>
        {/* Header */}
        <div style={{ padding:'1.25rem 1.5rem', borderBottom:'1px solid var(--border)', display:'flex', alignItems:'center', justifyContent:'space-between' }}>
          <div>
            <h3 style={{ fontWeight:800, fontSize:'1rem', margin:0 }}>Embed This Form</h3>
            <p style={{ margin:'2px 0 0', fontSize:'.78rem', color:'var(--text-3)' }}>Add to your website to capture leads</p>
          </div>
          <button onClick={onClose} style={{ background:'none', border:'none', display:'flex', alignItems:'center', cursor:'pointer', color:'var(--text-3)' }}><X size={18} strokeWidth={2} /></button>
        </div>

        <div style={{ padding:'1.5rem' }}>
          {error && <div className="form-error" style={{ marginBottom:'1rem' }}>{error}</div>}
          {!data && !error && <div style={{ color:'var(--text-3)', fontSize:'.875rem' }}>Loading…</div>}

          {data && (
            <>
              {/* Snippet section */}
              <div style={{ marginBottom:'1.25rem' }}>
                <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:'.5rem' }}>
                  <label className="form-label" style={{ margin:0 }}>Embed snippet</label>
                  <button
                    onClick={() => copy(data.snippet, 'snippet')}
                    className="btn btn-sm"
                    style={{ background: copied==='snippet' ? '#16a34a' : 'var(--primary,#0a6cc4)', color:'#fff', border:'none' }}>
                    {copied==='snippet' ? '✓ Copied!' : <><Clipboard size={13} strokeWidth={2} /> Copy Snippet</>}
                  </button>
                </div>
                <pre style={{
                  background:'#1e1e2e', color:'#a6e3a1', borderRadius:10, padding:'1rem',
                  fontSize:'.78rem', overflowX:'auto', lineHeight:1.6, margin:0,
                }}>
                  {data.snippet}
                </pre>
              </div>

              {/* Form URL section */}
              <div style={{ marginBottom:'1.5rem' }}>
                <div style={{ display:'flex', alignItems:'center', justifyContent:'space-between', marginBottom:'.5rem' }}>
                  <label className="form-label" style={{ margin:0 }}>Direct form URL</label>
                  <div style={{ display:'flex', gap:6 }}>
                    <button
                      onClick={() => copy(data.form_url, 'url')}
                      className="btn btn-sm btn-ghost">
                      {copied==='url' ? '✓ Copied!' : <><Link2 size={13} strokeWidth={2} /> Copy URL</>}
                    </button>
                    <a href={data.form_url} target="_blank" rel="noreferrer"
                      className="btn btn-sm btn-ghost" style={{ textDecoration:'none' }}>
                      <ExternalLink size={13} strokeWidth={2} /> Open
                    </a>
                  </div>
                </div>
                <pre style={{
                  background:'#f8fafc', borderRadius:8, padding:'.75rem 1rem', fontSize:'.78rem',
                  overflowX:'auto', border:'1px solid var(--border)', margin:0, color:'var(--primary,#0a6cc4)',
                }}>
                  {data.form_url}
                </pre>
              </div>

              {/* Instructions */}
              <div style={{ background:'#fffbeb', border:'1px solid #fde68a', borderRadius:10, padding:'1rem' }}>
                <div style={{ fontWeight:700, fontSize:'.8125rem', color:'#92400e', marginBottom:'.5rem' }}>
                  How to embed
                </div>
                {[
                  'Copy the snippet above',
                  'Paste it in your website HTML, just before </body>',
                  'The form appears automatically on your page',
                  'New submissions create contacts in TripSarthi instantly',
                ].map((step, i) => (
                  <div key={i} style={{ display:'flex', gap:8, fontSize:'.78rem', color:'#78350f', marginBottom:'.3rem' }}>
                    <span style={{ fontWeight:700, flexShrink:0 }}>{i+1}.</span>
                    <span>{step}</span>
                  </div>
                ))}
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  )
}

// ── Form editor Modal ─────────────────────────────────────────────────────
function FormModal({ editData, onClose, onSaved }) {
  const [title, setTitle]           = useState(editData?.title ?? '')
  const [redirectUrl, setRedirect]  = useState(editData?.redirect_url ?? '')
  const [fields, setFields]         = useState(() => parseFields(editData?.fields))
  const [catalog, setCatalog]       = useState([]) // tenant custom fields
  const [dragKey, setDragKey]       = useState(null)
  const [active, setActive]         = useState(editData?.active ?? true)
  const [saving, setSaving]         = useState(false)
  const [err, setErr]               = useState(null)

  useEffect(() => { api.get('/custom-fields').then(r => setCatalog(r.data ?? [])).catch(() => {}) }, [])

  const usedKeys = new Set(fields.map(f => f.field_key))
  const addable = [
    ...['name', 'email'].filter(k => !usedKeys.has(k)).map(k => ({ field_key: k, label: FIELD_META[k]?.label ?? k })),
    ...catalog.filter(c => !usedKeys.has(c.field_key)).map(c => ({ field_key: c.field_key, label: c.label, custom: true })),
  ]

  function addField(key) {
    const opt = addable.find(a => a.field_key === key)
    if (!opt) return
    setFields(fs => [...fs, { field_key: opt.field_key, label: opt.label, required: false, custom: !!opt.custom }])
  }
  function removeField(key) {
    if (key === 'wa_number') return
    setFields(fs => fs.filter(f => f.field_key !== key))
  }
  function patchField(key, patch) {
    setFields(fs => fs.map(f => f.field_key === key ? { ...f, ...patch } : f))
  }
  function moveField(fromKey, toKey) {
    if (!fromKey || fromKey === toKey) return
    setFields(fs => {
      const from = fs.findIndex(f => f.field_key === fromKey)
      const to = fs.findIndex(f => f.field_key === toKey)
      if (from < 0 || to < 0) return fs
      const copy = [...fs]
      const [moved] = copy.splice(from, 1)
      copy.splice(to, 0, moved)
      return copy
    })
  }

  async function handleSave(e) {
    e.preventDefault()
    if (!title.trim()) { setErr('Form title is required.'); return }
    setSaving(true); setErr(null)
    try {
      const payload = {
        title: title.trim(),
        redirect_url: redirectUrl.trim() || null,
        fields: fields.map(f => ({ field_key: f.field_key, label: f.label.trim() || f.field_key, required: !!f.required })),
        active,
      }
      if (editData) {
        await webformsApi.update(editData.id, payload)
        toast.success('Form updated!', title.trim())
      } else {
        await webformsApi.create(payload)
        toast.success('Form created!', title.trim())
      }
      onSaved()
    } catch (error) {
      setErr(error.message ?? 'Failed to save.')
      toast.error('Failed to save form', error.message)
    } finally {
      setSaving(false)
    }
  }

  const previewFields = fields

  return (
    <div onClick={onClose} style={{
      position:'fixed', inset:0, background:'rgba(0,0,0,.5)', zIndex:10000,
      display:'flex', alignItems:'center', justifyContent:'center', padding:20, backdropFilter:'blur(3px)',
    }}>
      <div onClick={e=>e.stopPropagation()} style={{
        background:'#fff', borderRadius:18, width:'100%', maxWidth:560,
        maxHeight:'90vh', overflow:'auto', boxShadow:'0 24px 64px rgba(0,0,0,.22)',
      }}>
        {/* Header */}
        <div style={{ padding:'1.25rem 1.5rem', borderBottom:'1px solid var(--border)', display:'flex', alignItems:'center', justifyContent:'space-between' }}>
          <div>
            <h3 style={{ fontWeight:800, fontSize:'1rem', margin:0 }}>{editData ? 'Edit Form' : 'Create Form'}</h3>
            <p style={{ margin:'2px 0 0', fontSize:'.78rem', color:'var(--text-3)' }}>Configure your lead capture form</p>
          </div>
          <button onClick={onClose} style={{ background:'none', border:'none', display:'flex', alignItems:'center', cursor:'pointer', color:'var(--text-3)' }}><X size={18} strokeWidth={2} /></button>
        </div>

        <form onSubmit={handleSave} style={{ padding:'1.5rem' }}>
          {err && <div className="field-error" style={{ marginBottom:'1rem', fontSize:'.875rem' }}>{err}</div>}

          {/* Title */}
          <div className="form-group" style={{ marginBottom:'1.25rem' }}>
            <label className="form-label" style={{ fontWeight:700 }}>Form title *</label>
            <input className="form-input" value={title} onChange={e=>setTitle(e.target.value)}
              placeholder="e.g. Contact Us, Get a Quote, Free Demo Request…" autoFocus />
          </div>

          {/* Redirect URL */}
          <div className="form-group" style={{ marginBottom:'1.5rem' }}>
            <label className="form-label" style={{ fontWeight:700 }}>Redirect URL <span style={{ fontWeight:400, color:'var(--text-3)' }}>(optional)</span></label>
            <input className="form-input" value={redirectUrl} onChange={e=>setRedirect(e.target.value)}
              placeholder="https://yoursite.com/thank-you" type="url" />
            <div className="field-hint">After submitting, redirect users to this URL</div>
          </div>

          {/* Fields builder */}
          <div style={{ marginBottom:'1.5rem' }}>
            <div style={{ fontWeight:700, fontSize:'.875rem', color:'var(--text)', marginBottom:'.35rem' }}>Form fields</div>
            <div style={{ fontSize:'.75rem', color:'var(--text-3)', marginBottom:'.75rem' }}>
              WhatsApp Number is always required. Drag to reorder; edit labels, toggle required, or add custom fields.
            </div>
            <div style={{ display:'flex', flexDirection:'column', gap:8 }}>
              {fields.map(f => {
                const m = FIELD_META[f.field_key]
                const locked = f.field_key === 'wa_number'
                return (
                  <div key={f.field_key}
                    draggable
                    onDragStart={() => setDragKey(f.field_key)}
                    onDragOver={e => e.preventDefault()}
                    onDrop={() => { moveField(dragKey, f.field_key); setDragKey(null) }}
                    onDragEnd={() => setDragKey(null)}
                    style={{ display:'flex', alignItems:'center', gap:8, padding:'.5rem .6rem', borderRadius:10, border:'1px solid var(--border)', background:'#fff', opacity: dragKey === f.field_key ? .5 : 1 }}>
                    <span title="Drag to reorder" style={{ cursor:'grab', color:'#cbd5e1', display:'flex' }}><GripVertical size={15} strokeWidth={2} /></span>
                    <span style={{ display:'flex', alignItems:'center', color:'var(--text-3)' }}>{m?.icon ?? (f.custom ? <Puzzle size={14} strokeWidth={2} /> : <FileText size={14} strokeWidth={2} />)}</span>
                    <input value={f.label} onChange={e => patchField(f.field_key, { label: e.target.value })}
                      style={{ flex:1, border:'1px solid var(--border)', borderRadius:8, padding:'.35rem .5rem', fontSize:'.85rem' }} />
                    <code style={{ fontSize:'.68rem', color:'#94a3b8' }}>{f.field_key}</code>
                    <label style={{ display:'flex', alignItems:'center', gap:4, fontSize:'.72rem', color:'#475569', cursor: locked ? 'default' : 'pointer' }}>
                      <input type="checkbox" checked={f.required} disabled={locked} onChange={e => patchField(f.field_key, { required: e.target.checked })} /> req
                    </label>
                    {!locked && <button type="button" onClick={() => removeField(f.field_key)} title="Remove" style={{ background:'none', border:'none', color:'#b91c1c', cursor:'pointer', fontSize:'1rem', lineHeight:1 }}>×</button>}
                  </div>
                )
              })}
            </div>
            {addable.length > 0 && (
              <select value="" onChange={e => { addField(e.target.value); e.target.value = '' }}
                style={{ marginTop:8, border:'1px dashed var(--border)', borderRadius:8, padding:'.45rem .6rem', fontSize:'.82rem', color:'#475569', background:'#fff' }}>
                <option value="">＋ Add a field…</option>
                {addable.map(a => <option key={a.field_key} value={a.field_key}>{a.label}{a.custom ? ' (custom)' : ''}</option>)}
              </select>
            )}
          </div>

          {/* Live Preview */}
          <div style={{ marginBottom:'1.5rem' }}>
            <div style={{ fontWeight:700, fontSize:'.875rem', color:'var(--text)', marginBottom:'.75rem' }}>
              Form Preview
            </div>
            <div style={{
              border:'2px dashed var(--border)', borderRadius:12, padding:'1.25rem',
              background:'#f8fafc',
            }}>
              <div style={{ fontWeight:800, fontSize:'1rem', marginBottom:'1rem', color:'#111' }}>{title || 'Your Form Title'}</div>
              {previewFields.map(f => {
                const m = FIELD_META[f.field_key]
                return (
                  <div key={f.field_key} style={{ marginBottom:'.75rem' }}>
                    <div style={{ fontSize:'.75rem', fontWeight:600, color:'#374151', marginBottom:'.25rem' }}>
                      {m?.icon ?? (f.custom ? <Puzzle size={14} strokeWidth={2} /> : <FileText size={14} strokeWidth={2} />)} {f.label}{f.required && <span style={{ color:'#dc2626' }}> *</span>}
                    </div>
                    <div style={{
                      height:36, borderRadius:6, border:'1px solid #d1d5db',
                      background:'#fff', padding:'0 .75rem', display:'flex', alignItems:'center',
                    }}>
                      <span style={{ fontSize:'.8rem', color:'#9ca3af' }}>
                        {f.field_key==='wa_number' ? '+91 98765 43210' : f.field_key==='email' ? 'name@example.com' : f.field_key==='name' ? 'Your name' : ''}
                      </span>
                    </div>
                  </div>
                )
              })}
              <div style={{
                height:38, borderRadius:8, background:'var(--primary,#0a6cc4)', display:'flex',
                alignItems:'center', justifyContent:'center', marginTop:'.5rem',
              }}>
                <span style={{ color:'#fff', fontWeight:700, fontSize:'.875rem' }}>Submit →</span>
              </div>
            </div>
          </div>

          {/* Active toggle */}
          <div style={{
            display:'flex', alignItems:'center', justifyContent:'space-between',
            padding:'.85rem 1rem', borderRadius:10, border:'1px solid var(--border)',
            background:'#fafafa', marginBottom:'1.5rem',
          }}>
            <div>
              <div style={{ fontWeight:600, fontSize:'.875rem' }}>Form is active</div>
              <div style={{ fontSize:'.75rem', color:'var(--text-3)' }}>Deactivate to stop accepting new submissions</div>
            </div>
            <Toggle on={active} onChange={setActive} />
          </div>

          {/* Buttons */}
          <div style={{ display:'flex', gap:10 }}>
            <button type="button" onClick={onClose} className="btn btn-ghost" style={{ flex:1 }}>Cancel</button>
            <button type="submit" disabled={saving || !title.trim()} className="btn btn-primary" style={{ flex:2, justifyContent:'center' }}>
              {saving ? '…' : (editData ? <><Check size={15} strokeWidth={2} /> Save Changes</> : <><Plus size={15} strokeWidth={2} /> Create Form</>)}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

// ── Form Card ─────────────────────────────────────────────────────────────
function FormCard({ form, onSnippet, onEdit, onDelete, onToggleActive, confirmId, setConfirmId }) {
  const [hover, setHover] = useState(false)
  const fields = Array.isArray(form.fields) ? form.fields : []

  return (
    <div
      onMouseEnter={() => setHover(true)}
      onMouseLeave={() => setHover(false)}
      style={{
        background:'#fff', borderRadius:14, border:'1px solid var(--border)',
        padding:'1.25rem', display:'flex', flexDirection:'column', gap:'.85rem',
        boxShadow: hover ? '0 8px 24px rgba(0,0,0,.1)' : '0 1px 4px rgba(0,0,0,.05)',
        transition:'box-shadow .18s',
      }}
    >
      {/* Header */}
      <div style={{ display:'flex', alignItems:'flex-start', justifyContent:'space-between' }}>
        <div>
          <div style={{ fontWeight:800, fontSize:'.9375rem', color:'var(--text)', display:'flex', alignItems:'center', gap:6 }}>
            <span style={{ display:'flex', alignItems:'center', color:'var(--primary,#0a6cc4)' }}><ClipboardList size={16} strokeWidth={2} /></span> {form.title}
          </div>
        </div>
        <button
          onClick={() => onToggleActive(form)}
          style={{
            background: form.active ? '#f0fdf4' : '#f3f4f6',
            color: form.active ? '#15803d' : '#6b7280',
            border: `1px solid ${form.active ? '#86efac' : '#d1d5db'}`,
            borderRadius: 999, padding: '.2rem .7rem',
            fontSize: '.72rem', fontWeight: 700, cursor:'pointer',
            display:'flex', alignItems:'center', gap:4, transition:'all .15s',
          }}>
          <span style={{ fontSize:'.55rem' }}>{form.active ? '●' : '○'}</span>
          {form.active ? 'Active' : 'Inactive'}
        </button>
      </div>

      {/* Fields pills */}
      <div style={{ display:'flex', flexWrap:'wrap', gap:6 }}>
        {fields.map(f => {
          const key = typeof f === 'string' ? f : f.field_key
          const m = FIELD_META[key] ?? { label: (typeof f === 'string' ? labelFor(f) : (f.label || labelFor(key))), icon: <Puzzle size={14} strokeWidth={2} />, bg:'#f3f4f6', color:'#374151' }
          return (
            <span key={key} style={{
              background: m.bg, color: m.color, borderRadius:999,
              padding:'.18rem .65rem', fontSize:'.72rem', fontWeight:600,
              display:'inline-flex', alignItems:'center', gap:4,
            }}>
              {m.icon} {m.label}
            </span>
          )
        })}
        {fields.length === 0 && <span style={{ fontSize:'.78rem', color:'var(--text-3)' }}>No fields configured</span>}
      </div>

      {/* Redirect URL */}
      {form.redirect_url && (
        <div style={{ fontSize:'.75rem', color:'var(--text-3)', display:'flex', alignItems:'center', gap:6 }}>
          <span style={{ display:'flex', alignItems:'center', flexShrink:0 }}><Link2 size={13} strokeWidth={2} /></span>
          <span style={{ overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }}>
            {form.redirect_url}
          </span>
        </div>
      )}

      {/* Date */}
      <div style={{ fontSize:'.75rem', color:'var(--text-3)' }}>
        Created: {form.created_at ? new Date(form.created_at).toLocaleDateString('en-IN', { day:'numeric', month:'short', year:'numeric' }) : '—'}
      </div>

      {/* Delete confirmation or actions */}
      {confirmId === form.id ? (
        <div style={{ display:'flex', alignItems:'center', gap:6 }}>
          <span style={{ fontSize:'.78rem', color:'#dc2626', fontWeight:600 }}>Delete "{form.title}"?</span>
          <button onClick={() => onDelete(form.id)} style={{ marginLeft:'auto', background:'#dc2626', color:'#fff', border:'none', borderRadius:6, padding:'4px 12px', fontSize:'.75rem', cursor:'pointer', fontWeight:600 }}>Yes</button>
          <button onClick={() => setConfirmId(null)} style={{ background:'var(--bg)', border:'1px solid var(--border)', borderRadius:6, padding:'4px 12px', fontSize:'.75rem', cursor:'pointer' }}>No</button>
        </div>
      ) : (
        <div style={{ display:'flex', gap:6, borderTop:'1px solid var(--border)', paddingTop:'.85rem', marginTop:'.1rem' }}>
          <button onClick={() => onSnippet(form.id)} className="btn btn-sm" style={{ flex:1, justifyContent:'center', background:'#eff6ff', border:'1px solid #bfdbfe', color:'#1d4ed8', fontWeight:600, fontSize:'.78rem' }}>
            &lt;/&gt; Snippet
          </button>
          <button onClick={() => onEdit(form)} className="btn btn-sm btn-ghost" style={{ flex:1, justifyContent:'center', fontSize:'.78rem' }}>
            <Pencil size={13} strokeWidth={2} /> Edit
          </button>
          <button onClick={() => setConfirmId(form.id)} className="btn btn-sm" style={{ flex:1, justifyContent:'center', background:'#fff1f0', border:'1px solid #fca5a5', color:'#dc2626', fontWeight:600, fontSize:'.78rem' }}>
            <Trash2 size={13} strokeWidth={2} /> Delete
          </button>
        </div>
      )}
    </div>
  )
}

// ── Main Page ─────────────────────────────────────────────────────────────
export default function WebFormsPage() {
  const [forms, setForms]             = useState([])
  const [loading, setLoading]         = useState(true)
  const [showModal, setShowModal]     = useState(false)
  const [editForm, setEditForm]       = useState(null)
  const [snippetId, setSnippetId]     = useState(null)
  const [confirmId, setConfirmId]     = useState(null)

  useEffect(() => { load() }, [])

  async function load() {
    setLoading(true)
    try {
      const res = await webformsApi.list()
      setForms(res.data ?? [])
    } catch (err) {
      toast.error('Failed to load forms', err.message)
    } finally {
      setLoading(false)
    }
  }

  async function handleDelete(id) {
    try {
      await webformsApi.del(id)
      const f = forms.find(x => x.id === id)
      toast.success('Form deleted', f?.title)
      setConfirmId(null)
      setForms(prev => prev.filter(x => x.id !== id))
    } catch (err) {
      toast.error('Failed to delete form', err.message)
    }
  }

  async function handleToggleActive(form) {
    try {
      await webformsApi.update(form.id, { active: !form.active })
      setForms(prev => prev.map(f => f.id === form.id ? { ...f, active: !f.active } : f))
      toast.success(form.active ? 'Form deactivated' : 'Form activated', form.title)
    } catch (err) {
      toast.error('Failed to update form', err.message)
    }
  }

  function openCreate() { setEditForm(null); setShowModal(true) }
  function openEdit(form) { setEditForm(form); setShowModal(true) }
  function closeModal() { setShowModal(false); setEditForm(null) }
  function afterSave() { closeModal(); load() }

  return (
    <div className="page">
      {/* ── Header ─────────────────────────────────────────────────── */}
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', flexWrap: 'wrap', gap: '.75rem', marginBottom: '1.5rem' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>Web Forms</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Embed lead-capture forms on your website — submissions become contacts instantly.</p>
        </div>
        <button onClick={openCreate} style={{ padding: '.6rem 1.2rem', borderRadius: 10, border: 'none', background: 'var(--primary,#0a6cc4)', color: '#fff', fontWeight: 700, fontSize: 14, cursor: 'pointer', boxShadow: '0 2px 10px var(--primary-ring,rgba(10,108,196,.35))' }}>+ New form</button>
      </div>

      {/* ── Loading skeleton ───────────────────────────────────────── */}
      {loading && (
        <div style={{ display:'grid', gridTemplateColumns:'repeat(auto-fill, minmax(300px, 1fr))', gap:'1rem' }}>
          {[1,2].map(i => (
            <div key={i} style={{ background:'#f3f4f6', borderRadius:14, height:200, animation:'lp-pulse 1.2s infinite' }} />
          ))}
        </div>
      )}

      {/* ── Empty state ────────────────────────────────────────────── */}
      {!loading && forms.length === 0 && (
        <div style={{
          textAlign:'center', padding:'4rem 2rem',
          background:'#fff', borderRadius:16, border:'2px dashed var(--border)',
        }}>
          <div style={{ marginBottom:'.75rem', color:'var(--text-3)' }}><ClipboardList size={40} strokeWidth={1.4} /></div>
          <h3 style={{ fontWeight:800, fontSize:'1.1rem', marginBottom:'.4rem' }}>No web forms yet</h3>
          <p style={{ color:'var(--text-3)', fontSize:'.875rem', maxWidth:360, margin:'0 auto 1.25rem' }}>
            Create embeddable forms to capture leads directly from your website into TripSarthi.
          </p>
          <div style={{ display:'flex', flexDirection:'column', gap:'.35rem', maxWidth:280, margin:'0 auto 1.5rem', textAlign:'left' }}>
            {[
              [<Link2 size={14} strokeWidth={2} />, 'Embed on any website with a single script tag'],
              [<Zap size={14} strokeWidth={2} />, 'Contacts auto-trigger your WhatsApp flows'],
            ].map(([icon, text], i) => (
              <div key={i} style={{ fontSize:'.8rem', color:'var(--text-3)', display:'flex', gap:8 }}>
                <span style={{ display:'flex', alignItems:'center', flexShrink:0 }}>{icon}</span><span>{text}</span>
              </div>
            ))}
          </div>
          <button className="btn btn-primary" onClick={openCreate}>+ Create your first form</button>
        </div>
      )}

      {/* ── Forms grid ─────────────────────────────────────────────── */}
      {!loading && forms.length > 0 && (
        <div style={{ display:'grid', gridTemplateColumns:'repeat(auto-fill, minmax(300px, 1fr))', gap:'1rem' }}>
          {forms.map(form => (
            <FormCard
              key={form.id}
              form={form}
              onSnippet={setSnippetId}
              onEdit={openEdit}
              onDelete={handleDelete}
              onToggleActive={handleToggleActive}
              confirmId={confirmId}
              setConfirmId={setConfirmId}
            />
          ))}
          {/* Quick-create card */}
          <button onClick={openCreate} style={{
            border:'2px dashed var(--border)', borderRadius:14, background:'transparent',
            padding:'1.5rem', cursor:'pointer', display:'flex', flexDirection:'column',
            alignItems:'center', justifyContent:'center', gap:'.5rem', color:'var(--text-3)',
            fontSize:'.875rem', minHeight:200, transition:'all .18s',
          }}
            onMouseEnter={e => { e.currentTarget.style.borderColor='var(--primary,#0a6cc4)'; e.currentTarget.style.color='var(--primary,#0a6cc4)'; e.currentTarget.style.background='var(--primary-light,#e8f3fc)' }}
            onMouseLeave={e => { e.currentTarget.style.borderColor='var(--border)'; e.currentTarget.style.color='var(--text-3)'; e.currentTarget.style.background='transparent' }}
          >
            <span style={{ fontSize:'2rem' }}>+</span>
            <span style={{ fontWeight:600 }}>New Form</span>
          </button>
        </div>
      )}

      <style>{`@keyframes lp-pulse{0%,100%{opacity:1}50%{opacity:.45}}`}</style>

      {/* ── Modals ─────────────────────────────────────────────────── */}
      {showModal && <FormModal editData={editForm} onClose={closeModal} onSaved={afterSave} />}
      {snippetId && <SnippetModal formId={snippetId} onClose={() => setSnippetId(null)} />}
    </div>
  )
}
