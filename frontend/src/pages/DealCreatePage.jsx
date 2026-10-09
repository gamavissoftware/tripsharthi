import { useState, useEffect } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { crm } from '../api/crm'
import { contacts as contactsApi } from '../api/contacts'
import { tags as tagsApi } from '../api/tags'
import { documents as documentsApi } from '../api/documents'
import { api } from '../api/client'
import { toast } from '../components/Toast'

const SOURCES = ['manual', 'web_form', 'meta_lead_ads', 'google_lead_forms', 'whatsapp_inbound', 'email_inbound', 'csv_import', 'shopify', 'woocommerce']
const lbl = { fontSize: 12.5, fontWeight: 600, color: '#374151', display: 'block', marginBottom: 4 }

function Field({ label, required, children }) {
  return <div><label style={lbl}>{label}{required && <span style={{ color: '#dc2626' }}> *</span>}</label>{children}</div>
}

/**
 * Rich "Add Deal" — captures the full lead profile (contact + qualification) AND
 * the deal in one form, mirroring the reference manual-lead entry. Orchestrates
 * existing endpoints: create/upsert the contact, optionally an account, then the
 * deal linked to both; finally attach tags (contact) and documents (deal).
 */
export default function DealCreatePage() {
  const navigate = useNavigate()
  const [stages, setStages] = useState([])
  const [agents, setAgents] = useState([])
  const [tagList, setTagList] = useState([])
  const [saving, setSaving] = useState(false)
  const [files, setFiles] = useState([])
  const [tags, setTags] = useState([])
  const [f, setF] = useState({
    lead_name: '', company: '', mobile: '', alt_mobile: '', email: '',
    city: '', state: '', country: '', business_type: '', requirement_type: '',
    deal_title: '', budget: '', source: 'manual', stage_id: '', timeline: '',
    priority: 'medium', owner_id: '', expected_close_date: '', is_recurring: false,
    recurring_interval: 'monthly', remarks: '',
  })
  const set = (k) => (e) => setF(s => ({ ...s, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }))

  useEffect(() => {
    crm.dealBoard().then(r => {
      const st = (r.data?.columns ?? []).map(c => c.stage)
      setStages(st)
      setF(s => ({ ...s, stage_id: st[0]?.id ?? '' }))
    }).catch(() => {})
    api.get('/team').then(r => setAgents(r.data ?? [])).catch(() => {})
    tagsApi.list().then(r => setTagList(r.data ?? [])).catch(() => {})
  }, [])

  async function submit(e) {
    e.preventDefault()
    if (!f.lead_name.trim() && !f.deal_title.trim()) { toast.error('Add a lead name or a deal title'); return }
    setSaving(true)
    try {
      let contactId = null, accountId = null

      // 1) Company → account
      if (f.company.trim()) {
        try { accountId = (await crm.createAccount({ name: f.company.trim() }))?.data?.id ?? null } catch { /* non-fatal */ }
      }

      // 2) Lead → contact (carries the full qualification profile)
      if (f.lead_name.trim() || f.mobile.trim() || f.email.trim()) {
        const c = await contactsApi.create({
          name: f.lead_name.trim() || null, wa_number: f.mobile.trim() || null, email: f.email.trim() || null,
          phone_secondary: f.alt_mobile.trim() || null, account_id: accountId, owner_id: f.owner_id || null,
          city: f.city || null, state: f.state || null, country: f.country || null,
          business_type: f.business_type || null, requirement_type: f.requirement_type || null,
          timeline: f.timeline || null, priority: f.priority, remarks: f.remarks || null, source: f.source,
        })
        contactId = c?.data?.id ?? null
        for (const tid of tags) { try { await contactsApi.attachTag(contactId, tid) } catch { /* ignore */ } }
      }

      // 3) The deal
      const deal = await crm.createDeal({
        title: f.deal_title.trim() || `${f.lead_name.trim()}${f.company.trim() ? ' — ' + f.company.trim() : ''}` || 'New deal',
        value_amount: f.budget ? Math.round(parseFloat(f.budget) * 100) : 0,
        stage_id: f.stage_id || undefined, source: f.source, owner_id: f.owner_id || undefined,
        primary_contact_id: contactId || undefined, account_id: accountId || undefined,
        expected_close_date: f.expected_close_date || undefined,
        is_recurring: f.is_recurring ? 1 : 0, recurring_interval: f.is_recurring ? f.recurring_interval : undefined,
      })
      const dealId = deal?.data?.id

      // 4) Attach documents to the deal
      for (const file of files) { try { await documentsApi.upload('deal', dealId, file) } catch { /* ignore */ } }

      toast.success('Deal created', f.deal_title || f.lead_name)
      navigate(dealId ? `/deals/${dealId}` : '/deals')
    } catch (err) {
      toast.error('Could not create deal', err.message)
    } finally { setSaving(false) }
  }

  const sec = { fontSize: 12.5, fontWeight: 800, letterSpacing: '.04em', textTransform: 'uppercase', color: '#6b7280', margin: '1.4rem 0 .85rem' }
  const grid = { display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '.85rem' }

  return (
    <div className="page" style={{ maxWidth: 1000 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: '.4rem', fontSize: 13, marginBottom: '1rem' }}>
        <Link to="/deals" style={{ color: 'var(--primary,#0a6cc4)', textDecoration: 'none', fontWeight: 600 }}>← Deals</Link>
        <span style={{ color: '#d1d5db' }}>/</span><span style={{ color: '#6b7280' }}>New deal</span>
      </div>

      <form onSubmit={submit} className="lp-cd-card">
        <h2 style={{ margin: 0, fontSize: 18, fontWeight: 800 }}>💼 Add Deal</h2>

        <div style={sec}>Lead &amp; contact</div>
        <div style={grid}>
          <Field label="Lead name" required><input className="lp-cd-input" value={f.lead_name} onChange={set('lead_name')} placeholder="Full name of contact" /></Field>
          <Field label="Company"><input className="lp-cd-input" value={f.company} onChange={set('company')} placeholder="Employer / corporate entity" /></Field>
          <Field label="Mobile number"><input className="lp-cd-input" value={f.mobile} onChange={set('mobile')} placeholder="e.g. +919999900000" /></Field>
          <Field label="Alternate mobile"><input className="lp-cd-input" value={f.alt_mobile} onChange={set('alt_mobile')} placeholder="Optional secondary phone" /></Field>
          <Field label="Email"><input className="lp-cd-input" value={f.email} onChange={set('email')} placeholder="contact@company.com" /></Field>
          <Field label="City"><input className="lp-cd-input" value={f.city} onChange={set('city')} /></Field>
          <Field label="State"><input className="lp-cd-input" value={f.state} onChange={set('state')} /></Field>
          <Field label="Country"><input className="lp-cd-input" value={f.country} onChange={set('country')} /></Field>
          <Field label="Business type"><input className="lp-cd-input" value={f.business_type} onChange={set('business_type')} placeholder="e.g. Retail, SaaS" /></Field>
          <Field label="Requirement type"><input className="lp-cd-input" value={f.requirement_type} onChange={set('requirement_type')} placeholder="e.g. CRM migration" /></Field>
        </div>

        <div style={sec}>Deal</div>
        <div style={grid}>
          <Field label="Deal title"><input className="lp-cd-input" value={f.deal_title} onChange={set('deal_title')} placeholder="Defaults to lead name" /></Field>
          <Field label="Budget (₹)"><input type="number" className="lp-cd-input" value={f.budget} onChange={set('budget')} placeholder="Available funds" /></Field>
          <Field label="Stage">
            <select className="lp-cd-select" value={f.stage_id} onChange={set('stage_id')}>
              {stages.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
            </select>
          </Field>
          <Field label="Lead source">
            <select className="lp-cd-select" value={f.source} onChange={set('source')}>
              {SOURCES.map(s => <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>)}
            </select>
          </Field>
          <Field label="Assign to agent">
            <select className="lp-cd-select" value={f.owner_id} onChange={set('owner_id')}>
              <option value="">Keep unassigned (auto)</option>
              {agents.map(a => <option key={a.id} value={a.id}>{a.name} ({a.role})</option>)}
            </select>
          </Field>
          <Field label="Priority">
            <select className="lp-cd-select" value={f.priority} onChange={set('priority')}>
              <option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option>
            </select>
          </Field>
          <Field label="Timeline"><input className="lp-cd-input" value={f.timeline} onChange={set('timeline')} placeholder="e.g. Immediate, 3 months" /></Field>
          <Field label="Expected close"><input type="date" className="lp-cd-input" value={f.expected_close_date} onChange={set('expected_close_date')} /></Field>
          <Field label="Recurring">
            <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13.5, color: '#374151', height: 38 }}>
              <input type="checkbox" checked={f.is_recurring} onChange={set('is_recurring')} />
              {f.is_recurring ? (
                <select className="lp-cd-select" style={{ flex: 1 }} value={f.recurring_interval} onChange={set('recurring_interval')}>
                  <option value="monthly">monthly</option><option value="quarterly">quarterly</option><option value="annual">annual</option>
                </select>
              ) : 'one-time'}
            </label>
          </Field>
        </div>

        <div style={sec}>Notes, tags &amp; documents</div>
        <Field label="Remarks / details">
          <textarea className="lp-cd-input" rows={3} value={f.remarks} onChange={set('remarks')} placeholder="Context notes regarding the lead's requirements" />
        </Field>
        <div style={{ marginTop: '.85rem' }}>
          <label style={lbl}>Tags</label>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
            {tagList.map(t => {
              const on = tags.includes(t.id)
              return <button type="button" key={t.id} onClick={() => setTags(s => on ? s.filter(x => x !== t.id) : [...s, t.id])}
                style={{ border: '1.5px solid ' + (on ? (t.color ?? '#0a6cc4') : '#e5e7eb'), background: on ? (t.color ?? '#0a6cc4') + '22' : '#fff', color: on ? (t.color ?? '#0a6cc4') : '#6b7280', borderRadius: 999, padding: '.2rem .65rem', fontSize: 12.5, fontWeight: 600, cursor: 'pointer' }}>{t.name}</button>
            })}
            {tagList.length === 0 && <span style={{ color: '#9ca3af', fontSize: 13 }}>No tags defined yet.</span>}
          </div>
        </div>
        <div style={{ marginTop: '.85rem' }}>
          <label style={lbl}>Attach documents <span style={{ color: '#9ca3af', fontWeight: 400 }}>(optional)</span></label>
          <input type="file" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.png,.jpg,.jpeg,.gif,.webp" onChange={e => setFiles([...e.target.files])} style={{ fontSize: 13 }} />
          {files.length > 0 && <div style={{ fontSize: 12, color: '#6b7280', marginTop: 4 }}>{files.length} file(s) selected</div>}
        </div>

        <div style={{ display: 'flex', gap: '.7rem', alignItems: 'center', borderTop: '1px solid #f0f1f3', paddingTop: '1.1rem', marginTop: '1.3rem' }}>
          <button type="submit" disabled={saving} style={{ padding: '.6rem 1.4rem', borderRadius: 10, border: 'none', background: saving ? '#c7cdd6' : 'var(--primary,#0a6cc4)', color: '#fff', fontWeight: 700, fontSize: 14, cursor: saving ? 'not-allowed' : 'pointer' }}>
            {saving ? 'Creating…' : 'Create deal'}
          </button>
          <Link to="/deals" style={{ padding: '.6rem 1rem', borderRadius: 10, border: '1.5px solid #e5e7eb', background: '#fff', color: '#374151', fontWeight: 600, fontSize: 14, textDecoration: 'none' }}>Cancel</Link>
        </div>
      </form>
    </div>
  )
}
