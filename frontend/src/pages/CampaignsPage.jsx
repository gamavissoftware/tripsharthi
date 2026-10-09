import { useState, useEffect, useRef } from 'react'
import {
  Send, Clock, Pencil, Ban, Target, Trash2, Users, Calendar, Check, CheckCheck,
  Eye, XCircle, Megaphone, LayoutTemplate, ClipboardList, Tag, X, AlertTriangle,
  Zap, FlaskConical, PhoneOff, MessageCircle, MousePointerClick, Building2,
} from 'lucide-react'
import { campaigns as campaignsApi } from '../api/campaigns'
import { segments as segmentsApi } from '../api/segments'
import { api } from '../api/client'
import { toast } from '../components/Toast'

// ── Inject keyframes once ─────────────────────────────────────────────────
if (typeof document !== 'undefined' && !document.getElementById('lp-camp-css')) {
  const el = document.createElement('style')
  el.id = 'lp-camp-css'
  el.textContent = `
    @keyframes lp-pulse {
      0%,100% { opacity:1; transform:scale(1); }
      50%      { opacity:.45; transform:scale(.88); }
    }
    @keyframes lp-bar-in {
      from { width: 0; }
    }
    .lp-card-hover {
      transition: box-shadow .18s ease, transform .18s ease;
    }
    .lp-card-hover:hover {
      box-shadow: 0 8px 28px rgba(0,0,0,.13);
      transform: translateY(-2px);
    }
  `
  document.head.appendChild(el)
}

// ── Constants ─────────────────────────────────────────────────────────────
const STATUSES = ['new', 'contacted', 'qualified', 'won', 'lost']

const STATUS_CFG = {
  draft:      { bg: '#f3f4f6', color: '#374151', label: 'Draft' },
  scheduled:  { bg: '#f5f3ff', color: '#6d28d9', label: 'Scheduled' },
  processing: { bg: '#eff6ff', color: '#1d4ed8', label: 'Sending…', pulse: true },
  done:       { bg: '#f0fdf4', color: '#15803d', label: 'Completed ✓' },
  failed:     { bg: '#fff1f2', color: '#be123c', label: 'Failed' },
  paused:     { bg: '#fffbeb', color: '#b45309', label: 'Paused' },
}

const CATEGORY_CFG = {
  marketing:      { bg: '#fff7ed', color: '#c2410c' },
  utility:        { bg: '#f0fdf4', color: '#166534' },
  authentication: { bg: '#eff6ff', color: '#1e40af' },
  service:        { bg: '#fdf4ff', color: '#7e22ce' },
}

function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' })
}

function fmtNum(n) {
  if (n == null) return '—'
  return Number(n).toLocaleString('en-IN')
}

function fmtDateTime(d) {
  if (!d) return '—'
  // scheduled_at comes back as a UTC datetime string; append Z so the browser
  // renders it in the viewer's local zone.
  const iso = d.includes('T') ? d : d.replace(' ', 'T') + 'Z'
  return new Date(iso).toLocaleString('en-IN', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

// ── Stat Card ─────────────────────────────────────────────────────────────
function StatCard({ icon, label, value, accent, loading }) {
  return (
    <div style={{
      background: '#fff',
      border: '1px solid #e5e7eb',
      borderRadius: 12,
      padding: '1.1rem 1.25rem',
      borderTop: `3px solid ${accent}`,
      flex: '1 1 180px',
      minWidth: 0,
    }}>
      {loading ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          <div style={{ height: 14, width: '60%', borderRadius: 6, background: '#e5e7eb', animation: 'pulse 1.2s infinite' }} />
          <div style={{ height: 28, width: '40%', borderRadius: 6, background: '#e5e7eb', animation: 'pulse 1.2s infinite' }} />
        </div>
      ) : (
        <>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 6 }}>
            <span style={{ fontSize: 12, fontWeight: 600, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '.05em' }}>{label}</span>
            <span style={{ display: 'flex', color: '#9ca3af' }}>{icon}</span>
          </div>
          <div style={{ fontSize: 28, fontWeight: 800, color: '#111827', lineHeight: 1.1 }}>{value}</div>
        </>
      )}
    </div>
  )
}

// ── Progress Bar ──────────────────────────────────────────────────────────
function ProgressBar({ pct, color }) {
  return (
    <div style={{ height: 8, background: '#e5e7eb', borderRadius: 999, overflow: 'hidden' }}>
      <div style={{
        height: '100%',
        width: `${Math.min(100, Math.max(0, pct))}%`,
        background: color,
        borderRadius: 999,
        transition: 'width .6s cubic-bezier(.4,0,.2,1)',
        animation: 'lp-bar-in .6s ease',
      }} />
    </div>
  )
}

// ── Status Badge ──────────────────────────────────────────────────────────
function StatusBadge({ status }) {
  const cfg = STATUS_CFG[status] ?? { bg: '#f3f4f6', color: '#374151', label: status }
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 9px', borderRadius: 999, fontSize: 12, fontWeight: 700, background: cfg.bg, color: cfg.color }}>
      {cfg.pulse && (
        <span style={{
          width: 7, height: 7, borderRadius: '50%', background: '#2563eb', flexShrink: 0,
          animation: 'lp-pulse 1.2s ease-in-out infinite',
        }} />
      )}
      {cfg.label}
    </span>
  )
}

// ── Category Badge ────────────────────────────────────────────────────────
function CategoryBadge({ category }) {
  if (!category) return null
  const cfg = CATEGORY_CFG[category] ?? { bg: '#f3f4f6', color: '#374151' }
  return (
    <span style={{
      display: 'inline-block', padding: '1px 7px', borderRadius: 999,
      fontSize: 11, fontWeight: 600, background: cfg.bg, color: cfg.color,
      textTransform: 'capitalize',
    }}>
      {category}
    </span>
  )
}

// ── Skeleton Card ─────────────────────────────────────────────────────────
function SkeletonCard() {
  const bar = (w, h = 12) => (
    <div style={{ height: h, width: w, borderRadius: 6, background: '#e5e7eb', marginBottom: 8 }} />
  )
  return (
    <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, padding: '1.25rem' }}>
      {bar('40%', 10)}
      {bar('70%', 18)}
      {bar('55%', 10)}
      <div style={{ marginTop: 16 }}>{bar('100%', 8)}</div>
      <div style={{ marginTop: 8, display: 'flex', gap: 16 }}>
        {bar('20%', 10)}{bar('20%', 10)}{bar('20%', 10)}{bar('20%', 10)}
      </div>
    </div>
  )
}

// ── Campaign Card ─────────────────────────────────────────────────────────
function CampaignCard({ campaign, templates, onSend, onDelete, onSchedule, onUnschedule, onRetarget, confirmDelete, setConfirmDelete }) {
  const [menuOpen, setMenuOpen] = useState(false)
  const menuRef = useRef(null)

  useEffect(() => {
    function handler(e) {
      if (menuRef.current && !menuRef.current.contains(e.target)) setMenuOpen(false)
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [])

  const c = campaign
  // Delivery counts come from the API as real per-status message counts —
  // never estimate them, the numbers drive what the customer thinks they paid for.
  const sent      = Number(c.sent_count      ?? 0)
  const delivered = Number(c.delivered_count ?? 0)
  const read      = Number(c.read_count      ?? 0)
  const failed    = Number(c.failed_count    ?? 0)
  const total     = Number(c.total_contacts  ?? 0)
  const pct       = total > 0 ? Math.round((sent / total) * 100) : 0

  const tpl = templates.find(t => String(t.id) === String(c.template_id))
  const tplName = tpl?.name ?? c.template?.name ?? c.template_name ?? null

  const barColor = c.status === 'done' ? '#16a34a' : c.status === 'failed' ? '#dc2626' : '#2563eb'
  const isDraft = c.status === 'draft'
  const isScheduled = c.status === 'scheduled'
  const isProcessing = c.status === 'processing'
  const isDone = c.status === 'done'
  const hasStats = !isDraft && !isScheduled

  return (
    <div className="lp-card-hover" style={{
      background: '#fff',
      border: '1px solid #e5e7eb',
      borderRadius: 14,
      padding: '1.25rem',
      display: 'flex',
      flexDirection: 'column',
      gap: 0,
      position: 'relative',
    }}>
      {/* Header row */}
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: 8 }}>
        <StatusBadge status={c.status} />
        <div ref={menuRef} style={{ position: 'relative' }}>
          <button
            onClick={() => setMenuOpen(v => !v)}
            style={{
              background: 'none', border: 'none', cursor: 'pointer',
              padding: '2px 6px', borderRadius: 6, fontSize: 18, color: '#9ca3af',
              lineHeight: 1,
            }}
            aria-label="Actions"
          >⋯</button>
          {menuOpen && (
            <div style={{
              position: 'absolute', right: 0, top: '110%', background: '#fff',
              border: '1px solid #e5e7eb', borderRadius: 10, boxShadow: '0 8px 24px rgba(0,0,0,.12)',
              zIndex: 10, minWidth: 140, overflow: 'hidden',
            }}>
              {isDraft && (
                <button
                  style={menuBtnStyle}
                  onClick={() => { setMenuOpen(false); onSend(c.id) }}
                >
                  <Send size={14} strokeWidth={2} /> Send now
                </button>
              )}
              {isDraft && (
                <button
                  style={menuBtnStyle}
                  onClick={() => { setMenuOpen(false); onSchedule(c) }}
                >
                  <Clock size={14} strokeWidth={2} /> Schedule
                </button>
              )}
              {isScheduled && (
                <button
                  style={menuBtnStyle}
                  onClick={() => { setMenuOpen(false); onSend(c.id) }}
                >
                  <Send size={14} strokeWidth={2} /> Send now
                </button>
              )}
              {isScheduled && (
                <button
                  style={menuBtnStyle}
                  onClick={() => { setMenuOpen(false); onSchedule(c) }}
                >
                  <Pencil size={14} strokeWidth={2} /> Reschedule
                </button>
              )}
              {isScheduled && (
                <button
                  style={{ ...menuBtnStyle, color: '#b45309' }}
                  onClick={() => { setMenuOpen(false); onUnschedule(c.id) }}
                >
                  <Ban size={14} strokeWidth={2} /> Cancel schedule
                </button>
              )}
              {isProcessing && (
                <div style={{ ...menuBtnStyle, color: '#9ca3af', cursor: 'default' }}>Processing…</div>
              )}
              {isDone && (
                <button
                  style={menuBtnStyle}
                  onClick={() => { setMenuOpen(false); onRetarget(c) }}
                >
                  <Target size={14} strokeWidth={2} /> Retarget
                </button>
              )}
              <button
                style={{ ...menuBtnStyle, color: '#dc2626' }}
                onClick={() => { setMenuOpen(false); setConfirmDelete(c.id) }}
              >
                <Trash2 size={14} strokeWidth={2} /> Delete
              </button>
            </div>
          )}
        </div>
      </div>

      {/* Name */}
      <div style={{ fontWeight: 700, fontSize: 16, color: '#111827', marginBottom: 4, lineHeight: 1.3 }}>
        {c.name}
      </div>

      {/* Template */}
      <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 10, display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
        <span>Template:</span>
        {tplName
          ? <span style={{ color: '#374151', fontWeight: 600 }}>{tplName}</span>
          : <span style={{ color: '#d1d5db', fontStyle: 'italic' }}>loading…</span>
        }
        {tpl?.category && <CategoryBadge category={tpl.category} />}
      </div>

      {/* Audience + Date */}
      <div style={{ display: 'flex', gap: 16, fontSize: 12, color: '#9ca3af', marginBottom: 14, flexWrap: 'wrap' }}>
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Users size={12} strokeWidth={2} /> {audienceLabel(c.segment)}</span>
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Calendar size={12} strokeWidth={2} /> {fmtDate(c.created_at)}</span>
        {c.scheduled_at && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Clock size={12} strokeWidth={2} /> {fmtDate(c.scheduled_at)}</span>}
      </div>

      {/* Scheduled banner */}
      {isScheduled && c.scheduled_at && (
        <div style={{
          display: 'flex', alignItems: 'center', gap: 8,
          background: '#f5f3ff', border: '1px solid #ddd6fe', borderRadius: 10,
          padding: '10px 12px', marginBottom: 14, fontSize: 13, color: '#5b21b6',
        }}>
          <Clock size={16} strokeWidth={2} style={{ flexShrink: 0 }} />
          <span>
            Sends <strong>{fmtDateTime(c.scheduled_at)}</strong>
            {c.schedule_timezone ? ` · ${c.schedule_timezone}` : ''}
          </span>
        </div>
      )}

      {/* Progress */}
      {!isDraft && !isScheduled && total > 0 && (
        <div style={{ marginBottom: 14 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12, color: '#6b7280', marginBottom: 5 }}>
            <span style={{ fontWeight: 600 }}>{pct}% sent</span>
            <span>{fmtNum(sent)} / {fmtNum(total)} contacts</span>
          </div>
          <ProgressBar pct={pct} color={barColor} />
        </div>
      )}
      {!isDraft && !isScheduled && total === 0 && (
        <div style={{ marginBottom: 14 }}>
          <ProgressBar pct={0} color={barColor} />
          <div style={{ fontSize: 12, color: '#9ca3af', marginTop: 4 }}>No contacts yet</div>
        </div>
      )}

      {/* Stats row */}
      {hasStats && (
        <div style={{
          display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)',
          gap: 6, marginBottom: 14,
          padding: '10px 12px',
          background: '#f9fafb', borderRadius: 10, border: '1px solid #f0f0f0',
        }}>
          <MiniStat icon={<Check size={14} strokeWidth={2} />}      label="Sent"      value={sent} />
          <MiniStat icon={<CheckCheck size={14} strokeWidth={2} />} label="Delivered" value={delivered} />
          <MiniStat icon={<Eye size={14} strokeWidth={2} />}        label="Read"      value={read} />
          <MiniStat icon={<XCircle size={14} strokeWidth={2} />}    label="Failed"    value={failed} />
        </div>
      )}

      {/* Inline confirm delete */}
      {confirmDelete === c.id ? (
        <div style={{
          background: '#fff1f2', border: '1px solid #fecdd3', borderRadius: 8,
          padding: '0.65rem 0.85rem', display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap',
        }}>
          <span style={{ fontSize: 13, color: '#9f1239', flex: 1 }}>Delete this campaign?</span>
          <button
            style={{ ...actionBtnStyle, background: '#dc2626', color: '#fff' }}
            onClick={() => onDelete(c.id)}
          >
            Confirm
          </button>
          <button
            style={{ ...actionBtnStyle, background: '#f3f4f6', color: '#374151' }}
            onClick={() => setConfirmDelete(null)}
          >
            Cancel
          </button>
        </div>
      ) : (
        <div style={{ display: 'flex', gap: 8 }}>
          {isDraft && (
            <button
              style={{ ...actionBtnStyle, background: '#08569f', color: '#fff', flex: 1 }}
              onClick={() => onSend(c.id)}
            >
              <Send size={14} strokeWidth={2} /> Send
            </button>
          )}
          <button
            style={{ ...actionBtnStyle, background: '#fff', color: '#dc2626', border: '1px solid #fecdd3', flex: isDraft ? 'none' : 1 }}
            onClick={() => setConfirmDelete(c.id)}
          >
            <Trash2 size={14} strokeWidth={2} /> Delete
          </button>
        </div>
      )}
    </div>
  )
}

function MiniStat({ icon, label, value }) {
  return (
    <div style={{ textAlign: 'center' }}>
      <div style={{ display: 'flex', justifyContent: 'center', color: '#6b7280' }}>{icon}</div>
      <div style={{ fontSize: 14, fontWeight: 700, color: '#111827', lineHeight: 1.2 }}>{fmtNum(value)}</div>
      <div style={{ fontSize: 10, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: '.05em' }}>{label}</div>
    </div>
  )
}

function audienceLabel(segment) {
  if (!segment) return 'All Contacts'
  if (typeof segment === 'string') {
    try { segment = JSON.parse(segment) } catch { return 'All Contacts' }
  }
  if (segment.all)        return 'All Contacts'
  if (segment.statuses)   return `Status: ${segment.statuses.join(', ')}`
  if (segment.tag_id || segment.tag_ids) return `By Tag`
  if (segment.category)   return `Category: ${segment.category}`
  if (segment.segment_id) return `Segment: ${segment.name || segment.segment_id}`
  return 'All Contacts'
}

const menuBtnStyle = {
  display: 'flex', alignItems: 'center', gap: 8, width: '100%', textAlign: 'left',
  background: 'none', border: 'none', padding: '9px 14px',
  cursor: 'pointer', fontSize: 13, color: '#374151',
}

const actionBtnStyle = {
  padding: '6px 14px', borderRadius: 7, border: 'none',
  cursor: 'pointer', fontSize: 13, fontWeight: 600,
  display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 6,
}

// ── Empty State ───────────────────────────────────────────────────────────
function EmptyState({ onNew }) {
  return (
    <div style={{
      display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
      padding: '4rem 2rem', textAlign: 'center',
    }}>
      <div style={{ marginBottom: 16, color: '#9ca3af' }}><Megaphone size={48} strokeWidth={1.4} /></div>
      <h2 style={{ margin: '0 0 8px', fontSize: 20, fontWeight: 700, color: '#111827' }}>No campaigns yet</h2>
      <p style={{ margin: '0 0 24px', color: '#6b7280', maxWidth: 380, lineHeight: 1.6 }}>
        Create your first broadcast campaign to reach all your contacts at once via WhatsApp.
      </p>
      <button
        style={{
          padding: '10px 22px', background: '#08569f', color: '#fff',
          border: 'none', borderRadius: 9, fontWeight: 700, fontSize: 14, cursor: 'pointer',
        }}
        onClick={onNew}
      >
        + Create Campaign
      </button>
    </div>
  )
}

// ── Step Indicator ────────────────────────────────────────────────────────
function StepIndicator({ current }) {
  const labels = ['Select Template', 'Choose Audience', 'Review & Send']
  return (
    <div style={{ display: 'flex', alignItems: 'center', marginBottom: '1.75rem' }}>
      {labels.map((label, i) => {
        const n = i + 1
        const done   = current > n
        const active = current === n
        return (
          <div key={n} style={{ display: 'flex', alignItems: 'center', flex: i < labels.length - 1 ? 1 : 'none' }}>
            <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4 }}>
              <div style={{
                width: 32, height: 32, borderRadius: '50%',
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                fontWeight: 700, fontSize: 14,
                background: done ? '#16a34a' : active ? '#08569f' : '#e5e7eb',
                color: (done || active) ? '#fff' : '#9ca3af',
                transition: 'background .2s',
              }}>
                {done ? '✓' : n}
              </div>
              <span style={{ fontSize: 11, fontWeight: 600, color: active ? '#08569f' : '#9ca3af', whiteSpace: 'nowrap' }}>
                {label}
              </span>
            </div>
            {i < labels.length - 1 && (
              <div style={{ flex: 1, height: 2, background: done ? '#16a34a' : '#e5e7eb', margin: '0 6px', marginBottom: 16, transition: 'background .2s' }} />
            )}
          </div>
        )
      })}
    </div>
  )
}

// ── Campaign Wizard (Modal) ───────────────────────────────────────────────
function CampaignWizard({ onDone, onCancel }) {
  const [step, setStep]             = useState(1)
  const [name, setName]             = useState('')
  const [templates, setTemplates]   = useState([])
  const [tplLoading, setTplLoading] = useState(true)
  const [selectedTpl, setSelectedTpl] = useState(null)
  const [audienceType, setAudienceType] = useState('all')
  const [statusSel, setStatusSel]   = useState([])
  const [tagList, setTagList]       = useState([])
  const [selectedTag, setSelectedTag] = useState('')
  const [catList, setCatList]       = useState([])
  const [selectedCategory, setSelectedCategory] = useState('')
  const [segmentList, setSegmentList] = useState([])
  const [selectedSegmentId, setSelectedSegmentId] = useState('')
  const [selectedSegmentName, setSelectedSegmentName] = useState('')
  const [totalContacts, setTotalContacts] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [abEnabled, setAbEnabled]   = useState(false)
  const [variantTpl, setVariantTpl] = useState(null)
  const [abSplit, setAbSplit]       = useState(50)

  useEffect(() => {
    api.get('/templates?meta_status=approved')
      .then(r => setTemplates(r.data ?? r ?? []))
      .catch(() => setTemplates([]))
      .finally(() => setTplLoading(false))
    api.get('/tags')
      .then(r => setTagList(r.data ?? r ?? []))
      .catch(() => {})
    segmentsApi.list()
      .then(r => setSegmentList(r.data ?? []))
      .catch(() => {})
    api.get('/contacts/categories')
      .then(r => setCatList(r.data ?? r ?? []))
      .catch(() => {})
    api.get('/contacts?count_only=1')
      .then(r => setTotalContacts(r.total ?? r.data?.total ?? null))
      .catch(() => {})
  }, [])

  function toggleStatus(s) {
    setStatusSel(prev => prev.includes(s) ? prev.filter(x => x !== s) : [...prev, s])
  }

  function buildSegment() {
    if (audienceType === 'all')      return { all: true }
    if (audienceType === 'status')   return { statuses: statusSel }
    // tag_ids (a list) is the documented shape SegmentResolver expects.
    if (audienceType === 'tag')      return { tag_ids: [Number(selectedTag)] }
    if (audienceType === 'category') return { category: selectedCategory }
    if (audienceType === 'segment')  return { segment_id: selectedSegmentId, name: selectedSegmentName }
    return { all: true }
  }

  function audienceDisplay() {
    if (audienceType === 'all') return totalContacts != null ? `All ${totalContacts.toLocaleString('en-IN')} contacts` : 'All contacts'
    if (audienceType === 'status') return statusSel.length ? `Status: ${statusSel.join(', ')}` : '—'
    if (audienceType === 'tag') {
      const t = tagList.find(x => String(x.id) === String(selectedTag))
      return t ? `Tag: ${t.name}` : '—'
    }
    if (audienceType === 'category') return selectedCategory ? `Category: ${selectedCategory}` : '—'
    if (audienceType === 'segment') {
      const seg = segmentList.find(x => String(x.id) === String(selectedSegmentId))
      if (seg) {
        const count = seg.contact_count ?? seg.contacts_count ?? null
        return count != null ? `Segment: ${seg.name} (~${count} contacts)` : `Segment: ${seg.name}`
      }
      return '—'
    }
    return '—'
  }

  function step1Valid() { return name.trim().length > 0 && selectedTpl != null }
  function step2Valid() {
    if (audienceType === 'status')  return statusSel.length > 0
    if (audienceType === 'tag')      return Boolean(selectedTag)
    if (audienceType === 'category') return Boolean(selectedCategory)
    if (audienceType === 'segment')  return Boolean(selectedSegmentId)
    return true
  }

  function handleSegmentChange(e) {
    const id = e.target.value
    setSelectedSegmentId(id)
    const seg = segmentList.find(x => String(x.id) === String(id))
    setSelectedSegmentName(seg?.name ?? '')
  }

  async function handleSubmit() {
    setSubmitting(true)
    try {
      const payload = { name: name.trim(), template_id: selectedTpl.id, segment: buildSegment() }
      if (abEnabled && variantTpl && variantTpl.id !== selectedTpl.id) {
        payload.variant_template_id = variantTpl.id
        payload.ab_split = abSplit
      }
      const created  = await api.post('/campaigns', payload)
      const campaign = created.data ?? created
      await api.post(`/campaigns/${campaign.id}/send`)
      const fresh    = await api.get(`/campaigns/${campaign.id}`)
      toast.success('Campaign queued', `"${name}" is now sending to your contacts.`)
      onDone(fresh.data ?? fresh)
    } catch (err) {
      toast.error('Failed to create campaign', err.message)
      setSubmitting(false)
    }
  }

  // Backdrop click closes
  function handleBackdrop(e) {
    if (e.target === e.currentTarget) onCancel()
  }

  return (
    <div
      onClick={handleBackdrop}
      style={{
        position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)',
        zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20,
      }}
    >
      <div style={{
        background: '#fff', borderRadius: 16,
        maxWidth: 600, width: '100%', maxHeight: '90vh',
        overflow: 'auto', padding: '2rem',
        boxShadow: '0 20px 60px rgba(0,0,0,.3)',
      }}>
        {/* Modal header */}
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '1.5rem' }}>
          <h2 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: '#111827' }}>New Campaign</h2>
          <button
            onClick={onCancel}
            style={{ background: 'none', border: 'none', cursor: 'pointer', display: 'flex', color: '#9ca3af', padding: '0 4px' }}
            aria-label="Close"
          ><X size={18} strokeWidth={2} /></button>
        </div>

        <StepIndicator current={step} />

        {/* ── Step 1: Select Template ── */}
        {step === 1 && (
          <div>
            <div style={{ marginBottom: '1.25rem' }}>
              <label style={labelStyle}>Campaign Name <span style={{ color: '#dc2626' }}>*</span></label>
              <input
                style={inputStyle(name.trim().length > 0)}
                value={name}
                onChange={e => setName(e.target.value)}
                placeholder="e.g. June Promo Blast"
                autoFocus
              />
            </div>

            <div>
              <label style={labelStyle}>Select Template <span style={{ color: '#dc2626' }}>*</span></label>
              {tplLoading ? (
                <div style={{ color: '#9ca3af', fontSize: 14, padding: '1rem 0' }}>Loading templates…</div>
              ) : templates.length === 0 ? (
                <div style={{
                  padding: '2rem', textAlign: 'center', background: '#f9fafb',
                  borderRadius: 10, border: '1px dashed #d1d5db',
                }}>
                  <div style={{ marginBottom: 8, color: '#9ca3af' }}><LayoutTemplate size={32} strokeWidth={1.4} /></div>
                  <div style={{ color: '#6b7280', fontSize: 14 }}>No approved templates found.</div>
                  <div style={{ color: '#9ca3af', fontSize: 13, marginTop: 4 }}>Create and get a template approved first.</div>
                </div>
              ) : (
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: '0.75rem', marginTop: 8 }}>
                  {templates.map(t => {
                    const sel = selectedTpl?.id === t.id
                    return (
                      <div
                        key={t.id}
                        onClick={() => setSelectedTpl(t)}
                        style={{
                          border: sel ? '2px solid #08569f' : '1.5px solid #e5e7eb',
                          borderRadius: 10, padding: '0.85rem',
                          cursor: 'pointer',
                          background: sel ? '#f5f3ff' : '#fff',
                          position: 'relative',
                          transition: 'border-color .15s, background .15s',
                        }}
                      >
                        {sel && (
                          <div style={{
                            position: 'absolute', top: 8, right: 8,
                            width: 18, height: 18, borderRadius: '50%',
                            background: '#08569f', color: '#fff',
                            display: 'flex', alignItems: 'center', justifyContent: 'center',
                            fontSize: 10, fontWeight: 800,
                          }}>✓</div>
                        )}
                        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 6, marginBottom: 6 }}>
                          <span style={{ flex: 1, fontWeight: 700, fontSize: 14, color: '#111827', lineHeight: 1.3 }}>{t.name}</span>
                        </div>
                        <CategoryBadge category={t.category} />
                        <div style={{ marginTop: 6, fontSize: 12, color: '#6b7280', lineHeight: 1.4, overflow: 'hidden', display: '-webkit-box', WebkitLineClamp: 3, WebkitBoxOrient: 'vertical' }}>
                          {t.body}
                        </div>
                      </div>
                    )
                  })}
                </div>
              )}
            </div>

            {/* A/B test */}
            {selectedTpl && templates.length > 1 && (
              <div style={{ marginTop: 18, padding: '1rem', background: '#faf5ff', border: '1px solid #e9d5ff', borderRadius: 10 }}>
                <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 14, fontWeight: 600, color: '#6d28d9', cursor: 'pointer' }}>
                  <input type="checkbox" checked={abEnabled} onChange={e => setAbEnabled(e.target.checked)} />
                  <FlaskConical size={15} strokeWidth={2} /> Run an A/B test
                </label>
                {abEnabled && (
                  <div style={{ marginTop: 12 }}>
                    <div style={{ fontSize: 12.5, color: '#6b7280', marginBottom: 8 }}>
                      A second template is sent to a share of the audience so you can compare performance.
                    </div>
                    <label style={{ ...labelStyle, fontSize: 12 }}>Variant B template</label>
                    <select style={selectStyle} value={variantTpl?.id ?? ''} onChange={e => setVariantTpl(templates.find(t => String(t.id) === e.target.value) ?? null)}>
                      <option value="">— choose variant —</option>
                      {templates.filter(t => t.id !== selectedTpl.id).map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
                    </select>
                    <div style={{ marginTop: 10, fontSize: 12.5, color: '#374151' }}>
                      Send <strong>{100 - abSplit}%</strong> to A / <strong>{abSplit}%</strong> to B
                    </div>
                    <input type="range" min="10" max="90" step="10" value={abSplit} onChange={e => setAbSplit(parseInt(e.target.value, 10))} style={{ width: '100%' }} />
                  </div>
                )}
              </div>
            )}

            <div style={{ display: 'flex', gap: 10, marginTop: '1.75rem', justifyContent: 'flex-end' }}>
              <button style={btnGhost} onClick={onCancel}>Cancel</button>
              <button
                style={{ ...btnPrimary, opacity: step1Valid() ? 1 : .45, cursor: step1Valid() ? 'pointer' : 'default' }}
                disabled={!step1Valid()}
                onClick={() => setStep(2)}
              >
                Next →
              </button>
            </div>
          </div>
        )}

        {/* ── Step 2: Choose Audience ── */}
        {step === 2 && (
          <div>
            <label style={labelStyle}>Audience</label>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginBottom: '1.25rem' }}>
              {[
                { val: 'all',     icon: <Users size={18} strokeWidth={1.8} />,  label: 'All contacts', sub: totalContacts != null ? `Send to all ${totalContacts.toLocaleString('en-IN')} contacts` : 'Send to all your contacts' },
                { val: 'status',  icon: <Target size={18} strokeWidth={1.8} />, label: 'By status',    sub: 'Filter by contact status' },
                { val: 'tag',     icon: <Tag size={18} strokeWidth={1.8} />,    label: 'By tag',       sub: 'Filter by a specific tag' },
                ...(catList.length ? [{ val: 'category', icon: <Building2 size={18} strokeWidth={1.8} />, label: 'By company category', sub: `Filter by one of your ${catList.length} categories` }] : []),
                { val: 'segment', icon: <ClipboardList size={18} strokeWidth={1.8} />, label: 'Saved segment', sub: 'Use a pre-built segment' },
              ].map(opt => {
                const active = audienceType === opt.val
                return (
                  <label
                    key={opt.val}
                    style={{
                      display: 'flex', alignItems: 'center', gap: 12, cursor: 'pointer',
                      padding: '10px 14px', borderRadius: 10,
                      border: active ? '2px solid #08569f' : '1.5px solid #e5e7eb',
                      background: active ? '#f5f3ff' : '#fff',
                      transition: 'border-color .15s, background .15s',
                    }}
                  >
                    <input type="radio" name="audienceType" value={opt.val} checked={active} onChange={() => setAudienceType(opt.val)} style={{ accentColor: '#08569f' }} />
                    <span style={{ display: 'flex', color: active ? '#08569f' : '#6b7280' }}>{opt.icon}</span>
                    <div>
                      <div style={{ fontWeight: 600, fontSize: 14, color: '#111827' }}>{opt.label}</div>
                      <div style={{ fontSize: 12, color: '#9ca3af' }}>{opt.sub}</div>
                    </div>
                  </label>
                )
              })}
            </div>

            {audienceType === 'status' && (
              <div style={{ padding: '1rem', background: '#f9fafb', borderRadius: 10, marginBottom: '1rem' }}>
                <div style={{ fontSize: 13, fontWeight: 600, color: '#374151', marginBottom: 10 }}>Select Statuses</div>
                <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                  {STATUSES.map(s => {
                    const chk = statusSel.includes(s)
                    return (
                      <label key={s} style={{
                        display: 'flex', alignItems: 'center', gap: 5,
                        padding: '5px 12px', borderRadius: 999, cursor: 'pointer',
                        background: chk ? '#08569f' : '#e5e7eb',
                        color: chk ? '#fff' : '#374151',
                        fontSize: 13, fontWeight: 600, textTransform: 'capitalize',
                        transition: 'background .15s, color .15s',
                      }}>
                        <input type="checkbox" checked={chk} onChange={() => toggleStatus(s)} style={{ display: 'none' }} />
                        {s}
                      </label>
                    )
                  })}
                </div>
              </div>
            )}

            {audienceType === 'tag' && (
              <div style={{ padding: '1rem', background: '#f9fafb', borderRadius: 10, marginBottom: '1rem' }}>
                <div style={{ fontSize: 13, fontWeight: 600, color: '#374151', marginBottom: 8 }}>Select Tag</div>
                <select
                  value={selectedTag}
                  onChange={e => setSelectedTag(e.target.value)}
                  style={{ ...selectStyle, maxWidth: 320 }}
                >
                  <option value="">— choose a tag —</option>
                  {tagList.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
                </select>
              </div>
            )}

            {audienceType === 'category' && (
              <div style={{ padding: '1rem', background: '#f9fafb', borderRadius: 10, marginBottom: '1rem' }}>
                <div style={{ fontSize: 13, fontWeight: 600, color: '#374151', marginBottom: 8 }}>Select Category</div>
                <select
                  value={selectedCategory}
                  onChange={e => setSelectedCategory(e.target.value)}
                  style={{ ...selectStyle, maxWidth: 420 }}
                >
                  <option value="">— choose a category —</option>
                  {catList.map(c => <option key={c} value={c}>{c}</option>)}
                </select>
              </div>
            )}

            {audienceType === 'segment' && (
              <div style={{ padding: '1rem', background: '#f9fafb', borderRadius: 10, marginBottom: '1rem' }}>
                <div style={{ fontSize: 13, fontWeight: 600, color: '#374151', marginBottom: 8 }}>Select Segment</div>
                <select
                  value={selectedSegmentId}
                  onChange={handleSegmentChange}
                  style={{ ...selectStyle, maxWidth: 360 }}
                >
                  <option value="">— choose a segment —</option>
                  {segmentList.map(seg => {
                    const count = seg.contact_count ?? seg.contacts_count ?? null
                    return (
                      <option key={seg.id} value={seg.id}>
                        {seg.name}{count != null ? ` (${count} contacts)` : ''}
                      </option>
                    )
                  })}
                </select>
                {segmentList.length === 0 && (
                  <div style={{ fontSize: 12, color: '#9ca3af', marginTop: 6 }}>
                    No saved segments. Create segments from the Contacts page first.
                  </div>
                )}
              </div>
            )}

            {/* Reach estimate */}
            <div style={{ fontSize: 13, color: '#6b7280', padding: '8px 12px', background: '#eff6ff', borderRadius: 8, display: 'inline-block' }}>
              Estimated reach: <strong style={{ color: '#1d4ed8' }}>{audienceDisplay()}</strong>
            </div>

            <div style={{ display: 'flex', gap: 10, marginTop: '1.75rem', justifyContent: 'flex-end' }}>
              <button style={btnGhost} onClick={() => setStep(1)}>← Back</button>
              <button
                style={{ ...btnPrimary, opacity: step2Valid() ? 1 : .45, cursor: step2Valid() ? 'pointer' : 'default' }}
                disabled={!step2Valid()}
                onClick={() => setStep(3)}
              >
                Next →
              </button>
            </div>
          </div>
        )}

        {/* ── Step 3: Review & Send ── */}
        {step === 3 && (
          <div>
            <div style={{
              background: '#f9fafb', border: '1px solid #e5e7eb',
              borderRadius: 12, padding: '1.25rem', marginBottom: '1.25rem',
            }}>
              <div style={{ fontWeight: 700, fontSize: 15, color: '#111827', marginBottom: 12 }}>Campaign Summary</div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                <SummaryRow label="Name"       value={name} />
                <SummaryRow
                  label="Template"
                  value={
                    <span style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                      {selectedTpl?.name}
                      <CategoryBadge category={selectedTpl?.category} />
                    </span>
                  }
                />
                <SummaryRow label="Audience"   value={audienceDisplay()} />
              </div>
            </div>

            {/* Template preview */}
            {selectedTpl?.body && (
              <div style={{
                background: '#f0fdf4', border: '1px solid #bbf7d0',
                borderRadius: 12, padding: '1.1rem', marginBottom: '1.25rem',
              }}>
                <div style={{ fontSize: 12, fontWeight: 700, color: '#166534', marginBottom: 8, textTransform: 'uppercase', letterSpacing: '.06em' }}>
                  Message Preview
                </div>
                <div style={{
                  background: '#fff', borderRadius: 10, padding: '12px 14px',
                  fontSize: 14, color: '#374151', lineHeight: 1.6,
                  borderLeft: '3px solid #22c55e', whiteSpace: 'pre-wrap',
                }}>
                  {selectedTpl.body}
                </div>
              </div>
            )}

            {/* Warning */}
            <div style={{
              background: '#fffbeb', border: '1px solid #fde68a',
              borderRadius: 10, padding: '10px 14px', fontSize: 13,
              color: '#92400e', marginBottom: '1.25rem', display: 'flex', gap: 8,
            }}>
              <AlertTriangle size={16} strokeWidth={2} style={{ flexShrink: 0, marginTop: 1 }} />
              <span>
                This will queue one message per contact. WhatsApp template charges apply per message via your WABA.
              </span>
            </div>

            <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
              <button style={btnGhost} disabled={submitting} onClick={() => setStep(2)}>← Back</button>
              <button
                style={{
                  ...btnPrimary,
                  background: submitting ? '#6b7280' : '#16a34a',
                  cursor: submitting ? 'default' : 'pointer',
                  minWidth: 160,
                  justifyContent: 'center', display: 'flex', alignItems: 'center', gap: 8,
                }}
                disabled={submitting}
                onClick={handleSubmit}
              >
                {submitting ? (
                  <><SpinIcon /> Creating…</>
                ) : (
                  <><Send size={15} strokeWidth={2} /> Create & Send</>
                )}
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

function SummaryRow({ label, value }) {
  return (
    <div style={{ display: 'flex', gap: 12, fontSize: 14, alignItems: 'center', flexWrap: 'wrap' }}>
      <span style={{ color: '#9ca3af', minWidth: 90, flexShrink: 0 }}>{label}</span>
      <span style={{ color: '#111827', fontWeight: 600 }}>{value}</span>
    </div>
  )
}

function SpinIcon() {
  return (
    <span style={{
      display: 'inline-block', width: 14, height: 14,
      border: '2px solid rgba(255,255,255,.35)',
      borderTopColor: '#fff', borderRadius: '50%',
      animation: 'spin .7s linear infinite',
    }} />
  )
}

// Inject spin keyframe
if (typeof document !== 'undefined' && !document.getElementById('lp-spin-css')) {
  const el = document.createElement('style')
  el.id = 'lp-spin-css'
  el.textContent = `@keyframes spin { to { transform: rotate(360deg); } }`
  document.head.appendChild(el)
}

// ── Shared style helpers ──────────────────────────────────────────────────
const labelStyle = {
  display: 'block', fontSize: 13, fontWeight: 600,
  color: '#374151', marginBottom: 6,
}

function inputStyle(valid) {
  return {
    width: '100%', boxSizing: 'border-box',
    padding: '10px 14px', border: `1.5px solid ${valid ? '#8ec5f0' : '#e5e7eb'}`,
    borderRadius: 9, fontSize: 15, color: '#111827',
    outline: 'none', transition: 'border-color .15s',
    fontWeight: 600,
  }
}

const selectStyle = {
  width: '100%', padding: '8px 12px', border: '1.5px solid #e5e7eb',
  borderRadius: 9, fontSize: 14, color: '#374151',
  background: '#fff', outline: 'none',
}

const btnPrimary = {
  padding: '10px 22px', background: '#08569f', color: '#fff',
  border: 'none', borderRadius: 9, fontWeight: 700,
  fontSize: 14, cursor: 'pointer',
}

const btnGhost = {
  padding: '10px 18px', background: '#f3f4f6', color: '#374151',
  border: 'none', borderRadius: 9, fontWeight: 600,
  fontSize: 14, cursor: 'pointer',
}

// ── Schedule Modal ────────────────────────────────────────────────────────
const COMMON_TIMEZONES = [
  'Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Asia/Karachi', 'Asia/Dhaka',
  'Europe/London', 'Europe/Paris', 'America/New_York', 'America/Chicago',
  'America/Los_Angeles', 'Australia/Sydney', 'UTC',
]

function guessTimezone() {
  try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC' }
  catch { return 'UTC' }
}

function ScheduleModal({ campaign, onSave, onCancel }) {
  // datetime-local needs "YYYY-MM-DDTHH:mm" in local wall-clock time.
  const [when, setWhen] = useState('')
  const [tz, setTz]     = useState(campaign.schedule_timezone || guessTimezone())
  const [saving, setSaving] = useState(false)

  // Ensure the guessed/selected zone is selectable even if not in the short list.
  const tzOptions = COMMON_TIMEZONES.includes(tz) ? COMMON_TIMEZONES : [tz, ...COMMON_TIMEZONES]

  // Minimum = now (local), so the picker discourages past times.
  const minLocal = (() => {
    const n = new Date(Date.now() - new Date().getTimezoneOffset() * 60000)
    return n.toISOString().slice(0, 16)
  })()

  async function handleSave() {
    if (!when) { toast.error('Pick a date and time'); return }
    setSaving(true)
    try {
      // Send wall-clock "YYYY-MM-DD HH:mm" + IANA zone; backend converts to UTC.
      const scheduled_at = when.replace('T', ' ')
      await campaignsApi.schedule(campaign.id, { scheduled_at, timezone: tz })
      toast.success('Campaign scheduled', `"${campaign.name}" will send at the chosen time.`)
      onSave()
    } catch (err) {
      toast.error('Failed to schedule', err.message)
      setSaving(false)
    }
  }

  function handleBackdrop(e) { if (e.target === e.currentTarget) onCancel() }

  return (
    <div onClick={handleBackdrop} style={{
      position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)', zIndex: 1000,
      display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20,
    }}>
      <div style={{ background: '#fff', borderRadius: 16, maxWidth: 440, width: '100%', padding: '1.75rem', boxShadow: '0 20px 60px rgba(0,0,0,.3)' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '1.25rem' }}>
          <h2 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#111827' }}>Schedule Campaign</h2>
          <button onClick={onCancel} style={{ background: 'none', border: 'none', cursor: 'pointer', display: 'flex', color: '#9ca3af' }} aria-label="Close"><X size={18} strokeWidth={2} /></button>
        </div>

        <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 16 }}>
          “{campaign.name}” will be queued automatically at the selected time.
        </div>

        <label style={labelStyle}>Send at</label>
        <input
          type="datetime-local"
          value={when}
          min={minLocal}
          onChange={e => setWhen(e.target.value)}
          style={{ ...selectStyle, marginBottom: 16 }}
        />

        <label style={labelStyle}>Timezone</label>
        <select value={tz} onChange={e => setTz(e.target.value)} style={{ ...selectStyle, marginBottom: 4 }}>
          {tzOptions.map(z => <option key={z} value={z}>{z}</option>)}
        </select>

        <div style={{ display: 'flex', gap: 10, marginTop: '1.5rem', justifyContent: 'flex-end' }}>
          <button style={btnGhost} disabled={saving} onClick={onCancel}>Cancel</button>
          <button style={{ ...btnPrimary, background: saving ? '#6b7280' : '#6d28d9', minWidth: 130 }} disabled={saving} onClick={handleSave}>
            {saving ? 'Scheduling…' : 'Schedule'}
          </button>
        </div>
      </div>
    </div>
  )
}

// ── Retarget Modal ────────────────────────────────────────────────────────
const RETARGET_OUTCOMES = [
  { key: 'not_delivered', icon: <PhoneOff size={18} strokeWidth={1.8} />,          label: 'Not delivered', sub: 'Message never reached the device' },
  { key: 'not_read',      icon: <Eye size={18} strokeWidth={1.8} />,               label: 'Delivered but not read', sub: 'Reached the device but unopened' },
  { key: 'no_reply',      icon: <MessageCircle size={18} strokeWidth={1.8} />,     label: 'No reply',       sub: 'Received it but never replied' },
  { key: 'clicked',       icon: <MousePointerClick size={18} strokeWidth={1.8} />, label: 'Clicked a button', sub: 'Tapped a CTA / quick-reply' },
]

function RetargetModal({ campaign, onDone, onCancel }) {
  const [counts, setCounts]   = useState(null)
  const [outcome, setOutcome] = useState('not_read')
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    campaignsApi.retargetPreview(campaign.id)
      .then(r => setCounts(r.data ?? {}))
      .catch(() => setCounts({}))
  }, [campaign.id])

  async function handleCreate() {
    setSubmitting(true)
    try {
      const res = await campaignsApi.retarget(campaign.id, { outcome })
      const count = res.recipient_count ?? 0
      toast.success('Retarget campaign created', `Draft targeting ${count} contact(s) is ready to review and send.`)
      onDone(res.data ?? null)
    } catch (err) {
      toast.error('Could not retarget', err.message)
      setSubmitting(false)
    }
  }

  function handleBackdrop(e) { if (e.target === e.currentTarget) onCancel() }

  const selectedCount = counts ? counts[outcome] : null
  const canCreate = selectedCount != null && selectedCount > 0

  return (
    <div onClick={handleBackdrop} style={{
      position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)', zIndex: 1000,
      display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20,
    }}>
      <div style={{ background: '#fff', borderRadius: 16, maxWidth: 460, width: '100%', padding: '1.75rem', boxShadow: '0 20px 60px rgba(0,0,0,.3)' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
          <h2 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#111827' }}>Retarget Campaign</h2>
          <button onClick={onCancel} style={{ background: 'none', border: 'none', cursor: 'pointer', display: 'flex', color: '#9ca3af' }} aria-label="Close"><X size={18} strokeWidth={2} /></button>
        </div>
        <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 16 }}>
          Re-broadcast “{campaign.name}” to the contacts who didn’t engage.
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 10, marginBottom: '1.25rem' }}>
          {RETARGET_OUTCOMES.map(opt => {
            const active = outcome === opt.key
            const n = counts ? counts[opt.key] : null
            const unavailable = counts && n == null
            return (
              <label key={opt.key} style={{
                display: 'flex', alignItems: 'center', gap: 12, cursor: unavailable ? 'not-allowed' : 'pointer',
                padding: '10px 14px', borderRadius: 10,
                border: active ? '2px solid #08569f' : '1.5px solid #e5e7eb',
                background: active ? '#f5f3ff' : '#fff', opacity: unavailable ? 0.5 : 1,
              }}>
                <input type="radio" name="retargetOutcome" value={opt.key} checked={active}
                  disabled={unavailable}
                  onChange={() => setOutcome(opt.key)} style={{ accentColor: '#08569f' }} />
                <span style={{ display: 'flex', color: active ? '#08569f' : '#6b7280' }}>{opt.icon}</span>
                <div style={{ flex: 1 }}>
                  <div style={{ fontWeight: 600, fontSize: 14, color: '#111827' }}>{opt.label}</div>
                  <div style={{ fontSize: 12, color: '#9ca3af' }}>{opt.sub}</div>
                </div>
                <span style={{ fontSize: 13, fontWeight: 700, color: active ? '#08569f' : '#9ca3af' }}>
                  {counts == null ? '…' : (n == null ? '—' : n)}
                </span>
              </label>
            )
          })}
        </div>

        <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end' }}>
          <button style={btnGhost} disabled={submitting} onClick={onCancel}>Cancel</button>
          <button
            style={{ ...btnPrimary, background: (canCreate && !submitting) ? '#08569f' : '#9ca3af', cursor: (canCreate && !submitting) ? 'pointer' : 'default', minWidth: 150 }}
            disabled={!canCreate || submitting}
            onClick={handleCreate}
          >
            {submitting ? 'Creating…' : `Create draft${selectedCount ? ` (${selectedCount})` : ''}`}
          </button>
        </div>
      </div>
    </div>
  )
}

// ── Main Page ─────────────────────────────────────────────────────────────
export default function CampaignsPage() {
  const [rows, setRows]                 = useState([])
  const [templates, setTemplates]       = useState([])
  const [loading, setLoading]           = useState(true)
  const [showWizard, setShowWizard]     = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(null)
  const [scheduleFor, setScheduleFor]   = useState(null)
  const [retargetFor, setRetargetFor]   = useState(null)
  const pollRef                         = useRef({})

  // Load templates for name resolution
  useEffect(() => {
    api.get('/templates')
      .then(r => setTemplates(r.data ?? r ?? []))
      .catch(() => {})
  }, [])

  useEffect(() => {
    load()
    return () => { Object.values(pollRef.current).forEach(clearInterval) }
  }, [])

  async function load() {
    setLoading(true)
    try {
      const res  = await campaignsApi.list()
      const list = res.data ?? res ?? []
      setRows(list)
      list.forEach(c => { if (c.status === 'processing') startPolling(c.id) })
    } catch (err) {
      toast.error('Failed to load campaigns', err.message)
    } finally {
      setLoading(false)
    }
  }

  function startPolling(id) {
    if (pollRef.current[id]) return
    pollRef.current[id] = setInterval(async () => {
      try {
        const res     = await campaignsApi.get(id)
        const updated = res.data ?? res
        setRows(prev => prev.map(r => r.id === id ? updated : r))
        if (updated.status !== 'processing') {
          clearInterval(pollRef.current[id])
          delete pollRef.current[id]
          if (updated.status === 'done') {
            toast.success('Campaign completed', `"${updated.name}" finished sending.`)
          } else if (updated.status === 'failed') {
            toast.error('Campaign failed', `"${updated.name}" encountered errors.`)
          }
        }
      } catch (_) {}
    }, 3000)
  }

  async function handleSend(id) {
    try {
      await campaignsApi.send(id)
      const res     = await campaignsApi.get(id)
      const updated = res.data ?? res
      setRows(prev => prev.map(r => r.id === id ? updated : r))
      if (updated.status === 'processing') startPolling(id)
      toast.success('Campaign queued', 'Messages are being sent to your contacts.')
    } catch (err) {
      toast.error('Failed to send campaign', err.message)
    }
  }

  async function handleUnschedule(id) {
    try {
      const res     = await campaignsApi.unschedule(id)
      const updated = res.data ?? res
      setRows(prev => prev.map(r => r.id === id ? updated : r))
      toast.success('Schedule cancelled', 'Campaign moved back to draft.')
    } catch (err) {
      toast.error('Failed to cancel schedule', err.message)
    }
  }

  function onScheduled() {
    setScheduleFor(null)
    load()
  }

  function onRetargeted(newCampaign) {
    setRetargetFor(null)
    if (newCampaign) setRows(prev => [newCampaign, ...prev])
    else load()
  }

  async function handleDelete(id) {
    try {
      await campaignsApi.del(id)
      setRows(prev => prev.filter(r => r.id !== id))
      setConfirmDelete(null)
      toast.success('Campaign deleted')
    } catch (err) {
      toast.error('Failed to delete campaign', err.message)
      setConfirmDelete(null)
    }
  }

  function onWizardDone(newCampaign) {
    setShowWizard(false)
    if (newCampaign) {
      setRows(prev => [newCampaign, ...prev])
      if (newCampaign.status === 'processing') startPolling(newCampaign.id)
    } else {
      load()
    }
  }

  // ── Computed stats ──────────────────────────────────────────────────────
  const totalCampaigns = rows.length
  const totalSent      = rows.reduce((acc, c) => acc + Number(c.sent_count ?? 0), 0)
  const totalDelivered = rows.reduce((acc, c) => acc + Number(c.delivered_count ?? 0), 0)
  const totalMessages  = rows.reduce((acc, c) => acc + Number(c.total_contacts ?? 0), 0)
  const deliveryRate   = totalSent > 0 ? Math.round((totalDelivered / totalSent) * 100) : 0
  const activeNow      = rows.filter(c => c.status === 'processing').length

  return (
    <div className="page">
      {/* ── Page Header ── */}
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', flexWrap: 'wrap', gap: '.75rem', marginBottom: '1.4rem' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>Campaigns</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Broadcast approved templates to a segment, on schedule or right now.</p>
        </div>
        <button
          onClick={() => setShowWizard(true)}
          style={{ padding: '.6rem 1.2rem', borderRadius: 10, border: 'none', background: 'var(--primary,#0a6cc4)', color: '#fff', fontWeight: 700, fontSize: 14, cursor: 'pointer', boxShadow: '0 2px 10px var(--primary-ring,rgba(10,108,196,.35))' }}
        >
          + New campaign
        </button>
      </div>

      {/* ── Stat Cards ── */}
      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: '1.5rem' }}>
        <StatCard loading={loading} icon={<Megaphone size={18} strokeWidth={1.8} />}  label="Total Campaigns"   value={totalCampaigns.toLocaleString('en-IN')} accent="#08569f" />
        <StatCard loading={loading} icon={<Send size={18} strokeWidth={1.8} />}       label="Messages Sent"     value={totalSent.toLocaleString('en-IN')}      accent="#2563eb" />
        <StatCard loading={loading} icon={<CheckCheck size={18} strokeWidth={1.8} />} label="Avg Delivery Rate" value={loading ? '—' : `${deliveryRate}%`}     accent="#16a34a" />
        <StatCard loading={loading} icon={<Zap size={18} strokeWidth={1.8} />}        label="Active Now"        value={activeNow.toLocaleString('en-IN')}      accent="#f59e0b" />
      </div>

      {/* ── Campaign Grid ── */}
      {loading ? (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(340px, 1fr))', gap: '1rem' }}>
          {[1, 2, 3, 4].map(n => <SkeletonCard key={n} />)}
        </div>
      ) : rows.length === 0 ? (
        <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14 }}>
          <EmptyState onNew={() => setShowWizard(true)} />
        </div>
      ) : (
        <div style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fill, minmax(340px, 1fr))',
          gap: '1rem',
        }}>
          {rows.map(c => (
            <CampaignCard
              key={c.id}
              campaign={c}
              templates={templates}
              onSend={handleSend}
              onDelete={handleDelete}
              onSchedule={setScheduleFor}
              onUnschedule={handleUnschedule}
              onRetarget={setRetargetFor}
              confirmDelete={confirmDelete}
              setConfirmDelete={setConfirmDelete}
            />
          ))}
        </div>
      )}

      {/* ── Wizard Modal ── */}
      {showWizard && (
        <CampaignWizard
          onDone={onWizardDone}
          onCancel={() => setShowWizard(false)}
        />
      )}

      {/* ── Schedule Modal ── */}
      {scheduleFor && (
        <ScheduleModal
          campaign={scheduleFor}
          onSave={onScheduled}
          onCancel={() => setScheduleFor(null)}
        />
      )}

      {/* ── Retarget Modal ── */}
      {retargetFor && (
        <RetargetModal
          campaign={retargetFor}
          onDone={onRetargeted}
          onCancel={() => setRetargetFor(null)}
        />
      )}
    </div>
  )
}
