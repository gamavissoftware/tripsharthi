import { useState, useEffect } from 'react'
import { useParams, Link } from 'react-router-dom'
import { crm } from '../api/crm'
import { toast } from '../components/Toast'
import { TimelinePanel, NotesPanel, TasksPanel } from '../components/crm/RecordPanels'
import DocumentsPanel from '../components/crm/DocumentsPanel'
import { api } from '../api/client'
import LoadFailed from '../components/LoadFailed'

const card = { background: '#fff', border: '1px solid #e5e7eb', borderRadius: 18, padding: '1.4rem 1.5rem', marginBottom: '1.25rem' }
const PR_TINT = { high: { bg: '#fee2e2', c: '#b91c1c' }, urgent: { bg: '#fee2e2', c: '#b91c1c' }, medium: { bg: '#fef3c7', c: '#92400e' }, low: { bg: '#f1f5f9', c: '#64748b' } }
const TIER_C = { hot: '#dc2626', warm: '#f59e0b', cold: '#3b82f6' }

function Info({ label, value, mono, children }) {
  return (
    <div>
      <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.04em', color: '#9ca3af', marginBottom: 2 }}>{label}</div>
      <div style={{ fontSize: 13.5, color: value || children ? '#111827' : '#cbd5e1', overflowWrap: 'anywhere', fontFamily: mono ? 'monospace' : 'inherit' }}>{children ?? (value || '—')}</div>
    </div>
  )
}
const STATUS = {
  open:     { label: 'Open',     bg: '#dbeafe', color: '#1d4ed8' },
  pending:  { label: 'Pending',  bg: '#fef9c3', color: '#a16207' },
  resolved: { label: 'Resolved', bg: '#dcfce7', color: '#15803d' },
  closed:   { label: 'Closed',   bg: '#f1f5f9', color: '#475569' },
}
const PRIORITY = {
  urgent: { label: 'Urgent', bg: '#fee2e2', color: '#b91c1c' },
  high:   { label: 'High',   bg: '#ffedd5', color: '#c2410c' },
  medium: { label: 'Medium', bg: '#fef9c3', color: '#a16207' },
  low:    { label: 'Low',    bg: '#e0f2fe', color: '#0369a1' },
}
const fmt = (dt) => { if (!dt) return '—'; try { return new Date(dt.replace(' ', 'T')).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) } catch { return dt } }

export default function TicketDetailPage() {
  const { id } = useParams()
  const [ticket, setTicket] = useState(null)
  const [tlKey, setTlKey] = useState(0)
  const [agents, setAgents] = useState([])

  const [loadErr, setLoadErr] = useState('')
  async function load() { try { const r = await crm.getTicket(id); setTicket(r.data) } catch (e) { setLoadErr(e?.message || 'Not found') } }
  useEffect(() => { load() }, [id])
  useEffect(() => { api.get('/team').then(r => setAgents(r.data ?? [])).catch(() => {}) }, [])

  async function setStatus(s) {
    try { await crm.ticketStatus(id, s); await load(); setTlKey(k => k + 1); toast.success(`Marked ${s}`) }
    catch (e) { toast.error('Could not update', e.message) }
  }

  if (!ticket && loadErr) return <LoadFailed what="ticket" message={loadErr} backTo="/tickets" backLabel="Back to tickets" />
  if (!ticket) return <div className="page" style={{ maxWidth: 760 }}><div style={{ ...card, height: 160, background: '#f3f4f6' }} /></div>
  const st = STATUS[ticket.status] ?? STATUS.open
  const pr = PRIORITY[ticket.priority] ?? PRIORITY.medium

  return (
    <div className="page" style={{ maxWidth: 900 }}>
      <div style={{ fontSize: 13, marginBottom: '1.1rem' }}>
        <Link to="/tickets" style={{ color: 'var(--primary,#0a6cc4)', textDecoration: 'none', fontWeight: 600 }}>← Tickets</Link>
        <span style={{ color: '#d1d5db' }}> / </span><span style={{ color: '#6b7280' }}>#{ticket.id}</span>
      </div>

      <div style={{ ...card, background: 'linear-gradient(135deg,#f8fafc,#eff6ff)' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
          <div style={{ flex: 1, minWidth: 0, overflowWrap: 'anywhere' }}>
            <div style={{ fontSize: 18, fontWeight: 800, color: '#111827' }}>{ticket.subject}</div>
            {ticket.description && <div style={{ fontSize: 13.5, color: '#475569', marginTop: 6, whiteSpace: 'pre-wrap' }}>{ticket.description}</div>}
            <div style={{ fontSize: 12.5, color: '#6b7280', marginTop: 8 }}>
              {ticket.contact?.name && <>👤 <Link to={`/contacts/${ticket.contact_id}`} style={{ color: '#0a6cc4', textDecoration: 'none' }}>{ticket.contact.name}</Link> · </>}
              <span style={{ textTransform: 'capitalize' }}>{ticket.source}</span>
              {ticket.category && <> · 🏷️ {String(ticket.category).replace(/_/g, ' ')}</>}
              {agents.find(a => String(a.id) === String(ticket.owner_id))?.name && <> · 🧑‍💼 {agents.find(a => String(a.id) === String(ticket.owner_id)).name}</>}
              {ticket.sla_due_at && <> · SLA due {fmt(ticket.sla_due_at)}{ticket.sla_breached ? <strong style={{ color: '#dc2626' }}> ⚠ breached</strong> : ''}</>}
            </div>
          </div>
          <div style={{ textAlign: 'right', display: 'flex', flexDirection: 'column', gap: 6, alignItems: 'flex-end' }}>
            <span style={{ padding: '.25rem .7rem', borderRadius: 999, fontSize: 12, fontWeight: 700, background: st.bg, color: st.color }}>{st.label}</span>
            <span style={{ padding: '.2rem .6rem', borderRadius: 999, fontSize: 11, fontWeight: 700, background: pr.bg, color: pr.color }}>{pr.label}</span>
          </div>
        </div>

        {/* Status workflow */}
        <div style={{ marginTop: '1rem', display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {['open', 'pending', 'resolved', 'closed'].map(s => (
            <button key={s} onClick={() => setStatus(s)} disabled={s === ticket.status}
              style={{ padding: '.4rem .85rem', borderRadius: 8, border: '1px solid #e5e7eb', background: s === ticket.status ? '#e8f3fc' : '#fff', color: s === ticket.status ? '#08569f' : '#374151', fontWeight: 600, fontSize: '.82rem', cursor: s === ticket.status ? 'default' : 'pointer', textTransform: 'capitalize' }}>
              {s === 'resolved' ? '✅ ' : ''}{s}
            </button>
          ))}
        </div>
      </div>

      {/* Customer profile (full info captured on the ticket form) */}
      {ticket.contact && (
        <div style={card}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '.9rem' }}>
            <h3 style={{ fontSize: 15, fontWeight: 800, margin: 0 }}>👤 Customer</h3>
            <Link to={`/contacts/${ticket.contact_id}`} style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--primary,#0a6cc4)', textDecoration: 'none' }}>Open contact →</Link>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(150px,1fr))', gap: '.85rem 1rem' }}>
            <Info label="Name" value={ticket.contact.name} />
            <Info label="Mobile" value={ticket.contact.wa_number} mono />
            <Info label="Email" value={ticket.contact.email} />
            <Info label="Company" value={ticket.account?.name} />
            <Info label="Business type" value={ticket.contact.business_type} />
            <Info label="Location" value={[ticket.contact.city, ticket.contact.state, ticket.contact.country].filter(Boolean).join(', ')} />
            <Info label="Lifecycle" value={ticket.contact.lifecycle_stage} />
            <Info label="Priority">
              {ticket.contact.priority ? <span style={{ fontSize: 11.5, fontWeight: 800, textTransform: 'uppercase', borderRadius: 999, padding: '.1rem .5rem', background: (PR_TINT[ticket.contact.priority] ?? PR_TINT.medium).bg, color: (PR_TINT[ticket.contact.priority] ?? PR_TINT.medium).c }}>{ticket.contact.priority}</span> : '—'}
            </Info>
            <Info label="Lead score">
              {ticket.contact.score_tier ? <span style={{ color: TIER_C[String(ticket.contact.score_tier).toLowerCase()] ?? '#64748b', fontWeight: 700 }}>● {ticket.contact.score_tier}{ticket.contact.lead_score != null ? ` · ${ticket.contact.lead_score}` : ''}</span> : '—'}
            </Info>
          </div>
        </div>
      )}

      <TasksPanel relatedType="ticket" relatedId={Number(id)} />
      <DocumentsPanel relatedType="ticket" relatedId={Number(id)} />
      <NotesPanel relatedType="ticket" relatedId={Number(id)} />
      <TimelinePanel relatedType="ticket" relatedId={Number(id)} refreshKey={tlKey} />
    </div>
  )
}
