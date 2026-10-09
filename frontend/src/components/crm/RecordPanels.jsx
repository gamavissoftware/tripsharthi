import { useState, useEffect } from 'react'
import { crm } from '../../api/crm'
import { toast } from '../Toast'

const card = { background: '#fff', border: '1px solid #e5e7eb', borderRadius: 18, padding: '1.4rem 1.5rem', marginBottom: '1.25rem' }
const title = { fontSize: 15, fontWeight: 800, color: '#111827', margin: '0 0 .9rem' }
const fmt = (dt) => { if (!dt) return ''; try { return new Date(dt.replace(' ', 'T')).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) } catch { return dt } }

const ACT_ICON = {
  note: '📝', call: '📞', meeting: '🗓️', email: '✉️', system: '⚙️',
  stage_change: '🔀', field_change: '✏️', lifecycle_change: '🔄',
  deal_won: '🏆', deal_lost: '💔', task_done: '✅',
}

// ── Unified timeline (activities + WhatsApp thread) ─────────────────────────
export function TimelinePanel({ relatedType, relatedId, refreshKey = 0 }) {
  const [feed, setFeed] = useState([])
  const [loading, setLoading] = useState(true)
  const [draft, setDraft] = useState('')
  const [type, setType] = useState('note')

  async function load() {
    setLoading(true)
    try { const r = await crm.timeline(relatedType, relatedId); setFeed(r.data ?? []) }
    catch (e) { toast.error('Failed to load timeline', e.message) }
    finally { setLoading(false) }
  }
  useEffect(() => { load() }, [relatedType, relatedId, refreshKey])

  async function log() {
    if (!draft.trim()) return
    try {
      await crm.logActivity({ type, body: draft, related_type: relatedType, related_id: relatedId })
      setDraft(''); toast.success('Logged'); await load()
    } catch (e) { toast.error('Could not log', e.message) }
  }

  return (
    <div style={card}>
      <h3 style={title}>🕓 Timeline</h3>

      {/* Log composer */}
      <div style={{ display: 'flex', gap: 8, marginBottom: '1rem', alignItems: 'flex-start' }}>
        <select value={type} onChange={e => setType(e.target.value)} style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem', fontSize: '.82rem' }}>
          {['note', 'call', 'meeting', 'email'].map(t => <option key={t} value={t}>{ACT_ICON[t]} {t}</option>)}
        </select>
        <input value={draft} onChange={e => setDraft(e.target.value)} onKeyDown={e => { if (e.key === 'Enter') log() }}
          placeholder="Log a call, meeting or note…" style={{ flex: 1, minWidth: 0, border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.88rem' }} />
        <button onClick={log} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 8, padding: '.5rem .9rem', fontWeight: 600, flexShrink: 0, cursor: 'pointer' }}>Log</button>
      </div>

      {loading ? <div style={{ color: '#9ca3af', fontSize: 13.5, textAlign: 'center', padding: '1rem' }}>Loading…</div>
        : feed.length === 0 ? <div style={{ textAlign: 'center', padding: '1.4rem 0', color: '#9ca3af' }}><div style={{ fontSize: 26 }}>🕓</div><div style={{ fontSize: 13.5 }}>Nothing yet</div></div>
        : (
          <div style={{ maxHeight: 380, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: '.6rem', paddingRight: 4 }}>
            {feed.map((e, i) => {
              const isWa = e.type === 'whatsapp_in' || e.type === 'whatsapp_out'
              if (isWa) {
                const out = e.type === 'whatsapp_out'
                return (
                  <div key={e.source + e.id + i} style={{ display: 'flex', flexDirection: 'column', alignItems: out ? 'flex-end' : 'flex-start' }}>
                    <div style={{ maxWidth: '82%', background: out ? '#e8f3fc' : '#f3f4f6', borderRadius: out ? '12px 12px 2px 12px' : '12px 12px 12px 2px', padding: '.5rem .75rem', fontSize: 13.5, color: '#111827', wordBreak: 'break-word' }}>
                      <div>{e.body || <em style={{ color: '#9ca3af' }}>(media / template)</em>}</div>
                      <div style={{ fontSize: 10.5, color: '#9ca3af', marginTop: 3 }}>
                        <span style={{ fontWeight: 700, color: out ? '#08569f' : '#475569' }}>{out ? 'WhatsApp out' : 'WhatsApp in'}</span> · {fmt(e.occurred_at)}
                      </div>
                    </div>
                  </div>
                )
              }
              return (
                <div key={e.source + e.id + i} style={{ display: 'flex', gap: 10, alignItems: 'flex-start', padding: '.4rem 0' }}>
                  <span style={{ fontSize: '1rem', flexShrink: 0, marginTop: 1 }}>{ACT_ICON[e.type] ?? '•'}</span>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    {e.subject && <div style={{ fontWeight: 600, fontSize: '.86rem', color: '#111827', overflowWrap: 'anywhere' }}>{e.subject}</div>}
                    {e.body && <div style={{ fontSize: '.84rem', color: '#374151', whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{e.body}</div>}
                    <div style={{ fontSize: 10.5, color: '#9ca3af', marginTop: 2, textTransform: 'capitalize' }}>{e.type.replace(/_/g, ' ')} · {fmt(e.occurred_at)}</div>
                  </div>
                </div>
              )
            })}
          </div>
        )}
    </div>
  )
}

// ── Notes ───────────────────────────────────────────────────────────────────
export function NotesPanel({ relatedType, relatedId }) {
  const [notes, setNotes] = useState([])
  const [draft, setDraft] = useState('')

  async function load() {
    try { const r = await crm.listNotes(relatedType, relatedId); setNotes(r.data ?? []) } catch { /* noop */ }
  }
  useEffect(() => { load() }, [relatedType, relatedId])

  async function add() {
    if (!draft.trim()) return
    try { await crm.createNote({ body: draft, related_type: relatedType, related_id: relatedId }); setDraft(''); await load() }
    catch (e) { toast.error('Could not add note', e.message) }
  }
  async function togglePin(n) { try { await crm.updateNote(n.id, { is_pinned: n.is_pinned ? 0 : 1 }); await load() } catch { /* noop */ } }
  async function remove(id) { try { await crm.deleteNote(id); setNotes(ns => ns.filter(x => x.id !== id)) } catch { /* noop */ } }

  return (
    <div style={card}>
      <h3 style={title}>🗒️ Notes</h3>
      <div style={{ display: 'flex', gap: 8, marginBottom: '.85rem' }}>
        <input value={draft} onChange={e => setDraft(e.target.value)} onKeyDown={e => { if (e.key === 'Enter') add() }}
          placeholder="Add a note…" style={{ flex: 1, border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.88rem' }} />
        <button onClick={add} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 8, padding: '.5rem .9rem', fontWeight: 600, cursor: 'pointer' }}>Add</button>
      </div>
      {notes.length === 0 ? <div style={{ color: '#9ca3af', fontSize: 13.5 }}>No notes yet.</div>
        : notes.map(n => (
          <div key={n.id} style={{ background: n.is_pinned ? '#fffbeb' : '#f9fafb', border: '1px solid ' + (n.is_pinned ? '#fde68a' : '#f1f5f9'), borderRadius: 10, padding: '.6rem .8rem', marginBottom: '.5rem' }}>
            <div style={{ fontSize: '.88rem', color: '#111827', whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{n.body}</div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 4 }}>
              <span style={{ fontSize: 10.5, color: '#9ca3af' }}>{fmt(n.created_at)}</span>
              <button onClick={() => togglePin(n)} style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: '.72rem', color: n.is_pinned ? '#a16207' : '#94a3b8' }}>{n.is_pinned ? '📌 Pinned' : 'Pin'}</button>
              <button onClick={() => remove(n.id)} style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: '.72rem', color: '#cbd5e1', marginLeft: 'auto' }}>Delete</button>
            </div>
          </div>
        ))}
    </div>
  )
}

// ── Associations (link to custom-object records) ────────────────────────────
export function AssociationsPanel({ relatedType, relatedId }) {
  const [links, setLinks] = useState([])
  const [objects, setObjects] = useState([])
  const [objId, setObjId] = useState('')
  const [records, setRecords] = useState([])
  const [recId, setRecId] = useState('')

  async function load() {
    try { const r = await crm.listAssociations(relatedType, relatedId); setLinks(r.data ?? []) } catch { /* noop */ }
  }
  useEffect(() => { load() }, [relatedType, relatedId])
  useEffect(() => { crm.listObjects().then(r => setObjects(r.data ?? [])).catch(() => {}) }, [])
  useEffect(() => { if (objId) crm.listRecords(objId).then(r => { setRecords(r.data ?? []); setRecId('') }).catch(() => {}); else setRecords([]) }, [objId])

  async function add() {
    if (!recId) return
    try { await crm.link({ from_type: relatedType, from_id: relatedId, to_type: 'custom_object_record', to_id: Number(recId) }); setRecId(''); await load() }
    catch (e) { toast.error('Could not link', e.message) }
  }
  async function remove(id) { try { await crm.unlink(id); setLinks(ls => ls.filter(l => l.id !== id)) } catch { /* noop */ } }

  if (objects.length === 0 && links.length === 0) return null // nothing to link to yet

  return (
    <div style={card}>
      <h3 style={title}>🔗 Linked records</h3>
      {links.length === 0 ? <div style={{ color: '#9ca3af', fontSize: 13.5, marginBottom: '.6rem' }}>No links yet.</div>
        : links.map(l => (
          <div key={l.id} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '.4rem 0' }}>
            <span style={{ flex: 1, fontSize: '.88rem' }}>{l.other_name} <span style={{ color: '#94a3b8', fontSize: '.74rem' }}>· {l.other_type.replace(/_/g, ' ')}</span></span>
            <button onClick={() => remove(l.id)} style={{ background: 'none', border: 'none', color: '#cbd5e1', cursor: 'pointer' }}>✕</button>
          </div>
        ))}
      {objects.length > 0 && (
        <div style={{ display: 'flex', gap: 6, marginTop: '.6rem', flexWrap: 'wrap' }}>
          <select value={objId} onChange={e => setObjId(e.target.value)} style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: '.45rem', fontSize: '.82rem' }}>
            <option value="">Object…</option>{objects.map(o => <option key={o.id} value={o.id}>{o.icon} {o.label_singular}</option>)}
          </select>
          <select value={recId} onChange={e => setRecId(e.target.value)} disabled={!objId} style={{ flex: 1, border: '1px solid #e5e7eb', borderRadius: 8, padding: '.45rem', fontSize: '.82rem' }}>
            <option value="">Record…</option>{records.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}
          </select>
          <button onClick={add} disabled={!recId} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 8, padding: '.45rem .9rem', fontWeight: 600, cursor: 'pointer' }}>Link</button>
        </div>
      )}
    </div>
  )
}

// ── Tasks (record-scoped) ───────────────────────────────────────────────────
export function TasksPanel({ relatedType, relatedId }) {
  const [tasks, setTasks] = useState([])
  const [draft, setDraft] = useState('')

  async function load() {
    try { const r = await crm.listTasks({ related_type: relatedType, related_id: relatedId }); setTasks(r.data ?? []) } catch { /* noop */ }
  }
  useEffect(() => { load() }, [relatedType, relatedId])

  async function add() {
    if (!draft.trim()) return
    try { await crm.createTask({ title: draft, related_type: relatedType, related_id: relatedId }); setDraft(''); await load() }
    catch (e) { toast.error('Could not add task', e.message) }
  }
  async function complete(id) { try { await crm.completeTask(id); await load() } catch { /* noop */ } }

  return (
    <div style={card}>
      <h3 style={title}>✓ Tasks</h3>
      <div style={{ display: 'flex', gap: 8, marginBottom: '.85rem' }}>
        <input value={draft} onChange={e => setDraft(e.target.value)} onKeyDown={e => { if (e.key === 'Enter') add() }}
          placeholder="Add a task for this record…" style={{ flex: 1, border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.88rem' }} />
        <button onClick={add} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 8, padding: '.5rem .9rem', fontWeight: 600, cursor: 'pointer' }}>Add</button>
      </div>
      {tasks.length === 0 ? <div style={{ color: '#9ca3af', fontSize: 13.5 }}>No tasks yet.</div>
        : tasks.map(t => {
          const done = t.status === 'done'
          return (
            <div key={t.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '.45rem 0' }}>
              <button onClick={() => !done && complete(t.id)} title={done ? 'Done' : 'Mark done'}
                style={{ width: 20, height: 20, borderRadius: '50%', border: '2px solid ' + (done ? '#16a34a' : '#cbd5e1'), background: done ? '#16a34a' : '#fff', color: '#fff', cursor: done ? 'default' : 'pointer', flexShrink: 0, fontSize: 11, lineHeight: 1 }}>{done ? '✓' : ''}</button>
              <span style={{ flex: 1, minWidth: 0, overflowWrap: 'anywhere', fontSize: '.88rem', color: done ? '#9ca3af' : '#111827', textDecoration: done ? 'line-through' : 'none' }}>{t.title}</span>
              {t.due_at && <span style={{ fontSize: '.72rem', color: '#94a3b8' }}>{fmt(t.due_at)}</span>}
            </div>
          )
        })}
    </div>
  )
}
