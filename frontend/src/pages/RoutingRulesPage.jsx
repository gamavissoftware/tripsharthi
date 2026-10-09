import { useState, useEffect } from 'react'
import { routingRules as api } from '../api/routingRules'
import { tags as tagsApi } from '../api/tags'
import { api as client } from '../api/client'
import { useToast } from '../components/Toast'
import { Plus, Inbox, CheckCircle2 } from 'lucide-react'

const MATCH_LABELS = { any: 'Any conversation', tag: 'Has tag', keyword: 'Message contains' }

const blankRule = () => ({
  name: '', match_type: 'any', match_value: '',
  strategy: 'least_loaded', assigned_user_id: '', sort_order: 0, is_active: true,
})

export default function RoutingRulesPage() {
  const { toast } = useToast()
  const [rules, setRules]   = useState([])
  const [tagList, setTags]  = useState([])
  const [users, setUsers]   = useState([])
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState(null) // rule object being created/edited

  useEffect(() => { load() }, [])

  async function load() {
    setLoading(true)
    try {
      const [r, t] = await Promise.all([api.list(), tagsApi.list()])
      setRules(r.data ?? [])
      setTags(t.data ?? [])
      try { const u = await client.get('/team'); setUsers(u.data ?? []) } catch { setUsers([]) }
    } catch (err) {
      toast.error('Failed to load routing rules', err?.message)
    }
    setLoading(false)
  }

  async function save() {
    const r = editing
    if (!r.name.trim()) { toast.error('Name is required'); return }
    const payload = {
      ...r,
      assigned_user_id: r.strategy === 'specific' ? (parseInt(r.assigned_user_id, 10) || null) : null,
      match_value: r.match_type === 'any' ? null : r.match_value,
    }
    try {
      if (r.id) await api.update(r.id, payload)
      else      await api.create(payload)
      setEditing(null)
      toast.success('Routing rule saved')
      load()
    } catch (err) {
      toast.error('Failed to save', err?.message)
    }
  }

  async function remove(id) {
    try { await api.remove(id); setRules(prev => prev.filter(x => x.id !== id)); toast.success('Rule deleted') }
    catch (err) { toast.error('Failed to delete', err?.message) }
  }

  function describe(r) {
    if (r.match_type === 'any') return 'Any conversation'
    if (r.match_type === 'tag') {
      const t = tagList.find(x => String(x.id) === String(r.match_value))
      return `Has tag: ${t?.name ?? `#${r.match_value}`}`
    }
    return `Message contains: "${r.match_value}"`
  }

  function assignee(r) {
    if (r.strategy === 'least_loaded') return 'Least-loaded agent'
    const u = users.find(x => String(x.id) === String(r.assigned_user_id))
    return u ? u.name : `User #${r.assigned_user_id}`
  }

  return (
    <div className="page">
      <div className="page-header">
        <div>
          <h1 className="page-title">Inbox Routing</h1>
          <p style={{ color: 'var(--text-3)', fontSize: 13, marginTop: 4 }}>
            Auto-assign incoming conversations to agents. Rules are checked top to bottom; the first match wins.
          </p>
        </div>
        <button className="btn btn-primary" onClick={() => setEditing(blankRule())}><Plus size={15} strokeWidth={2} /> New rule</button>
      </div>

      <div className="card" style={{ overflow: 'hidden' }}>
        {loading ? (
          <div className="empty-state"><span className="empty-state-text">Loading…</span></div>
        ) : rules.length === 0 ? (
          <div className="empty-state" style={{ padding: '48px 24px', textAlign: 'center' }}>
            <div style={{ marginBottom: 8, color: 'var(--text-3)' }}><Inbox size={40} strokeWidth={1.4} /></div>
            <div style={{ fontWeight: 700, marginBottom: 6 }}>No routing rules yet</div>
            <div style={{ color: 'var(--text-3)', fontSize: 14, maxWidth: 360, margin: '0 auto 16px' }}>
              Without rules, new conversations stay unassigned until an agent picks them up.
            </div>
            <button className="btn btn-primary" onClick={() => setEditing(blankRule())}>Create your first rule</button>
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}><table className="data-table">
            <thead><tr><th>#</th><th>Name</th><th>When</th><th>Assign to</th><th>Active</th><th></th></tr></thead>
            <tbody>
              {rules.map((r, i) => (
                <tr key={r.id}>
                  <td style={{ color: 'var(--text-3)' }}>{r.sort_order ?? i}</td>
                  <td style={{ fontWeight: 600 }}>{r.name}</td>
                  <td>{describe(r)}</td>
                  <td>{assignee(r)}</td>
                  <td>{Number(r.is_active) ? <CheckCircle2 size={15} strokeWidth={2} color="#16a34a" /> : '—'}</td>
                  <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                    <button className="btn btn-ghost btn-sm" onClick={() => setEditing({ ...r, is_active: !!Number(r.is_active) })}>Edit</button>
                    <button className="btn btn-ghost btn-sm" style={{ color: '#dc2626' }} onClick={() => remove(r.id)}>Delete</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table></div>
        )}
      </div>

      {editing && (
        <div onClick={e => { if (e.target === e.currentTarget) setEditing(null) }}
          style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
          <div style={{ background: '#fff', borderRadius: 14, maxWidth: 440, width: '100%', padding: '1.5rem' }}>
            <h2 style={{ margin: '0 0 1rem', fontSize: 18, fontWeight: 800 }}>{editing.id ? 'Edit' : 'New'} routing rule</h2>

            <label style={lbl}>Name</label>
            <input className="form-input" value={editing.name} onChange={e => setEditing(s => ({ ...s, name: e.target.value }))} placeholder="e.g. VIP customers → Priya" />

            <label style={lbl}>When</label>
            <select className="form-select" value={editing.match_type} onChange={e => setEditing(s => ({ ...s, match_type: e.target.value, match_value: '' }))}>
              {Object.entries(MATCH_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>

            {editing.match_type === 'tag' && (
              <select className="form-select" style={{ marginTop: 8 }} value={editing.match_value} onChange={e => setEditing(s => ({ ...s, match_value: e.target.value }))}>
                <option value="">— choose tag —</option>
                {tagList.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
              </select>
            )}
            {editing.match_type === 'keyword' && (
              <input className="form-input" style={{ marginTop: 8 }} value={editing.match_value} onChange={e => setEditing(s => ({ ...s, match_value: e.target.value }))} placeholder="keyword to match in the message" />
            )}

            <label style={lbl}>Assign to</label>
            <select className="form-select" value={editing.strategy} onChange={e => setEditing(s => ({ ...s, strategy: e.target.value }))}>
              <option value="least_loaded">Least-loaded agent (auto-balance)</option>
              <option value="specific">A specific agent</option>
            </select>
            {editing.strategy === 'specific' && (
              <select className="form-select" style={{ marginTop: 8 }} value={editing.assigned_user_id ?? ''} onChange={e => setEditing(s => ({ ...s, assigned_user_id: e.target.value }))}>
                <option value="">— choose agent —</option>
                {users.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}
              </select>
            )}

            <div style={{ display: 'flex', gap: 16, marginTop: 12 }}>
              <label style={lbl}>Priority
                <input type="number" className="form-input" style={{ marginTop: 4, width: 90 }} value={editing.sort_order ?? 0} onChange={e => setEditing(s => ({ ...s, sort_order: parseInt(e.target.value, 10) || 0 }))} />
              </label>
              <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, marginTop: 22 }}>
                <input type="checkbox" checked={!!editing.is_active} onChange={e => setEditing(s => ({ ...s, is_active: e.target.checked }))} /> Active
              </label>
            </div>

            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: '1.5rem' }}>
              <button className="btn btn-ghost" onClick={() => setEditing(null)}>Cancel</button>
              <button className="btn btn-primary" onClick={save}>Save rule</button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

const lbl = { display: 'block', fontSize: 13, fontWeight: 600, color: '#374151', margin: '14px 0 6px' }
