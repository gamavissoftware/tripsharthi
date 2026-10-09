import { useCallback, useEffect, useRef, useState } from 'react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { Pill } from './AdminUi'
import { STATUS_COLOR, ago, fmtDateTime } from './adminUtil'

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

  // unread total in the tab title so a new chat is noticed from another tab
  useEffect(() => { const n = list.reduce((a, s) => a + s.unread_agent, 0); document.title = (n ? `(${n}) ` : '') + 'Live chat — TripSarthi'; return () => { document.title = 'TripSarthi — Your Travel Business. Simplified.' } }, [list])

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
    e.preventDefault(); if (!text.trim() || busy) return
    setBusy(true)
    try {
      await admin.chatReply(sel, text.trim()); setText('')
      const r = await admin.chat(sel, last.current)
      setThread(p => { const msgs = [...(p?.messages || []), ...r.messages.filter(m => !(p?.messages || []).some(x => x.id === m.id))]; if (msgs.length) last.current = msgs[msgs.length - 1].id; return { session: r.session, messages: msgs } })
      loadList()
    } catch (er) { toast.error('Not sent', er.message) } finally { setBusy(false) }
  }
  async function close() { if (!window.confirm('Close this chat? The visitor will see it has ended.')) return; try { await admin.chatClose(sel); setSel(null); setThread(null); loadList() } catch (er) { toast.error('Not closed', er.message) } }

  const s = thread?.session
  return (
    <div style={{ display: 'grid', gridTemplateColumns: 'minmax(260px,330px) 1fr', gap: 12, minHeight: 520 }}>
      <div className="card" style={{ overflowY: 'auto', maxHeight: 640 }}>
        <div style={{ display: 'flex', gap: 6, padding: 10, borderBottom: '1px solid var(--border)' }}>{['open', 'closed'].map(x => <button key={x} className={'btn btn-sm ' + (status === x ? 'btn-primary' : 'btn-ghost')} onClick={() => { setStatus(x); setSel(null); setThread(null) }}>{x === 'open' ? 'Open' : 'Closed'}</button>)}</div>
        {list.length === 0 && <p style={{ padding: 16, color: 'var(--text-3)' }}>{status === 'open' ? 'No open chats. New visitor chats appear here within seconds.' : 'No closed chats yet.'}</p>}
        {list.map(c => (
          <div key={c.id} onClick={() => setSel(c.id)} style={{ padding: '10px 12px', borderBottom: '1px solid var(--border)', cursor: 'pointer', background: sel === c.id ? 'var(--primary-light)' : 'transparent' }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 6 }}><b>{c.visitor_name || 'Visitor'} {c.visitor_online && <span title="online now" style={{ color: 'var(--success)' }}>●</span>}</b>{c.unread_agent > 0 && <span style={{ background: 'var(--danger)', color: '#fff', borderRadius: 999, fontSize: 11, padding: '1px 7px', fontWeight: 700 }}>{c.unread_agent}</span>}</div>
            <div style={{ color: 'var(--text-2)', fontSize: 13, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{c.last_body}</div>
            <div style={{ color: 'var(--text-3)', fontSize: 11.5 }}>{ago(c.last_message_at)}{c.page_url ? ` · ${c.page_url}` : ''}</div>
          </div>))}
      </div>
      <div className="card" style={{ display: 'flex', flexDirection: 'column', maxHeight: 640 }}>
        {!sel && <p style={{ margin: 'auto', color: 'var(--text-3)' }}>Select a conversation</p>}
        {sel && s && <>
          <div style={{ padding: '10px 14px', borderBottom: '1px solid var(--border)', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10 }}>
            <div><b>{s.visitor_name || 'Visitor'}</b> {s.visitor_online ? <Pill color="var(--success)">online</Pill> : <Pill>away</Pill>}<div style={{ color: 'var(--text-3)', fontSize: 12.5 }}>{s.visitor_email ? <a href={`mailto:${s.visitor_email}`}>{s.visitor_email}</a> : 'no email given'} · {s.page_url || '—'} · started {fmtDateTime(s.created_at)}</div></div>
            {s.status === 'open' && <button className="btn btn-ghost btn-sm" onClick={close}>Close chat</button>}
          </div>
          <div ref={box} style={{ flex: 1, overflowY: 'auto', padding: 14, display: 'flex', flexDirection: 'column', gap: 8, background: 'var(--surface-2)' }}>
            {thread.messages.map(m => (
              <div key={m.id} style={{ alignSelf: m.sender === 'agent' ? 'flex-end' : m.sender === 'system' ? 'center' : 'flex-start', maxWidth: '78%' }}>
                <div style={{ padding: '8px 12px', borderRadius: 14, fontSize: 14.5, whiteSpace: 'pre-wrap', wordBreak: 'break-word', background: m.sender === 'agent' ? 'var(--primary)' : m.sender === 'system' ? 'transparent' : '#fff', color: m.sender === 'agent' ? '#fff' : m.sender === 'system' ? 'var(--text-3)' : 'var(--text)', border: m.sender === 'visitor' ? '1px solid var(--border)' : 'none', fontStyle: m.sender === 'system' ? 'italic' : 'normal' }}>{m.body}</div>
                <div style={{ fontSize: 11, color: 'var(--text-3)', textAlign: m.sender === 'agent' ? 'right' : 'left', padding: '2px 4px' }}>{fmtDateTime(m.created_at)}</div>
              </div>))}
          </div>
          {s.status === 'open'
            ? <form onSubmit={send} style={{ display: 'flex', gap: 8, padding: 10, borderTop: '1px solid var(--border)' }}><input className="form-input" style={{ flex: 1 }} placeholder="Type your reply…" value={text} onChange={e => setText(e.target.value)} autoFocus /><button className="btn btn-primary" disabled={busy || !text.trim()}>Send</button></form>
            : <p style={{ padding: 12, color: 'var(--text-3)', margin: 0 }}>This chat is closed.</p>}
        </>}
      </div>
    </div>
  )
}

function Enquiries() {
  const [st, setSt] = useState('new')
  const [rows, setRows] = useState(null)
  const load = useCallback(() => admin.enquiries(st).then(setRows).catch(e => toast.error('Could not load', e.message)), [st])
  useEffect(() => { load() }, [load])
  const mark = async (id, status) => { try { await admin.enquiryStatus(id, status); load() } catch (e) { toast.error('Not changed', e.message) } }
  return (
    <div>
      <div style={{ display: 'flex', gap: 6, marginBottom: 10 }}>{['new', 'handled', 'spam', ''].map(x => <button key={x || 'all'} className={'btn btn-sm ' + (st === x ? 'btn-primary' : 'btn-ghost')} onClick={() => setSt(x)}>{x || 'All'}</button>)}</div>
      {!rows && <p style={{ color: 'var(--text-3)' }}>Loading…</p>}
      {rows && rows.length === 0 && <div className="card card-body" style={{ color: 'var(--text-3)' }}>No enquiries here.</div>}
      {rows?.map(r => (
        <div key={r.id} className="card card-body" style={{ marginBottom: 10 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 8 }}>
            <div><b>{r.name}</b> <Pill color={STATUS_COLOR[r.status]}>{r.status}</Pill> <Pill>{TOPIC[r.topic] || r.topic}</Pill>
              <div style={{ color: 'var(--text-2)', fontSize: 14, marginTop: 4 }}><a href={`mailto:${r.email}`}>{r.email}</a>{r.phone && <> · <a href={`tel:${r.phone}`}>{r.phone}</a></>}{r.company && <> · {r.company}</>}</div></div>
            <div style={{ color: 'var(--text-3)', fontSize: 12.5, textAlign: 'right' }}>{fmtDateTime(r.created_at)}{!r.notified_at && r.status === 'new' && <div style={{ color: 'var(--warning)' }}>notification email not sent</div>}</div>
          </div>
          {r.message && <p style={{ whiteSpace: 'pre-wrap', margin: '10px 0', background: 'var(--surface-2)', padding: 10, borderRadius: 8 }}>{r.message}</p>}
          <div style={{ display: 'flex', gap: 8 }}>
            <a className="btn btn-primary btn-sm" href={`mailto:${r.email}?subject=${encodeURIComponent('Re: your TripSarthi enquiry')}`}>Reply by email</a>
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
  return (
    <div className="page" style={{ maxWidth: 1240 }}>
      <div className="page-header"><h1 className="page-title">Website inbox</h1>
        <div style={{ display: 'flex', gap: 6 }}><button className={'btn btn-sm ' + (tab === 'chat' ? 'btn-primary' : 'btn-ghost')} onClick={() => setTab('chat')}>Live chat</button><button className={'btn btn-sm ' + (tab === 'enq' ? 'btn-primary' : 'btn-ghost')} onClick={() => setTab('enq')}>Contact enquiries</button></div>
      </div>
      {tab === 'chat' ? <LiveChat /> : <Enquiries />}
    </div>
  )
}
