import { useState, useEffect } from 'react'
import { useParams, Link } from 'react-router-dom'
import { crm } from '../api/crm'
import { toast } from '../components/Toast'
import { DynamicField } from './RecordsPage'
import { TimelinePanel, NotesPanel, TasksPanel } from '../components/crm/RecordPanels'
import LoadFailed from '../components/LoadFailed'

const card = { background: '#fff', border: '1px solid #e5e7eb', borderRadius: 18, padding: '1.4rem 1.5rem', marginBottom: '1.25rem' }

export default function RecordDetailPage() {
  const { objectId, recordId } = useParams()
  const [record, setRecord] = useState(null)
  const [form, setForm] = useState({ name: '', data: {} })
  const [editing, setEditing] = useState(false)
  const [saving, setSaving] = useState(false)
  const [tlKey, setTlKey] = useState(0)

  const [loadErr, setLoadErr] = useState('')
  async function load() {
    let r
    try { r = await crm.getRecord(recordId) } catch (e) { setLoadErr(e?.message || 'Not found'); return }
    setRecord(r.data)
    setForm({ name: r.data.name ?? '', data: r.data.data ?? {} })
  }
  useEffect(() => { load() }, [recordId])

  async function save() {
    setSaving(true)
    try { await crm.updateRecord(recordId, { name: form.name, data: form.data }); setEditing(false); await load(); setTlKey(k => k + 1); toast.success('Saved') }
    catch (e) { toast.error('Save failed', e.message) }
    finally { setSaving(false) }
  }

  if (!record && loadErr) return <LoadFailed what="record" message={loadErr} backTo="/objects" backLabel="Back to objects" />
  if (!record) return <div className="page" style={{ maxWidth: 760 }}><div style={{ ...card, height: 160, background: '#f3f4f6' }} /></div>
  const obj = record.object ?? {}
  const fields = record.fields ?? []

  return (
    <div className="page" style={{ maxWidth: 760 }}>
      <div style={{ fontSize: 13, marginBottom: '1.1rem' }}>
        <Link to="/objects" style={{ color: 'var(--primary,#0a6cc4)', textDecoration: 'none', fontWeight: 600 }}>Objects</Link>
        <span style={{ color: '#d1d5db' }}> / </span>
        <Link to={`/objects/${objectId}`} style={{ color: 'var(--primary,#0a6cc4)', textDecoration: 'none', fontWeight: 600 }}>{obj.label_plural}</Link>
        <span style={{ color: '#d1d5db' }}> / </span><span style={{ color: '#6b7280' }}>{record.name}</span>
      </div>

      <div style={{ ...card, background: 'linear-gradient(135deg,#f8fafc,#e8f3fc)' }}>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10, justifyContent: 'space-between', alignItems: 'flex-start' }}>
          <div style={{ display: 'flex', gap: 12, alignItems: 'center', flex: '1 1 220px', minWidth: 0 }}>
            <span style={{ fontSize: '1.8rem' }}>{obj.icon}</span>
            {editing
              ? <input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} style={{ flex: 1, minWidth: 0, fontSize: '1.1rem', fontWeight: 800, border: '1px solid #e5e7eb', borderRadius: 8, padding: '.4rem .6rem' }} />
              : <div style={{ fontSize: 19, fontWeight: 800, color: '#111827', minWidth: 0, overflowWrap: 'anywhere' }}>{record.name}</div>}
          </div>
          {editing
            ? <div style={{ display: 'flex', gap: 6 }}><button onClick={save} disabled={saving} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 8, padding: '.45rem .9rem', fontWeight: 700, cursor: 'pointer' }}>{saving ? '…' : 'Save'}</button><button onClick={() => { setEditing(false); load() }} style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.45rem .7rem', cursor: 'pointer' }}>✕</button></div>
            : <button onClick={() => setEditing(true)} style={{ background: 'none', border: 'none', color: '#0a6cc4', fontWeight: 600, fontSize: 13, cursor: 'pointer' }}>Edit</button>}
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: '.9rem', marginTop: '1.1rem' }}>
          {fields.map(f => (
            <div key={f.id}>
              <div style={{ fontSize: '.7rem', color: '#94a3b8', textTransform: 'uppercase', fontWeight: 700, marginBottom: 3 }}>{f.label}</div>
              {editing
                ? <DynamicField field={f} value={form.data[f.field_key]} onChange={v => setForm(s => ({ ...s, data: { ...s.data, [f.field_key]: v } }))} />
                : <div style={{ fontSize: '.9rem', color: '#111827' }}>{record.data[f.field_key] || <span style={{ color: '#cbd5e1' }}>—</span>}</div>}
            </div>
          ))}
        </div>
      </div>

      <TasksPanel relatedType="custom_object_record" relatedId={Number(recordId)} />
      <NotesPanel relatedType="custom_object_record" relatedId={Number(recordId)} />
      <TimelinePanel relatedType="custom_object_record" relatedId={Number(recordId)} refreshKey={tlKey} />
    </div>
  )
}
