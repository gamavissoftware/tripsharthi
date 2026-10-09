import { useState, useEffect, useRef, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { notifications as notifApi } from '../api/notifications'

// ── In-app notification bell (Phase L) ───────────────────────────────────────
// Polls the feed, shows an unread badge, and opens a dropdown of recent items.

const ago = (ts) => {
  if (!ts) return ''
  const s = Math.max(0, (Date.now() - new Date(ts.replace(' ', 'T')).getTime()) / 1000)
  if (s < 60) return 'just now'
  if (s < 3600) return `${Math.floor(s / 60)}m ago`
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`
  return `${Math.floor(s / 86400)}d ago`
}

export default function NotificationBell() {
  const navigate = useNavigate()
  const [items, setItems] = useState([])
  const [unread, setUnread] = useState(0)
  const [open, setOpen] = useState(false)
  const wrapRef = useRef(null)

  const load = useCallback(async () => {
    try { const r = await notifApi.feed(); setItems(r.data ?? []); setUnread(r.unread ?? 0) } catch { /* noop */ }
  }, [])

  useEffect(() => { load(); const id = setInterval(load, 45000); return () => clearInterval(id) }, [load])
  useEffect(() => {
    function onDoc(e) { if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false) }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [])

  async function openItem(n) {
    setOpen(false)
    if (!n.read_at) { try { await notifApi.markRead(n.id) } catch { /* noop */ } setUnread(u => Math.max(0, u - 1)) }
    if (n.link) navigate(n.link)
  }
  async function markAll() {
    try { await notifApi.markAllRead(); setUnread(0); setItems(its => its.map(n => ({ ...n, read_at: n.read_at || 'now' }))) } catch { /* noop */ }
  }

  return (
    <div ref={wrapRef} style={{ position: 'relative' }}>
      <button onClick={() => { setOpen(o => !o); if (!open) load() }} title="Notifications"
        style={{ position: 'relative', background: '#fff', border: '1.5px solid #e5e7eb', borderRadius: 12, width: 40, height: 40, cursor: 'pointer', fontSize: 17, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
        🔔
        {unread > 0 && (
          <span style={{ position: 'absolute', top: -5, right: -5, background: '#ef4444', color: '#fff', borderRadius: 999, fontSize: 10, fontWeight: 800, minWidth: 17, height: 17, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '0 4px' }}>
            {unread > 9 ? '9+' : unread}
          </span>
        )}
      </button>

      {open && (
        <div style={{ position: 'absolute', top: 'calc(100% + 6px)', right: 0, width: 320, maxHeight: '60vh', overflow: 'auto', background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, boxShadow: '0 16px 44px rgba(0,0,0,.16)', zIndex: 9000 }}>
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '.7rem .9rem', borderBottom: '1px solid #f0f1f3' }}>
            <span style={{ fontWeight: 800, fontSize: 13 }}>Notifications</span>
            {unread > 0 && <button onClick={markAll} style={{ background: 'none', border: 'none', color: 'var(--primary,#0a6cc4)', fontSize: 12, fontWeight: 700, cursor: 'pointer' }}>Mark all read</button>}
          </div>
          {items.length === 0 ? (
            <div style={{ padding: '1.6rem .9rem', textAlign: 'center', color: '#9ca3af', fontSize: 13 }}>You're all caught up ✨</div>
          ) : items.map(n => (
            <div key={n.id} onClick={() => openItem(n)} style={{ display: 'flex', gap: 9, padding: '.6rem .9rem', borderBottom: '1px solid #f6f7f9', cursor: 'pointer', background: n.read_at ? '#fff' : '#f5f7ff' }}>
              <span style={{ fontSize: 15 }}>{n.type === 'mention' ? '💬' : '🔔'}</span>
              <div style={{ minWidth: 0 }}>
                <div style={{ fontSize: 13, color: '#111827' }}>{n.body}</div>
                <div style={{ fontSize: 11, color: '#9ca3af', marginTop: 2 }}>{ago(n.created_at)}</div>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
