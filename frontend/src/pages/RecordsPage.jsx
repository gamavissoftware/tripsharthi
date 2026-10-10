import { useState, useEffect } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { crm } from '../api/crm'
import { toast } from '../components/Toast'
import LoadFailed from '../components/LoadFailed'

// Render the right input for a field type. Value is always stored as a string.
export function DynamicField({ field, value, onChange, disabled }) {
  const opts = (() => { try { return JSON.parse(field.options || '[]') } catch { return [] } })()
  const common = { width: '100%', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.9rem', boxSizing: 'border-box' }
  if (field.type === 'textarea') return <textarea style={{ ...common, resize: 'vertical' }} rows={3} value={value ?? ''} disabled={disabled} onChange={e => onChange(e.target.value)} />
  if (field.type === 'select') return <select style={common} value={value ?? ''} disabled={disabled} onChange={e => onChange(e.target.value)}><option value="">—</option>{opts.map(o => <option key={o} value={o}>{o}</option>)}</select>
  if (field.type === 'boolean') return <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '.88rem' }}><input type="checkbox" checked={value === 'yes' || value === true} disabled={disabled} onChange={e => onChange(e.target.checked ? 'yes' : 'no')} /> {value === 'yes' ? 'Yes' : 'No'}</label>
  const inputType = { number: 'number', date: 'date', email: 'email', phone: 'tel' }[field.type] ?? 'text'
  return <input type={inputType} style={common} value={value ?? ''} disabled={disabled} onChange={e => onChange(e.target.value)} />
}

function CreateModal({ object, fields, onClose, onCreated }) {
  const [name, setName] = useState('')
  const [data, setData] = useState({})
  const [saving, setSaving] = useState(false)
  async function save(e) {
    e.preventDefault()
    if (!name.trim()) { toast.error('A title is required'); return }
    setSaving(true)
    try { await crm.createRecord(object.id, { name, data }); toast.success(`${object.label_singular} created`); onCreated() }
    catch (e) { toast.error('Could not create', e.message) }
    finally { setSaving(false) }
  }
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, backdropFilter: 'blur(3px)' }}>
      <form onClick={e => e.stopPropagation()} onSubmit={save} style={{ background: '#fff', borderRadius: 16, width: '100%', maxWidth: 480, maxHeight: '88vh', overflow: 'auto', padding: '1.5rem', boxShadow: '0 24px 64px rgba(0,0,0,.22)' }}>
        <h3 style={{ margin: '0 0 1rem', fontWeight: 800, fontSize: '1.05rem' }}>{object.icon} New {object.label_singular}</h3>
        <label style={lbl}>Title *</label>
        <input value={name} onChange={e => setName(e.target.value)} placeholder={`${object.label_singular} name`} autoFocus style={inp} />
        {fields.map(f => (
          <div key={f.id} style={{ marginTop: '.7rem' }}>
            <label style={lbl}>{f.label}{Number(f.required) ? ' *' : ''}</label>
            <DynamicField field={f} value={data[f.field_key]} onChange={v => setData(d => ({ ...d, [f.field_key]: v }))} />
          </div>
        ))}
        <div style={{ display: 'flex', gap: 10, marginTop: '1.25rem' }}>
          <button type="button" onClick={onClose} style={btnGhost}>Cancel</button>
          <button type="submit" disabled={saving} style={{ ...btnPrimary, flex: 2 }}>{saving ? 'Saving…' : `Create ${object.label_singular}`}</button>
        </div>
      </form>
    </div>
  )
}

export default function RecordsPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [object, setObject] = useState(null)
  const [records, setRecords] = useState([])
  const [loading, setLoading] = useState(true)
  const [showCreate, setShowCreate] = useState(false)

  const [loadErr, setLoadErr] = useState('')
  async function load() {
    setLoading(true)
    try {
      const [o, r] = await Promise.all([crm.getObject(id), crm.listRecords(id)])
      setObject(o.data); setRecords(r.data ?? [])
    } catch (e) { setLoadErr(e?.message || 'Not found'); toast.error('Failed to load', e.message) }
    finally { setLoading(false) }
  }
  useEffect(() => { load() }, [id])

  if (!object && loadErr) return <LoadFailed what="object" message={loadErr} backTo="/objects" backLabel="Back to objects" />
  if (!object) return <div className="page" style={{ maxWidth: 820 }}><div style={{ height: 120, borderRadius: 12, background: '#f3f4f6' }} /></div>
  const fields = object.fields ?? []
  const preview = fields.slice(0, 2)

  return (
    <div className="page" style={{ maxWidth: 820 }}>
      <div style={{ fontSize: 13, marginBottom: '1rem' }}>
        <Link to="/objects" style={{ color: 'var(--primary,#0a6cc4)', textDecoration: 'none', fontWeight: 600 }}>← Objects</Link>
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '1.25rem', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>{object.icon} {object.label_plural}</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>{records.length} records</p>
        </div>
        <button onClick={() => setShowCreate(true)} style={btnPrimary}>+ New {object.label_singular}</button>
      </div>

      {loading ? <div style={{ height: 64, borderRadius: 12, background: '#f3f4f6', animation: 'lp-pulse 1.2s infinite' }} />
        : records.length === 0 ? (
          <div style={{ textAlign: 'center', padding: '3rem 2rem', background: '#fff', borderRadius: 12, border: '2px dashed #e5e7eb' }}>
            <div style={{ fontSize: '2.5rem' }}>{object.icon}</div>
            <h3 style={{ fontWeight: 800, fontSize: '1rem', margin: '.5rem 0 .25rem' }}>No {object.label_plural.toLowerCase()} yet</h3>
            <button onClick={() => setShowCreate(true)} style={{ ...btnPrimary, flex: 'none', marginTop: '.5rem' }}>+ New {object.label_singular}</button>
          </div>
        ) : (
          <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 12, overflow: 'hidden' }}>
            {records.map((rec, i) => {
              const d = (() => { try { return JSON.parse(rec.data || '{}') } catch { return {} } })()
              return (
                <div key={rec.id} onClick={() => navigate(`/objects/${id}/records/${rec.id}`)} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '.85rem 1rem', borderTop: i ? '1px solid #f1f5f9' : 'none', cursor: 'pointer' }}>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontWeight: 700, fontSize: '.9rem', color: '#111827' }}>{rec.name}</div>
                    <div style={{ fontSize: '.74rem', color: '#94a3b8' }}>{preview.map(f => d[f.field_key] ? `${f.label}: ${d[f.field_key]}` : null).filter(Boolean).join(' · ')}</div>
                  </div>
                  <span style={{ color: '#cbd5e1' }}>›</span>
                </div>
              )
            })}
          </div>
        )}

      {showCreate && <CreateModal object={object} fields={fields} onClose={() => setShowCreate(false)} onCreated={() => { setShowCreate(false); load() }} />}
      <style>{`@keyframes lp-pulse{0%,100%{opacity:1}50%{opacity:.45}}`}</style>
    </div>
  )
}

const lbl = { display: 'block', fontSize: '.8rem', fontWeight: 600, color: '#374151', margin: '.5rem 0 .3rem' }
const inp = { width: '100%', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.9rem', boxSizing: 'border-box' }
const btnPrimary = { flex: 1, background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 10, padding: '.55rem 1rem', fontWeight: 700, cursor: 'pointer' }
const btnGhost = { flex: 1, background: '#fff', border: '1.5px solid #e5e7eb', borderRadius: 10, padding: '.6rem', fontWeight: 600, cursor: 'pointer' }
