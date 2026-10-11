import { useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import LoadFailed from '../../components/LoadFailed'
import { Pill } from './AdminUi'
import { PLAN_COLOR, fmtDateTime, TICKET_STATUS, PRIORITY_COLOR } from './adminUtil'

export default function AdminSupportTicketPage() {
  const { id } = useParams()
  const [t, setT] = useState(null)
  const [failed, setFailed] = useState(false)
  const [mode, setMode] = useState('reply')            // reply | note
  const [msg, setMsg] = useState('')
  const [after, setAfter] = useState('pending')
  const [busy, setBusy] = useState(false)
  const end = useRef(null)

  useEffect(() => { admin.supportTicket(id).then(setT).catch(() => setFailed(true)) }, [id])
  useEffect(() => { end.current?.scrollIntoView({ block: 'end' }) }, [t?.messages?.length])

  const keepTeam = (next) => setT(prev => ({ ...next, team: prev?.team || [] }))
  async function send(e) {
    e.preventDefault(); if (!msg.trim()) return
    setBusy(true)
    try {
      keepTeam(mode === 'note' ? await admin.supportNote(id, msg) : await admin.supportReply(id, msg, after))
      setMsg(''); toast.success(mode === 'note' ? 'Note saved (only the team sees it)' : 'Reply sent to the customer')
    } catch (err) { toast.error('Not sent', err.message) }
    setBusy(false)
  }
  async function patch(body) { try { keepTeam(await admin.supportUpdate(id, body)) } catch (err) { toast.error('Not changed', err.message) } }

  if (failed) return <LoadFailed what="ticket" backTo="/admin/support" />
  if (!t) return <div className="page"><div className="card card-body" style={{ color: 'var(--text-3)' }}>Loading…</div></div>
  const [sl, sc] = TICKET_STATUS[t.status] || [t.status, 'var(--text-3)']
  return (
    <div className="page">
      <div style={{ marginBottom: 8 }}><Link to="/admin/support">← Support</Link></div>
      <div className="page-header">
        <div><h1 className="page-title" style={{ marginBottom: 2 }}>{t.subject}</h1>
          <div style={{ color: 'var(--text-3)', fontSize: 13 }}>{t.number} · {t.category_label} · <Pill color={sc}>{sl}</Pill></div></div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'minmax(0,1fr) 280px', gap: 14, alignItems: 'start' }} className="admin-ticket-grid">
        <div>
          <div className="card card-body" style={{ display: 'grid', gap: 12 }}>
            {t.messages.map(m => m.from === 'event'
              ? <div key={m.id} style={{ textAlign: 'center', color: 'var(--text-3)', fontSize: 12.5 }}>{m.body} · {fmtDateTime(m.at)}</div>
              : (
                <div key={m.id} style={{ justifySelf: m.from === 'customer' ? 'start' : 'end', maxWidth: '88%' }}>
                  <div style={{ fontSize: 12, color: 'var(--text-3)', marginBottom: 2, textAlign: m.from === 'customer' ? 'left' : 'right' }}>
                    {m.from === 'customer' ? t.user_name : `${m.name || 'Team'}${m.from === 'note' ? ' · internal note' : ''}`} · {fmtDateTime(m.at)}</div>
                  <div style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', padding: '10px 14px', borderRadius: 12,
                    background: m.from === 'note' ? 'color-mix(in srgb, var(--warning) 14%, transparent)' : m.from === 'team' ? 'color-mix(in srgb, var(--primary) 10%, transparent)' : 'var(--surface-2, #f3f7fc)',
                    border: m.from === 'note' ? '1px dashed var(--warning)' : 'none' }}>{m.body}</div>
                </div>))}
            <div ref={end} />
          </div>

          {t.status === 'closed'
            ? <p style={{ color: 'var(--text-3)', marginTop: 12 }}>Closed. Reopen it from the status box if you need to reply.</p>
            : (
              <form onSubmit={send} className="card card-body" style={{ marginTop: 12 }}>
                <div style={{ display: 'flex', gap: 8, marginBottom: 8 }}>
                  <button type="button" className={'btn ' + (mode === 'reply' ? 'btn-primary' : 'btn-ghost')} onClick={() => setMode('reply')}>Reply to customer</button>
                  <button type="button" className={'btn ' + (mode === 'note' ? 'btn-primary' : 'btn-ghost')} onClick={() => setMode('note')}>Internal note</button>
                </div>
                <textarea className="form-input" rows={5} value={msg} maxLength={5000} onChange={e => setMsg(e.target.value)} placeholder={mode === 'note' ? 'Only the TripSarthi team can read this.' : 'The customer gets this by e-mail and in their app.'} />
                <div style={{ display: 'flex', gap: 10, marginTop: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                  <button className="btn btn-primary" disabled={busy || !msg.trim()}>{busy ? 'Sending…' : mode === 'note' ? 'Save note' : 'Send reply'}</button>
                  {mode === 'reply' && <label style={{ fontSize: 13 }}>then mark as{' '}
                    <select className="form-select" style={{ display: 'inline-block', width: 'auto' }} value={after} onChange={e => setAfter(e.target.value)}>
                      <option value="pending">Waiting on customer</option><option value="resolved">Resolved</option><option value="open">Still open</option></select></label>}
                </div>
              </form>)}
        </div>

        <div style={{ display: 'grid', gap: 12 }}>
          <div className="card card-body" style={{ display: 'grid', gap: 10 }}>
            <label style={{ fontSize: 13 }}>Status
              <select className="form-select" value={t.status} onChange={e => patch({ status: e.target.value })}>
                {Object.entries(TICKET_STATUS).map(([k, [l]]) => <option key={k} value={k}>{l}</option>)}</select></label>
            <label style={{ fontSize: 13 }}>Priority <Pill color={PRIORITY_COLOR[t.priority]}>{t.priority}</Pill>
              <select className="form-select" value={t.priority} onChange={e => patch({ priority: e.target.value })}>
                {['low', 'normal', 'high', 'urgent'].map(p => <option key={p}>{p}</option>)}</select></label>
            <label style={{ fontSize: 13 }}>Assigned to
              <select className="form-select" value={t.assigned_to || ''} onChange={e => patch({ assigned_to: e.target.value || null })}>
                <option value="">Nobody</option>{t.team.map(m => <option key={m.id} value={m.id}>{m.name}</option>)}</select></label>
          </div>
          <div className="card card-body" style={{ fontSize: 13.5 }}>
            <div style={{ fontWeight: 700, marginBottom: 4 }}>Customer</div>
            <div><Link to={`/admin/customers?open=${t.tenant_id}`}><b>{t.tenant_name}</b></Link> <Pill color={PLAN_COLOR[t.tenant_plan]}>{t.tenant_plan}</Pill></div>
            <div style={{ color: 'var(--text-3)' }}>Workspace {t.tenant_status}</div>
            <div style={{ marginTop: 6 }}>{t.user_name} <span style={{ color: 'var(--text-3)' }}>({t.user_role})</span></div>
            <div><a href={`mailto:${t.user_email}`}>{t.user_email}</a></div>
            {t.other_tickets > 0 && <div style={{ marginTop: 6, color: 'var(--text-3)' }}>{t.other_tickets} other request{t.other_tickets === 1 ? '' : 's'} from this workspace</div>}
          </div>
        </div>
      </div>
    </div>
  )
}
