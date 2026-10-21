import { useCallback, useEffect, useRef, useState } from 'react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { Search, MessageCircle, Mail } from 'lucide-react'
import { Pill } from './AdminUi'
import { STATUS_COLOR, ago, fmtDateTime } from './adminUtil'
import { confirmAsk } from '../../components/confirmAsk'

const TOPIC = { demo: 'Demo request', pricing: 'Pricing', support: 'Support', partner: 'Partnership', other: 'Other' }

/** Live chat: sessions on the left (polled), the open conversation on the right (polled faster). */
function LiveChat() {
  const [status, setStatus] = useState('open')
  const [list, setList] = useState([])
  const [sel, setSel] = useState(null)
  const [thread, setThread] = useState(null)
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const last = useRef(0)
  const box = useRef(null)

  const loadList = useCallback(() => admin.chats(status).then(r => setList(r.sessions)).catch(() => {}), [status])
  useEffect(() => { loadList(); const t = setInterval(loadList, 5000); return () => clearInterval(t) }, [loadList])

  // unread total in the tab title so a new chat is noticed from another tab; the old title comes back on leaving
  const baseTitle = useRef(document.title)
  useEffect(() => { const n = list.reduce((a, s) => a + s.unread_agent, 0); document.title = (n ? `(${n}) ` : '') + 'Live chat — TripSarthi' }, [list])
  useEffect(() => { const t = baseTitle.current; return () => { document.title = t } }, [])

  useEffect(() => {
    if (!sel) return undefined
    last.current = 0; let alive = true
    const pull = (first) => admin.chat(sel, last.current).then(r => {
      if (!alive) return
      setThread(prev => {
        const msgs = first || !prev ? r.messages : [...prev.messages, ...r.messages.filter(m => !prev.messages.some(x => x.id === m.id))]
        if (msgs.length) last.current = msgs[msgs.length - 1].id
        return { session: r.session, messages: msgs }
      })
    }).catch(() => {})
    pull(true); const t = setInterval(() => pull(false), 3000)
    return () => { alive = false; clearInterval(t) }
  }, [sel])
  useEffect(() => { box.current?.scrollTo({ top: box.current.scrollHeight }) }, [thread?.messages?.length])

  async function send(e) {
    e.preventDefault(); if (busy) return
    if (!text.trim()) { toast.warning('One thing needs fixing', 'Type a reply first'); return }
    setBusy(true)
    try {
      await admin.chatReply(sel, text.trim()); setText('')
      const r = await admin.chat(sel, last.current)
      setThread(p => { const msgs = [...(p?.messages || []), ...r.messages.filter(m => !(p?.messages || []).some(x => x.id === m.id))]; if (msgs.length) last.current = msgs[msgs.length - 1].id; return { session: r.session, messages: msgs } })
      loadList()
    } catch (er) { toast.error('Not sent', er.message) } finally { setBusy(false) }
  }
  async function close() { if (!(await confirmAsk('Close this chat? The visitor will see it has ended.'))) return; try { await admin.chatClose(sel); setSel(null); setThread(null); loadList() } catch (er) { toast.error('Not closed', er.message) } }

  const s = thread?.session
  const initials = (n) => String(n || 'V').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase()
  const unread = list.reduce((a, c) => a + c.unread_agent, 0)
  return (
    <div className="inbox-grid">
      <div className="card inbox-list">
        <div className="cu-chips" style={{ padding: '10px 12px 0', margin: 0 }}>{[['open', 'Open'], ['closed', 'Closed']].map(([x, label]) => <button key={x} className={'cu-chip' + (status === x ? ' on' : '')} onClick={() => { setStatus(x); setSel(null); setThread(null) }}>{label}{x === 'open' && unread > 0 && <b>{unread} new</b>}</button>)}</div>
        {list.length === 0 && <p style={{ padding: 18, color: 'var(--text-3)' }}>{status === 'open' ? 'No open chats. New visitor chats appear here within seconds.' : 'No closed chats yet.'}</p>}
        {list.map(c => (
          <div key={c.id} onClick={() => setSel(c.id)} className={'inbox-row' + (sel === c.id ? ' on' : '')}>
            <span className="sp-who"><i>{initials(c.visitor_name)}</i></span>
            <div style={{ minWidth: 0, flex: 1 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 6 }}><b style={{ color: '#0a1f44' }}>{c.visitor_name || 'Visitor'} {c.visitor_online && <span title="online now" style={{ color: 'var(--success)' }}>●</span>}</b><span style={{ color: 'var(--text-3)', fontSize: 11.5 }}>{ago(c.last_message_at)}</span></div>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 6, alignItems: 'center' }}><span className="inbox-snip">{c.last_body}</span>{c.unread_agent > 0 && <span className="inbox-badge">{c.unread_agent}</span>}</div>
              {c.page_url && <div className="cu-sub" style={{ whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{c.page_url}</div>}
            </div>
          </div>))}
      </div>
      <div className="card inbox-thread">
        {!sel && <div style={{ margin: 'auto', textAlign: 'center', color: 'var(--text-3)' }}><MessageCircle size={34} strokeWidth={1.4} /><p>Select a conversation</p></div>}
        {sel && s && <>
          <div className="inbox-head">
            <div><b style={{ color: '#0a1f44' }}>{s.visitor_name || 'Visitor'}</b> {s.visitor_online ? <Pill color="var(--success)">online</Pill> : <Pill>away</Pill>}<div className="cu-sub">{s.visitor_email ? <a href={`mailto:${s.visitor_email}`}>{s.visitor_email}</a> : 'no e-mail given'} · {s.page_url || '—'} · started {fmtDateTime(s.created_at)}</div></div>
            {s.status === 'open' && <button className="btn btn-ghost btn-sm" onClick={close}>Close chat</button>}
          </div>
          <div ref={box} className="sp-thread" style={{ flex: 1, maxHeight: 'none', padding: 16, background: '#f7fafd' }}>
            {thread.messages.map(m => m.sender === 'system'
              ? <div key={m.id} className="sp-event">{m.body}</div>
              : (
                <div key={m.id} className={'sp-msg ' + (m.sender === 'agent' ? 'team' : 'customer')}>
                  <div className="sp-by">{m.sender === 'agent' ? 'You' : (s.visitor_name || 'Visitor')} · {fmtDateTime(m.created_at)}</div>
                  <div className="sp-bubble">{m.body}</div>
                </div>))}
          </div>
          {s.status === 'open'
            ? <form onSubmit={send} className="inbox-reply"><input className="form-input" style={{ flex: 1 }} placeholder="Type your reply…" aria-label="Reply" required maxLength={1000} value={text} onChange={e => setText(e.target.value)} autoFocus /><button className="btn btn-primary" disabled={busy}>Send</button></form>
            : <p style={{ padding: 12, color: 'var(--text-3)', margin: 0 }}>This chat is closed.</p>}
        </>}
      </div>
    </div>
  )
}

function Enquiries({ onCount }) {
  const [st, setSt] = useState('new')
  const [q, setQ] = useState('')
  const [rows, setRows] = useState(null)
  const load = useCallback(() => admin.enquiries(st).then(r => { setRows(r); if (st === 'new') onCount(r.length) }).catch(e => toast.error('Could not load', e.message)), [st, onCount])
  useEffect(() => { load() }, [load])
  const mark = async (id, status) => { try { await admin.enquiryStatus(id, status); load() } catch (e) { toast.error('Not changed', e.message) } }
  const needle = q.trim().toLowerCase()
  const shown = (rows || []).filter(r => !needle || `${r.name} ${r.email} ${r.company || ''} ${r.message || ''}`.toLowerCase().includes(needle))
  return (
    <div>
      <div className="cu-chips">{[['new', 'New'], ['handled', 'Handled'], ['spam', 'Spam'], ['', 'All']].map(([x, label]) => <button key={x || 'all'} className={'cu-chip' + (st === x ? ' on' : '')} onClick={() => setSt(x)}>{label}</button>)}</div>
      <div className="cu-filters"><label className="cu-search"><Search size={16} /><input placeholder="Search name, e-mail, company or message" value={q} onChange={e => setQ(e.target.value)} /></label></div>
      {!rows && <p style={{ color: 'var(--text-3)' }}>Loading…</p>}
      {rows && shown.length === 0 && <div className="card card-body" style={{ color: 'var(--text-3)', textAlign: 'center', padding: 28 }}>{st === 'new' ? 'No new enquiries — all caught up.' : 'No enquiries here.'}</div>}
      {shown.map(r => (
        <div key={r.id} className="card card-body" style={{ marginBottom: 10, borderLeft: r.status === 'new' ? '4px solid #0a6cc4' : undefined }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 8 }}>
            <div><div className="cu-name">{r.name} <Pill color={STATUS_COLOR[r.status]}>{r.status}</Pill> <Pill>{TOPIC[r.topic] || r.topic}</Pill></div>
              <div className="cu-sub" style={{ fontSize: 13.5 }}><a href={`mailto:${r.email}`}>{r.email}</a>{r.phone && <> · <a href={`tel:${r.phone}`}>{r.phone}</a></>}{r.company && <> · {r.company}</>}</div></div>
            <div style={{ color: 'var(--text-3)', fontSize: 12.5, textAlign: 'right' }}>{fmtDateTime(r.created_at)} · {ago(r.created_at)}{!r.notified_at && r.status === 'new' && <div style={{ color: 'var(--warning)' }}>notification e-mail not sent</div>}</div>
          </div>
          {r.message && <p style={{ whiteSpace: 'pre-wrap', margin: '10px 0', background: '#f7fafd', padding: '10px 14px', borderRadius: 10, lineHeight: 1.55 }}>{r.message}</p>}
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <a className="btn btn-primary btn-sm" href={`mailto:${r.email}?subject=${encodeURIComponent('Re: your TripSarthi enquiry')}`}>Reply by e-mail</a>
            {r.status !== 'handled' && <button className="btn btn-ghost btn-sm" onClick={() => mark(r.id, 'handled')}>Mark handled</button>}
            {r.status !== 'spam' && <button className="btn btn-ghost btn-sm" onClick={() => mark(r.id, 'spam')}>Spam</button>}
            {r.status !== 'new' && <button className="btn btn-ghost btn-sm" onClick={() => mark(r.id, 'new')}>Reopen</button>}
          </div>
        </div>))}
    </div>
  )
}

export default function AdminInboxPage() {
  const [tab, setTab] = useState('chat')
  const [newEnq, setNewEnq] = useState(null)
  const [openChats, setOpenChats] = useState(null)
  useEffect(() => {
    const pull = () => { admin.chats('open').then(r => setOpenChats(r.sessions.reduce((a, c) => a + c.unread_agent, 0))).catch(() => {}); admin.enquiries('new').then(r => setNewEnq(r.length)).catch(() => {}) }
    pull(); const t = setInterval(pull, 15000); return () => clearInterval(t)
  }, [])
  return (
    <div className="page" style={{ maxWidth: 1240 }}>
      <div className="page-header"><div><h1 className="page-title">Website inbox</h1><div style={{ color: 'var(--text-3)', fontSize: 13.5 }}>Visitors from tripsarthi.com: live chats and contact-form enquiries.</div></div></div>
      <div className="wa-tabs">
        <button className={tab === 'chat' ? 'on' : ''} onClick={() => setTab('chat')}><MessageCircle size={16} />Live chat{openChats > 0 && <span className="inbox-badge">{openChats}</span>}</button>
        <button className={tab === 'enq' ? 'on' : ''} onClick={() => setTab('enq')}><Mail size={16} />Contact enquiries{newEnq > 0 && <span className="inbox-badge">{newEnq}</span>}</button>
      </div>
      {tab === 'chat' ? <LiveChat /> : <Enquiries onCount={setNewEnq} />}
    </div>
  )
}
