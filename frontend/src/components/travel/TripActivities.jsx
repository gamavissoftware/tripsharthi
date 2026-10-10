import { useState, useEffect, useCallback } from 'react'
import { Phone, MessageCircle, Mail, CalendarDays, CheckSquare, TriangleAlert, Check } from 'lucide-react'
import { crm } from '../../api/crm'
import { toast } from '../Toast'
import { dueInfo } from './tripActivity'

// Pipedrive-style activity indicator for a trip card + the panel to schedule / complete activities.
// Activities are CRM tasks on the trip's deal (related_type 'deal'), so they also show on the deal and in Tasks.
const TYPES = {
  call:     { label: 'Call',     icon: Phone },
  whatsapp: { label: 'WhatsApp', icon: MessageCircle },
  email:    { label: 'Email',    icon: Mail },
  meeting:  { label: 'Meeting',  icon: CalendarDays },
  todo:     { label: 'To-do',    icon: CheckSquare },
}

/** The small status on a card: red = overdue, green = planned, amber = nothing scheduled. */
export function ActivityChip({ trip, onOpen }) {
  if (!trip.deal_id) return null
  const open = Number(trip.task_open) || 0
  const next = trip.task_next
  let tone = 'none', Icon = TriangleAlert, label = 'No activity', tip = 'Nothing scheduled — click to schedule an activity'
  if (open > 0 && next) {
    const di = dueInfo(next.due_at)
    Icon = TYPES[next.type]?.icon || CheckSquare
    label = di.dated ? di.txt : 'No date'
    tone = di.overdue ? 'overdue' : 'planned'
    tip = `${TYPES[next.type]?.label || 'Task'}: ${next.title}${di.overdue ? ' (overdue)' : ''}` + (open > 1 ? ` — ${open} open` : '')
  }
  return (
    <button type="button" className={'tact tact-' + tone} title={tip} draggable={false}
      onClick={e => { e.stopPropagation(); onOpen(trip) }} onMouseDown={e => e.stopPropagation()}>
      <Icon size={12} /> <span>{label}</span>{open > 1 && <b>+{open - 1}</b>}
    </button>
  )
}

const tomorrow10 = () => { const d = new Date(); d.setDate(d.getDate() + 1); d.setHours(10, 0, 0, 0); const p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}` }

export function ActivityModal({ trip, onClose, onChanged }) {
  const [tasks, setTasks] = useState(null)
  const [f, setF] = useState({ type: 'call', title: '', due_at: tomorrow10(), priority: 'medium' })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState('')

  const load = useCallback(() => {
    crm.listTasks({ related_type: 'deal', related_id: trip.deal_id })
      .then(r => setTasks((r.data ?? []).filter(t => t.status === 'open')))
      .catch(e => { setTasks([]); setErr(e.message) })
  }, [trip.deal_id])
  useEffect(() => { load() }, [load])

  async function add(e) {
    e.preventDefault()
    if (!f.title.trim()) { setErr('Give the activity a title.'); return }
    setSaving(true); setErr('')
    try {
      await crm.createTask({ title: f.title.trim(), type: f.type, priority: f.priority, due_at: f.due_at ? f.due_at.replace('T', ' ') + ':00' : null, related_type: 'deal', related_id: Number(trip.deal_id) })
      setF(s => ({ ...s, title: '' }))
      toast.success('Activity scheduled')
      load(); onChanged()
    } catch (e2) { setErr(e2.message) } finally { setSaving(false) }
  }
  async function done(id) {
    try { await crm.completeTask(id); toast.success('Marked done'); load(); onChanged() } catch (e) { setErr(e.message) }
  }

  return (
    <div className="modal-backdrop" onClick={onClose}>
      <div className="modal" style={{ width: 520 }} onClick={e => e.stopPropagation()}>
        <div className="modal-header">Activities — {trip.title}</div>
        <div className="modal-body">
          {tasks === null && <p>Loading…</p>}
          {tasks?.length === 0 && <p className="text-muted" style={{ marginBottom: 12 }}>Nothing scheduled yet. Every open enquiry should have a next step.</p>}
          {tasks?.map(t => {
            const di = dueInfo(t.due_at); const Icon = TYPES[t.type]?.icon || CheckSquare
            return (
              <div key={t.id} className="tact-row">
                <button type="button" className="tact-done" title="Mark as done" aria-label={`Mark "${t.title}" as done`} onClick={() => done(t.id)}><Check size={14} /></button>
                <Icon size={14} style={{ color: 'var(--text-3)', flexShrink: 0 }} />
                <span className="tact-title">{t.title}</span>
                <span className={'tact-due' + (di.overdue ? ' is-overdue' : '')}>{di.dated ? di.txt + (t.due_at ? ' ' + String(t.due_at).slice(11, 16) : '') : 'No date'}</span>
              </div>
            )
          })}
          <form onSubmit={add} style={{ marginTop: 14, borderTop: '1px solid var(--border)', paddingTop: 12 }}>
            <label className="form-label" style={{ display: 'block', marginBottom: 6 }}>Schedule an activity</label>
            <div className="tact-types">
              {Object.entries(TYPES).map(([k, v]) => <button key={k} type="button" className={'btn btn-sm ' + (f.type === k ? 'btn-primary' : 'btn-ghost')} onClick={() => setF(s => ({ ...s, type: k }))}><v.icon size={13} /> {v.label}</button>)}
            </div>
            <input className="form-input" style={{ marginTop: 8 }} autoFocus placeholder="e.g. Send revised quote" value={f.title} onChange={e => setF(s => ({ ...s, title: e.target.value }))} />
            <div className="form-grid-2" style={{ marginTop: 8 }}>
              <input className="form-input" type="datetime-local" value={f.due_at} onChange={e => setF(s => ({ ...s, due_at: e.target.value }))} />
              <select className="form-select" value={f.priority} onChange={e => setF(s => ({ ...s, priority: e.target.value }))}>
                <option value="low">Low priority</option><option value="medium">Medium priority</option><option value="high">High priority</option>
              </select>
            </div>
            {err && <div className="form-error" style={{ marginTop: 8 }}>{err}</div>}
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: 12 }}>
              <button type="button" className="btn btn-ghost" onClick={onClose}>Close</button>
              <button className="btn btn-primary" disabled={saving}>{saving ? 'Saving…' : 'Schedule'}</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  )
}
