import { useState, useEffect, useRef, useCallback } from 'react'
import { useNavigate } from 'react-router-dom'
import { crm } from '../api/crm'

// ── Global quick search (Phase G3) ───────────────────────────────────────────
// A debounced search box that queries /crm/search and shows grouped results
// (contacts, accounts, deals, tickets, and read-only message matches). Clicking
// a result navigates to its detail page.

if (typeof document !== 'undefined' && !document.getElementById('lp-gs-css')) {
  const el = document.createElement('style')
  el.id = 'lp-gs-css'
  el.textContent = `
    .lp-gs-wrap { position:relative; width:100%; max-width:520px; }
    .lp-gs-input {
      width:100%; box-sizing:border-box; padding:.4rem .8rem .4rem 2.2rem; border-radius:4px;
      border:1px solid #cdd0d5; background:#fff; font-size:14px; color:#26292c; outline:none;
      transition:border-color .15s, background .15s;
    }
    .lp-gs-input:focus { border-color:var(--primary,#0a6cc4); background:#fff; }
    .lp-gs-pop {
      position:absolute; top:calc(100% + 6px); left:0; right:0; z-index:9000; background:#fff;
      border:1px solid #e3e5e8; border-radius:6px; box-shadow:0 8px 24px rgba(38,41,44,.16);
      max-height:60vh; overflow:auto; padding:.4rem;
    }
    .lp-gs-group-label { font-size:10.5px; text-transform:uppercase; letter-spacing:.06em; color:#9ca3af; font-weight:700; padding:.5rem .6rem .25rem; }
    .lp-gs-item { display:flex; align-items:center; gap:.6rem; padding:.5rem .6rem; border-radius:4px; cursor:pointer; }
    .lp-gs-item:hover, .lp-gs-item-active { background:#f1f5fa; }
  `
  document.head.appendChild(el)
}

const ICON = { contact: '👤', account: '🏢', deal: '💼', ticket: '🎫', message: '💬' }

export default function GlobalSearch() {
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const [groups, setGroups] = useState([])
  const [open, setOpen] = useState(false)
  const [loading, setLoading] = useState(false)
  const wrapRef = useRef(null)

  // Close on outside click.
  useEffect(() => {
    function onDoc(e) { if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false) }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [])

  const run = useCallback(async (term) => {
    if (term.trim().length < 2) { setGroups([]); return }
    setLoading(true)
    try {
      const res = await crm.search(term.trim())
      setGroups(res.data ?? [])
    } catch { setGroups([]) }
    setLoading(false)
  }, [])

  // Debounce.
  useEffect(() => {
    const id = setTimeout(() => run(q), 220)
    return () => clearTimeout(id)
  }, [q, run])

  function go(item) {
    setOpen(false); setQ('')
    navigate(item.url)
  }

  const hasResults = groups.some(g => g.items.length > 0)

  return (
    <div className="lp-gs-wrap" ref={wrapRef}>
      <span style={{ position: 'absolute', left: 11, top: '50%', transform: 'translateY(-50%)', fontSize: 14, opacity: .5, pointerEvents: 'none' }}>🔍</span>
      <input className="lp-gs-input" value={q} placeholder="Search contacts, accounts, deals, tickets…"
        onFocus={() => setOpen(true)} onChange={e => { setQ(e.target.value); setOpen(true) }} />

      {open && q.trim().length >= 2 && (
        <div className="lp-gs-pop">
          {loading && !hasResults ? (
            <div style={{ padding: '1rem .6rem', color: '#9ca3af', fontSize: 13 }}>Searching…</div>
          ) : !hasResults ? (
            <div style={{ padding: '1rem .6rem', color: '#9ca3af', fontSize: 13 }}>No matches for “{q}”.</div>
          ) : groups.map(g => g.items.length > 0 && (
            <div key={g.type}>
              <div className="lp-gs-group-label">{ICON[g.type] ?? ''} {g.label}</div>
              {g.items.map((it, i) => (
                <div key={g.type + '-' + i} className="lp-gs-item" onClick={() => go(it)}>
                  <span style={{ fontSize: 15 }}>{ICON[g.type] ?? '•'}</span>
                  <div style={{ minWidth: 0 }}>
                    <div style={{ fontWeight: 600, fontSize: 13.5, color: '#111827', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{it.title}</div>
                    {it.subtitle && <div style={{ fontSize: 12, color: '#9ca3af', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{it.subtitle}</div>}
                  </div>
                </div>
              ))}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
