import { useState, useEffect, useCallback } from 'react'
import { Search, LayoutTemplate, X, Image as ImageIcon, ExternalLink } from 'lucide-react'
import { templates as templatesApi } from '../api/templates'
import { toast } from '../components/Toast'

// ── Inject styles once ──────────────────────────────────────────────────────
if (typeof document !== 'undefined' && !document.getElementById('lp-tpl-css')) {
  const el = document.createElement('style')
  el.id = 'lp-tpl-css'
  el.textContent = `
    @keyframes lp-tpl-in { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
    @keyframes lp-tpl-modal { from { opacity:0; transform:scale(.97); } to { opacity:1; transform:scale(1); } }
    .lp-tpl-card { background:#fff; border:1px solid #e5e7eb; border-radius:18px; animation:lp-tpl-in .25s ease both; }
    .lp-tpl-input, .lp-tpl-select, .lp-tpl-area {
      width:100%; padding:.6rem .8rem; border-radius:10px; border:1.5px solid #e5e7eb; background:#f9fafb;
      font-size:14px; color:#111827; outline:none; box-sizing:border-box; transition:border-color .15s, background .15s; font-family:inherit;
    }
    .lp-tpl-select { background:#fff; cursor:pointer; }
    .lp-tpl-input:focus, .lp-tpl-select:focus, .lp-tpl-area:focus { border-color:var(--primary,#0a6cc4); background:#fff; }
    .lp-tpl-label { font-size:12.5px; font-weight:700; color:#374151; margin-bottom:.35rem; display:block; }
    .lp-tpl-row:hover { background:#fafbff; }
    .lp-tpl-btn { padding:.35rem .75rem; border-radius:8px; font-weight:700; font-size:12.5px; cursor:pointer; border:1.5px solid transparent; white-space:nowrap; }
  `
  document.head.appendChild(el)
}

const META_STATUS = {
  draft:    { label: 'Draft',    bg: '#f3f4f6', color: '#4b5563' },
  pending:  { label: 'Pending',  bg: '#fef9c3', color: '#a16207' },
  approved: { label: 'Approved', bg: '#dcfce7', color: '#15803d' },
  rejected: { label: 'Rejected', bg: '#fee2e2', color: '#b91c1c' },
}
const CATEGORY = {
  marketing:      { label: 'Marketing', bg: '#ede9fe', color: '#6d28d9' },
  utility:        { label: 'Utility',   bg: '#dbeafe', color: '#1d4ed8' },
  authentication: { label: 'Auth',      bg: '#ffedd5', color: '#9a3412' },
}
const LANGUAGES = [
  { value: 'en', label: 'English' }, { value: 'hi', label: 'Hindi' }, { value: 'ta', label: 'Tamil' },
  { value: 'te', label: 'Telugu' }, { value: 'mr', label: 'Marathi' }, { value: 'bn', label: 'Bengali' }, { value: 'gu', label: 'Gujarati' },
]
const BLANK = { display_name: '', name: '', language: 'en', category: 'marketing', body: '', variables: '', header_type: 'none', footer: '', cards: [] }

const BLANK_CARD = { image_url: '', body: '', buttons: [{ type: 'URL', text: '', url: '' }] }

const parseVariables = (raw) => { if (!raw) return []; if (Array.isArray(raw)) return raw; try { return JSON.parse(raw) } catch { return [] } }
const toSnake = (s) => s.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '')

// ── WhatsApp-style preview ──────────────────────────────────────────────────
function WaPreview({ form }) {
  const varNames = form.variables ? form.variables.split(',').map(v => v.trim()).filter(Boolean) : []
  const renderBody = (text) => text.split(/(\{\{\d+\}\})/g).map((part, i) =>
    /^\{\{\d+\}\}$/.test(part)
      ? <span key={i} style={{ background: '#fde68a', color: '#92400e', borderRadius: 4, padding: '0 3px', fontWeight: 600 }}>{part}</span>
      : <span key={i}>{part}</span>)
  return (
    <div style={{ background: '#e5ddd5', borderRadius: 12, padding: '1rem', minHeight: 120 }}>
      <div style={{ background: '#fff', borderRadius: '0 8px 8px 8px', padding: '.7rem .85rem', maxWidth: 280, boxShadow: '0 1px 1px rgba(0,0,0,.12)', fontSize: 13.5, color: '#111b21', lineHeight: 1.5 }}>
        {form.header_type === 'text' && form.display_name && <div style={{ fontWeight: 700, marginBottom: 4 }}>{form.display_name}</div>}
        <div style={{ whiteSpace: 'pre-wrap' }}>{form.body ? renderBody(form.body) : <span style={{ color: '#9ca3af' }}>Your message body…</span>}</div>
        {form.footer && <div style={{ fontSize: 11, color: '#8696a0', marginTop: 6 }}>{form.footer}</div>}
        <div style={{ fontSize: 10, color: '#8696a0', textAlign: 'right', marginTop: 4 }}>12:00 ✓✓</div>
      </div>
      {(form.cards || []).length > 0 && (
        <div style={{ display: 'flex', gap: 8, marginTop: 10, overflowX: 'auto', paddingBottom: 4 }}>
          {form.cards.map((card, i) => (
            <div key={i} style={{ flex: '0 0 150px', background: '#fff', borderRadius: 8, overflow: 'hidden', boxShadow: '0 1px 1px rgba(0,0,0,.12)' }}>
              <div style={{ height: 80, background: card.image_url ? `center/cover no-repeat url(${card.image_url})` : '#d1d5db', display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#6b7280', fontSize: 11 }}>
                {!card.image_url && <ImageIcon size={18} strokeWidth={1.6} />}
              </div>
              <div style={{ padding: '.4rem .5rem' }}>
                <div style={{ fontSize: 11.5, color: '#111b21', lineHeight: 1.35, minHeight: 28 }}>{card.body || <span style={{ color: '#9ca3af' }}>Card text…</span>}</div>
                {card.buttons?.[0]?.text && <div style={{ marginTop: 5, borderTop: '1px solid #f0f1f3', paddingTop: 4, textAlign: 'center', color: '#1d9bf0', fontSize: 11.5, fontWeight: 600 }}>{card.buttons[0].type === 'URL' ? <ExternalLink size={11} style={{ verticalAlign: -1, marginRight: 3 }} /> : '↩ '}{card.buttons[0].text}</div>}
              </div>
            </div>
          ))}
        </div>
      )}
      {varNames.length > 0 && (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 5, marginTop: 10 }}>
          {varNames.map((v, i) => (
            <span key={i} style={{ background: '#fde68a', color: '#92400e', borderRadius: 999, padding: '.1rem .5rem', fontSize: 11, fontWeight: 600 }}>{`{{${i + 1}}}`} {v}</span>
          ))}
        </div>
      )}
    </div>
  )
}

export default function TemplatesPage() {
  const [rows, setRows]       = useState([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch]   = useState('')
  const [fStatus, setFStatus] = useState('')
  const [fCat, setFCat]       = useState('')
  const [showModal, setShowModal] = useState(false)
  const [editingId, setEditingId] = useState(null)
  const [form, setForm]       = useState(BLANK)
  const [saving, setSaving]   = useState(false)
  const [modalErr, setModalErr] = useState(null)
  const [busy, setBusy]       = useState({})
  const [confirmDel, setConfirmDel] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try { setRows((await templatesApi.list()).data ?? []) }
    catch (err) { toast.error('Failed to load templates', err.message) }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { load() }, [load])

  function openCreate() { setEditingId(null); setForm(BLANK); setModalErr(null); setShowModal(true) }
  function openEdit(row) {
    setEditingId(row.id)
    setForm({
      display_name: row.display_name ?? '', name: row.name ?? '', language: row.language ?? 'en',
      category: row.category ?? 'marketing', body: row.body ?? '', variables: parseVariables(row.variables).join(', '),
      header_type: row.header_type ?? 'none', footer: row.footer ?? '',
      cards: (() => { try { return JSON.parse(row.cards ?? '[]') || [] } catch { return [] } })(),
    })
    setModalErr(null); setShowModal(true)
  }

  async function handleSave() {
    if (!form.display_name.trim()) return setModalErr('Display name is required.')
    if (!form.name.trim()) return setModalErr('Internal name is required.')
    if (!form.body.trim()) return setModalErr('Body text is required.')
    setSaving(true); setModalErr(null)
    const vars = form.variables ? form.variables.split(',').map(v => v.trim()).filter(Boolean) : []
    const payload = {
      display_name: form.display_name.trim(), name: form.name.trim(), language: form.language, category: form.category,
      body: form.body.trim(), variables: vars, header_type: form.header_type, footer: form.footer.trim(),
      cards: (form.cards || []).filter(c => (c.image_url || '').trim() || (c.body || '').trim()),
    }
    try {
      if (editingId) { await templatesApi.update(editingId, payload); toast.success('Template updated', form.display_name.trim()) }
      else { await templatesApi.create(payload); toast.success('Template created', form.display_name.trim()) }
      setShowModal(false); await load()
    } catch (err) { setModalErr(err.message ?? 'Failed to save template.') }
    finally { setSaving(false) }
  }

  async function action(id, key, fn, okMsg) {
    setBusy(b => ({ ...b, [id + key]: true }))
    try { await fn(id); toast.success(okMsg); await load() }
    catch (err) { toast.error('Action failed', err.message) }
    finally { setBusy(b => ({ ...b, [id + key]: false })) }
  }

  async function handleDelete(id) {
    setBusy(b => ({ ...b, [id + 'del']: true }))
    try {
      await templatesApi.del(id)
      setRows(r => r.filter(x => x.id !== id))
      toast.success('Template deleted')
      setConfirmDel(null)
    } catch (err) { toast.error('Failed to delete', err.message) }
    finally { setBusy(b => ({ ...b, [id + 'del']: false })) }
  }

  const filtered = rows.filter(r => {
    if (search && !r.display_name?.toLowerCase().includes(search.toLowerCase()) && !r.name?.toLowerCase().includes(search.toLowerCase())) return false
    if (fStatus && r.meta_status !== fStatus) return false
    if (fCat && r.category !== fCat) return false
    return true
  })

  return (
    <div className="page">
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', flexWrap: 'wrap', gap: '.75rem', marginBottom: '1.4rem' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>Templates</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Pre-approved WhatsApp message templates — only approved ones can be sent in campaigns & flows.</p>
        </div>
        <button onClick={openCreate} style={{ padding: '.6rem 1.2rem', borderRadius: 10, border: 'none', background: 'var(--primary,#0a6cc4)', color: '#fff', fontWeight: 700, fontSize: 14, cursor: 'pointer', boxShadow: '0 2px 10px var(--primary-ring,rgba(10,108,196,.35))' }}>+ New template</button>
      </div>

      {/* Filter bar */}
      <div className="lp-tpl-card" style={{ padding: '.9rem 1rem', marginBottom: '1.1rem', display: 'flex', gap: '.7rem', flexWrap: 'wrap', alignItems: 'center' }}>
        <div style={{ position: 'relative', flex: '1 1 220px', minWidth: 180 }}>
          <span style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', display: 'flex', opacity: .5 }}><Search size={15} strokeWidth={2} /></span>
          <input className="lp-tpl-input" style={{ paddingLeft: '2.1rem' }} placeholder="Search by name…" value={search} onChange={e => setSearch(e.target.value)} />
        </div>
        <select className="lp-tpl-select" style={{ width: 'auto' }} value={fStatus} onChange={e => setFStatus(e.target.value)}>
          <option value="">All statuses</option>
          {Object.entries(META_STATUS).map(([v, s]) => <option key={v} value={v}>{s.label}</option>)}
        </select>
        <select className="lp-tpl-select" style={{ width: 'auto' }} value={fCat} onChange={e => setFCat(e.target.value)}>
          <option value="">All categories</option>
          {Object.entries(CATEGORY).map(([v, s]) => <option key={v} value={v}>{s.label}</option>)}
        </select>
      </div>

      {/* Table */}
      <div className="lp-tpl-card" style={{ overflow: 'hidden' }}>
        {loading ? (
          <div style={{ padding: '1rem 1.25rem' }}>
            {[1, 2, 3].map(i => <div key={i} style={{ height: 44, background: '#f3f4f6', borderRadius: 8, marginBottom: 8 }} />)}
          </div>
        ) : filtered.length === 0 ? (
          <div style={{ textAlign: 'center', padding: '56px 24px' }}>
            <div style={{ marginBottom: 10, color: '#9ca3af' }}><LayoutTemplate size={40} strokeWidth={1.4} /></div>
            <div style={{ fontWeight: 700, color: '#111827', marginBottom: 4 }}>No templates found</div>
            <div style={{ color: '#9ca3af', fontSize: 14, marginBottom: 16 }}>Create your first template to start sending campaigns.</div>
            <button onClick={openCreate} style={{ padding: '.55rem 1.1rem', borderRadius: 10, border: 'none', background: 'var(--primary,#0a6cc4)', color: '#fff', fontWeight: 700, fontSize: 14, cursor: 'pointer' }}>+ New template</button>
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14, minWidth: 720 }}>
              <thead>
                <tr style={{ textAlign: 'left', color: '#6b7280', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em', borderBottom: '1px solid #f0f1f3' }}>
                  <th style={{ padding: '.7rem 1.25rem', fontWeight: 700 }}>Template</th>
                  <th style={{ padding: '.7rem .6rem', fontWeight: 700 }}>Category</th>
                  <th style={{ padding: '.7rem .6rem', fontWeight: 700 }}>Lang</th>
                  <th style={{ padding: '.7rem .6rem', fontWeight: 700 }}>Status</th>
                  <th style={{ padding: '.7rem .6rem', fontWeight: 700 }}>Vars</th>
                  <th style={{ padding: '.7rem 1.25rem', fontWeight: 700, textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map(row => {
                  const vars = parseVariables(row.variables)
                  const cat = CATEGORY[row.category] ?? { label: row.category, bg: '#f3f4f6', color: '#4b5563' }
                  const st = META_STATUS[row.meta_status] ?? META_STATUS.draft
                  const canSubmit = row.meta_status === 'draft' || row.meta_status === 'rejected'
                  const canSync = row.meta_status === 'pending'
                  const canEdit = row.meta_status === 'draft' || row.meta_status === 'rejected'
                  return (
                    <tr key={row.id} className="lp-tpl-row" style={{ borderBottom: '1px solid #f6f7f9' }}>
                      <td style={{ padding: '.7rem 1.25rem' }}>
                        <div style={{ fontWeight: 700, color: '#111827' }}>{row.display_name}</div>
                        <div style={{ fontSize: 12, color: '#9ca3af', fontFamily: 'monospace' }}>{row.name}</div>
                      </td>
                      <td style={{ padding: '.7rem .6rem' }}><span style={{ padding: '.2rem .6rem', borderRadius: 999, fontSize: 11.5, fontWeight: 700, background: cat.bg, color: cat.color }}>{cat.label}</span></td>
                      <td style={{ padding: '.7rem .6rem', textTransform: 'uppercase', fontSize: 12.5, color: '#6b7280', fontWeight: 600 }}>{row.language}</td>
                      <td style={{ padding: '.7rem .6rem' }}><span style={{ padding: '.2rem .6rem', borderRadius: 999, fontSize: 11.5, fontWeight: 700, background: st.bg, color: st.color }}>{st.label}</span></td>
                      <td style={{ padding: '.7rem .6rem', color: '#6b7280', fontSize: 13 }}>{vars.length || '—'}</td>
                      <td style={{ padding: '.7rem 1.25rem' }}>
                        {confirmDel === row.id ? (
                          <div style={{ display: 'flex', gap: 6, alignItems: 'center', justifyContent: 'flex-end' }}>
                            <span style={{ fontSize: 12.5, color: '#dc2626', fontWeight: 600 }}>Delete?</span>
                            <button className="lp-tpl-btn" onClick={() => handleDelete(row.id)} disabled={busy[row.id + 'del']} style={{ background: '#dc2626', color: '#fff' }}>{busy[row.id + 'del'] ? '…' : 'Yes'}</button>
                            <button className="lp-tpl-btn" onClick={() => setConfirmDel(null)} style={{ background: '#fff', color: '#374151', borderColor: '#e5e7eb' }}>No</button>
                          </div>
                        ) : (
                          <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
                            {canSubmit && <button className="lp-tpl-btn" disabled={busy[row.id + 'submit']} onClick={() => action(row.id, 'submit', templatesApi.submit, 'Submitted to Meta — pending review.')} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff' }}>{busy[row.id + 'submit'] ? '…' : 'Submit'}</button>}
                            {canSync && <button className="lp-tpl-btn" disabled={busy[row.id + 'sync']} onClick={() => action(row.id, 'sync', templatesApi.sync, 'Status synced.')} style={{ background: '#fff', color: '#374151', borderColor: '#e5e7eb' }}>{busy[row.id + 'sync'] ? '…' : '↻ Sync'}</button>}
                            {canEdit && <button className="lp-tpl-btn" onClick={() => openEdit(row)} style={{ background: '#fff', color: '#374151', borderColor: '#e5e7eb' }}>Edit</button>}
                            <button className="lp-tpl-btn" onClick={() => setConfirmDel(row.id)} style={{ background: '#fff', color: '#dc2626', borderColor: '#fecaca' }}>Delete</button>
                          </div>
                        )}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* Modal */}
      {showModal && (
        <div onClick={() => !saving && setShowModal(false)} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)', backdropFilter: 'blur(4px)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '1rem', zIndex: 1000 }}>
          <div onClick={e => e.stopPropagation()} style={{ background: '#fff', borderRadius: 18, width: 720, maxWidth: '96vw', maxHeight: '92vh', overflow: 'auto', boxShadow: '0 25px 60px rgba(0,0,0,.22)', animation: 'lp-tpl-modal .18s ease' }}>
            <div style={{ padding: '1.2rem 1.5rem', borderBottom: '1px solid #f0f1f3', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <h2 style={{ margin: 0, fontSize: 17, fontWeight: 800, color: '#111827' }}>{editingId ? 'Edit template' : 'New template'}</h2>
              <button onClick={() => setShowModal(false)} style={{ background: 'none', border: 'none', display: 'flex', cursor: 'pointer', color: '#9ca3af' }}><X size={18} strokeWidth={2} /></button>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0,1fr) 300px', gap: 0 }}>
              {/* Form */}
              <div style={{ padding: '1.4rem 1.5rem' }}>
                {modalErr && <div style={{ background: '#fef2f2', border: '1px solid #fca5a5', color: '#dc2626', borderRadius: 10, padding: '.6rem .85rem', marginBottom: '1rem', fontSize: 13.5 }}>{modalErr}</div>}
                <div style={{ marginBottom: '1rem' }}>
                  <label className="lp-tpl-label">Display name *</label>
                  <input className="lp-tpl-input" value={form.display_name} onChange={e => setForm(f => ({ ...f, display_name: e.target.value, name: editingId ? f.name : toSnake(e.target.value) }))} placeholder="e.g. Welcome Message" />
                </div>
                <div style={{ marginBottom: '1rem' }}>
                  <label className="lp-tpl-label">Internal name (snake_case) *</label>
                  <input className="lp-tpl-input" value={form.name} readOnly={!!editingId} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} placeholder="welcome_message" style={editingId ? { background: '#f3f4f6', color: '#9ca3af', cursor: 'not-allowed', fontFamily: 'monospace' } : { fontFamily: 'monospace' }} />
                </div>
                <div style={{ display: 'flex', gap: '.8rem', marginBottom: '1rem' }}>
                  <div style={{ flex: 1 }}>
                    <label className="lp-tpl-label">Language</label>
                    <select className="lp-tpl-select" value={form.language} onChange={e => setForm(f => ({ ...f, language: e.target.value }))}>{LANGUAGES.map(l => <option key={l.value} value={l.value}>{l.label}</option>)}</select>
                  </div>
                  <div style={{ flex: 1 }}>
                    <label className="lp-tpl-label">Category</label>
                    <select className="lp-tpl-select" value={form.category} onChange={e => setForm(f => ({ ...f, category: e.target.value }))}>{Object.entries(CATEGORY).map(([v, s]) => <option key={v} value={v}>{s.label}</option>)}</select>
                  </div>
                </div>
                <div style={{ marginBottom: '1rem' }}>
                  <label className="lp-tpl-label">Body text *</label>
                  <textarea className="lp-tpl-area" rows={5} value={form.body} onChange={e => setForm(f => ({ ...f, body: e.target.value }))} placeholder="Hello {{1}}, your appointment is confirmed for {{2}}." style={{ resize: 'vertical' }} />
                  <div style={{ fontSize: 12, color: '#9ca3af', marginTop: 4 }}>Use {'{{1}}'} {'{{2}}'} as placeholders for variables.</div>
                </div>
                <div style={{ marginBottom: '1rem' }}>
                  <label className="lp-tpl-label">Variables (comma-separated)</label>
                  <input className="lp-tpl-input" value={form.variables} onChange={e => setForm(f => ({ ...f, variables: e.target.value }))} placeholder="customer_name, appointment_date" />
                </div>
                <div style={{ display: 'flex', gap: '.8rem' }}>
                  <div style={{ flex: 1 }}>
                    <label className="lp-tpl-label">Header</label>
                    <select className="lp-tpl-select" value={form.header_type} onChange={e => setForm(f => ({ ...f, header_type: e.target.value }))}><option value="none">None</option><option value="text">Text</option></select>
                  </div>
                  <div style={{ flex: 2 }}>
                    <label className="lp-tpl-label">Footer (optional)</label>
                    <input className="lp-tpl-input" value={form.footer} onChange={e => setForm(f => ({ ...f, footer: e.target.value }))} placeholder="Reply STOP to unsubscribe" />
                  </div>
                </div>

                {/* Carousel cards */}
                <div style={{ marginTop: '1.4rem', borderTop: '1px dashed #e5e7eb', paddingTop: '1.1rem' }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '.5rem' }}>
                    <label className="lp-tpl-label" style={{ marginBottom: 0 }}>Carousel cards (optional)</label>
                    {(form.cards || []).length < 10 && (
                      <button type="button" onClick={() => setForm(f => ({ ...f, cards: [...(f.cards || []), JSON.parse(JSON.stringify(BLANK_CARD))] }))}
                        style={{ background: '#e8f3fc', color: '#08569f', border: '1px solid #bcdcf6', borderRadius: 8, padding: '.3rem .7rem', fontSize: 12.5, fontWeight: 700, cursor: 'pointer' }}>+ Add card</button>
                    )}
                  </div>
                  <div style={{ fontSize: 12, color: '#9ca3af', marginBottom: '.7rem' }}>
                    Add 2–10 cards for a swipeable carousel. The body above becomes the intro message. Each card needs a public image URL. Category must be Marketing.
                  </div>
                  {(form.cards || []).map((card, ci) => {
                    const setCard = (patch) => setForm(f => ({ ...f, cards: f.cards.map((c, i) => i === ci ? { ...c, ...patch } : c) }))
                    const btn = card.buttons?.[0] ?? { type: 'URL', text: '', url: '' }
                    const setBtn = (patch) => setCard({ buttons: [{ ...btn, ...patch }] })
                    return (
                      <div key={ci} style={{ border: '1px solid #e5e7eb', borderRadius: 12, padding: '.9rem', marginBottom: '.7rem', background: '#fafafa' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '.55rem' }}>
                          <span style={{ fontSize: 12.5, fontWeight: 700, color: '#6b7280' }}>Card {ci + 1}</span>
                          <button type="button" onClick={() => setForm(f => ({ ...f, cards: f.cards.filter((_, i) => i !== ci) }))}
                            style={{ background: 'none', border: 'none', color: '#dc2626', fontSize: 12.5, fontWeight: 700, cursor: 'pointer' }}>Remove</button>
                        </div>
                        <input className="lp-tpl-input" style={{ marginBottom: '.5rem' }} value={card.image_url} onChange={e => setCard({ image_url: e.target.value })} placeholder="Card image URL (https://…)" />
                        <textarea className="lp-tpl-area" rows={2} style={{ marginBottom: '.5rem', resize: 'vertical' }} value={card.body} onChange={e => setCard({ body: e.target.value })} placeholder="Card text" />
                        <div style={{ display: 'flex', gap: '.5rem', alignItems: 'center' }}>
                          <select className="lp-tpl-select" style={{ width: 130 }} value={btn.type} onChange={e => setBtn({ type: e.target.value })}>
                            <option value="URL">Link button</option>
                            <option value="QUICK_REPLY">Quick reply</option>
                          </select>
                          <input className="lp-tpl-input" style={{ flex: 1 }} value={btn.text} onChange={e => setBtn({ text: e.target.value })} placeholder="Button text" />
                        </div>
                        {btn.type === 'URL' && (
                          <input className="lp-tpl-input" style={{ marginTop: '.5rem' }} value={btn.url} onChange={e => setBtn({ url: e.target.value })} placeholder="https://link-to-open.com" />
                        )}
                      </div>
                    )
                  })}
                </div>
              </div>
              {/* Preview */}
              <div style={{ padding: '1.4rem 1.25rem', background: '#f9fafb', borderLeft: '1px solid #f0f1f3' }}>
                <div style={{ fontSize: 12, fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: '.7rem' }}>Preview</div>
                <WaPreview form={form} />
              </div>
            </div>
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '.7rem', padding: '1rem 1.5rem', borderTop: '1px solid #f0f1f3' }}>
              <button onClick={() => setShowModal(false)} disabled={saving} style={{ padding: '.6rem 1.1rem', borderRadius: 10, border: '1.5px solid #e5e7eb', background: '#fff', color: '#374151', fontWeight: 600, fontSize: 14, cursor: 'pointer' }}>Cancel</button>
              <button onClick={handleSave} disabled={saving} style={{ padding: '.6rem 1.4rem', borderRadius: 10, border: 'none', background: saving ? '#c7cdd6' : 'var(--primary,#0a6cc4)', color: '#fff', fontWeight: 700, fontSize: 14, cursor: saving ? 'not-allowed' : 'pointer' }}>{saving ? 'Saving…' : editingId ? 'Save changes' : 'Create template'}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
