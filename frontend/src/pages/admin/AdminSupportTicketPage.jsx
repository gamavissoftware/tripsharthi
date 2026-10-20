import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ChevronDown } from 'lucide-react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import LoadFailed from '../../components/LoadFailed'
import { Pill } from './AdminUi'
import { PLAN_COLOR, fmtDate, fmtDateTime, ago, TICKET_STATUS, PRIORITY_COLOR } from './adminUtil'
import { SUPPORT_TEMPLATES, fillTemplate, minutesText } from './supportTemplates'

const BILL_COLOR = { paying: 'var(--success)', complimentary: '#0e8f8c', free: '#64748b', lapsed: 'var(--warning)', halted: 'var(--danger)' }
const TONE = { danger: 'var(--danger)', warn: 'var(--warning)', info: 'var(--primary)', muted: 'var(--text-3)' }

/** One ticket; keyed by id so moving to another request starts from a clean page. */
export default function AdminSupportTicketPage() {
  const { id } = useParams()
  return <Ticket key={id} id={id} />
}

function Ticket({ id }) {
  const [t, setT] = useState(null)
  const [failed, setFailed] = useState(false)
  const [mode, setMode] = useState('reply')            // reply | note
  const [msg, setMsg] = useState('')
  const [after, setAfter] = useState('pending')
  const [busy, setBusy] = useState(false)
  const [tplOpen, setTplOpen] = useState(false)
  const end = useRef(null)

  useEffect(() => { admin.supportTicket(id).then(setT).catch(() => setFailed(true)) }, [id])
  useEffect(() => { end.current?.scrollIntoView({ block: 'end' }) }, [t?.messages?.length])

  const keepTeam = (next) => setT(prev => ({ ...next, team: prev?.team || [] }))
  async function send(e) {
    e?.preventDefault(); if (busy) return
    if (msg.trim().length < 2) { toast.warning('One thing needs fixing', mode === 'note' ? 'Write the note first' : 'Write your reply first'); return }
    setBusy(true)
    try {
      keepTeam(mode === 'note' ? await admin.supportNote(id, msg) : await admin.supportReply(id, msg, after))
      setMsg(''); toast.success(mode === 'note' ? 'Note saved (only the team sees it)' : 'Reply sent to the customer')
    } catch (err) { toast.error('Not sent', err.message) }
    setBusy(false)
  }
  async function patch(body) { try { keepTeam(await admin.supportUpdate(id, body)) } catch (err) { toast.error('Not changed', err.message) } }
  const applyTemplate = (tpl) => { setMode('reply'); setMsg(m => (m.trim() ? m + '\n\n' : '') + fillTemplate(tpl, t.user_name)); setTplOpen(false) }

  if (failed) return <LoadFailed what="ticket" backTo="/admin/support" />
  if (!t) return <div className="page"><div className="card card-body" style={{ color: 'var(--text-3)' }}>Loading…</div></div>
  const [sl, sc] = TICKET_STATUS[t.status] || [t.status, 'var(--text-3)']
  const cu = t.customer
  return (
    <div className="page">
      <div style={{ marginBottom: 8 }}><Link to="/admin/support">← Support</Link></div>
      <div className="page-header">
        <div>
          <h1 className="page-title" style={{ marginBottom: 4 }}>{t.subject}</h1>
          <div className="sp-meta"><span>{t.number}</span><span>{t.category_label}</span><Pill color={sc}>{sl}</Pill><Pill color={PRIORITY_COLOR[t.priority]}>{t.priority}</Pill>
            {t.waiting_hours != null && <span style={{ color: t.past_target ? 'var(--danger)' : 'var(--warning)', fontWeight: 700 }}>customer waiting {t.waiting_hours < 1 ? 'since just now' : `${t.waiting_hours} h`}{t.past_target ? ' · past target' : ''}</span>}</div>
        </div>
      </div>

      <div className="sp-grid">
        <div>
          <div className="card card-body sp-thread">
            {t.messages.map(m => m.from === 'event'
              ? <div key={m.id} className="sp-event">{m.body} · {fmtDateTime(m.at)}</div>
              : (
                <div key={m.id} className={'sp-msg ' + m.from}>
                  <div className="sp-by">{m.from === 'customer' ? t.user_name : `${m.name || 'Team'}${m.from === 'note' ? ' · internal note' : ''}`} · {fmtDateTime(m.at)}</div>
                  <div className="sp-bubble">{m.body}</div>
                </div>))}
            <div ref={end} />
          </div>

          {t.status === 'closed'
            ? <p style={{ color: 'var(--text-3)', marginTop: 12 }}>Closed. Reopen it from the status box if you need to reply.</p>
            : (
              <form onSubmit={send} className="card card-body" style={{ marginTop: 12 }}>
                <div className="sp-tabs">
                  <button type="button" className={mode === 'reply' ? 'on' : ''} onClick={() => setMode('reply')}>Reply to customer</button>
                  <button type="button" className={mode === 'note' ? 'on warn' : ''} onClick={() => setMode('note')}>Internal note</button>
                  {mode === 'reply' && <div className="sp-tpl">
                    <button type="button" className="btn btn-ghost btn-sm" onClick={() => setTplOpen(o => !o)}>Quick replies <ChevronDown size={14} /></button>
                    {tplOpen && <div className="sp-tpl-menu">{SUPPORT_TEMPLATES.map(x => <button key={x.key} type="button" onClick={() => applyTemplate(x)}>{x.label}</button>)}</div>}
                  </div>}
                </div>
                <textarea className={'form-input' + (mode === 'note' ? ' sp-note-input' : '')} rows={6} value={msg} required minLength={2} maxLength={5000} aria-label={mode === 'note' ? 'Internal note' : 'Reply to customer'} onChange={e => setMsg(e.target.value)}
                  onKeyDown={e => { if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') send(e) }}
                  placeholder={mode === 'note' ? 'Only the TripSarthi team can read this.' : 'The customer gets this by e-mail and in their app. Ctrl/Cmd + Enter sends.'} />
                <div style={{ display: 'flex', gap: 10, marginTop: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                  <button className="btn btn-primary" disabled={busy}>{busy ? 'Sending…' : mode === 'note' ? 'Save note' : 'Send reply'}</button>
                  {mode === 'reply' && <label style={{ fontSize: 13 }}>then mark as{' '}
                    <select className="form-select" style={{ display: 'inline-block', width: 'auto' }} value={after} onChange={e => setAfter(e.target.value)}>
                      <option value="pending">Waiting on customer</option><option value="resolved">Resolved</option><option value="open">Still open</option></select></label>}
                  <span className="cu-sub" style={{ marginLeft: 'auto' }}>{msg.length}/5000</span>
                </div>
              </form>)}
        </div>

        <div style={{ display: 'grid', gap: 12 }}>
          <div className="card card-body" style={{ display: 'grid', gap: 10 }}>
            <label style={{ fontSize: 13 }}>Status
              <select className="form-select" value={t.status} onChange={e => patch({ status: e.target.value })}>
                {Object.entries(TICKET_STATUS).map(([k, [l]]) => <option key={k} value={k}>{l}</option>)}</select></label>
            <label style={{ fontSize: 13 }}>Priority
              <select className="form-select" value={t.priority} onChange={e => patch({ priority: e.target.value })}>
                {['low', 'normal', 'high', 'urgent'].map(p => <option key={p}>{p}</option>)}</select></label>
            <label style={{ fontSize: 13 }}>Assigned to
              <select className="form-select" value={t.assigned_to || ''} onChange={e => patch({ assigned_to: e.target.value || null })}>
                <option value="">Nobody</option>{t.team.map(m => <option key={m.id} value={m.id}>{m.name}</option>)}</select></label>
            <div className="cu-sub">Opened {fmtDateTime(t.created_at)} · first answer: <b>{t.first_response_minutes == null ? 'not yet' : minutesText(t.first_response_minutes)}</b></div>
          </div>

          <div className="card card-body" style={{ fontSize: 13.5 }}>
            <div style={{ fontWeight: 800, marginBottom: 6, color: '#0a1f44' }}>Customer</div>
            <div><Link to={`/admin/customers?open=${t.tenant_id}`}><b>{t.tenant_name}</b></Link> <Pill color={PLAN_COLOR[t.tenant_plan]}>{t.tenant_plan}</Pill>{t.tenant_status !== 'active' && <Pill color="var(--danger)">{t.tenant_status}</Pill>}</div>
            {cu && <>
              <div style={{ marginTop: 6 }}><Pill color={BILL_COLOR[cu.billing.state]}>{cu.billing.label}</Pill> <span className="cu-sub">{cu.billing.renews_at ? `runs to ${fmtDate(cu.billing.renews_at)}` : cu.billing.state === 'free' ? 'never paid' : ''}</span></div>
              <div className="cu-sub" style={{ marginTop: 6 }}>Last seen {ago(cu.last_active)} · {cu.usage.contacts.toLocaleString('en-IN')} contacts · {cu.usage.users} user{cu.usage.users === 1 ? '' : 's'}</div>
              {cu.flags.length > 0 && <div className="cu-flags">{cu.flags.map(x => <span key={x.key} className="cu-flag" style={{ '--c': TONE[x.tone] }}>{x.label}</span>)}</div>}
            </>}
            <div style={{ marginTop: 8 }}>{t.user_name} <span className="cu-sub">({t.user_role})</span></div>
            <div><a href={`mailto:${t.user_email}`}>{t.user_email}</a></div>
            <div style={{ display: 'flex', gap: 8, marginTop: 10, flexWrap: 'wrap' }}>
              <Link className="btn btn-ghost btn-sm" to={`/admin/customers?open=${t.tenant_id}`}>Open customer</Link>
              <Link className="btn btn-ghost btn-sm" to={`/admin/customers?open=${t.tenant_id}&login=1`} title="Sign in as this customer to see what they see (a reason is required and the customer's audit log shows it)">Log in as customer</Link>
            </div>
          </div>

          {t.previous.length > 0 && <div className="card card-body" style={{ fontSize: 13.5 }}>
            <div style={{ fontWeight: 800, marginBottom: 6, color: '#0a1f44' }}>Earlier requests from this workspace</div>
            {t.previous.map(p => { const [pl, pc] = TICKET_STATUS[p.status] || [p.status, 'var(--text-3)']; return <Link key={p.id} to={`/admin/support/${p.id}`} className="dash-li"><span><b>{p.subject}</b><small>{p.number} · {fmtDate(p.created_at)}</small></span><Pill color={pc}>{pl}</Pill></Link> })}
          </div>}
        </div>
      </div>
    </div>
  )
}
