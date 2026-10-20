import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { LifeBuoy, CreditCard, MessageCircle, Wrench, CircleHelp, Clock3, ChevronRight } from 'lucide-react'
import { support } from '../api/support'
import { toast } from '../components/Toast'
import { SUPPORT_STATUS } from './supportUi'

const pill = (status) => { const [label, color] = SUPPORT_STATUS[status] || [status, 'var(--text-3)']; return <span style={{ display: 'inline-block', padding: '2px 9px', borderRadius: 999, fontSize: 12, fontWeight: 600, color, background: 'color-mix(in srgb, currentColor 12%, transparent)' }}>{label}</span> }
const when = (s) => (s ? new Date(String(s).replace(' ', 'T') + 'Z').toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '')

/** Contact the TripSarthi team: raise a request, follow the replies. */
export default function SupportPage() {
  const nav = useNavigate()
  const [d, setD] = useState(null)
  const [filter, setFilter] = useState('all')
  const [form, setForm] = useState({ subject: '', category: 'howto', message: '' })
  const [busy, setBusy] = useState(false)
  const [showForm, setShowForm] = useState(false)

  const load = () => support.list(filter).then(setD).catch(e => toast.error('Could not load', e.message))
  useEffect(() => { load() }, [filter]) // eslint-disable-line react-hooks/exhaustive-deps

  async function submit(e) {
    e.preventDefault(); setBusy(true)
    try { const t = await support.create(form); toast.success('Request sent', `Reference ${t.number}. We'll reply here and by e-mail.`); nav(`/support/${t.id}`) }
    catch (err) { toast.error('Not sent', err.message) }
    setBusy(false)
  }

  const TILES = [['billing', 'Billing & plans', 'Upgrades, invoices, coupons', CreditCard, '#0a6cc4'], ['whatsapp', 'WhatsApp', 'Connecting a number, templates', MessageCircle, '#1a9d57'],
    ['technical', 'Something is not working', 'Errors or odd behaviour', Wrench, '#d97706'], ['howto', 'How do I…?', 'Ask us anything', CircleHelp, '#12a89e']]
  const pick = (cat) => { setForm(f => ({ ...f, category: cat })); setShowForm(true); setTimeout(() => document.getElementById('support-title')?.focus(), 50) }

  return (
    <div className="page page-wide">
      <section style={{ position: 'relative', overflow: 'hidden', borderRadius: 20, padding: '26px 28px', color: '#fff', background: 'linear-gradient(125deg,#0a1f44 0%,#0a4aa0 55%,#0b8f94 100%)', boxShadow: '0 16px 40px rgba(10,31,68,.18)', marginBottom: 18 }}>
        <div style={{ position: 'absolute', width: 300, height: 300, right: -90, top: -130, borderRadius: '50%', background: 'radial-gradient(circle, rgba(47,208,194,.35), transparent 68%)' }} />
        <div style={{ position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16, flexWrap: 'wrap' }}>
          <div>
            <h1 style={{ color: '#fff', margin: '0 0 6px', fontSize: '1.7rem', fontWeight: 800, letterSpacing: '-.5px', display: 'flex', alignItems: 'center', gap: 10 }}><LifeBuoy size={26} /> Help &amp; support</h1>
            <p style={{ margin: 0, color: '#d4e3f8', maxWidth: 560, lineHeight: 1.55 }}>Stuck, or something not working? Send us a request and the TripSarthi team will reply here and by e-mail.</p>
            <div style={{ marginTop: 10, display: 'inline-flex', alignItems: 'center', gap: 6, background: 'rgba(255,255,255,.14)', border: '1px solid rgba(255,255,255,.25)', borderRadius: 999, padding: '5px 12px', fontSize: 13, fontWeight: 600 }}><Clock3 size={14} /> We usually reply within one working day</div>
          </div>
          <button className="btn btn-primary" style={{ background: '#fff', color: '#0a1f44', borderColor: '#fff', boxShadow: '0 8px 20px rgba(0,0,0,.2)', minHeight: 44 }} onClick={() => setShowForm(v => !v)}>{showForm ? 'Cancel' : '+ New request'}</button>
        </div>
      </section>

      {!showForm && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(210px,1fr))', gap: 12, marginBottom: 18 }}>
          {TILES.map(([k, t, sub, Icon, c]) => (
            <button key={k} onClick={() => pick(k)} style={{ textAlign: 'left', cursor: 'pointer', background: '#fff', border: '1px solid #e3eaf3', borderRadius: 16, padding: '16px 16px 14px', boxShadow: '0 2px 10px rgba(10,31,68,.05)', display: 'grid', gap: 4, font: 'inherit' }}>
              <span style={{ width: 38, height: 38, borderRadius: 11, background: c, color: '#fff', display: 'grid', placeItems: 'center', marginBottom: 6 }}><Icon size={18} /></span>
              <b style={{ color: '#0a1f44' }}>{t}</b><span style={{ fontSize: 13, color: '#6b7a93' }}>{sub}</span>
            </button>))}
        </div>)}

      {showForm && (
        <form className="card card-body" onSubmit={submit} style={{ marginBottom: 18, maxWidth: 720 }}>
          <div style={{ display: 'grid', gap: 12 }}>
            <label>What is it about? *
              <select className="form-select" required value={form.category} onChange={e => setForm({ ...form, category: e.target.value })}>
                {Object.entries(d?.categories || {}).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select></label>
            <label>Short title *<input id="support-title" className="form-input" value={form.subject} minLength={5} maxLength={160} onChange={e => setForm({ ...form, subject: e.target.value })} placeholder="e.g. Cannot connect my WhatsApp number" required /></label>
            <label>Tell us what happened *<textarea className="form-input" rows={6} value={form.message} minLength={10} maxLength={5000} onChange={e => setForm({ ...form, message: e.target.value })} placeholder="What you did, what you expected, what you saw. Please don't include passwords or card numbers." required /></label>
          </div>
          <div style={{ marginTop: 12 }}><button className="btn btn-primary" disabled={busy}>{busy ? 'Sending…' : 'Send to the TripSarthi team'}</button></div>
        </form>
      )}

      <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
        {[['all', 'All'], ['open', 'Open'], ['closed', 'Closed']].map(([k, l]) => (
          <button key={k} className={'btn ' + (filter === k ? 'btn-primary' : 'btn-ghost')} onClick={() => setFilter(k)}>{l}</button>))}
      </div>

      <div className="card" style={{ overflow: 'hidden' }}>
        {!d && <div className="card-body" style={{ color: 'var(--text-3)' }}>Loading…</div>}
        {d && d.tickets.length === 0 && (
          <div style={{ textAlign: 'center', padding: '38px 20px 42px', color: '#5b6b86' }}>
            <svg width="64" height="64" viewBox="0 0 64 64" fill="none" aria-hidden="true" style={{ display: 'block', margin: '0 auto 10px' }}><circle cx="32" cy="32" r="30" fill="#eef4fc" /><path d="M20 38l8-8 6 6 10-12" stroke="#0a6cc4" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" /><circle cx="44" cy="24" r="3" fill="#12a89e" /></svg>
            <b style={{ display: 'block', color: '#0a1f44', fontSize: '1.02rem', marginBottom: 4 }}>No requests yet</b>
            <span style={{ fontSize: 13.5 }}>Everything running smoothly? Great. If you ever need a hand, pick a topic above or press “New request”.</span>
          </div>)}
        {d?.tickets.map((t, i) => (
          <Link key={t.id} to={`/support/${t.id}`} style={{ display: 'flex', gap: 12, alignItems: 'center', padding: '14px 18px', borderTop: i ? '1px solid #edf1f7' : 'none', color: 'inherit', textDecoration: 'none' }}>
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontWeight: t.unread ? 800 : 600, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', color: '#0a1f44' }}>{t.unread && <span style={{ color: 'var(--primary)' }}>● </span>}{t.subject}</div>
              <div style={{ color: 'var(--text-3)', fontSize: 12.5 }}>{t.number} · {t.category_label} · {when(t.last_message_at)}</div>
            </div>
            {pill(t.status)}<ChevronRight size={16} color="#9fb1cc" />
          </Link>))}
      </div>
    </div>
  )
}
