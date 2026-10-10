import { useState, useEffect } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { crm, rupees } from '../api/crm'
import { products as productsApi } from '../api/products'
import { toast } from '../components/Toast'
import { fmt, CURRENCIES } from '../components/Charts'
import { TimelinePanel, NotesPanel, TasksPanel } from '../components/crm/RecordPanels'
import DocumentsPanel from '../components/crm/DocumentsPanel'
import { contacts as contactsApi } from '../api/contacts'
import { api } from '../api/client'

const money = fmt.money
const SOURCES = ['manual', 'web_form', 'meta_lead_ads', 'google_lead_forms', 'whatsapp_inbound', 'email_inbound', 'csv_import', 'shopify', 'woocommerce']
const eIn = { border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .6rem', fontSize: '.88rem', width: '100%', boxSizing: 'border-box' }
const eLbl = { fontSize: 11.5, fontWeight: 700, color: '#6b7280', display: 'block', marginBottom: 3 }
const PRIORITY_TINT = { high: { bg: '#fee2e2', c: '#b91c1c' }, medium: { bg: '#fef3c7', c: '#92400e' }, low: { bg: '#f1f5f9', c: '#64748b' } }
const TIER_C = { hot: '#dc2626', warm: '#f59e0b', cold: '#3b82f6' }

function Info({ label, value, mono, children }) {
  return (
    <div>
      <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.04em', color: '#9ca3af', marginBottom: 2 }}>{label}</div>
      <div style={{ fontSize: 13.5, color: value || children ? '#111827' : '#cbd5e1', fontFamily: mono ? 'monospace' : 'inherit' }}>{children ?? (value || '—')}</div>
    </div>
  )
}

const card = { background: '#fff', border: '1px solid #e5e7eb', borderRadius: 18, padding: '1.4rem 1.5rem', marginBottom: '1.25rem' }
const QUOTE_STATUS = {
  draft:    { label: 'Draft',    bg: '#f1f5f9', color: '#475569' },
  sent:     { label: 'Sent',     bg: '#dbeafe', color: '#1d4ed8' },
  accepted: { label: 'Accepted', bg: '#dcfce7', color: '#15803d' },
  rejected: { label: 'Rejected', bg: '#fee2e2', color: '#b91c1c' },
  expired:  { label: 'Expired',  bg: '#fef9c3', color: '#a16207' },
}

function QuotesPanel({ dealId }) {
  const [quotes, setQuotes] = useState([])
  const [open, setOpen] = useState(null)
  async function load() { try { const r = await crm.listQuotes(dealId); setQuotes(r.data ?? []) } catch { /* noop */ } }
  useEffect(() => { load() }, [dealId])
  async function gen() { try { await crm.createQuote(dealId); toast.success('Quote generated'); await load() } catch (e) { toast.error('Could not generate', e.message) } }
  async function setStatus(id, s) { try { await crm.quoteStatus(id, s); await load(); if (open?.id === id) { const r = await crm.getQuote(id); setOpen(r.data) } } catch (e) { toast.error('Could not update', e.message) } }
  async function view(id) { try { const r = await crm.getQuote(id); setOpen(r.data) } catch { /* noop */ } }
  const [sending, setSending] = useState(false)
  async function downloadPdf(q) {
    try {
      const blob = await crm.quotePdf(q.id)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url; a.download = `${q.number}.pdf`
      document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url)
    } catch (e) { toast.error('Could not generate PDF', e?.message) }
  }
  async function sendWhatsApp(q) {
    if (!confirm(`Send ${q.number} to the deal's primary contact on WhatsApp?`)) return
    setSending(true)
    try {
      const r = await crm.sendQuote(q.id)
      if (r?.success === false && r?.code === 'WINDOW_CLOSED') toast.error('24h window closed', 'Send a template to reopen the conversation first.')
      else { toast.success('Quote sent on WhatsApp'); await load(); const fresh = await crm.getQuote(q.id); setOpen(fresh.data) }
    } catch (e) {
      const msg = e?.data?.code === 'WINDOW_CLOSED' ? 'The 24-hour window is closed — send a template first.' : e?.message
      toast.error('Could not send', msg)
    }
    setSending(false)
  }
  async function sendEmail(q) {
    if (!confirm(`Email ${q.number} (PDF attached) to the deal's primary contact?`)) return
    setSending(true)
    try {
      await crm.sendQuoteEmail(q.id)
      toast.success('Quote emailed'); await load(); const fresh = await crm.getQuote(q.id); setOpen(fresh.data)
    } catch (e) {
      toast.error('Could not email', e?.data?.messages?.email || e?.message)
    }
    setSending(false)
  }

  return (
    <div style={card}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '.9rem' }}>
        <h3 style={{ fontSize: 15, fontWeight: 800, margin: 0 }}>📄 Quotes</h3>
        <button onClick={gen} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 8, padding: '.4rem .85rem', fontWeight: 600, fontSize: '.82rem', cursor: 'pointer' }}>+ Generate from line items</button>
      </div>
      {quotes.length === 0 ? <div style={{ color: '#9ca3af', fontSize: 13.5 }}>No quotes yet.</div>
        : quotes.map(q => {
          const st = QUOTE_STATUS[q.status] ?? QUOTE_STATUS.draft
          return (
            <div key={q.id} style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '4px 10px', padding: '.5rem 0', borderTop: '1px solid #f6f7f9' }}>
              <span style={{ fontWeight: 700, fontSize: '.85rem' }}>{q.number}</span>
              <span style={{ background: st.bg, color: st.color, borderRadius: 999, padding: '.1rem .5rem', fontSize: '.7rem', fontWeight: 700 }}>{st.label}</span>
              <span style={{ flex: 1, fontSize: '.8rem', color: '#94a3b8' }}>valid till {q.valid_until ?? '—'}</span>
              <span style={{ fontWeight: 700, fontSize: '.88rem' }}>{money(q.total, q.currency)}</span>
              <button onClick={() => view(q.id)} style={{ background: 'none', border: 'none', color: '#0a6cc4', fontWeight: 600, fontSize: '.8rem', cursor: 'pointer' }}>View</button>
            </div>
          )
        })}

      {open && (
        <div onClick={() => setOpen(null)} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, backdropFilter: 'blur(3px)' }}>
          <div onClick={e => e.stopPropagation()} style={{ background: '#fff', borderRadius: 16, width: '100%', maxWidth: 520, maxHeight: '88vh', overflow: 'auto', padding: '1.5rem', boxShadow: '0 24px 64px rgba(0,0,0,.22)' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div><div style={{ fontWeight: 800, fontSize: '1.1rem' }}>Quote {open.number}</div><div style={{ fontSize: '.78rem', color: '#94a3b8' }}>Valid until {open.valid_until ?? '—'}</div></div>
              <button onClick={() => setOpen(null)} style={{ background: 'none', border: 'none', fontSize: '1.2rem', cursor: 'pointer', color: '#94a3b8' }}>✕</button>
            </div>
            <table className="lp-table-fit" style={{ width: '100%', borderCollapse: 'collapse', fontSize: '.85rem', margin: '1rem 0' }}>
              <thead><tr style={{ textAlign: 'left', color: '#94a3b8', fontSize: '.72rem', textTransform: 'uppercase' }}><th style={{ padding: '.3rem 0' }}>Item</th><th>Qty</th><th style={{ textAlign: 'right' }}>Total</th></tr></thead>
              <tbody>
                {(open.items ?? []).map((it, i) => (
                  <tr key={i} style={{ borderTop: '1px solid #f1f5f9' }}><td style={{ padding: '.4rem 0' }}>{it.name}</td><td>{it.quantity}</td><td style={{ textAlign: 'right', fontWeight: 600 }}>{money(it.total, open.currency)}</td></tr>
                ))}
                {(open.items ?? []).length === 0 && <tr><td colSpan={3} style={{ padding: '.6rem 0', color: '#94a3b8' }}>No line items — add some to the deal first.</td></tr>}
              </tbody>
            </table>
            <div style={{ display: 'flex', justifyContent: 'space-between', fontWeight: 800, fontSize: '1rem', borderTop: '2px solid #f1f5f9', paddingTop: '.6rem' }}><span>Total</span><span>{money(open.total, open.currency)}</span></div>
            <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginTop: '1.1rem' }}>
              <button onClick={() => downloadPdf(open)} style={{ flex: '1 1 120px', padding: '.55rem', borderRadius: 8, border: '1.5px solid #e5e7eb', background: '#fff', color: '#374151', fontWeight: 700, fontSize: '.84rem', cursor: 'pointer' }}>📄 Download PDF</button>
              <button onClick={() => sendWhatsApp(open)} disabled={sending} style={{ flex: '1 1 120px', padding: '.55rem', borderRadius: 8, border: 'none', background: '#16a34a', color: '#fff', fontWeight: 700, fontSize: '.84rem', cursor: 'pointer' }}>{sending ? 'Sending…' : '📲 Send on WhatsApp'}</button>
              <button onClick={() => sendEmail(open)} disabled={sending} style={{ flex: '1 1 120px', padding: '.55rem', borderRadius: 8, border: 'none', background: '#0a6cc4', color: '#fff', fontWeight: 700, fontSize: '.84rem', cursor: 'pointer' }}>✉️ Email</button>
            </div>
            <div style={{ display: 'flex', gap: 8, marginTop: '.6rem', flexWrap: 'wrap' }}>
              {['sent', 'accepted', 'rejected'].map(s => (
                <button key={s} onClick={() => setStatus(open.id, s)} disabled={open.status === s} style={{ flex: 1, padding: '.5rem', borderRadius: 8, border: '1px solid #e5e7eb', background: open.status === s ? '#e8f3fc' : '#fff', color: open.status === s ? '#08569f' : '#374151', fontWeight: 600, fontSize: '.82rem', cursor: open.status === s ? 'default' : 'pointer', textTransform: 'capitalize' }}>Mark {s}</button>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

const STATUS = {
  open: { label: 'Open', bg: '#e8f3fc', color: '#08569f' },
  won:  { label: 'Won 🏆', bg: '#dcfce7', color: '#15803d' },
  lost: { label: 'Lost', bg: '#fee2e2', color: '#b91c1c' },
}

export default function DealDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [deal, setDeal] = useState(null)
  const [stages, setStages] = useState([])
  const [tlKey, setTlKey] = useState(0)
  const [edit, setEdit] = useState(null)
  const [li, setLi] = useState({ name: '', quantity: 1, unit_price: '', tax_pct: 18, product_id: '' })
  const [catalog, setCatalog] = useState([])
  const [books, setBooks] = useState([])
  const [agents, setAgents] = useState([])
  useEffect(() => { productsApi.list().then(r => setCatalog(r.data ?? [])).catch(() => {}) }, [])
  useEffect(() => { crm.priceBooks().then(r => setBooks(r.data ?? [])).catch(() => {}) }, [])
  useEffect(() => { api.get('/team').then(r => setAgents(r.data ?? [])).catch(() => {}) }, [])

  async function setPriceBook(bookId) {
    try { await crm.updateDeal(id, { price_book_id: bookId ? Number(bookId) : null }); await load(); toast.success('Price book updated') }
    catch (e) { toast.error('Could not set price book', e?.message) }
  }

  function pickProduct(pid) {
    const p = catalog.find(x => String(x.id) === String(pid))
    if (!p) { setLi(s => ({ ...s, product_id: '' })); return }
    setLi(s => ({ ...s, product_id: p.id, name: p.name, unit_price: (Number(p.price_paise || 0) / 100).toString() }))
  }

  const [loadError, setLoadError] = useState('')
  async function load() {
    let r
    try { r = await crm.getDeal(id) } catch (e) { setLoadError(e?.message || 'Deal not found'); return }
    setLoadError('')
    setDeal(r.data)
    try {
      const p = await crm.listPipelines()
      const pipe = (p.data ?? []).find(x => String(x.id) === String(r.data.pipeline_id))
      setStages(pipe?.stages ?? [])
    } catch { /* noop */ }
  }
  useEffect(() => { load() }, [id])

  async function moveTo(stageId) {
    try { await crm.moveDeal(id, Number(stageId)); await load(); setTlKey(k => k + 1); toast.success('Stage updated') }
    catch (e) { toast.error('Move failed', e.message) }
  }
  async function saveEdit() {
    try {
      await crm.updateDeal(id, {
        title: edit.title, value_amount: Math.round(parseFloat(edit.value || 0) * 100), currency: edit.currency,
        expected_close_date: edit.expected_close_date || null, source: edit.source || null,
        owner_id: edit.owner_id || null, is_recurring: edit.is_recurring ? 1 : 0,
        recurring_interval: edit.is_recurring ? edit.recurring_interval : null,
      })
      // Edit the linked lead's profile too (the deal page shows it, so make it editable here).
      if (deal.primary_contact_id) {
        await contactsApi.update(deal.primary_contact_id, {
          name: edit.c_name || null, wa_number: edit.c_wa_number || null, email: edit.c_email || null,
          business_type: edit.c_business_type || null, requirement_type: edit.c_requirement_type || null,
          city: edit.c_city || null, state: edit.c_state || null, country: edit.c_country || null,
          priority: edit.c_priority || 'medium', timeline: edit.c_timeline || null, remarks: edit.c_remarks || null,
        })
      }
      setEdit(null); await load(); toast.success('Deal updated')
    } catch (e) { toast.error('Save failed', e.message) }
  }
  async function addLi() {
    if (!li.name.trim()) return
    try {
      await crm.addLineItem(id, { name: li.name, quantity: Number(li.quantity) || 1, unit_price: Math.round(parseFloat(li.unit_price || 0) * 100), tax_pct: Number(li.tax_pct) || 0, product_id: li.product_id || null })
      setLi({ name: '', quantity: 1, unit_price: '', tax_pct: 18, product_id: '' }); await load()
    } catch (e) { toast.error('Could not add item', e.message) }
  }
  async function delLi(itemId) { try { await crm.deleteLineItem(id, itemId); await load() } catch { /* noop */ } }

  if (loadError && !deal) return <div className="page" style={{ maxWidth: 900 }}><div style={{ ...card, padding: '2rem', textAlign: 'center' }}><h3 style={{ marginTop: 0 }}>Deal not found</h3><p style={{ color: '#6b7280' }}>{loadError.replace(/\.$/, '')}. It may have been deleted or you may not have access.</p><a className="btn btn-primary" href="#/deals">Back to deals</a></div></div>
  if (!deal) return <div className="page" style={{ maxWidth: 900 }}><div style={{ ...card, height: 160, background: '#f3f4f6' }} /></div>
  const st = STATUS[deal.status] ?? STATUS.open

  return (
    <div className="page" style={{ maxWidth: 900 }}>
      <div style={{ fontSize: 13, marginBottom: '1.1rem' }}>
        <Link to="/deals" style={{ color: 'var(--primary,#0a6cc4)', textDecoration: 'none', fontWeight: 600 }}>← Deals</Link>
        <span style={{ color: '#d1d5db' }}> / </span><span style={{ color: '#6b7280' }}>{deal.title}</span>
      </div>

      {/* Header */}
      <div style={{ ...card, background: 'linear-gradient(135deg,#f8fafc,#e8f3fc)' }}>
        {edit ? (
          <div style={{ display: 'grid', gap: 12 }}>
            <div>
              <label style={eLbl}>Deal title</label>
              <input value={edit.title} onChange={e => setEdit(s => ({ ...s, title: e.target.value }))} style={{ ...eIn, fontWeight: 700 }} />
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(130px,1fr))', gap: 10 }}>
              <div><label style={eLbl}>Value</label><input type="number" value={edit.value} onChange={e => setEdit(s => ({ ...s, value: e.target.value }))} style={eIn} /></div>
              <div><label style={eLbl}>Currency</label><select value={edit.currency} onChange={e => setEdit(s => ({ ...s, currency: e.target.value }))} style={eIn}>{CURRENCIES.map(c => <option key={c} value={c}>{c}</option>)}</select></div>
              <div><label style={eLbl}>Expected close</label><input type="date" value={edit.expected_close_date || ''} onChange={e => setEdit(s => ({ ...s, expected_close_date: e.target.value }))} style={eIn} /></div>
              <div><label style={eLbl}>Source</label><select value={edit.source} onChange={e => setEdit(s => ({ ...s, source: e.target.value }))} style={eIn}><option value="">—</option>{SOURCES.map(x => <option key={x} value={x}>{x.replace(/_/g, ' ')}</option>)}</select></div>
              <div><label style={eLbl}>Assign to agent</label><select value={edit.owner_id || ''} onChange={e => setEdit(s => ({ ...s, owner_id: e.target.value }))} style={eIn}><option value="">Unassigned</option>{agents.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}</select></div>
              <div><label style={eLbl}>Recurring</label>
                <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 12.5, color: '#475569', height: 36 }}>
                  <input type="checkbox" checked={!!edit.is_recurring} onChange={e => setEdit(s => ({ ...s, is_recurring: e.target.checked }))} />
                  {edit.is_recurring
                    ? <select value={edit.recurring_interval || 'monthly'} onChange={e => setEdit(s => ({ ...s, recurring_interval: e.target.value }))} style={{ ...eIn, flex: 1 }}><option value="monthly">monthly</option><option value="quarterly">quarterly</option><option value="annual">annual</option></select>
                    : 'one-time'}
                </label>
              </div>
            </div>

            {deal.primary_contact_id && (
              <>
                <div style={{ fontSize: 11.5, fontWeight: 800, letterSpacing: '.04em', textTransform: 'uppercase', color: '#9ca3af', marginTop: 4 }}>Lead &amp; contact</div>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(130px,1fr))', gap: 10 }}>
                  <div><label style={eLbl}>Name</label><input value={edit.c_name} onChange={e => setEdit(s => ({ ...s, c_name: e.target.value }))} style={eIn} /></div>
                  <div><label style={eLbl}>Mobile</label><input value={edit.c_wa_number} onChange={e => setEdit(s => ({ ...s, c_wa_number: e.target.value }))} style={eIn} /></div>
                  <div><label style={eLbl}>Email</label><input value={edit.c_email} onChange={e => setEdit(s => ({ ...s, c_email: e.target.value }))} style={eIn} /></div>
                  <div><label style={eLbl}>Business type</label><input value={edit.c_business_type} onChange={e => setEdit(s => ({ ...s, c_business_type: e.target.value }))} style={eIn} /></div>
                  <div><label style={eLbl}>Requirement</label><input value={edit.c_requirement_type} onChange={e => setEdit(s => ({ ...s, c_requirement_type: e.target.value }))} style={eIn} /></div>
                  <div><label style={eLbl}>Priority</label><select value={edit.c_priority} onChange={e => setEdit(s => ({ ...s, c_priority: e.target.value }))} style={eIn}><option value="low">low</option><option value="medium">medium</option><option value="high">high</option></select></div>
                  <div><label style={eLbl}>City</label><input value={edit.c_city} onChange={e => setEdit(s => ({ ...s, c_city: e.target.value }))} style={eIn} /></div>
                  <div><label style={eLbl}>State</label><input value={edit.c_state} onChange={e => setEdit(s => ({ ...s, c_state: e.target.value }))} style={eIn} /></div>
                  <div><label style={eLbl}>Country</label><input value={edit.c_country} onChange={e => setEdit(s => ({ ...s, c_country: e.target.value }))} style={eIn} /></div>
                  <div style={{ gridColumn: '1 / 3' }}><label style={eLbl}>Timeline</label><input value={edit.c_timeline} onChange={e => setEdit(s => ({ ...s, c_timeline: e.target.value }))} style={eIn} /></div>
                </div>
                <div><label style={eLbl}>Remarks</label><textarea rows={2} value={edit.c_remarks} onChange={e => setEdit(s => ({ ...s, c_remarks: e.target.value }))} style={{ ...eIn, resize: 'vertical' }} /></div>
              </>
            )}

            <div style={{ display: 'flex', gap: 8 }}>
              <button onClick={saveEdit} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 8, padding: '.55rem 1.2rem', fontWeight: 700, cursor: 'pointer' }}>Save changes</button>
              <button onClick={() => setEdit(null)} style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.55rem 1rem', cursor: 'pointer' }}>Cancel</button>
            </div>
          </div>
        ) : (
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12 }}>
            <div>
              <div style={{ fontSize: 19, fontWeight: 800, color: '#111827' }}>{deal.title}</div>
              <div style={{ fontSize: 22, fontWeight: 800, color: '#15803d', marginTop: 4 }}>
                {money(deal.value_amount, deal.currency)}
                {Number(deal.is_recurring) === 1 && <span style={{ fontSize: 12, fontWeight: 700, color: '#074a8c', background: '#e0e7ff', borderRadius: 999, padding: '.15rem .55rem', marginLeft: 8, verticalAlign: 'middle' }}>🔁 {deal.recurring_interval || 'recurring'}{deal.next_renewal_at ? ` · renews ${deal.next_renewal_at}` : ''}</span>}
              </div>
              <div style={{ fontSize: 12.5, color: '#6b7280', marginTop: 4 }}>
                {deal.account?.name && <>🏢 <Link to="/accounts" style={{ color: '#0a6cc4', textDecoration: 'none' }}>{deal.account.name}</Link> · </>}
                {deal.contact?.name && <>👤 <Link to={`/contacts/${deal.primary_contact_id}`} style={{ color: '#0a6cc4', textDecoration: 'none' }}>{deal.contact.name}</Link></>}
                {deal.expected_close_date && <> · 📅 {deal.expected_close_date}</>}
                {agents.find(a => String(a.id) === String(deal.owner_id))?.name && <> · 🧑‍💼 {agents.find(a => String(a.id) === String(deal.owner_id)).name}</>}
                {deal.source && <> · 🔗 {String(deal.source).replace(/_/g, ' ')}</>}
              </div>
            </div>
            <div style={{ textAlign: 'right' }}>
              <span style={{ padding: '.25rem .7rem', borderRadius: 999, fontSize: 12, fontWeight: 700, background: st.bg, color: st.color }}>{st.label}</span>
              <div><button onClick={() => setEdit({
                title: deal.title, value: (deal.value_amount / 100).toString(), currency: deal.currency || 'INR',
                expected_close_date: deal.expected_close_date, source: deal.source || '', owner_id: deal.owner_id || '',
                is_recurring: Number(deal.is_recurring) === 1, recurring_interval: deal.recurring_interval || 'monthly',
                c_name: deal.contact?.name ?? '', c_wa_number: deal.contact?.wa_number ?? '', c_email: deal.contact?.email ?? '',
                c_business_type: deal.contact?.business_type ?? '', c_requirement_type: deal.contact?.requirement_type ?? '',
                c_priority: deal.contact?.priority ?? 'medium', c_city: deal.contact?.city ?? '', c_state: deal.contact?.state ?? '',
                c_country: deal.contact?.country ?? '', c_timeline: deal.contact?.timeline ?? '', c_remarks: deal.contact?.remarks ?? '',
              })} style={{ marginTop: 8, background: 'none', border: 'none', color: '#0a6cc4', fontSize: 12.5, fontWeight: 600, cursor: 'pointer' }}>Edit</button></div>
            </div>
          </div>
        )}
        {/* Stage mover */}
        <div style={{ marginTop: '1rem', display: 'flex', alignItems: 'center', gap: 8 }}>
          <span style={{ fontSize: 12.5, color: '#6b7280', fontWeight: 600 }}>Stage</span>
          <select value={deal.stage_id} onChange={e => moveTo(e.target.value)} style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: '.45rem .7rem', fontSize: '.88rem', fontWeight: 600 }}>
            {stages.map(s => <option key={s.id} value={s.id}>{s.name} ({s.probability}%)</option>)}
          </select>
        </div>
      </div>

      {/* Lead & contact profile (the full info captured on the deal form) */}
      {deal.contact && (
        <div style={card}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '.9rem' }}>
            <h3 style={{ fontSize: 15, fontWeight: 800, margin: 0 }}>👤 Lead &amp; contact</h3>
            <Link to={`/contacts/${deal.primary_contact_id}`} style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--primary,#0a6cc4)', textDecoration: 'none' }}>Open full lead →</Link>
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(130px,1fr))', gap: '.85rem 1rem' }}>
            <Info label="Name" value={deal.contact.name} />
            <Info label="Mobile" value={deal.contact.wa_number} mono />
            <Info label="Email" value={deal.contact.email} />
            <Info label="Company" value={deal.account?.name} />
            <Info label="Business type" value={deal.contact.business_type} />
            <Info label="Requirement" value={deal.contact.requirement_type} />
            <Info label="Location" value={[deal.contact.city, deal.contact.state, deal.contact.country].filter(Boolean).join(', ')} />
            <Info label="Timeline" value={deal.contact.timeline} />
            <Info label="Priority">
              {deal.contact.priority ? <span style={{ fontSize: 11.5, fontWeight: 800, textTransform: 'uppercase', borderRadius: 999, padding: '.1rem .5rem', background: (PRIORITY_TINT[deal.contact.priority] ?? PRIORITY_TINT.medium).bg, color: (PRIORITY_TINT[deal.contact.priority] ?? PRIORITY_TINT.medium).c }}>{deal.contact.priority}</span> : '—'}
            </Info>
            <Info label="Lead score">
              {deal.contact.score_tier ? <span style={{ color: TIER_C[String(deal.contact.score_tier).toLowerCase()] ?? '#64748b', fontWeight: 700 }}>● {deal.contact.score_tier}{deal.contact.lead_score != null ? ` · ${deal.contact.lead_score}` : ''}</span> : '—'}
            </Info>
            <Info label="Qualification" value={deal.contact.qualification_status} />
            <Info label="Current process" value={deal.contact.current_process} />
          </div>
          {deal.contact.remarks && (
            <div style={{ marginTop: '.9rem', paddingTop: '.8rem', borderTop: '1px solid #f1f5f9' }}>
              <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.04em', color: '#9ca3af', marginBottom: 3 }}>Remarks</div>
              <div style={{ fontSize: 13.5, color: '#374151', whiteSpace: 'pre-wrap' }}>{deal.contact.remarks}</div>
            </div>
          )}
        </div>
      )}

      {/* Line items */}
      <div style={card}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', margin: '0 0 .9rem' }}>
          <h3 style={{ fontSize: 15, fontWeight: 800, margin: 0 }}>🧾 Line items</h3>
          {books.length > 0 && (
            <label style={{ fontSize: 12.5, color: '#6b7280', display: 'flex', alignItems: 'center', gap: 6 }}>
              Price book
              <select value={deal.price_book_id ?? ''} onChange={e => setPriceBook(e.target.value)} style={{ border: '1px solid #e5e7eb', borderRadius: 7, padding: '.3rem .4rem', fontSize: 12.5 }}>
                <option value="">Base prices</option>
                {books.map(b => <option key={b.id} value={b.id}>{b.name} ({b.currency})</option>)}
              </select>
            </label>
          )}
        </div>
        {(deal.line_items ?? []).map(it => (
          <div key={it.id} style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '4px 10px', padding: '.45rem 0', borderBottom: '1px solid #f6f7f9' }}>
            <span style={{ flex: '1 1 180px', minWidth: 0, overflowWrap: 'anywhere', fontSize: '.88rem' }}>{it.name}</span>
            <span style={{ fontSize: '.8rem', color: '#94a3b8' }}>{it.quantity} × {money(it.unit_price, deal.currency)}{Number(it.tax_pct) ? ` +${it.tax_pct}%` : ''}</span>
            <span style={{ fontSize: '.88rem', fontWeight: 700 }}>{money(it.total, deal.currency)}</span>
            <button onClick={() => delLi(it.id)} style={{ background: 'none', border: 'none', color: '#cbd5e1', cursor: 'pointer' }}>✕</button>
          </div>
        ))}
        <div style={{ display: 'flex', gap: 6, marginTop: '.7rem', flexWrap: 'wrap' }}>
          {catalog.length > 0 && (
            <select value={li.product_id} onChange={e => pickProduct(e.target.value)} title="Pick from catalog" style={{ flex: '1 1 130px', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.45rem', fontSize: '.85rem', background: '#fff' }}>
              <option value="">— Product —</option>
              {catalog.map(p => <option key={p.id} value={p.id}>{p.name} · {money(p.price_paise, p.currency)}</option>)}
            </select>
          )}
          <input value={li.name} onChange={e => setLi(s => ({ ...s, name: e.target.value, product_id: '' }))} placeholder="Item" style={{ flex: '2 1 140px', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.45rem .6rem', fontSize: '.85rem' }} />
          <input type="number" value={li.quantity} onChange={e => setLi(s => ({ ...s, quantity: e.target.value }))} placeholder="Qty" style={{ width: 60, border: '1px solid #e5e7eb', borderRadius: 8, padding: '.45rem', fontSize: '.85rem' }} />
          <input type="number" value={li.unit_price} onChange={e => setLi(s => ({ ...s, unit_price: e.target.value }))} placeholder="Unit ₹" style={{ width: 90, border: '1px solid #e5e7eb', borderRadius: 8, padding: '.45rem', fontSize: '.85rem' }} />
          <input type="number" value={li.tax_pct} onChange={e => setLi(s => ({ ...s, tax_pct: e.target.value }))} placeholder="Tax%" style={{ width: 64, border: '1px solid #e5e7eb', borderRadius: 8, padding: '.45rem', fontSize: '.85rem' }} />
          <button onClick={addLi} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 8, padding: '.45rem .9rem', fontWeight: 600, cursor: 'pointer' }}>Add</button>
        </div>
      </div>

      <QuotesPanel dealId={id} />
      <DocumentsPanel relatedType="deal" relatedId={Number(id)} />

      {/* CRM panels for the deal */}
      <TasksPanel relatedType="deal" relatedId={Number(id)} />
      <NotesPanel relatedType="deal" relatedId={Number(id)} />
      <TimelinePanel relatedType="deal" relatedId={Number(id)} refreshKey={tlKey} />
    </div>
  )
}
