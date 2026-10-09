import { useState, useEffect, useCallback } from 'react'
import { crm } from '../api/crm'
import { api } from '../api/client'
import { toast } from '../components/Toast'

// ── Auto-assignment rules (Phase H2) ─────────────────────────────────────────
// Per-entity (deal/ticket) rules that route new records to reps by round-robin /
// least-loaded / a specific user, with an optional pool and a capacity cap.

const ENTITIES = [{ key: 'deal', label: 'Deals', icon: '💼' }, { key: 'ticket', label: 'Tickets', icon: '🎫' }]
const STRATEGIES = { round_robin: 'Round-robin', least_loaded: 'Least loaded', specific: 'Specific person' }
const inp = { width: '100%', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.9rem', boxSizing: 'border-box' }

const blank = (entity) => ({ entity_type: entity, name: '', match_type: 'any', match_field: '', match_value: '', strategy: 'round_robin', assigned_user_id: '', capacity: 50, is_active: true })

function RuleModal({ entity, rule, members, onClose, onSaved }) {
  const [f, setF] = useState(rule ? { ...rule, assigned_user_id: rule.assigned_user_id ?? '' } : blank(entity))
  const [saving, setSaving] = useState(false)
  const set = (k, v) => setF(p => ({ ...p, [k]: v }))

  async function save(e) {
    e.preventDefault()
    if (!f.name.trim()) { toast.error('Name is required'); return }
    setSaving(true)
    try {
      const payload = { ...f, assigned_user_id: f.strategy === 'specific' ? Number(f.assigned_user_id) || null : null }
      if (rule) await crm.updateAssignmentRule(rule.id, payload)
      else await crm.createAssignmentRule(payload)
      toast.success(rule ? 'Rule updated' : 'Rule created'); onSaved()
    } catch (e) { toast.error('Save failed', e?.message) }
    setSaving(false)
  }

  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, backdropFilter: 'blur(3px)' }}>
      <form onClick={e => e.stopPropagation()} onSubmit={save} style={{ background: '#fff', borderRadius: 16, width: '100%', maxWidth: 480, padding: '1.5rem', boxShadow: '0 24px 64px rgba(0,0,0,.22)' }}>
        <h3 style={{ margin: '0 0 1rem', fontWeight: 800, fontSize: '1.05rem' }}>{rule ? 'Edit' : 'New'} {entity} assignment rule</h3>
        <label style={lbl}>Rule name</label>
        <input style={inp} value={f.name} onChange={e => set('name', e.target.value)} placeholder="e.g. Urgent → senior reps" autoFocus />

        <label style={lbl}>Applies to</label>
        <select style={inp} value={f.match_type} onChange={e => set('match_type', e.target.value)}>
          <option value="any">All new {entity}s</option>
          <option value="field">When a field equals…</option>
        </select>
        {f.match_type === 'field' && (
          <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
            <input style={inp} value={f.match_field} onChange={e => set('match_field', e.target.value)} placeholder="field (e.g. priority)" />
            <input style={inp} value={f.match_value} onChange={e => set('match_value', e.target.value)} placeholder="value (e.g. urgent)" />
          </div>
        )}

        <label style={lbl}>Assign by</label>
        <select style={inp} value={f.strategy} onChange={e => set('strategy', e.target.value)}>
          {Object.entries(STRATEGIES).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        {f.strategy === 'specific' && (
          <select style={{ ...inp, marginTop: 8 }} value={f.assigned_user_id} onChange={e => set('assigned_user_id', e.target.value)}>
            <option value="">Choose a person…</option>
            {members.map(m => <option key={m.id} value={m.id}>{m.name} ({m.role})</option>)}
          </select>
        )}

        {f.strategy !== 'specific' && (
          <>
            <label style={lbl}>Capacity (max open per rep)</label>
            <input style={inp} type="number" value={f.capacity} onChange={e => set('capacity', Number(e.target.value) || 0)} />
          </>
        )}

        <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: '.85rem', color: '#374151', margin: '1rem 0 1.25rem', cursor: 'pointer' }}>
          <input type="checkbox" checked={f.is_active} onChange={e => set('is_active', e.target.checked)} /> Active
        </label>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '.5rem' }}>
          <button type="button" style={btn(false)} onClick={onClose}>Cancel</button>
          <button type="submit" style={btn(true)} disabled={saving}>{saving ? 'Saving…' : 'Save'}</button>
        </div>
      </form>
    </div>
  )
}
const lbl = { display: 'block', fontSize: '.8rem', fontWeight: 600, color: '#374151', margin: '.85rem 0 .3rem' }
const btn = (p) => ({ padding: '.55rem 1.1rem', borderRadius: 9, fontWeight: 700, fontSize: 13.5, cursor: 'pointer', border: '1.5px solid ' + (p ? 'transparent' : '#e5e7eb'), background: p ? 'var(--primary,#0a6cc4)' : '#fff', color: p ? '#fff' : '#374151' })

export default function AssignmentRulesPage() {
  const [entity, setEntity] = useState('deal')
  const [rules, setRules] = useState([])
  const [members, setMembers] = useState([])
  const [loading, setLoading] = useState(true)
  const [editing, setEditing] = useState(undefined) // undefined=closed, null=new, obj=edit

  useEffect(() => { api.get('/team').then(r => setMembers(r.data ?? [])).catch(() => {}) }, [])

  const load = useCallback(async () => {
    setLoading(true)
    try { const r = await crm.assignmentRules(entity); setRules(r.data ?? []) }
    catch (e) { toast.error('Failed to load rules', e?.message) }
    setLoading(false)
  }, [entity])
  useEffect(() => { load() }, [load])

  async function remove(id) {
    if (!confirm('Delete this rule?')) return
    try { await crm.deleteAssignmentRule(id); load() } catch (e) { toast.error('Delete failed', e?.message) }
  }
  const userName = (id) => members.find(m => m.id === id)?.name ?? `#${id}`

  return (
    <div className="page" style={{ maxWidth: 820 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '1.25rem', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>🙋 Assignment Rules</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Auto-route new deals and tickets to your team.</p>
        </div>
        <button onClick={() => setEditing(null)} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 10, padding: '.55rem 1rem', fontWeight: 700, cursor: 'pointer' }}>+ New rule</button>
      </div>

      <div style={{ display: 'flex', gap: 6, marginBottom: '1rem' }}>
        {ENTITIES.map(e => (
          <button key={e.key} onClick={() => setEntity(e.key)}
            style={{ padding: '.4rem .9rem', borderRadius: 999, border: '1px solid ' + (entity === e.key ? 'var(--primary,#0a6cc4)' : '#e5e7eb'), background: entity === e.key ? 'var(--primary,#0a6cc4)' : '#fff', color: entity === e.key ? '#fff' : '#475569', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>{e.icon} {e.label}</button>
        ))}
      </div>

      {loading ? (
        <div style={{ padding: '2.5rem', textAlign: 'center', color: '#9ca3af' }}>Loading…</div>
      ) : rules.length === 0 ? (
        <div style={{ textAlign: 'center', padding: '3rem 2rem', background: '#fff', borderRadius: 14, border: '1px solid #e5e7eb', color: '#6b7280' }}>
          No {entity} rules yet — new {entity}s go to their creator.
        </div>
      ) : (
        <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, overflow: 'hidden' }}>
          {rules.map((r, i) => (
            <div key={r.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '.85rem 1rem', borderTop: i ? '1px solid #f1f5f9' : 'none' }}>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontWeight: 700, fontSize: '.92rem', color: '#111827' }}>
                  {r.name} {!r.is_active && <span style={{ fontSize: 11, color: '#9ca3af', fontWeight: 600 }}>(inactive)</span>}
                </div>
                <div style={{ fontSize: '.76rem', color: '#94a3b8' }}>
                  {r.match_type === 'field' ? `When ${r.match_field} = ${r.match_value}` : 'All new'} · {STRATEGIES[r.strategy]}
                  {r.strategy === 'specific' ? ` → ${userName(r.assigned_user_id)}` : ` · cap ${r.capacity}`}
                </div>
              </div>
              <button onClick={() => setEditing(r)} style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.35rem .7rem', fontSize: 12.5, fontWeight: 600, cursor: 'pointer' }}>Edit</button>
              <button onClick={() => remove(r.id)} style={{ background: '#fff', border: '1px solid #fecaca', color: '#b91c1c', borderRadius: 8, padding: '.35rem .7rem', fontSize: 12.5, fontWeight: 600, cursor: 'pointer' }}>Delete</button>
            </div>
          ))}
        </div>
      )}

      {editing !== undefined && <RuleModal entity={entity} rule={editing} members={members} onClose={() => setEditing(undefined)} onSaved={() => { setEditing(undefined); load() }} />}
    </div>
  )
}
