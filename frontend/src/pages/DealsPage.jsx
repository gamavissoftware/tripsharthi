import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { crm, rupees } from '../api/crm'
import { toast } from '../components/Toast'
import CrmListView from '../components/CrmListView'

const initials = (n) => (n || '#').trim().split(/\s+/).map(w => w[0]).join('').slice(0, 2).toUpperCase()

function stageTint(stage) {
  if (Number(stage.is_won)) return { head: '#15803d', bg: '#f0fdf4', bar: '#16a34a' }
  if (Number(stage.is_lost)) return { head: '#b91c1c', bg: '#fef2f2', bar: '#dc2626' }
  return { head: '#08569f', bg: '#f8fafc', bar: '#0a6cc4' }
}

const PRIORITY = {
  high: { bg: '#fee2e2', c: '#b91c1c' }, medium: { bg: '#fef3c7', c: '#92400e' }, low: { bg: '#f1f5f9', c: '#64748b' },
}
const TIER_COLOR = { hot: '#dc2626', warm: '#f59e0b', cold: '#3b82f6' }
const fmtDue = (s) => { try { return new Date(s.replace(' ', 'T')).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) } catch { return s } }

// Rich kanban lead card — id badge, priority, score, contact, company, phone,
// value, overdue follow-up, owner, and a one-click advance to the next stage.
function DealCard({ d, dragId, nextStageId, onDragStart, onDragEnd, onOpen, onNext }) {
  const pr   = PRIORITY[(d.priority || 'medium').toLowerCase()] || PRIORITY.medium
  const tier = (d.score_tier || '').toLowerCase()
  return (
    <div draggable onDragStart={onDragStart} onDragEnd={onDragEnd} onClick={onOpen}
      style={{ background: '#fff', border: '1px solid ' + (d.is_rotting ? '#fca5a5' : '#e5e7eb'), borderLeft: d.is_rotting ? '3px solid #dc2626' : '1px solid #e5e7eb', borderRadius: 10, padding: '.6rem .65rem', cursor: 'grab', boxShadow: dragId === d.id ? '0 8px 20px rgba(0,0,0,.12)' : '0 1px 2px rgba(0,0,0,.04)', opacity: dragId === d.id ? .6 : 1 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 5 }}>
        <span style={{ fontSize: '.62rem', fontWeight: 800, letterSpacing: '.03em', color: '#64748b', background: '#f1f5f9', borderRadius: 5, padding: '.1rem .35rem' }}>#{String(d.id).padStart(5, '0')}</span>
        <span style={{ fontSize: '.6rem', fontWeight: 800, color: pr.c, background: pr.bg, borderRadius: 5, padding: '.1rem .4rem', textTransform: 'uppercase' }}>{(d.priority || 'medium')}</span>
      </div>
      {d.score_tier && (
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: '.62rem', fontWeight: 700, color: TIER_COLOR[tier] || '#64748b', background: '#fff7ed', border: '1px solid #fde68a', borderRadius: 999, padding: '.05rem .45rem', marginBottom: 5 }}>
          ● {d.score_tier}{d.lead_score != null ? ` · ${d.lead_score}` : ''}
        </span>
      )}
      <div style={{ fontWeight: 700, fontSize: '.86rem', color: '#111827', display: 'flex', alignItems: 'center', gap: 5 }}>
        {d.is_rotting && <span title="Rotting — idle too long">🥀</span>}{d.contact_name || d.title}
      </div>
      {d.company && <div style={{ fontSize: '.73rem', color: '#6b7280', marginTop: 2 }}>🏢 {d.company}</div>}
      {d.contact_phone && <div style={{ fontSize: '.73rem', color: '#6b7280', fontFamily: 'monospace' }}>📞 {d.contact_phone}</div>}
      {d.overdue_at && <div style={{ fontSize: '.7rem', color: '#dc2626', fontWeight: 700, marginTop: 3 }}>⏰ Overdue: {fmtDue(d.overdue_at)}</div>}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginTop: 6 }}>
        <span style={{ fontSize: '.8rem', fontWeight: 700, color: '#15803d' }}>{rupees(d.value_amount)}</span>
        {d.owner_name && <span title={d.owner_name} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: '.66rem', color: '#475569' }}>
          <span style={{ width: 20, height: 20, borderRadius: '50%', background: '#e8f3fc', color: '#08569f', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '.58rem', fontWeight: 700 }}>{initials(d.owner_name)}</span>
        </span>}
      </div>
      {nextStageId && (
        <button onClick={e => { e.stopPropagation(); onNext() }}
          style={{ marginTop: 7, width: '100%', border: '1px solid #bcdcf6', background: '#e8f3fc', color: '#08569f', borderRadius: 7, padding: '.32rem', fontSize: '.72rem', fontWeight: 700, cursor: 'pointer' }}>
          Next »
        </button>
      )}
    </div>
  )
}

function CreateModal({ onClose, onCreated }) {
  const [form, setForm] = useState({ title: '', value: '', expected_close_date: '' })
  const [saving, setSaving] = useState(false)
  async function save(e) {
    e.preventDefault()
    if (!form.title.trim()) { toast.error('Deal title is required'); return }
    setSaving(true)
    try {
      await crm.createDeal({
        title: form.title,
        value_amount: form.value ? Math.round(parseFloat(form.value) * 100) : 0,
        expected_close_date: form.expected_close_date || null,
      })
      toast.success('Deal created', form.title); onCreated()
    } catch (e) { toast.error('Could not create deal', e.message) }
    finally { setSaving(false) }
  }
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, backdropFilter: 'blur(3px)' }}>
      <form onClick={e => e.stopPropagation()} onSubmit={save} style={{ background: '#fff', borderRadius: 16, width: '100%', maxWidth: 440, padding: '1.5rem', boxShadow: '0 24px 64px rgba(0,0,0,.22)' }}>
        <h3 style={{ margin: '0 0 1rem', fontWeight: 800, fontSize: '1.05rem' }}>💼 New Deal</h3>
        <label style={lbl}>Deal title *</label>
        <input value={form.title} onChange={e => setForm(f => ({ ...f, title: e.target.value }))} placeholder="e.g. Acme annual contract" autoFocus style={inp} />
        <label style={lbl}>Value (₹)</label>
        <input type="number" value={form.value} onChange={e => setForm(f => ({ ...f, value: e.target.value }))} placeholder="150000" style={inp} />
        <label style={lbl}>Expected close</label>
        <input type="date" value={form.expected_close_date} onChange={e => setForm(f => ({ ...f, expected_close_date: e.target.value }))} style={inp} />
        <div style={{ display: 'flex', gap: 10, marginTop: '1.25rem' }}>
          <button type="button" onClick={onClose} style={{ flex: 1, background: '#fff', border: '1.5px solid #e5e7eb', borderRadius: 10, padding: '.6rem', fontWeight: 600, cursor: 'pointer' }}>Cancel</button>
          <button type="submit" disabled={saving} style={{ flex: 2, background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 10, padding: '.6rem', fontWeight: 700, cursor: 'pointer' }}>{saving ? 'Creating…' : 'Create deal'}</button>
        </div>
      </form>
    </div>
  )
}
const lbl = { display: 'block', fontSize: '.8rem', fontWeight: 600, color: '#374151', margin: '.7rem 0 .3rem' }
const inp = { width: '100%', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.9rem', boxSizing: 'border-box' }

export default function DealsPage() {
  const navigate = useNavigate()
  const [board, setBoard] = useState(null)
  const [loading, setLoading] = useState(true)
  const [showCreate, setShowCreate] = useState(false)
  const [dragId, setDragId] = useState(null)
  const [overStage, setOverStage] = useState(null)
  const [view, setView] = useState('board')   // board | list
  const [listKey, setListKey] = useState(0)

  useEffect(() => { load() }, [])
  async function load() {
    setLoading(true)
    try { const r = await crm.dealBoard(); setBoard(r.data) }
    catch (e) { toast.error('Failed to load board', e.message) }
    finally { setLoading(false) }
  }

  async function drop(stageId, explicitId = null) {
    const id = explicitId ?? dragId
    setDragId(null); setOverStage(null)
    if (!id || !stageId) return
    // optimistic: move card locally
    setBoard(b => {
      const cols = b.columns.map(c => ({ ...c, deals: c.deals.filter(d => d.id !== id) }))
      let moved = null
      b.columns.forEach(c => { const f = c.deals.find(d => d.id === id); if (f) moved = f })
      if (moved) { const t = cols.find(c => c.stage.id === stageId); if (t) t.deals = [{ ...moved, stage_id: stageId }, ...t.deals] }
      return { ...b, columns: cols.map(c => ({ ...c, count: c.deals.length, total_value: c.deals.reduce((s, d) => s + Number(d.value_amount || 0), 0) })) }
    })
    try { await crm.moveDeal(id, stageId); load() }
    catch (e) { toast.error('Move failed', e.message); load() }
  }

  async function editRotting(stage) {
    const current = stage.rotting_days ?? ''
    const input = window.prompt(`Rot "${stage.name}" deals after how many idle days? (blank to disable)`, current)
    if (input === null) return
    try {
      await crm.updateStage(stage.id, { rotting_days: input.trim() === '' ? '' : parseInt(input, 10) })
      toast.success('Stage updated')
      load()
    } catch (e) { toast.error('Could not update stage', e?.message) }
  }

  const grandTotal = board ? board.columns.reduce((s, c) => s + c.total_value, 0) : 0

  return (
    <div className="page" style={{ maxWidth: '100%' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '1.25rem', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>💼 Deals</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>
            {board ? `${board.pipeline.name} · open pipeline value ${rupees(grandTotal)}` : 'Your sales pipeline'}
          </p>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          <div style={{ display: 'inline-flex', background: '#f1f5f9', borderRadius: 10, padding: 3 }}>
            {['board', 'list'].map(v => (
              <button key={v} onClick={() => setView(v)}
                style={{ border: 'none', borderRadius: 8, padding: '.4rem .9rem', fontSize: 13, fontWeight: 700, cursor: 'pointer',
                  background: view === v ? '#fff' : 'transparent', color: view === v ? '#111827' : '#64748b',
                  boxShadow: view === v ? '0 1px 3px rgba(0,0,0,.1)' : 'none' }}>
                {v === 'board' ? '▦ Board' : '☰ List'}
              </button>
            ))}
          </div>
          <button onClick={() => navigate('/deals/new')} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 10, padding: '.55rem 1rem', fontWeight: 700, cursor: 'pointer' }}>+ New Deal</button>
        </div>
      </div>

      {view === 'list' ? (
        <CrmListView key={listKey} entity="deal" onRowClick={d => navigate(`/deals/${d.id}`)} />
      ) : loading ? (
        <div style={{ height: 300, borderRadius: 12, background: '#f3f4f6', animation: 'lp-pulse 1.2s infinite' }} />
      ) : (
        <div style={{ display: 'flex', gap: 14, overflowX: 'auto', paddingBottom: 12, alignItems: 'flex-start' }}>
          {board.columns.map((col, ci) => {
            const t = stageTint(col.stage)
            const isOver = overStage === col.stage.id
            const nextStageId = board.columns[ci + 1]?.stage?.id ?? null
            return (
              <div key={col.stage.id}
                onDragOver={e => { e.preventDefault(); if (overStage !== col.stage.id) setOverStage(col.stage.id) }}
                onDragLeave={() => setOverStage(s => s === col.stage.id ? null : s)}
                onDrop={() => drop(col.stage.id)}
                style={{ flex: '0 0 270px', background: isOver ? '#e8f3fc' : t.bg, border: `1px solid ${isOver ? '#bcdcf6' : '#e5e7eb'}`, borderRadius: 14, padding: 10, minHeight: 120, transition: 'background .1s' }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '.25rem .35rem .6rem' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 7 }}>
                    <span style={{ width: 8, height: 8, borderRadius: '50%', background: t.bar }} />
                    <span style={{ fontWeight: 800, fontSize: '.82rem', color: t.head }}>{col.stage.name}</span>
                    <span style={{ fontSize: '.72rem', color: '#94a3b8', fontWeight: 600 }}>{col.count}</span>
                  </div>
                  <span
                    title={col.stage.rotting_days ? `Deals rot after ${col.stage.rotting_days} days idle — click to change` : 'Set rotting threshold (days)'}
                    onClick={() => editRotting(col.stage)}
                    style={{ fontSize: '.68rem', color: col.stage.rotting_days ? '#b45309' : '#cbd5e1', fontWeight: 700, cursor: 'pointer' }}>
                    {col.stage.rotting_days ? `⏳ ${col.stage.rotting_days}d` : '⏳'}
                  </span>
                  <span style={{ fontSize: '.72rem', color: '#64748b', fontWeight: 700 }}>{rupees(col.total_value)}</span>
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                  {col.deals.map(d => <DealCard key={d.id} d={d} t={t} dragId={dragId} nextStageId={nextStageId}
                    onDragStart={() => setDragId(d.id)} onDragEnd={() => { setDragId(null); setOverStage(null) }}
                    onOpen={() => navigate(`/deals/${d.id}`)} onNext={() => drop(nextStageId, d.id)} />)}
                  {col.deals.length === 0 && <div style={{ textAlign: 'center', color: '#cbd5e1', fontSize: '.78rem', padding: '1rem 0' }}>Drop here</div>}
                </div>
              </div>
            )
          })}
        </div>
      )}

      {showCreate && <CreateModal onClose={() => setShowCreate(false)} onCreated={() => { setShowCreate(false); load(); setListKey(k => k + 1) }} />}
      <style>{`@keyframes lp-pulse{0%,100%{opacity:1}50%{opacity:.45}}`}</style>
    </div>
  )
}
