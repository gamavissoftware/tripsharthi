import { useState, useEffect } from 'react'
import { crm } from '../api/crm'
import { toast } from '../components/Toast'

const TYPE_ICON = { call: '📞', whatsapp: '💬', email: '✉️', meeting: '🗓️', todo: '✓' }
const PRIORITY = {
  high:   { label: 'High',   bg: '#fee2e2', color: '#b91c1c' },
  medium: { label: 'Medium', bg: '#fef9c3', color: '#a16207' },
  low:    { label: 'Low',    bg: '#e0f2fe', color: '#0369a1' },
}

function dueLabel(due) {
  if (!due) return null
  const d = new Date(due.replace(' ', 'T'))
  const today = new Date(); today.setHours(0, 0, 0, 0)
  const day = new Date(d); day.setHours(0, 0, 0, 0)
  const diff = Math.round((day - today) / 86400000)
  const overdue = d < new Date()
  let txt = d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })
  if (diff === 0) txt = 'Today'
  else if (diff === 1) txt = 'Tomorrow'
  else if (diff === -1) txt = 'Yesterday'
  return { txt, overdue }
}

export default function TasksPage() {
  const [tasks, setTasks] = useState([])
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState({ title: '', type: 'todo', priority: 'medium', due_at: '' })
  const [saving, setSaving] = useState(false)

  useEffect(() => { load() }, [])

  async function load() {
    setLoading(true)
    try {
      const r = await crm.listTasks({ assigned: 'me' })
      setTasks(r.data ?? [])
    } catch (e) { toast.error('Failed to load tasks', e.message) }
    finally { setLoading(false) }
  }

  async function add(e) {
    e.preventDefault()
    if (!form.title.trim()) { toast.error('Task title is required'); return }
    setSaving(true)
    try {
      const payload = { ...form, due_at: form.due_at ? form.due_at.replace('T', ' ') + ':00' : null }
      await crm.createTask(payload)
      setForm({ title: '', type: 'todo', priority: 'medium', due_at: '' })
      toast.success('Task added')
      await load()
    } catch (e) { toast.error('Could not add task', e.message) }
    finally { setSaving(false) }
  }

  async function complete(id) {
    try { await crm.completeTask(id); setTasks(t => t.filter(x => x.id !== id)); toast.success('Task completed ✓') }
    catch (e) { toast.error('Could not complete', e.message) }
  }

  async function remove(id) {
    try { await crm.deleteTask(id); setTasks(t => t.filter(x => x.id !== id)) }
    catch (e) { toast.error('Could not delete', e.message) }
  }

  return (
    <div className="page" style={{ maxWidth: 820 }}>
      <div style={{ marginBottom: '1.25rem' }}>
        <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>✓ My Tasks</h1>
        <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Your open follow-ups across every contact and deal.</p>
      </div>

      {/* Quick add */}
      <form onSubmit={add} style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center', background: '#fff', border: '1px solid #e5e7eb', borderRadius: 12, padding: '.75rem', marginBottom: '1rem' }}>
        <input value={form.title} onChange={e => setForm(f => ({ ...f, title: e.target.value }))}
          placeholder="Add a task…" style={{ flex: '2 1 240px', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.9rem' }} />
        <select value={form.type} onChange={e => setForm(f => ({ ...f, type: e.target.value }))} style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem', fontSize: '.85rem' }}>
          {Object.keys(TYPE_ICON).map(t => <option key={t} value={t}>{TYPE_ICON[t]} {t}</option>)}
        </select>
        <select value={form.priority} onChange={e => setForm(f => ({ ...f, priority: e.target.value }))} style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem', fontSize: '.85rem' }}>
          {Object.keys(PRIORITY).map(p => <option key={p} value={p}>{PRIORITY[p].label}</option>)}
        </select>
        <input type="datetime-local" value={form.due_at} onChange={e => setForm(f => ({ ...f, due_at: e.target.value }))}
          style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: '.45rem .5rem', fontSize: '.85rem' }} />
        <button type="submit" disabled={saving} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 8, padding: '.5rem 1rem', fontSize: '.85rem', fontWeight: 600, cursor: 'pointer' }}>
          {saving ? '…' : 'Add'}
        </button>
      </form>

      {/* List */}
      {loading ? (
        <div style={{ height: 64, borderRadius: 12, background: '#f3f4f6', animation: 'lp-pulse 1.2s infinite' }} />
      ) : tasks.length === 0 ? (
        <div style={{ textAlign: 'center', padding: '3rem 2rem', background: '#fff', borderRadius: 12, border: '2px dashed #e5e7eb' }}>
          <div style={{ fontSize: '2.5rem' }}>🎉</div>
          <h3 style={{ fontWeight: 800, fontSize: '1rem', margin: '.5rem 0 .25rem' }}>All clear</h3>
          <p style={{ color: '#6b7280', fontSize: '.85rem' }}>No open tasks assigned to you.</p>
        </div>
      ) : (
        <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 12, overflow: 'hidden' }}>
          {tasks.map((t, i) => {
            const due = dueLabel(t.due_at)
            const pr = PRIORITY[t.priority] ?? PRIORITY.medium
            return (
              <div key={t.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '.8rem 1rem', borderTop: i ? '1px solid #f1f5f9' : 'none' }}>
                <button onClick={() => complete(t.id)} title="Mark done" style={{ width: 22, height: 22, borderRadius: '50%', border: '2px solid #cbd5e1', background: '#fff', cursor: 'pointer', flexShrink: 0 }} />
                <span style={{ fontSize: '1.05rem' }}>{TYPE_ICON[t.type] ?? '✓'}</span>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontWeight: 600, fontSize: '.9rem', color: '#111827', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{t.title}</div>
                  {t.related_type && <div style={{ fontSize: '.72rem', color: '#94a3b8' }}>on {t.related_type} #{t.related_id}</div>}
                </div>
                {due && <span style={{ fontSize: '.75rem', fontWeight: 600, color: due.overdue ? '#dc2626' : '#475569' }}>{due.overdue ? '⚠ ' : ''}{due.txt}</span>}
                <span style={{ background: pr.bg, color: pr.color, borderRadius: 999, padding: '.12rem .55rem', fontSize: '.7rem', fontWeight: 700 }}>{pr.label}</span>
                <button onClick={() => remove(t.id)} title="Delete" style={{ background: 'none', border: 'none', color: '#cbd5e1', cursor: 'pointer', fontSize: '1rem' }}>✕</button>
              </div>
            )
          })}
        </div>
      )}
      <style>{`@keyframes lp-pulse{0%,100%{opacity:1}50%{opacity:.45}}`}</style>
    </div>
  )
}
