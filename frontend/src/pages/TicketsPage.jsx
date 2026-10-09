import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { crm } from '../api/crm'
import { contacts as contactsApi } from '../api/contacts'
import { documents as documentsApi } from '../api/documents'
import { api } from '../api/client'
import { toast } from '../components/Toast'
import CrmListView from '../components/CrmListView'

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
const FILTERS = ['open', 'pending', 'resolved', 'closed', 'all']
const breached = (t) => ['open', 'pending'].includes(t.status) && t.sla_due_at && new Date(t.sla_due_at.replace(' ', 'T')) < new Date()

const CATEGORIES = ['', 'general', 'billing', 'technical', 'onboarding', 'complaint', 'feature_request']

function CreateModal({ onClose, onCreated }) {
  const [f, setF] = useState({
    subject: '', description: '', priority: 'medium', source: 'manual', category: '', owner_id: '',
    cust_name: '', cust_mobile: '', cust_email: '', cust_company: '',
  })
  const [agents, setAgents] = useState([])
  const [files, setFiles] = useState([])
  const [saving, setSaving] = useState(false)
  const set = (k) => (e) => setF(s => ({ ...s, [k]: e.target.value }))

  useEffect(() => { api.get('/team').then(r => setAgents(r.data ?? [])).catch(() => {}) }, [])

  async function save(e) {
    e.preventDefault()
    if (!f.subject.trim()) { toast.error('Subject is required'); return }
    setSaving(true)
    try {
      let contactId = null, accountId = null
      if (f.cust_company.trim()) {
        try { accountId = (await crm.createAccount({ name: f.cust_company.trim() }))?.data?.id ?? null } catch { /* non-fatal */ }
      }
      if (f.cust_name.trim() || f.cust_mobile.trim() || f.cust_email.trim()) {
        const c = await contactsApi.create({
          name: f.cust_name.trim() || null, wa_number: f.cust_mobile.trim() || null,
          email: f.cust_email.trim() || null, account_id: accountId, source: f.source,
        })
        contactId = c?.data?.id ?? null
      }
      const t = await crm.createTicket({
        subject: f.subject.trim(), description: f.description || null, priority: f.priority, source: f.source,
        category: f.category || null, owner_id: f.owner_id || undefined,
        contact_id: contactId || undefined, account_id: accountId || undefined,
      })
      const ticketId = t?.data?.id
      for (const file of files) { try { await documentsApi.upload('ticket', ticketId, file) } catch { /* ignore */ } }
      toast.success('Ticket opened', f.subject)
      onCreated()
    } catch (e) { toast.error('Could not open ticket', e.message) }
    finally { setSaving(false) }
  }

  const sec = { fontSize: 11.5, fontWeight: 800, letterSpacing: '.04em', textTransform: 'uppercase', color: '#6b7280', margin: '1.1rem 0 .3rem' }
  const row = { display: 'flex', gap: 10 }
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: 20, backdropFilter: 'blur(3px)', overflowY: 'auto' }}>
      <form onClick={e => e.stopPropagation()} onSubmit={save} style={{ background: '#fff', borderRadius: 16, width: '100%', maxWidth: 620, padding: '1.5rem', boxShadow: '0 24px 64px rgba(0,0,0,.22)', margin: 'auto' }}>
        <h3 style={{ margin: '0 0 .3rem', fontWeight: 800, fontSize: '1.05rem' }}>🎫 New Ticket</h3>

        <div style={sec}>Ticket</div>
        <label style={lbl}>Subject *</label>
        <input value={f.subject} onChange={set('subject')} placeholder="What's the issue?" autoFocus style={inp} />
        <label style={lbl}>Description</label>
        <textarea value={f.description} onChange={set('description')} rows={3} style={{ ...inp, resize: 'vertical' }} placeholder="Details of the problem…" />
        <div style={row}>
          <div style={{ flex: 1 }}><label style={lbl}>Priority</label>
            <select value={f.priority} onChange={set('priority')} style={inp}>{Object.keys(PRIORITY).map(p => <option key={p} value={p}>{PRIORITY[p].label}</option>)}</select>
          </div>
          <div style={{ flex: 1 }}><label style={lbl}>Category</label>
            <select value={f.category} onChange={set('category')} style={inp}>{CATEGORIES.map(c => <option key={c} value={c}>{c ? c.replace(/_/g, ' ') : '— none —'}</option>)}</select>
          </div>
        </div>
        <div style={row}>
          <div style={{ flex: 1 }}><label style={lbl}>Source</label>
            <select value={f.source} onChange={set('source')} style={inp}>{['manual', 'whatsapp', 'email', 'web'].map(s => <option key={s} value={s}>{s}</option>)}</select>
          </div>
          <div style={{ flex: 1 }}><label style={lbl}>Assign to agent</label>
            <select value={f.owner_id} onChange={set('owner_id')} style={inp}><option value="">Auto-assign</option>{agents.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}</select>
          </div>
        </div>

        <div style={sec}>Customer <span style={{ fontWeight: 400, textTransform: 'none', color: '#9ca3af' }}>(optional — links a contact)</span></div>
        <div style={row}>
          <div style={{ flex: 1 }}><label style={lbl}>Name</label><input value={f.cust_name} onChange={set('cust_name')} placeholder="Customer name" style={inp} /></div>
          <div style={{ flex: 1 }}><label style={lbl}>Company</label><input value={f.cust_company} onChange={set('cust_company')} placeholder="Company" style={inp} /></div>
        </div>
        <div style={row}>
          <div style={{ flex: 1 }}><label style={lbl}>Mobile</label><input value={f.cust_mobile} onChange={set('cust_mobile')} placeholder="+9199…" style={inp} /></div>
          <div style={{ flex: 1 }}><label style={lbl}>Email</label><input value={f.cust_email} onChange={set('cust_email')} placeholder="customer@company.com" style={inp} /></div>
        </div>

        <label style={lbl}>Attach documents <span style={{ color: '#9ca3af', fontWeight: 400 }}>(optional)</span></label>
        <input type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.png,.jpg,.jpeg,.gif,.webp" onChange={e => setFiles([...e.target.files])} style={{ fontSize: 13 }} />
        {files.length > 0 && <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>{files.length} file(s) selected</div>}

        <div style={{ display: 'flex', gap: 10, marginTop: '1.25rem' }}>
          <button type="button" onClick={onClose} style={{ flex: 1, background: '#fff', border: '1.5px solid #e5e7eb', borderRadius: 10, padding: '.6rem', fontWeight: 600, cursor: 'pointer' }}>Cancel</button>
          <button type="submit" disabled={saving} style={{ flex: 2, background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 10, padding: '.6rem', fontWeight: 700, cursor: 'pointer' }}>{saving ? 'Opening…' : 'Open ticket'}</button>
        </div>
      </form>
    </div>
  )
}
const lbl = { display: 'block', fontSize: '.8rem', fontWeight: 600, color: '#374151', margin: '.7rem 0 .3rem' }
const inp = { width: '100%', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.9rem', boxSizing: 'border-box' }

export default function TicketsPage() {
  const navigate = useNavigate()
  const [tickets, setTickets] = useState([])
  const [loading, setLoading] = useState(true)
  const [filter, setFilter] = useState('open')
  const [showCreate, setShowCreate] = useState(false)
  const [view, setView] = useState('cards')   // cards (SLA view) | list (saved views)
  const [listKey, setListKey] = useState(0)

  useEffect(() => { load() }, [filter])
  async function load() {
    setLoading(true)
    try { const r = await crm.listTickets({ status: filter }); setTickets(r.data ?? []) }
    catch (e) { toast.error('Failed to load tickets', e.message) }
    finally { setLoading(false) }
  }

  return (
    <div className="page" style={{ maxWidth: 920 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '1.25rem', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>🎫 Tickets</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Support cases with SLA tracking — often opened from WhatsApp.</p>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          <div style={{ display: 'inline-flex', background: '#f1f5f9', borderRadius: 10, padding: 3 }}>
            {[['cards', '🎫 SLA'], ['list', '☰ List']].map(([v, label]) => (
              <button key={v} onClick={() => setView(v)}
                style={{ border: 'none', borderRadius: 8, padding: '.4rem .9rem', fontSize: 13, fontWeight: 700, cursor: 'pointer',
                  background: view === v ? '#fff' : 'transparent', color: view === v ? '#111827' : '#64748b',
                  boxShadow: view === v ? '0 1px 3px rgba(0,0,0,.1)' : 'none' }}>{label}</button>
            ))}
          </div>
          <button onClick={() => setShowCreate(true)} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 10, padding: '.55rem 1rem', fontWeight: 700, cursor: 'pointer' }}>+ New Ticket</button>
        </div>
      </div>

      {view === 'list' ? (
        <CrmListView key={listKey} entity="ticket" onRowClick={t => navigate(`/tickets/${t.id}`)} />
      ) : (
      <>
      <div style={{ display: 'flex', gap: 6, marginBottom: '1rem', flexWrap: 'wrap' }}>
        {FILTERS.map(f => (
          <button key={f} onClick={() => setFilter(f)} style={{ padding: '.35rem .8rem', borderRadius: 999, border: '1px solid ' + (filter === f ? 'var(--primary,#0a6cc4)' : '#e5e7eb'), background: filter === f ? 'var(--primary,#0a6cc4)' : '#fff', color: filter === f ? '#fff' : '#475569', fontSize: '.8rem', fontWeight: 600, cursor: 'pointer', textTransform: 'capitalize' }}>{f}</button>
        ))}
      </div>

      {loading ? (
        <div style={{ height: 64, borderRadius: 12, background: '#f3f4f6', animation: 'lp-pulse 1.2s infinite' }} />
      ) : tickets.length === 0 ? (
        <div style={{ textAlign: 'center', padding: '3rem 2rem', background: '#fff', borderRadius: 12, border: '2px dashed #e5e7eb' }}>
          <div style={{ fontSize: '2.5rem' }}>🎫</div>
          <h3 style={{ fontWeight: 800, fontSize: '1rem', margin: '.5rem 0 .25rem' }}>No {filter === 'all' ? '' : filter} tickets</h3>
          <p style={{ color: '#6b7280', fontSize: '.85rem' }}>Support cases will appear here.</p>
        </div>
      ) : (
        <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 12, overflow: 'hidden' }}>
          {tickets.map((t, i) => {
            const st = STATUS[t.status] ?? STATUS.open
            const pr = PRIORITY[t.priority] ?? PRIORITY.medium
            const isBreach = breached(t)
            return (
              <div key={t.id} onClick={() => navigate(`/tickets/${t.id}`)} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '.85rem 1rem', borderTop: i ? '1px solid #f1f5f9' : 'none', cursor: 'pointer' }}>
                <span style={{ fontSize: '.72rem', color: '#94a3b8', fontWeight: 700, width: 40 }}>#{t.id}</span>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontWeight: 700, fontSize: '.9rem', color: '#111827', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{t.subject}</div>
                  <div style={{ fontSize: '.72rem', color: '#94a3b8', textTransform: 'capitalize' }}>{t.source}{t.category ? ` · ${t.category}` : ''}</div>
                </div>
                {isBreach && <span title="SLA breached" style={{ fontSize: '.7rem', fontWeight: 700, color: '#dc2626', background: '#fef2f2', borderRadius: 999, padding: '.12rem .5rem' }}>⚠ SLA</span>}
                {t.escalated_at && <span title={`Escalated ${t.escalated_at}`} style={{ fontSize: '.7rem', fontWeight: 700, color: '#9333ea', background: '#faf5ff', borderRadius: 999, padding: '.12rem .5rem' }}>⬆ Escalated</span>}
                <span style={{ background: pr.bg, color: pr.color, borderRadius: 999, padding: '.12rem .55rem', fontSize: '.7rem', fontWeight: 700 }}>{pr.label}</span>
                <span style={{ background: st.bg, color: st.color, borderRadius: 999, padding: '.12rem .6rem', fontSize: '.72rem', fontWeight: 700 }}>{st.label}</span>
              </div>
            )
          })}
        </div>
      )}
      </>
      )}

      {showCreate && <CreateModal onClose={() => setShowCreate(false)} onCreated={() => { setShowCreate(false); load(); setListKey(k => k + 1) }} />}
      <style>{`@keyframes lp-pulse{0%,100%{opacity:1}50%{opacity:.45}}`}</style>
    </div>
  )
}
