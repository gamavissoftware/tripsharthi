import { useState, useEffect } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { MessageCircle, Zap, Loader, X } from 'lucide-react'
import { contacts as contactsApi } from '../api/contacts'
import { tags as tagsApi } from '../api/tags'
import { api } from '../api/client'
import { toast } from '../components/Toast'
import { TimelinePanel, NotesPanel, TasksPanel, AssociationsPanel } from '../components/crm/RecordPanels'
import QuotationsPanel from '../components/crm/QuotationsPanel'
import DocumentsPanel from '../components/crm/DocumentsPanel'
import AiScriptPanel from '../components/crm/AiScriptPanel'
import EmailPanel from '../components/crm/EmailPanel'
import { TierBadge } from '../components/CrmListView'
import LoadFailed from '../components/LoadFailed'

const SCORE_LABELS = { lifecycle: 'Lifecycle stage', source: 'Source quality', email: 'Has email', account: 'Has account', deal: 'Linked deal value', engagement: 'WhatsApp engagement', tasks: 'Task completion', decay: 'Recency decay' }

function LeadScoreCard({ contact }) {
  let breakdown = {}
  try { breakdown = typeof contact.score_breakdown === 'string' ? JSON.parse(contact.score_breakdown || '{}') : (contact.score_breakdown || {}) } catch { breakdown = {} }
  const entries = Object.entries(breakdown).filter(([, v]) => v !== 0)
  if (contact.score_tier == null && entries.length === 0) return null
  return (
    <div className="lp-cd-card">
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: entries.length ? '.8rem' : 0 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <span style={{ fontSize: 13, fontWeight: 800, color: '#111827' }}>Lead score</span>
          <TierBadge tier={contact.score_tier} />
        </div>
        <span style={{ fontSize: 22, fontWeight: 800, color: '#111827' }}>{contact.lead_score ?? 0}<span style={{ fontSize: 13, color: '#9ca3af', fontWeight: 600 }}>/100</span></span>
      </div>
      {entries.length > 0 && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
          {entries.map(([k, v]) => (
            <div key={k} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 12.5 }}>
              <span style={{ color: '#6b7280' }}>{SCORE_LABELS[k] ?? k}</span>
              <span style={{ fontWeight: 700, color: v < 0 ? '#b91c1c' : '#15803d' }}>{v > 0 ? '+' : ''}{v}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

// ── Inject styles once ──────────────────────────────────────────────────────
if (typeof document !== 'undefined' && !document.getElementById('lp-cd-css')) {
  const el = document.createElement('style')
  el.id = 'lp-cd-css'
  el.textContent = `
    @keyframes lp-cd-in { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
    .lp-cd-card { background:#fff; border:1px solid #e5e7eb; border-radius:18px; padding:1.4rem 1.5rem; margin-bottom:1.25rem; animation:lp-cd-in .25s ease both; }
    .lp-cd-label { font-size:12.5px; font-weight:700; color:#374151; margin-bottom:.35rem; display:block; }
    .lp-cd-input, .lp-cd-select {
      width:100%; padding:.6rem .8rem; border-radius:10px; border:1.5px solid #e5e7eb; background:#f9fafb;
      font-size:14px; color:#111827; outline:none; box-sizing:border-box; transition:border-color .15s, background .15s;
    }
    .lp-cd-select { background:#fff; cursor:pointer; }
    .lp-cd-input:focus, .lp-cd-select:focus { border-color:var(--primary,#0a6cc4); background:#fff; }
    .lp-cd-input.invalid { border-color:#fca5a5; background:#fef2f2; }
    .lp-cd-err { font-size:12px; color:#dc2626; margin-top:.3rem; }
  `
  document.head.appendChild(el)
}

const STATUS = {
  new:       { label: 'New',       bg: '#e8f3fc', color: '#08569f', dot: '#0a6cc4' },
  contacted: { label: 'Contacted', bg: '#fef9c3', color: '#a16207', dot: '#d97706' },
  qualified: { label: 'Qualified', bg: '#dbeafe', color: '#1d4ed8', dot: '#2563eb' },
  won:       { label: 'Won',       bg: '#dcfce7', color: '#15803d', dot: '#16a34a' },
  lost:      { label: 'Lost',      bg: '#fee2e2', color: '#b91c1c', dot: '#dc2626' },
}
const STATUSES = ['new', 'contacted', 'qualified', 'won', 'lost']
const LIFECYCLE = ['subscriber', 'lead', 'mql', 'sql', 'opportunity', 'customer', 'evangelist', 'other']
const LIFECYCLE_LABEL = { subscriber: 'Subscriber', lead: 'Lead', mql: 'MQL', sql: 'SQL', opportunity: 'Opportunity', customer: 'Customer', evangelist: 'Evangelist', other: 'Other' }
const RUN_STATUS = { running: { bg: '#e0e7ff', color: '#3730a3' }, completed: { bg: '#d1fae5', color: '#065f46' }, stopped: { bg: '#fee2e2', color: '#991b1b' } }

const fmtDate = (dt) => { if (!dt) return '—'; try { return new Date(dt).toLocaleString() } catch { return dt } }
const initials = (name, num) => (name || num || '#').trim().split(/\s+/).map(w => w[0]).join('').slice(0, 2).toUpperCase()

function validate(f) {
  const e = {}
  const hasWa = !!f.wa_number?.trim()
  const hasEmail = !!f.email?.trim()
  // A contact needs at least one identity: WhatsApp number or email.
  if (!hasWa && !hasEmail) {
    e.wa_number = 'Provide a WhatsApp number or an email'
  } else if (hasWa && !/^\+?[1-9]\d{7,14}$/.test(f.wa_number.replace(/\s/g, ''))) {
    e.wa_number = 'Enter a valid number with country code (e.g. +919999900000)'
  }
  if (hasEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email)) e.email = 'Enter a valid email address'
  return e
}

/**
 * One labelled read-only fact in the profile panel. Renders nothing when the
 * value is blank, so the panel stays dense instead of a wall of dashes.
 */
function Fact({ label, value }) {
  const v = typeof value === 'string' ? value.trim() : value
  if (v === null || v === undefined || v === '') return null
  return (
    <div style={{ minWidth: 0 }}>
      <div style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em', color: '#9ca3af', fontWeight: 700 }}>{label}</div>
      <div style={{ fontSize: 13.5, color: '#111827', marginTop: 2, wordBreak: 'break-word' }}>{v}</div>
    </div>
  )
}

function SectionTitle({ children }) {
  return <h3 style={{ fontSize: 15, fontWeight: 800, color: '#111827', margin: '0 0 .9rem' }}>{children}</h3>
}
function MiniEmpty({ icon, text }) {
  return <div style={{ textAlign: 'center', padding: '1.4rem 0', color: '#9ca3af' }}><div style={{ fontSize: 26, marginBottom: 4 }}>{icon}</div><div style={{ fontSize: 13.5 }}>{text}</div></div>
}

export default function ContactDetailPage() {
  const { id }   = useParams()
  const navigate = useNavigate()
  const isNew    = id === 'new'

  const [contact, setContact] = useState(null)
  const [tagList, setTagList] = useState([])
  const [form, setForm]       = useState({
    wa_number: '', name: '', email: '', language: '', status: 'new', lifecycle_stage: 'lead', job_title: '', company: '',
    phone_secondary: '', city: '', state: '', country: '', business_type: '', requirement_type: '',
    current_process: '', budget_amount: '', timeline: '', qualification_status: '', priority: 'medium', remarks: '',
    source: 'manual', owner_id: '',
  })
  const [agents, setAgents] = useState([])
  const [accountList, setAccountList] = useState([])
  const [errors, setErrors]   = useState({})
  const [saving, setSaving]   = useState(false)
  const [loading, setLoading] = useState(!isNew)
  const [loadErr, setLoadErr] = useState('')
  const [confirmDel, setConfirmDel] = useState(false)

  const [timelineKey, setTimelineKey] = useState(0)

  const [flowRuns, setFlowRuns] = useState([])
  const [flowRunsLoading, setFlowRunsLoading] = useState(false)
  const [flowRunsError, setFlowRunsError] = useState(null)

  useEffect(() => {
    tagsApi.list().then(r => setTagList(r.data ?? []))
    api.get('/team').then(r => setAgents(r.data ?? [])).catch(() => {})
    if (!isNew) {
      contactsApi.get(id)
        .then(r => {
          const c = r.data ?? {}
          setContact(c)
          setForm({
            wa_number: c.wa_number ?? '', name: c.name ?? '', email: c.email ?? '', language: c.language ?? '',
            status: c.status ?? 'new', lifecycle_stage: c.lifecycle_stage ?? 'lead', job_title: c.job_title ?? '',
            company: c.company_name ?? '',
            phone_secondary: c.phone_secondary ?? '', city: c.city ?? '', state: c.state ?? '', country: c.country ?? '',
            business_type: c.business_type ?? '', requirement_type: c.requirement_type ?? '', current_process: c.current_process ?? '',
            budget_amount: c.budget_amount ?? '', timeline: c.timeline ?? '', qualification_status: c.qualification_status ?? '',
            priority: c.priority ?? 'medium', remarks: c.remarks ?? '',
            source: c.source ?? 'manual', owner_id: c.owner_id ?? '',
          })
          setLoading(false)
        })
        .catch(e => { setLoadErr(e?.message || 'Not found'); setLoading(false) })
    }
  }, [id])

  // Company suggestions are searched, not preloaded: /accounts caps at 500 rows
  // and a tenant can have thousands, so an eager list would weigh down every
  // page load AND still omit most companies. Typing is free-text either way —
  // the backend matches an existing name exactly, so a company missing from
  // these suggestions still links rather than duplicating.
  useEffect(() => {
    const term = (form.company ?? '').trim()
    // Everything runs inside the timer, including the clear, so nothing sets
    // state synchronously while the effect body executes.
    const timer = setTimeout(() => {
      if (term.length < 2) {
        setAccountList([])
        return
      }
      api.get('/accounts?q=' + encodeURIComponent(term))
        .then(r => setAccountList(r.data ?? []))
        .catch(() => {})
    }, 250)
    return () => clearTimeout(timer)
  }, [form.company])

  const [msgOpen, setMsgOpen] = useState(false)
  function openMessage() { setMsgOpen(true) }

  // Flow activity
  useEffect(() => {
    if (isNew || !contact) return
    const contactId = parseInt(id, 10)
    setFlowRunsLoading(true); setFlowRunsError(null)
    api.get('/analytics/flows')
      .then(async r => {
        const flows = r.data ?? []
        if (!Array.isArray(flows) || flows.length === 0) { setFlowRuns([]); setFlowRunsLoading(false); return }
        const allRuns = []
        await Promise.allSettled(flows.map(f =>
          api.get('/flows/' + f.id + '/runs').then(rr => {
            (rr.data ?? []).forEach(run => {
              if (parseInt(run.contact_id, 10) === contactId) allRuns.push({ ...run, flow_name: f.name, flow_status: f.status })
            })
          })
        ))
        allRuns.sort((a, b) => new Date(b.created_at ?? 0) - new Date(a.created_at ?? 0))
        setFlowRuns(allRuns)
      })
      .catch(err => setFlowRunsError(err.message ?? 'Failed to load flow activity.'))
      .finally(() => setFlowRunsLoading(false))
  }, [contact, isNew, id])

  async function save(e) {
    e.preventDefault()
    const errs = validate(form)
    setErrors(errs)
    if (Object.keys(errs).length > 0) { toast.error('Please fix the highlighted fields'); return }
    setSaving(true)
    try {
      if (isNew) {
        await contactsApi.create(form)
        toast.success('Contact created', form.name || form.wa_number)
        navigate('/contacts')
      } else {
        await contactsApi.update(id, form)
        toast.success('Contact updated', 'Changes saved.')
        setContact((await contactsApi.get(id)).data)
      }
    } catch (err) { toast.error('Save failed', err.message ?? 'Please try again') }
    finally { setSaving(false) }
  }

  async function attachTag(tagId) {
    try {
      await contactsApi.attachTag(id, tagId)
      setContact((await contactsApi.get(id)).data)
      toast.success('Tag added', tagList.find(t => t.id === tagId)?.name)
    } catch (err) { toast.error('Failed to add tag', err.message) }
  }
  async function detachTag(tagId) {
    try {
      await contactsApi.detachTag(id, tagId)
      setContact((await contactsApi.get(id)).data)
      toast.success('Tag removed', tagList.find(t => t.id === tagId)?.name)
    } catch (err) { toast.error('Failed to remove tag', err.message) }
  }
  async function del() {
    try { await contactsApi.remove(id); toast.success('Contact deleted'); navigate('/contacts') }
    catch (err) { toast.error('Delete failed', err.message) }
  }
  // Quick lead-stage transitions (the reference's Mark Converted / Next / Mark Lost).
  async function setLeadStatus(status) {
    try {
      await contactsApi.update(id, { status })
      setForm(f => ({ ...f, status }))
      setContact((await contactsApi.get(id)).data)
      toast.success('Lead updated', STATUS[status]?.label ?? status)
    } catch (err) { toast.error('Update failed', err.message) }
  }

  if (loadErr && !isNew) return <LoadFailed what="contact" message={loadErr} backTo="/contacts" backLabel="Back to contacts" />
  if (loading) {
    return <div className="page" style={{ maxWidth: 760 }}><div className="lp-cd-card" style={{ height: 200, background: '#f3f4f6', border: 'none' }} /></div>
  }

  const contactTags   = contact?.tags ?? []
  const tagIds        = new Set(contactTags.map(t => t.id))
  const availableTags = tagList.filter(t => !tagIds.has(t.id))
  const st            = STATUS[form.status] ?? STATUS.new

  const nextStatus = { new: 'contacted', contacted: 'qualified', qualified: 'won' }[form.status]

  return (
    <div className="page" style={{ maxWidth: 1080 }}>
      {/* Breadcrumb */}
      <div style={{ display: 'flex', alignItems: 'center', gap: '.4rem', fontSize: 13, marginBottom: '1.1rem' }}>
        <Link to="/contacts" style={{ color: 'var(--primary,#0a6cc4)', textDecoration: 'none', fontWeight: 600 }}>← Contacts</Link>
        <span style={{ color: '#d1d5db' }}>/</span>
        <span style={{ color: '#6b7280' }}>{isNew ? 'New contact' : (contact?.name || contact?.wa_number || `#${id}`)}</span>
      </div>

      {/* Identity hero (existing only) */}
      {!isNew && contact && (
        <div className="lp-cd-card" style={{ display: 'flex', alignItems: 'center', gap: '1rem', background: 'linear-gradient(135deg,#f8fafc,#e8f3fc)' }}>
          <div style={{ width: 56, height: 56, borderRadius: 16, flexShrink: 0, background: 'var(--primary,#0a6cc4)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 22, fontWeight: 800, boxShadow: '0 6px 18px var(--primary-ring,rgba(10,108,196,.35))' }}>
            {initials(contact.name, contact.wa_number)}
          </div>
          <div className="lp-cd-main" style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 18, fontWeight: 800, color: '#111827' }}>{contact.name || '—'}</div>
            {(contact.job_title || contact.company_name) && (
              <div style={{ fontSize: 13.5, color: '#374151', marginTop: 2 }}>
                {contact.job_title}
                {contact.job_title && contact.company_name && <span style={{ color: '#9ca3af' }}> · </span>}
                {contact.company_name && <span style={{ fontWeight: 700 }}>{contact.company_name}</span>}
              </div>
            )}
            <div style={{ fontSize: 13, color: '#6b7280', fontFamily: 'monospace', marginTop: 2 }}>{contact.wa_number}</div>
            {(contact.business_type || contact.company_industry) && (
              <div style={{ marginTop: 6 }}>
                <span style={{ display: 'inline-block', padding: '.15rem .55rem', borderRadius: 999, fontSize: 11.5, fontWeight: 700, background: '#e0e7ff', color: '#074a8c' }}>
                  {contact.business_type || contact.company_industry}
                </span>
              </div>
            )}
          </div>
          <div className="lp-cd-side" style={{ textAlign: 'right', display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 8 }}>
            <button type="button" onClick={openMessage}
              style={{ padding: '.5rem 1rem', borderRadius: 10, border: 'none', background: '#16a34a', color: '#fff', fontWeight: 700, fontSize: 13.5, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6, boxShadow: '0 4px 12px rgba(22,163,74,.3)' }}>
              <MessageCircle size={15} strokeWidth={2} /> Send message
            </button>
            <div>
              <span style={{ padding: '.25rem .7rem', borderRadius: 999, fontSize: 12, fontWeight: 700, background: st.bg, color: st.color }}>{st.label}</span>
              {contact.source && <div style={{ fontSize: 11.5, color: '#9ca3af', marginTop: 5, textTransform: 'capitalize' }}>{contact.source.replace(/_/g, ' ')}</div>}
            </div>
          </div>
        </div>
      )}

      {/* Profile at a glance — the read-only detail the edit form below buries
          in inputs. Blank facts render nothing, so this stays dense. */}
      {!isNew && contact && (() => {
        const location = [contact.city, contact.state, contact.country].filter(Boolean).join(', ')
        const owner    = agents.find(a => String(a.id) === String(contact.owner_id))
        const facts    = [
          ['Email',            contact.email],
          ['Alternate number', contact.phone_secondary],
          ['Location',         location],
          ['Language',         contact.language],
          ['Lifecycle stage',  contact.lifecycle_stage],
          ['Owner',            owner?.name],
          ['Added',            contact.created_at?.slice(0, 10)],
          ['Last inbound',     contact.last_inbound_at?.slice(0, 16).replace('T', ' ')],
          ['Notes',            contact.remarks],
        ].filter(([, v]) => v !== null && v !== undefined && String(v).trim() !== '')

        if (facts.length === 0) return null

        return (
          <div className="lp-cd-card">
            <SectionTitle>Profile</SectionTitle>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(170px,1fr))', gap: '.9rem 1.5rem' }}>
              {facts.map(([label, value]) => <Fact key={label} label={label} value={value} />)}
            </div>
          </div>
        )
      })()}

      {/* Lead stage actions (reference: Mark Converted / Next / Mark Lost) */}
      {!isNew && contact && form.status !== 'won' && form.status !== 'lost' && (
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: '1rem' }}>
          <button type="button" onClick={() => setLeadStatus('won')}
            style={{ padding: '.5rem 1rem', borderRadius: 10, border: 'none', background: '#08569f', color: '#fff', fontWeight: 700, fontSize: 13.5, cursor: 'pointer' }}>✓ Mark Converted</button>
          {nextStatus && (
            <button type="button" onClick={() => setLeadStatus(nextStatus)}
              style={{ padding: '.5rem 1rem', borderRadius: 10, border: '1.5px solid #bcdcf6', background: '#e8f3fc', color: '#08569f', fontWeight: 700, fontSize: 13.5, cursor: 'pointer' }}>» Next: {STATUS[nextStatus]?.label}</button>
          )}
          <button type="button" onClick={() => setLeadStatus('lost')}
            style={{ padding: '.5rem 1rem', borderRadius: 10, border: '1.5px solid #fde68a', background: '#fffbeb', color: '#b45309', fontWeight: 700, fontSize: 13.5, cursor: 'pointer' }}>✕ Mark Lost</button>
        </div>
      )}

      {contact && <LeadScoreCard contact={contact} />}

      {msgOpen && (
        <SendMessageModal
          contact={contact}
          onClose={() => setMsgOpen(false)}
          onSent={() => { setMsgOpen(false); setTimelineKey(k => k + 1); }}
          navigate={navigate}
        />
      )}

      {/* Edit form */}
      <div className="lp-stack-mobile" style={{ display: 'grid', gridTemplateColumns: !isNew && contact ? 'minmax(0,1.7fr) minmax(0,1fr)' : '1fr', gap: 16, alignItems: 'start' }}>
      <form onSubmit={save} className="lp-cd-card">
        <SectionTitle>{isNew ? 'New contact' : 'Edit contact'}</SectionTitle>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: '1rem', marginBottom: '1.1rem' }}>
          <div>
            <label className="lp-cd-label">WhatsApp number *</label>
            <input className={`lp-cd-input ${errors.wa_number ? 'invalid' : ''}`} value={form.wa_number} onChange={e => { setForm(f => ({ ...f, wa_number: e.target.value })); if (errors.wa_number) setErrors(x => ({ ...x, wa_number: '' })) }} placeholder="+919999900000" />
            {errors.wa_number && <div className="lp-cd-err">{errors.wa_number}</div>}
          </div>
          <div>
            <label className="lp-cd-label">Full name</label>
            <input className="lp-cd-input" value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} placeholder="e.g. Priya Mehta" />
          </div>
          <div>
            <label className="lp-cd-label">Email</label>
            <input type="email" className={`lp-cd-input ${errors.email ? 'invalid' : ''}`} value={form.email} onChange={e => { setForm(f => ({ ...f, email: e.target.value })); if (errors.email) setErrors(x => ({ ...x, email: '' })) }} placeholder="email@example.com" />
            {errors.email && <div className="lp-cd-err">{errors.email}</div>}
          </div>
          <div>
            <label className="lp-cd-label">Language</label>
            <input className="lp-cd-input" value={form.language ?? ''} onChange={e => setForm(f => ({ ...f, language: e.target.value }))} placeholder="e.g. en, hi, en_US" />
            <div style={{ fontSize: 11, color: 'var(--text-3)', marginTop: 4 }}>Template sends auto-pick this language version when available.</div>
          </div>
          <div>
            <label className="lp-cd-label">Status</label>
            <div style={{ position: 'relative' }}>
              <span style={{ position: 'absolute', left: '.75rem', top: '50%', transform: 'translateY(-50%)', width: 9, height: 9, borderRadius: '50%', background: st.dot, pointerEvents: 'none' }} />
              <select className="lp-cd-select" style={{ paddingLeft: '1.85rem' }} value={form.status} onChange={e => setForm(f => ({ ...f, status: e.target.value }))}>
                {STATUSES.map(v => <option key={v} value={v}>{STATUS[v].label}</option>)}
              </select>
            </div>
          </div>
          <div>
            <label className="lp-cd-label">Lifecycle stage</label>
            <select className="lp-cd-select" value={form.lifecycle_stage} onChange={e => setForm(f => ({ ...f, lifecycle_stage: e.target.value }))}>
              {LIFECYCLE.map(v => <option key={v} value={v}>{LIFECYCLE_LABEL[v]}</option>)}
            </select>
          </div>
          <div>
            <label className="lp-cd-label">Company</label>
            <input
              className="lp-cd-input"
              list="lp-company-options"
              value={form.company ?? ''}
              onChange={e => setForm(f => ({ ...f, company: e.target.value }))}
              placeholder="Type to link or create"
            />
            {/* Free text with suggestions: picking an existing company links it,
                typing a new name creates it on save. Clearing the field unlinks. */}
            <datalist id="lp-company-options">
              {accountList.map(a => <option key={a.id} value={a.name} />)}
            </datalist>
            <div style={{ fontSize: 11.5, color: '#9ca3af', marginTop: 4 }}>
              A new name creates a company; clearing this unlinks it.
            </div>
          </div>
          <div>
            <label className="lp-cd-label">Job title</label>
            <input className="lp-cd-input" value={form.job_title ?? ''} onChange={e => setForm(f => ({ ...f, job_title: e.target.value }))} placeholder="e.g. Head of Ops" />
          </div>
          <div>
            <label className="lp-cd-label">Alternate mobile</label>
            <input className="lp-cd-input" value={form.phone_secondary ?? ''} onChange={e => setForm(f => ({ ...f, phone_secondary: e.target.value }))} placeholder="Optional secondary phone" />
          </div>
        </div>

        {/* Business & qualification — makes the lead useful across industries */}
        <div style={{ borderTop: '1px solid #f0f1f3', marginTop: '1.1rem', paddingTop: '1rem' }}>
          <div style={{ fontSize: 12.5, fontWeight: 800, letterSpacing: '.04em', textTransform: 'uppercase', color: '#6b7280', marginBottom: '.85rem' }}>Business &amp; Qualification</div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '.85rem' }}>
            <div>
              <label className="lp-cd-label">Priority</label>
              <select className="lp-cd-select" value={form.priority} onChange={e => setForm(f => ({ ...f, priority: e.target.value }))}>
                <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
              </select>
            </div>
            <div>
              <label className="lp-cd-label">Lead source</label>
              <select className="lp-cd-select" value={form.source} onChange={e => setForm(f => ({ ...f, source: e.target.value }))}>
                {['manual', 'web_form', 'meta_lead_ads', 'google_lead_forms', 'whatsapp_inbound', 'email_inbound', 'csv_import', 'shopify', 'woocommerce']
                  .map(s => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
              </select>
            </div>
            <div>
              <label className="lp-cd-label">Assign to agent</label>
              <select className="lp-cd-select" value={form.owner_id ?? ''} onChange={e => setForm(f => ({ ...f, owner_id: e.target.value }))}>
                <option value="">Unassigned</option>
                {agents.map(a => <option key={a.id} value={a.id}>{a.name} ({a.role})</option>)}
              </select>
            </div>
            <div>
              <label className="lp-cd-label">Qualification status</label>
              <input className="lp-cd-input" value={form.qualification_status ?? ''} onChange={e => setForm(f => ({ ...f, qualification_status: e.target.value }))} placeholder="e.g. Qualified, Nurturing" />
            </div>
            <div>
              <label className="lp-cd-label">Business type</label>
              <input className="lp-cd-input" value={form.business_type ?? ''} onChange={e => setForm(f => ({ ...f, business_type: e.target.value }))} placeholder="e.g. Retail, Healthcare" />
            </div>
            <div>
              <label className="lp-cd-label">Requirement type</label>
              <input className="lp-cd-input" value={form.requirement_type ?? ''} onChange={e => setForm(f => ({ ...f, requirement_type: e.target.value }))} placeholder="e.g. CRM migration" />
            </div>
            <div>
              <label className="lp-cd-label">Budget</label>
              <input type="number" className="lp-cd-input" value={form.budget_amount ?? ''} onChange={e => setForm(f => ({ ...f, budget_amount: e.target.value }))} placeholder="Available funds" />
            </div>
            <div>
              <label className="lp-cd-label">Timeline</label>
              <input className="lp-cd-input" value={form.timeline ?? ''} onChange={e => setForm(f => ({ ...f, timeline: e.target.value }))} placeholder="e.g. Immediate, 6 months" />
            </div>
            <div>
              <label className="lp-cd-label">City</label>
              <input className="lp-cd-input" value={form.city ?? ''} onChange={e => setForm(f => ({ ...f, city: e.target.value }))} placeholder="City" />
            </div>
            <div>
              <label className="lp-cd-label">State</label>
              <input className="lp-cd-input" value={form.state ?? ''} onChange={e => setForm(f => ({ ...f, state: e.target.value }))} placeholder="State" />
            </div>
            <div>
              <label className="lp-cd-label">Country</label>
              <input className="lp-cd-input" value={form.country ?? ''} onChange={e => setForm(f => ({ ...f, country: e.target.value }))} placeholder="Country" />
            </div>
            <div style={{ gridColumn: '1 / 4' }}>
              <label className="lp-cd-label">Current process / software</label>
              <input className="lp-cd-input" value={form.current_process ?? ''} onChange={e => setForm(f => ({ ...f, current_process: e.target.value }))} placeholder="What they use today, if any" />
            </div>
            <div style={{ gridColumn: '1 / 4' }}>
              <label className="lp-cd-label">Remarks / requirement details</label>
              <textarea className="lp-cd-input" rows={3} value={form.remarks ?? ''} onChange={e => setForm(f => ({ ...f, remarks: e.target.value }))} placeholder="Context notes regarding the lead's requirements" />
            </div>
          </div>
        </div>

        <div style={{ display: 'flex', gap: '.7rem', alignItems: 'center', borderTop: '1px solid #f0f1f3', paddingTop: '1.1rem', marginTop: '1.1rem' }}>
          <button type="submit" disabled={saving} style={{ padding: '.6rem 1.3rem', borderRadius: 10, border: 'none', background: saving ? '#c7cdd6' : 'var(--primary,#0a6cc4)', color: '#fff', fontWeight: 700, fontSize: 14, cursor: saving ? 'not-allowed' : 'pointer' }}>
            {saving ? 'Saving…' : isNew ? 'Create contact' : 'Save changes'}
          </button>
          {!isNew && (confirmDel ? (
            <span style={{ display: 'inline-flex', gap: 6, alignItems: 'center' }}>
              <span style={{ fontSize: 13, color: '#dc2626', fontWeight: 600 }}>Delete permanently?</span>
              <button type="button" onClick={del} style={{ padding: '.5rem .9rem', borderRadius: 9, border: 'none', background: '#dc2626', color: '#fff', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>Yes, delete</button>
              <button type="button" onClick={() => setConfirmDel(false)} style={{ padding: '.5rem .9rem', borderRadius: 9, border: '1.5px solid #e5e7eb', background: '#fff', color: '#374151', fontWeight: 600, fontSize: 13, cursor: 'pointer' }}>No</button>
            </span>
          ) : (
            <button type="button" onClick={() => setConfirmDel(true)} style={{ padding: '.6rem 1rem', borderRadius: 10, border: '1.5px solid #fecaca', background: '#fff', color: '#dc2626', fontWeight: 600, fontSize: 14, cursor: 'pointer' }}>Delete</button>
          ))}
          <Link to="/contacts" style={{ marginLeft: 'auto', padding: '.6rem 1rem', borderRadius: 10, border: '1.5px solid #e5e7eb', background: '#fff', color: '#374151', fontWeight: 600, fontSize: 14, textDecoration: 'none' }}>Cancel</Link>
        </div>
      </form>
      {!isNew && contact && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <NotesPanel relatedType="contact" relatedId={contact.id} />
          <TimelinePanel relatedType="contact" relatedId={contact.id} refreshKey={timelineKey} />
        </div>
      )}
      </div>

      {/* Tags */}
      {!isNew && (
        <div className="lp-cd-card">
          <SectionTitle>Tags</SectionTitle>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, alignItems: 'center', marginBottom: contactTags.length || availableTags.length ? '.85rem' : 0, minHeight: 24 }}>
            {contactTags.map(t => (
              <span key={t.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '.2rem .6rem', borderRadius: 999, fontSize: 12, fontWeight: 600, background: (t.color ?? '#0a6cc4') + '22', color: t.color ?? '#0a6cc4' }}>
                {t.name}
                <button onClick={() => detachTag(t.id)} title={`Remove ${t.name}`} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'inherit', fontSize: 13, lineHeight: 1, padding: 0, opacity: .7 }}>×</button>
              </span>
            ))}
            {contactTags.length === 0 && <span style={{ color: '#9ca3af', fontSize: 13.5 }}>No tags assigned yet.</span>}
          </div>
          {availableTags.length > 0 && (
            <select className="lp-cd-select" style={{ maxWidth: 240 }} value="" onChange={e => { if (e.target.value) attachTag(+e.target.value) }}>
              <option value="">+ Add tag…</option>
              {availableTags.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
          )}
        </div>
      )}

      {/* CRM record panels — tasks, notes, and the unified timeline
          (which merges the WhatsApp thread with logged activities) */}
      {!isNew && contact && (
        <>
          <TasksPanel relatedType="contact" relatedId={contact.id} />
          <QuotationsPanel contactId={contact.id} />
          <DocumentsPanel relatedType="contact" relatedId={contact.id} />
          <EmailPanel contactId={contact.id} onSent={() => setTimelineKey(k => k + 1)} />
          <AiScriptPanel contactId={contact.id} />
          <AssociationsPanel relatedType="contact" relatedId={contact.id} />
        </>
      )}

      {/* Flow activity */}
      {!isNew && (
        <div className="lp-cd-card">
          <SectionTitle>Flow activity</SectionTitle>
          {flowRunsLoading ? <MiniEmpty icon={<Loader size={26} strokeWidth={1.4} />} text="Loading flow activity…" />
            : flowRunsError ? <div style={{ color: '#dc2626', fontSize: 13.5 }}>{flowRunsError}</div>
            : flowRuns.length === 0 ? <MiniEmpty icon={<Zap size={26} strokeWidth={1.4} />} text="Not enrolled in any flows" />
            : (
              <div style={{ overflowX: 'auto' }}>
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13.5, minWidth: 480 }}>
                  <thead>
                    <tr style={{ textAlign: 'left', color: '#6b7280', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em', borderBottom: '1px solid #f0f1f3' }}>
                      <th style={{ padding: '.5rem .6rem', fontWeight: 700 }}>Flow</th>
                      <th style={{ padding: '.5rem .6rem', fontWeight: 700 }}>Status</th>
                      <th style={{ padding: '.5rem .6rem', fontWeight: 700 }}>Started</th>
                      <th style={{ padding: '.5rem .6rem', fontWeight: 700 }}>Last activity</th>
                    </tr>
                  </thead>
                  <tbody>
                    {flowRuns.map((run, idx) => {
                      const rs = RUN_STATUS[run.status] ?? { bg: '#f3f4f6', color: '#374151' }
                      return (
                        <tr key={run.id ?? idx} style={{ borderBottom: '1px solid #f6f7f9' }}>
                          <td style={{ padding: '.55rem .6rem', color: '#111827', fontWeight: 600 }}>{run.flow_name ?? '—'}</td>
                          <td style={{ padding: '.55rem .6rem' }}><span style={{ fontSize: 11.5, padding: '2px 8px', borderRadius: 999, fontWeight: 700, background: rs.bg, color: rs.color }}>{run.status ?? '—'}</span></td>
                          <td style={{ padding: '.55rem .6rem', color: '#6b7280', fontSize: 12.5 }}>{fmtDate(run.created_at)}</td>
                          <td style={{ padding: '.55rem .6rem', color: '#6b7280', fontSize: 12.5 }}>{fmtDate(run.updated_at ?? run.last_run_at)}</td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
        </div>
      )}
    </div>
  )
}

// Template `variables` can be a JSON array, a double-encoded string, null, or junk — never throw.
function parseTplVars(raw) {
  let v = raw
  for (let i = 0; i < 3 && typeof v === 'string'; i++) {
    try { v = JSON.parse(v) } catch { return [] }
  }
  return Array.isArray(v) ? v.map(x => (typeof x === 'string' || typeof x === 'number') ? String(x) : (x?.name ? String(x.name) : '')) : []
}

// ── Send Message modal: template send (WhatsApp-compliant first contact) ──────
function SendMessageModal({ contact, onClose, onSent, navigate }) {
  const [info, setInfo]         = useState(null)   // { window_open, conversation_id }
  const [templates, setTemplates] = useState([])
  const [tplId, setTplId]       = useState('')
  const [vars, setVars]         = useState([])
  const [sending, setSending]   = useState(false)

  useEffect(() => {
    contactsApi.messaging(contact.id).then(r => setInfo(r.data)).catch(() => setInfo({ window_open: false }))
    api.get('/templates?meta_status=approved').then(r => setTemplates(Array.isArray(r.data) ? r.data : [])).catch(() => {})
  }, [contact.id])

  const tpl = templates.find(t => String(t.id) === String(tplId))
  const tplVars = tpl ? parseTplVars(tpl.variables) : []

  function pickTemplate(id) {
    setTplId(id)
    const t = templates.find(x => String(x.id) === String(id))
    const tv = t ? parseTplVars(t.variables) : []
    // prefill {{1}} with the contact's name
    setVars(tv.map((_, i) => (i === 0 ? (contact.name || '') : '')))
  }

  const allVarsFilled = tplVars.every((_, i) => String(vars[i] ?? '').trim() !== '')

  async function send() {
    if (!tplId) { toast.error('Pick a template'); return }
    if (!allVarsFilled) { toast.error('Fill in all template variables', 'Every {{n}} placeholder needs a value.'); return }
    setSending(true)
    try {
      await contactsApi.sendTemplate(contact.id, { template_id: Number(tplId), variables: vars })
      toast.success('Message sent', `Template sent to ${contact.name || contact.wa_number}.`)
      onSent()
    } catch (err) {
      toast.error('Could not send', err?.message)
      setSending(false)
    }
  }

  function backdrop(e) { if (e.target === e.currentTarget) onClose() }

  return (
    <div onClick={backdrop} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)', zIndex: 1000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
      <div style={{ background: '#fff', borderRadius: 16, maxWidth: 460, width: '100%', padding: '1.5rem', maxHeight: '88vh', overflow: 'auto' }}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 12 }}>
          <h2 style={{ margin: 0, fontSize: 18, fontWeight: 800 }}>Send message</h2>
          <button onClick={onClose} style={{ background: 'none', border: 'none', display: 'flex', alignItems: 'center', cursor: 'pointer', color: '#9ca3af' }}><X size={18} strokeWidth={2} /></button>
        </div>
        <div style={{ fontSize: 13, color: '#6b7280', marginBottom: 14 }}>To {contact.name || contact.wa_number}</div>

        {/* Window status + inbox shortcut */}
        {info?.window_open && (
          <div style={{ background: '#f0fdf4', border: '1px solid #bbf7d0', borderRadius: 10, padding: '10px 12px', marginBottom: 14, fontSize: 13, color: '#166534' }}>
            The 24-hour window is open — you can also{' '}
            <a onClick={() => navigate('/inbox')} style={{ color: '#15803d', fontWeight: 700, cursor: 'pointer', textDecoration: 'underline' }}>reply free-form in the Inbox →</a>
          </div>
        )}
        {info && !info.window_open && (
          <div style={{ background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 10, padding: '10px 12px', marginBottom: 14, fontSize: 12.5, color: '#92400e' }}>
            This contact hasn't messaged you (or the 24h window is closed), so WhatsApp only allows an <strong>approved template</strong>.
          </div>
        )}

        <label style={lbl}>Template</label>
        <select className="lp-cd-select lp-cd-input" value={tplId} onChange={e => pickTemplate(e.target.value)}>
          <option value="">— choose an approved template —</option>
          {templates.map(t => <option key={t.id} value={t.id}>{t.name} ({t.language}){t.category ? ` · ${t.category}` : ''}</option>)}
        </select>
        {templates.length === 0 && <div style={{ fontSize: 12, color: '#9ca3af', marginTop: 6 }}>No approved templates. Create one under Templates first.</div>}

        {tpl && (
          <>
            <div style={{ background: '#f0fdf4', borderLeft: '3px solid #22c55e', borderRadius: 8, padding: '10px 12px', marginTop: 12, fontSize: 13.5, color: '#374151', whiteSpace: 'pre-wrap' }}>
              {tpl.body}
            </div>
            {tplVars.map((name, i) => (
              <div key={i} style={{ marginTop: 10 }}>
                <label style={lbl}>{`{{${i + 1}}}`} — {name}</label>
                <input className="lp-cd-input" value={vars[i] ?? ''} placeholder={i === 0 ? 'e.g. contact name' : `value for ${name}`}
                  onChange={e => setVars(v => { const n = [...v]; n[i] = e.target.value; return n })} />
              </div>
            ))}
          </>
        )}

        <div style={{ display: 'flex', gap: 10, justifyContent: 'flex-end', marginTop: '1.5rem' }}>
          <button onClick={onClose} disabled={sending} style={{ padding: '.6rem 1.1rem', borderRadius: 10, border: '1.5px solid #e5e7eb', background: '#fff', fontWeight: 600, cursor: 'pointer' }}>Cancel</button>
          <button onClick={send} disabled={sending || !tplId || !allVarsFilled} title={!allVarsFilled ? 'Fill all template variables first' : ''} style={{ padding: '.6rem 1.3rem', borderRadius: 10, border: 'none', background: (sending || !tplId || !allVarsFilled) ? '#c7cdd6' : '#16a34a', color: '#fff', fontWeight: 700, cursor: (sending || !tplId || !allVarsFilled) ? 'not-allowed' : 'pointer' }}>
            {sending ? 'Sending…' : 'Send template'}
          </button>
        </div>
      </div>
    </div>
  )
}

const lbl = { display: 'block', fontSize: 12.5, fontWeight: 600, color: '#374151', marginBottom: 5 }
