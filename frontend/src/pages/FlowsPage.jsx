import { useState, useEffect } from 'react'
import { TRAVEL_TRIGGERS } from '../flow-builder/travelTriggers'
import { useNavigate } from 'react-router-dom'
import {
  UserPlus, Tag, ClipboardList, Megaphone, Globe, MessageCircle, Inbox,
  Briefcase, Shuffle, Trophy, HeartCrack, Ticket,
  ShoppingCart, Package, ShoppingBag, FileText, Cake, Zap,
  CheckCircle2, Pause, RefreshCw, Search, SearchX, Bot,
  Pencil, Trash2, Plus, X, Lock, Repeat,
} from 'lucide-react'
import { flows as flowsApi } from '../api/flows'
import { toast } from '../components/Toast'

// ── Inject styles once ────────────────────────────────────────────────────
if (typeof document !== 'undefined' && !document.getElementById('lp-flows-css')) {
  const el = document.createElement('style')
  el.id = 'lp-flows-css'
  el.textContent = `
    @keyframes lp-fl-spin {
      to { transform: rotate(360deg); }
    }
    @keyframes lp-fl-in {
      from { opacity:0; transform:translateY(10px); }
      to   { opacity:1; transform:translateY(0); }
    }
    @keyframes lp-fl-pulse {
      0%,100% { opacity:1; }
      50%      { opacity:.5; }
    }
    .lp-fl-card {
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:14px;
      padding:0;
      overflow:hidden;
      transition: box-shadow .18s ease, transform .18s ease;
      animation: lp-fl-in .22s ease both;
    }
    .lp-fl-card:hover {
      box-shadow: 0 8px 28px rgba(0,0,0,.11);
      transform: translateY(-2px);
    }
    .lp-fl-stat-card {
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:14px;
      padding:1.2rem 1.4rem;
      display:flex;
      align-items:center;
      gap:1rem;
      flex:1 1 160px;
      min-width:0;
      transition: box-shadow .18s ease, transform .15s ease;
    }
    .lp-fl-stat-card:hover {
      box-shadow:0 4px 20px rgba(0,0,0,.09);
      transform:translateY(-1px);
    }
    .lp-fl-badge {
      display:inline-flex;
      align-items:center;
      gap:.35rem;
      padding:.2rem .65rem;
      border-radius:999px;
      font-size:11px;
      font-weight:700;
      letter-spacing:.03em;
      text-transform:uppercase;
    }
    .lp-fl-trigger-chip {
      display:inline-flex;
      align-items:center;
      gap:.3rem;
      padding:.22rem .65rem;
      border-radius:999px;
      font-size:11.5px;
      font-weight:600;
      background:#f3f4f6;
      color:#374151;
    }
    .lp-fl-stat-pill {
      display:inline-flex;
      flex-direction:column;
      align-items:center;
      padding:.3rem .7rem;
      background:#f9fafb;
      border:1px solid #f3f4f6;
      border-radius:8px;
      min-width:52px;
    }
    .lp-fl-icon-btn {
      display:inline-flex;
      align-items:center;
      justify-content:center;
      width:34px;
      height:34px;
      border-radius:8px;
      border:1px solid #e5e7eb;
      background:#fff;
      cursor:pointer;
      transition: background .13s, border-color .13s, transform .12s;
      font-size:15px;
      color:#374151;
    }
    .lp-fl-icon-btn:hover {
      background:#f9fafb;
      border-color:#d1d5db;
      transform:scale(1.07);
    }
    .lp-fl-icon-btn.danger:hover {
      background:#fff1f2;
      border-color:#fca5a5;
      color:#dc2626;
    }
    .lp-fl-icon-btn.success:hover {
      background:#f0fdf4;
      border-color:#86efac;
      color:#16a34a;
    }
    .lp-fl-toggle {
      position:relative;
      display:inline-flex;
      align-items:center;
      width:38px;
      height:21px;
      cursor:pointer;
    }
    .lp-fl-toggle input { display:none; }
    .lp-fl-toggle-track {
      width:38px; height:21px;
      background:#d1d5db;
      border-radius:999px;
      transition:background .2s;
      position:relative;
    }
    .lp-fl-toggle-track::after {
      content:'';
      position:absolute;
      left:3px; top:3px;
      width:15px; height:15px;
      background:#fff;
      border-radius:50%;
      box-shadow:0 1px 3px rgba(0,0,0,.25);
      transition:transform .2s;
    }
    .lp-fl-toggle.on .lp-fl-toggle-track { background:#0a6cc4; }
    .lp-fl-toggle.on .lp-fl-toggle-track::after { transform:translateX(17px); }
    .lp-fl-empty-bg {
      background: linear-gradient(135deg, #f5f3ff 0%, #eff6ff 100%);
      border-radius:16px;
      border:2px dashed #c4b5fd;
    }
    .lp-fl-modal {
      background:#fff;
      border-radius:20px;
      width:480px;
      max-width:94vw;
      overflow:hidden;
      box-shadow:0 25px 60px rgba(0,0,0,.18);
      animation:lp-fl-in .22s ease;
    }
    .lp-fl-select {
      appearance:none;
      background:#f9fafb url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E") no-repeat right 12px center;
      border:1.5px solid #e5e7eb;
      border-radius:10px;
      padding:.6rem 2.2rem .6rem .9rem;
      font-size:14px;
      color:#111827;
      width:100%;
      cursor:pointer;
      transition:border-color .15s;
    }
    .lp-fl-select:focus {
      outline:none;
      border-color:#0a6cc4;
      box-shadow:0 0 0 3px rgba(10,108,196,.15);
    }
    .lp-fl-input {
      background:#f9fafb;
      border:1.5px solid #e5e7eb;
      border-radius:10px;
      padding:.65rem .9rem;
      font-size:14px;
      color:#111827;
      width:100%;
      box-sizing:border-box;
      transition:border-color .15s, box-shadow .15s;
    }
    .lp-fl-input:focus {
      outline:none;
      background:#fff;
      border-color:#0a6cc4;
      box-shadow:0 0 0 3px rgba(10,108,196,.15);
    }
    .lp-fl-input::placeholder { color:#9ca3af; }
    .lp-fl-reentry-card {
      border:1.5px solid #e5e7eb;
      border-radius:10px;
      padding:.7rem 1rem;
      cursor:pointer;
      transition:border-color .15s, background .15s;
      display:flex;
      align-items:flex-start;
      gap:.65rem;
    }
    .lp-fl-reentry-card.selected {
      border-color:#0a6cc4;
      background:#f5f3ff;
    }
    .lp-fl-reentry-radio {
      width:16px; height:16px;
      border:2px solid #d1d5db;
      border-radius:50%;
      margin-top:2px;
      flex-shrink:0;
      transition:border-color .15s;
      display:flex;
      align-items:center;
      justify-content:center;
    }
    .lp-fl-reentry-card.selected .lp-fl-reentry-radio {
      border-color:#0a6cc4;
    }
    .lp-fl-reentry-card.selected .lp-fl-reentry-radio::after {
      content:'';
      width:8px; height:8px;
      border-radius:50%;
      background:#0a6cc4;
      display:block;
    }
  `
  document.head.appendChild(el)
}

// ── Config ────────────────────────────────────────────────────────────────
const TRIGGER_CFG = {
  ...Object.fromEntries(TRAVEL_TRIGGERS.map(t => [t.key, { icon: t.icon, label: t.label, color: t.color, bg: t.bg }])),
  lead_created:       { icon: UserPlus, label: 'Lead Created',    color: '#0a6cc4', bg: '#ede9fe' },
  tag_added:          { icon: Tag, label: 'Tag Added',       color: '#0891b2', bg: '#e0f2fe' },
  form_submitted:     { icon: ClipboardList, label: 'Form Submitted',  color: '#d97706', bg: '#fef3c7' },
  meta_lead_received: { icon: Megaphone, label: 'Meta Lead',       color: '#1d4ed8', bg: '#dbeafe' },
  google_lead_received:{ icon: Globe, label: 'Google Lead',    color: '#15803d', bg: '#dcfce7' },
  deal_created:       { icon: Briefcase, label: 'Deal Created',    color: '#08569f', bg: '#e8f3fc' },
  deal_stage_changed: { icon: Shuffle, label: 'Deal Stage Moved', color: '#08569f', bg: '#e8f3fc' },
  deal_won:           { icon: Trophy, label: 'Deal Won',        color: '#15803d', bg: '#dcfce7' },
  deal_lost:          { icon: HeartCrack, label: 'Deal Lost',       color: '#b91c1c', bg: '#fee2e2' },
  ticket_created:     { icon: Ticket, label: 'Ticket Created',  color: '#0891b2', bg: '#cffafe' },
  ticket_resolved:    { icon: CheckCircle2, label: 'Ticket Resolved', color: '#15803d', bg: '#dcfce7' },
  keyword_reply:      { icon: MessageCircle, label: 'Keyword Reply',   color: '#0e8f8c', bg: '#ede9fe' },
  inbound_message:    { icon: Inbox, label: 'Any Inbound',     color: '#059669', bg: '#d1fae5' },
  order_placed:       { icon: ShoppingCart, label: 'Order Placed',    color: '#c2410c', bg: '#ffedd5' },
  order_fulfilled:    { icon: Package, label: 'Order Fulfilled', color: '#15803d', bg: '#dcfce7' },
  abandoned_cart:     { icon: ShoppingBag, label: 'Abandoned Cart',  color: '#be123c', bg: '#ffe4e6' },
  flow_response:      { icon: FileText, label: 'Form Response',   color: '#0f766e', bg: '#ccfbf1' },
  date_reached:       { icon: Cake, label: 'Date Reached',    color: '#9d174d', bg: '#fce7f3' },
}

const STATUS_CFG = {
  draft:  { label: 'Draft',  bg: '#f3f4f6', color: '#374151', dot: '#9ca3af' },
  active: { label: 'Active', bg: '#dcfce7', color: '#15803d', dot: '#22c55e' },
  paused: { label: 'Paused', bg: '#fef9c3', color: '#a16207', dot: '#eab308' },
}

const ACCENT_CFG = {
  draft:  '#d1d5db',
  active: '#22c55e',
  paused: '#f59e0b',
}

function fmtDate(d) {
  if (!d) return '—'
  const dt = new Date(d)
  return dt.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
}

// ── Stat Card ─────────────────────────────────────────────────────────────
function StatCard({ icon, label, value, accent, sub }) {
  return (
    <div className="lp-fl-stat-card" style={{ borderTop: `3px solid ${accent}` }}>
      <div style={{
        width: 44, height: 44, borderRadius: 12, flexShrink: 0,
        background: accent + '18',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        fontSize: 20,
      }}>
        {icon}
      </div>
      <div style={{ minWidth: 0 }}>
        <div style={{ fontSize: 11, fontWeight: 700, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: 3 }}>
          {label}
        </div>
        <div style={{ fontSize: 26, fontWeight: 800, color: '#111827', lineHeight: 1 }}>
          {value}
        </div>
        {sub && <div style={{ fontSize: 11, color: '#9ca3af', marginTop: 3 }}>{sub}</div>}
      </div>
    </div>
  )
}

// ── Flow Card ─────────────────────────────────────────────────────────────
function FlowCard({ flow, onEdit, onDelete, onToggle }) {
  const sc = STATUS_CFG[flow.status] ?? STATUS_CFG.draft
  const tc = TRIGGER_CFG[flow.trigger_type] ?? { icon: Zap, label: flow.trigger_type, color: '#6b7280', bg: '#f3f4f6' }
  const TriggerIcon = tc.icon
  const accent = ACCENT_CFG[flow.status] ?? '#d1d5db'
  const isActive = flow.status === 'active'
  const runs = parseInt(flow.total_runs, 10) || 0
  const completed = parseInt(flow.completed_runs, 10) || 0
  const active = parseInt(flow.active_runs, 10) || 0

  return (
    <div className="lp-fl-card">
      {/* Left accent stripe */}
      <div style={{ display: 'flex' }}>
        <div style={{ width: 4, background: accent, flexShrink: 0 }} />

        <div style={{ flex: 1, padding: '1.1rem 1.25rem', display: 'flex', alignItems: 'flex-start', gap: '1rem', minWidth: 0 }}>
          {/* Trigger icon bubble */}
          <div style={{
            width: 46, height: 46, borderRadius: 12, flexShrink: 0,
            background: tc.bg,
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            fontSize: 20, marginTop: 2, color: tc.color,
          }}>
            <TriggerIcon size={20} strokeWidth={1.8} />
          </div>

          {/* Main content */}
          <div style={{ flex: 1, minWidth: 0 }}>
            {/* Top row */}
            <div style={{ display: 'flex', alignItems: 'center', gap: '.6rem', flexWrap: 'wrap', marginBottom: 6 }}>
              <span style={{ fontWeight: 700, fontSize: 15, color: '#111827', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 260 }}>
                {flow.name}
              </span>
              {/* Status badge */}
              <span className="lp-fl-badge" style={{ background: sc.bg, color: sc.color }}>
                <span style={{ width: 6, height: 6, borderRadius: '50%', background: sc.dot, flexShrink: 0 }} />
                {sc.label}
              </span>
              <span style={{ fontSize: 11, color: '#9ca3af', marginLeft: 'auto', flexShrink: 0 }}>
                v{flow.version ?? 1}
              </span>
            </div>

            {/* Trigger chip + date */}
            <div style={{ display: 'flex', alignItems: 'center', gap: '.5rem', flexWrap: 'wrap', marginBottom: 10 }}>
              <span className="lp-fl-trigger-chip" style={{ background: tc.bg, color: tc.color }}>
                <TriggerIcon size={12} strokeWidth={2} /> {tc.label}
              </span>
              <span style={{ fontSize: 11.5, color: '#9ca3af' }}>
                Created {fmtDate(flow.created_at)}
              </span>
            </div>

            {/* Stats row */}
            <div style={{ display: 'flex', alignItems: 'center', gap: '.5rem', flexWrap: 'wrap' }}>
              <div className="lp-fl-stat-pill">
                <span style={{ fontSize: 15, fontWeight: 800, color: '#0a6cc4' }}>{runs}</span>
                <span style={{ fontSize: 10, fontWeight: 600, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: '.04em' }}>Runs</span>
              </div>
              <div className="lp-fl-stat-pill">
                <span style={{ fontSize: 15, fontWeight: 800, color: '#22c55e' }}>{completed}</span>
                <span style={{ fontSize: 10, fontWeight: 600, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: '.04em' }}>Done</span>
              </div>
              <div className="lp-fl-stat-pill">
                <span style={{ fontSize: 15, fontWeight: 800, color: '#f59e0b' }}>{active}</span>
                <span style={{ fontSize: 10, fontWeight: 600, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: '.04em' }}>Active</span>
              </div>

              {/* Spacer */}
              <div style={{ flex: 1 }} />

              {/* Toggle */}
              <label
                className={`lp-fl-toggle ${isActive ? 'on' : ''}`}
                title={isActive ? 'Pause flow' : 'Activate flow'}
                onClick={e => { e.preventDefault(); onToggle(flow) }}
              >
                <div className="lp-fl-toggle-track" />
              </label>

              {/* Edit */}
              <button className="lp-fl-icon-btn" title="Edit flow" onClick={() => onEdit(flow.id)}>
                <Pencil size={15} strokeWidth={2} />
              </button>

              {/* Delete */}
              <button className="lp-fl-icon-btn danger" title="Delete flow" onClick={() => onDelete(flow.id, flow.name)}>
                <Trash2 size={15} strokeWidth={2} />
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

// ── Create Modal ──────────────────────────────────────────────────────────
function CreateModal({ onClose, onCreate }) {
  const [form, setForm] = useState({ name: '', trigger_type: 'lead_created', reentry_policy: 'once' })
  const [creating, setCreating] = useState(false)
  const [error, setError] = useState(null)

  const tc = TRIGGER_CFG[form.trigger_type] ?? TRIGGER_CFG.lead_created
  const TcIcon = tc.icon

  async function handleSubmit(e) {
    e.preventDefault()
    if (!form.name.trim()) return
    setCreating(true); setError(null)
    try {
      await onCreate(form)
    } catch (err) {
      setError(err.message ?? 'Create failed.')
      setCreating(false)
    }
  }

  return (
    <div
      style={{
        position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)', backdropFilter: 'blur(4px)',
        display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000, padding: '1rem',
      }}
      onClick={e => { if (e.target === e.currentTarget) onClose() }}
    >
      <div className="lp-fl-modal">
        {/* Header */}
        <div style={{
          background: 'linear-gradient(135deg, #0a6cc4 0%, #12a89e 100%)',
          padding: '1.4rem 1.6rem',
          display: 'flex', alignItems: 'center', justifyContent: 'space-between',
        }}>
          <div>
            <div style={{ fontSize: 18, fontWeight: 800, color: '#fff', letterSpacing: '-.01em' }}>
              New Automation Flow
            </div>
            <div style={{ fontSize: 13, color: 'rgba(255,255,255,.75)', marginTop: 3 }}>
              Define a trigger and let TripSarthi handle the rest
            </div>
          </div>
          <button
            onClick={onClose}
            style={{ background: 'rgba(255,255,255,.18)', border: 'none', color: '#fff', width: 30, height: 30, borderRadius: 8, cursor: 'pointer', fontSize: 16, display: 'flex', alignItems: 'center', justifyContent: 'center' }}
          >
            <X size={16} />
          </button>
        </div>

        <form onSubmit={handleSubmit}>
          <div style={{ padding: '1.4rem 1.6rem', display: 'flex', flexDirection: 'column', gap: '1.1rem' }}>
            {error && (
              <div style={{ background: '#fff1f2', border: '1px solid #fca5a5', borderRadius: 8, padding: '.65rem .9rem', fontSize: 13, color: '#dc2626' }}>
                {error}
              </div>
            )}

            {/* Flow name */}
            <div>
              <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#374151', marginBottom: 6 }}>
                Flow Name
              </label>
              <input
                className="lp-fl-input"
                value={form.name}
                onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
                placeholder="e.g. Welcome New Leads"
                autoFocus
                required
              />
            </div>

            {/* Trigger */}
            <div>
              <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#374151', marginBottom: 6 }}>
                Trigger Event
              </label>
              <select
                className="lp-fl-select"
                value={form.trigger_type}
                onChange={e => setForm(f => ({ ...f, trigger_type: e.target.value }))}
              >
                {Object.entries(TRIGGER_CFG).map(([key, cfg]) => (
                  <option key={key} value={key}>{cfg.label}</option>
                ))}
              </select>
              {/* Trigger preview */}
              <div style={{
                marginTop: 8, padding: '.6rem .8rem',
                background: tc.bg, borderRadius: 8,
                display: 'flex', alignItems: 'center', gap: '.5rem',
                fontSize: 12.5, color: tc.color, fontWeight: 600,
              }}>
                <TcIcon size={18} strokeWidth={1.8} style={{ flexShrink: 0 }} />
                This flow starts when a <strong>{tc.label}</strong> event fires
              </div>
            </div>

            {/* Re-entry policy */}
            <div>
              <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#374151', marginBottom: 8 }}>
                Re-entry Policy
              </label>
              <div style={{ display: 'flex', flexDirection: 'column', gap: '.5rem' }}>
                {[
                  { value: 'once',   icon: Lock,   title: 'Once per contact', desc: 'Skip if this contact is already enrolled or has completed the flow' },
                  { value: 'always', icon: Repeat, title: 'Allow re-entry',   desc: 'Re-enroll the contact each time the trigger fires' },
                ].map(opt => (
                  <div
                    key={opt.value}
                    className={`lp-fl-reentry-card ${form.reentry_policy === opt.value ? 'selected' : ''}`}
                    onClick={() => setForm(f => ({ ...f, reentry_policy: opt.value }))}
                  >
                    <div className="lp-fl-reentry-radio" />
                    <div>
                      <div style={{ fontSize: 13, fontWeight: 700, color: '#111827', display: 'flex', alignItems: 'center', gap: 6 }}><opt.icon size={13} strokeWidth={2} /> {opt.title}</div>
                      <div style={{ fontSize: 12, color: '#6b7280', marginTop: 2 }}>{opt.desc}</div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* Footer */}
          <div style={{
            padding: '1rem 1.6rem',
            borderTop: '1px solid #f3f4f6',
            display: 'flex', gap: '.75rem', justifyContent: 'flex-end',
            background: '#fafafa',
          }}>
            <button
              type="button"
              onClick={onClose}
              style={{
                padding: '.6rem 1.2rem', borderRadius: 10, border: '1.5px solid #e5e7eb',
                background: '#fff', color: '#374151', fontWeight: 600, fontSize: 14, cursor: 'pointer',
              }}
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={creating || !form.name.trim()}
              style={{
                padding: '.6rem 1.4rem', borderRadius: 10, border: 'none',
                background: creating ? '#8ec5f0' : 'linear-gradient(135deg, #0a6cc4, #12a89e)',
                color: '#fff', fontWeight: 700, fontSize: 14, cursor: creating ? 'not-allowed' : 'pointer',
                display: 'flex', alignItems: 'center', gap: '.45rem',
                boxShadow: '0 2px 8px rgba(10,108,196,.35)',
              }}
            >
              {creating ? (
                <>
                  <span style={{ width: 14, height: 14, border: '2px solid rgba(255,255,255,.4)', borderTopColor: '#fff', borderRadius: '50%', animation: 'lp-fl-spin .7s linear infinite' }} />
                  Creating…
                </>
              ) : (
                <>
                  <Zap size={15} strokeWidth={2} />
                  Create & Open Builder
                </>
              )}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}

// ── Main Page ─────────────────────────────────────────────────────────────
export default function FlowsPage() {
  const navigate = useNavigate()
  const [list, setList]             = useState([])
  const [loading, setLoading]       = useState(true)
  const [showCreate, setShowCreate] = useState(false)
  const [filter, setFilter]         = useState('all')   // all | active | paused | draft
  const [search, setSearch]         = useState('')

  useEffect(() => { load() }, [])

  async function load() {
    setLoading(true)
    try {
      const r = await flowsApi.list()
      setList(r.data ?? [])
    } catch {
      setList([])
    }
    setLoading(false)
  }

  async function handleToggle(flow) {
    const next = flow.status === 'active' ? 'paused' : 'active'
    try {
      await flowsApi.setStatus(flow.id, next)
      setList(prev => prev.map(f => f.id === flow.id ? { ...f, status: next } : f))
      toast.success(`Flow ${next === 'active' ? 'activated' : 'paused'}`, flow.name)
    } catch (e) {
      toast.error('Status update failed', e.message)
    }
  }

  async function handleDelete(id, name) {
    if (!window.confirm(`Delete flow "${name}"? This cannot be undone.`)) return
    try {
      await flowsApi.delete(id)
      setList(prev => prev.filter(f => f.id !== id))
      toast.success('Flow deleted', name)
    } catch (e) {
      toast.error('Delete failed', e.message)
    }
  }

  async function handleCreate(form) {
    const r = await flowsApi.create({
      name:           form.name.trim(),
      trigger_type:   form.trigger_type,
      reentry_policy: form.reentry_policy,
      graph:          { nodes: [], edges: [] },
    })
    setShowCreate(false)
    navigate(`/flows/${r.data.id}`)
  }

  // Computed stats
  const total   = list.length
  const active  = list.filter(f => f.status === 'active').length
  const paused  = list.filter(f => f.status === 'paused').length
  const draft   = list.filter(f => f.status === 'draft').length
  const totalRuns = list.reduce((s, f) => s + (parseInt(f.total_runs, 10) || 0), 0)

  // Filtered list
  const filtered = list.filter(f => {
    if (filter !== 'all' && f.status !== filter) return false
    if (search && !f.name.toLowerCase().includes(search.toLowerCase())) return false
    return true
  })

  // Skeleton rows
  if (loading) {
    return (
      <div className="page">
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '1.5rem' }}>
          <div>
            <div style={{ height: 28, width: 200, borderRadius: 8, background: '#e5e7eb' }} />
            <div style={{ height: 14, width: 140, borderRadius: 6, background: '#f3f4f6', marginTop: 8 }} />
          </div>
          <div style={{ height: 38, width: 120, borderRadius: 10, background: '#e5e7eb' }} />
        </div>
        <div style={{ display: 'flex', gap: '1rem', marginBottom: '1.5rem', flexWrap: 'wrap' }}>
          {[1,2,3,4].map(i => (
            <div key={i} style={{ height: 86, flex: '1 1 160px', borderRadius: 14, background: '#f3f4f6' }} />
          ))}
        </div>
        {[1,2,3].map(i => (
          <div key={i} style={{ height: 108, borderRadius: 14, background: '#f3f4f6', marginBottom: '.75rem', opacity: 1.2 - i * 0.2 }} />
        ))}
      </div>
    )
  }

  return (
    <div className="page">
      {/* ── Page Header ── */}
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: '1.5rem', gap: '1rem', flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>
            Automation Flows
          </h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>
            Build, activate, and monitor your WhatsApp automations
          </p>
        </div>
        <button
          onClick={() => setShowCreate(true)}
          style={{
            display: 'flex', alignItems: 'center', gap: '.45rem',
            padding: '.6rem 1.2rem', borderRadius: 10, border: 'none',
            background: 'linear-gradient(135deg, #0a6cc4, #12a89e)',
            color: '#fff', fontWeight: 700, fontSize: 14, cursor: 'pointer',
            boxShadow: '0 2px 10px rgba(10,108,196,.35)',
            whiteSpace: 'nowrap',
          }}
        >
          <Plus size={16} strokeWidth={2.5} /> New Flow
        </button>
      </div>

      {/* ── Stat Cards ── */}
      <div style={{ display: 'flex', gap: '1rem', marginBottom: '1.5rem', flexWrap: 'wrap' }}>
        <StatCard icon={<Zap size={20} strokeWidth={1.8} color="#0a6cc4" />}          label="Total Flows" value={total}     accent="#0a6cc4" />
        <StatCard icon={<CheckCircle2 size={20} strokeWidth={1.8} color="#22c55e" />} label="Active"      value={active}    accent="#22c55e" sub={`${draft} draft`} />
        <StatCard icon={<Pause size={20} strokeWidth={1.8} color="#f59e0b" />}        label="Paused"      value={paused}    accent="#f59e0b" />
        <StatCard icon={<RefreshCw size={20} strokeWidth={1.8} color="#0ea5e9" />}    label="Total Runs"  value={totalRuns} accent="#0ea5e9" sub="all time" />
      </div>

      {/* ── Filter bar ── */}
      {total > 0 && (
        <div style={{ display: 'flex', alignItems: 'center', gap: '.75rem', marginBottom: '1rem', flexWrap: 'wrap' }}>
          {/* Search */}
          <div style={{ position: 'relative', flex: '1 1 220px', maxWidth: 320 }}>
            <span style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', display: 'flex', alignItems: 'center', color: '#9ca3af', pointerEvents: 'none' }}><Search size={14} strokeWidth={2} /></span>
            <input
              style={{
                width: '100%', boxSizing: 'border-box', paddingLeft: 32, paddingRight: 10,
                height: 36, borderRadius: 9, border: '1.5px solid #e5e7eb',
                background: '#f9fafb', fontSize: 13.5, color: '#111827',
                outline: 'none', transition: 'border-color .15s',
              }}
              placeholder="Search flows…"
              value={search}
              onChange={e => setSearch(e.target.value)}
              onFocus={e => e.target.style.borderColor = '#0a6cc4'}
              onBlur={e => e.target.style.borderColor = '#e5e7eb'}
            />
          </div>

          {/* Status filter pills */}
          <div style={{ display: 'flex', gap: '.35rem', flexWrap: 'wrap' }}>
            {[
              { key: 'all',    label: `All (${total})` },
              { key: 'active', label: `Active (${active})` },
              { key: 'paused', label: `Paused (${paused})` },
              { key: 'draft',  label: `Draft (${draft})` },
            ].map(f => (
              <button
                key={f.key}
                onClick={() => setFilter(f.key)}
                style={{
                  padding: '.3rem .8rem', borderRadius: 999,
                  border: filter === f.key ? '2px solid #0a6cc4' : '1.5px solid #e5e7eb',
                  background: filter === f.key ? '#f5f3ff' : '#fff',
                  color: filter === f.key ? '#0a6cc4' : '#374151',
                  fontWeight: filter === f.key ? 700 : 500,
                  fontSize: 13, cursor: 'pointer',
                  transition: 'all .13s',
                }}
              >
                {f.label}
              </button>
            ))}
          </div>
        </div>
      )}

      {/* ── Empty state ── */}
      {total === 0 && (
        <div className="lp-fl-empty-bg" style={{ padding: '4rem 2rem', textAlign: 'center' }}>
          <div style={{ marginBottom: 16, color: '#12a89e' }}><Bot size={40} strokeWidth={1.4} /></div>
          <div style={{ fontSize: 20, fontWeight: 800, color: '#1e1b4b', marginBottom: 8 }}>
            No flows yet
          </div>
          <div style={{ fontSize: 14, color: '#0e8f8c', marginBottom: 24, maxWidth: 380, margin: '0 auto 24px' }}>
            Create your first automation flow to start sending WhatsApp messages automatically when leads arrive.
          </div>
          <button
            onClick={() => setShowCreate(true)}
            style={{
              padding: '.7rem 1.6rem', borderRadius: 10, border: 'none',
              background: 'linear-gradient(135deg, #0a6cc4, #12a89e)',
              color: '#fff', fontWeight: 700, fontSize: 15, cursor: 'pointer',
              boxShadow: '0 4px 14px rgba(10,108,196,.4)',
              display: 'inline-flex', alignItems: 'center', gap: '.4rem',
            }}
          >
            <Zap size={15} strokeWidth={2} /> Create Your First Flow
          </button>
        </div>
      )}

      {/* ── No search results ── */}
      {total > 0 && filtered.length === 0 && (
        <div style={{ textAlign: 'center', padding: '3rem 1rem', color: '#9ca3af' }}>
          <div style={{ marginBottom: 10 }}><SearchX size={36} strokeWidth={1.4} /></div>
          <div style={{ fontSize: 15, fontWeight: 600, color: '#374151' }}>No flows match your filter</div>
          <button onClick={() => { setFilter('all'); setSearch('') }} style={{ marginTop: 12, padding: '.4rem .9rem', borderRadius: 8, border: '1px solid #e5e7eb', background: '#fff', cursor: 'pointer', fontSize: 13, color: '#0a6cc4', fontWeight: 600 }}>
            Clear filters
          </button>
        </div>
      )}

      {/* ── Flow Cards ── */}
      <div style={{ display: 'flex', flexDirection: 'column', gap: '.75rem' }}>
        {filtered.map((flow, i) => (
          <div key={flow.id} style={{ animationDelay: `${i * 40}ms` }}>
            <FlowCard
              flow={flow}
              onEdit={id => navigate(`/flows/${id}`)}
              onDelete={handleDelete}
              onToggle={handleToggle}
            />
          </div>
        ))}
      </div>

      {/* ── Create Modal ── */}
      {showCreate && (
        <CreateModal
          onClose={() => setShowCreate(false)}
          onCreate={handleCreate}
        />
      )}
    </div>
  )
}
