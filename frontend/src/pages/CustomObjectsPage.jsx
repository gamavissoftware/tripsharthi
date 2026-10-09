import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { crm } from '../api/crm'
import { toast } from '../components/Toast'

const FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'select', 'boolean', 'email', 'phone']

function CreateObjectModal({ onClose, onCreated }) {
  const [form, setForm] = useState({ label_singular: '', label_plural: '', icon: '📦' })
  const [saving, setSaving] = useState(false)
  async function save(e) {
    e.preventDefault()
    if (!form.label_singular.trim()) { toast.error('Name is required'); return }
    setSaving(true)
    try { const r = await crm.createObject(form); toast.success('Object created', form.label_singular); onCreated(r.data) }
    catch (e) { toast.error('Could not create', e.message) }
    finally { setSaving(false) }
  }
  return (
    <div onClick={onClose} style={modalBg}>
      <form onClick={e => e.stopPropagation()} onSubmit={save} style={modalCard}>
        <h3 style={{ margin: '0 0 1rem', fontWeight: 800, fontSize: '1.05rem' }}>📦 New Custom Object</h3>
        <label style={lbl}>Singular name *</label>
        <input value={form.label_singular} onChange={e => setForm(f => ({ ...f, label_singular: e.target.value }))} placeholder="e.g. Property" autoFocus style={inp} />
        <label style={lbl}>Plural name</label>
        <input value={form.label_plural} onChange={e => setForm(f => ({ ...f, label_plural: e.target.value }))} placeholder="e.g. Properties (auto)" style={inp} />
        <label style={lbl}>Icon</label>
        <input value={form.icon} onChange={e => setForm(f => ({ ...f, icon: e.target.value }))} maxLength={2} style={{ ...inp, width: 70, textAlign: 'center', fontSize: '1.2rem' }} />
        <div style={{ display: 'flex', gap: 10, marginTop: '1.25rem' }}>
          <button type="button" onClick={onClose} style={btnGhost}>Cancel</button>
          <button type="submit" disabled={saving} style={{ ...btnPrimary, flex: 2 }}>{saving ? 'Creating…' : 'Create object'}</button>
        </div>
      </form>
    </div>
  )
}

function FieldManager({ object, onChanged }) {
  const [fields, setFields] = useState(object.fields ?? [])
  const [nf, setNf] = useState({ label: '', type: 'text', required: 0, options: '' })

  useEffect(() => { crm.getObject(object.id).then(r => setFields(r.data.fields ?? [])).catch(() => {}) }, [object.id])

  async function add() {
    if (!nf.label.trim()) return
    const payload = { label: nf.label, type: nf.type, required: nf.required }
    if (nf.type === 'select' && nf.options.trim()) payload.options = nf.options.split(',').map(s => s.trim()).filter(Boolean)
    try {
      await crm.addField(object.id, payload)
      setNf({ label: '', type: 'text', required: 0, options: '' })
      const r = await crm.getObject(object.id); setFields(r.data.fields ?? []); onChanged?.()
    } catch (e) { toast.error('Could not add field', e.message) }
  }
  async function remove(id) { try { await crm.deleteField(id); setFields(fs => fs.filter(f => f.id !== id)); onChanged?.() } catch { /* noop */ } }

  return (
    <div style={{ background: '#f8fafc', borderRadius: 10, padding: '.85rem', marginTop: '.5rem' }}>
      <div style={{ fontSize: '.72rem', fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: '.5rem' }}>Fields</div>
      {fields.map(f => (
        <div key={f.id} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '.3rem 0' }}>
          <span style={{ flex: 1, fontSize: '.85rem' }}>{f.label} <span style={{ color: '#94a3b8', fontSize: '.75rem' }}>· {f.type}{Number(f.required) ? ' · required' : ''}</span></span>
          <button onClick={() => remove(f.id)} style={{ background: 'none', border: 'none', color: '#cbd5e1', cursor: 'pointer' }}>✕</button>
        </div>
      ))}
      <div style={{ display: 'flex', gap: 6, marginTop: '.5rem', flexWrap: 'wrap', alignItems: 'center' }}>
        <input value={nf.label} onChange={e => setNf(s => ({ ...s, label: e.target.value }))} placeholder="Field label" style={{ ...inp, flex: '2 1 120px', margin: 0 }} />
        <select value={nf.type} onChange={e => setNf(s => ({ ...s, type: e.target.value }))} style={{ ...inp, width: 110, margin: 0 }}>{FIELD_TYPES.map(t => <option key={t} value={t}>{t}</option>)}</select>
        {nf.type === 'select' && <input value={nf.options} onChange={e => setNf(s => ({ ...s, options: e.target.value }))} placeholder="opt1, opt2" style={{ ...inp, flex: '1 1 120px', margin: 0 }} />}
        <label style={{ fontSize: '.78rem', display: 'flex', alignItems: 'center', gap: 4 }}><input type="checkbox" checked={!!nf.required} onChange={e => setNf(s => ({ ...s, required: e.target.checked ? 1 : 0 }))} /> req</label>
        <button onClick={add} style={{ ...btnPrimary, padding: '.4rem .8rem' }}>Add field</button>
      </div>
    </div>
  )
}

export default function CustomObjectsPage() {
  const navigate = useNavigate()
  const [objects, setObjects] = useState([])
  const [templates, setTemplates] = useState([])
  const [loading, setLoading] = useState(true)
  const [expanded, setExpanded] = useState(null)
  const [showCreate, setShowCreate] = useState(false)

  useEffect(() => { load() }, [])
  async function load() {
    setLoading(true)
    try {
      const [o, t] = await Promise.all([crm.listObjects(), crm.listTemplates()])
      setObjects(o.data ?? []); setTemplates(t.data ?? [])
    } catch (e) { toast.error('Failed to load', e.message) }
    finally { setLoading(false) }
  }
  async function applyTemplate(key) {
    try { const r = await crm.applyTemplate(key); toast.success('Template applied', r.data.label_plural); await load() }
    catch (e) { toast.error('Could not apply', e.message) }
  }
  async function del(id) { try { await crm.deleteObject(id); setObjects(os => os.filter(o => o.id !== id)) } catch (e) { toast.error('Delete failed', e.message) } }

  return (
    <div className="page" style={{ maxWidth: 820 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '1.25rem', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>📦 Custom Objects</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Model anything your industry needs — Properties, Patients, Policies, and more.</p>
        </div>
        <button onClick={() => setShowCreate(true)} style={btnPrimary}>+ New Object</button>
      </div>

      {/* Industry template quick-start */}
      <div style={{ marginBottom: '1.5rem' }}>
        <div style={{ fontSize: '.72rem', fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '.08em', marginBottom: '.6rem' }}>⚡ Quick start — apply an industry template</div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(190px, 1fr))', gap: '.6rem' }}>
          {templates.map(t => (
            <div key={t.key} style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 12, padding: '.85rem' }}>
              <div style={{ fontSize: '1.4rem' }}>{t.icon}</div>
              <div style={{ fontWeight: 700, fontSize: '.88rem', marginTop: 4 }}>{t.name}</div>
              <div style={{ fontSize: '.74rem', color: '#94a3b8', minHeight: 30 }}>{t.description}</div>
              <button onClick={() => applyTemplate(t.key)} style={{ ...btnPrimary, width: '100%', padding: '.4rem', marginTop: 6, fontSize: '.8rem' }}>Add {t.object_label}</button>
            </div>
          ))}
        </div>
      </div>

      {/* Existing objects */}
      <div style={{ fontSize: '.72rem', fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '.08em', marginBottom: '.6rem' }}>Your objects</div>
      {loading ? <div style={{ height: 64, borderRadius: 12, background: '#f3f4f6', animation: 'lp-pulse 1.2s infinite' }} />
        : objects.length === 0 ? <div style={{ color: '#94a3b8', fontSize: '.88rem', padding: '1rem 0' }}>No custom objects yet — apply a template above or create one.</div>
        : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '.6rem' }}>
            {objects.map(o => (
              <div key={o.id} style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 12, padding: '.85rem 1rem' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                  <span style={{ fontSize: '1.5rem' }}>{o.icon}</span>
                  <div style={{ flex: 1, cursor: 'pointer' }} onClick={() => navigate(`/objects/${o.id}`)}>
                    <div style={{ fontWeight: 700, fontSize: '.95rem' }}>{o.label_plural}</div>
                    <div style={{ fontSize: '.74rem', color: '#94a3b8' }}>{o.field_count} fields · {o.record_count} records</div>
                  </div>
                  <button onClick={() => navigate(`/objects/${o.id}`)} style={{ ...btnGhost, flex: 'none', padding: '.4rem .8rem', fontSize: '.8rem' }}>Open</button>
                  <button onClick={() => setExpanded(expanded === o.id ? null : o.id)} style={{ ...btnGhost, flex: 'none', padding: '.4rem .8rem', fontSize: '.8rem' }}>{expanded === o.id ? 'Done' : 'Fields'}</button>
                  <button onClick={() => del(o.id)} title="Delete object" style={{ background: 'none', border: 'none', color: '#cbd5e1', cursor: 'pointer', fontSize: '1rem' }}>🗑</button>
                </div>
                {expanded === o.id && <FieldManager object={o} onChanged={load} />}
              </div>
            ))}
          </div>
        )}

      {showCreate && <CreateObjectModal onClose={() => setShowCreate(false)} onCreated={() => { setShowCreate(false); load() }} />}
      <style>{`@keyframes lp-pulse{0%,100%{opacity:1}50%{opacity:.45}}`}</style>
    </div>
  )
}

const modalBg = { position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, backdropFilter: 'blur(3px)' }
const modalCard = { background: '#fff', borderRadius: 16, width: '100%', maxWidth: 440, padding: '1.5rem', boxShadow: '0 24px 64px rgba(0,0,0,.22)' }
const lbl = { display: 'block', fontSize: '.8rem', fontWeight: 600, color: '#374151', margin: '.7rem 0 .3rem' }
const inp = { width: '100%', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.9rem', boxSizing: 'border-box' }
const btnPrimary = { flex: 1, background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 10, padding: '.55rem 1rem', fontWeight: 700, cursor: 'pointer' }
const btnGhost = { flex: 1, background: '#fff', border: '1.5px solid #e5e7eb', borderRadius: 10, padding: '.6rem', fontWeight: 600, cursor: 'pointer' }
