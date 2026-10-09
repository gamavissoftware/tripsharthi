import { useState, useEffect, useRef } from 'react'
import { contacts as contactsApi } from '../../api/contacts'

/**
 * Reusable contact autocomplete. Searches contacts by name/number/email
 * (debounced) and calls onChange(contactId, contactObj) on select. When a
 * contact is chosen it renders a removable chip; otherwise a search input.
 *
 * Props: value (selected contact id | null), onChange, placeholder.
 */
export default function ContactPicker({ value, onChange, placeholder = 'Search a contact…' }) {
  const [q, setQ]           = useState('')
  const [results, setResults] = useState([])
  const [open, setOpen]     = useState(false)
  const [loading, setLoading] = useState(false)
  const [selected, setSelected] = useState(null) // resolved contact object for `value`
  const boxRef = useRef(null)

  // Resolve an externally-provided value to a display name (e.g. on edit).
  useEffect(() => {
    if (!value) { setSelected(null); return }
    if (selected?.id === value) return
    let alive = true
    contactsApi.get(value).then(r => { if (alive) setSelected(r.data) }).catch(() => {})
    return () => { alive = false }
  }, [value]) // eslint-disable-line react-hooks/exhaustive-deps

  // Debounced search.
  useEffect(() => {
    if (selected) return // not searching while a contact is chosen
    const term = q.trim()
    if (term.length < 2) { setResults([]); return }
    setLoading(true)
    const t = setTimeout(async () => {
      try {
        const r = await contactsApi.list({ q: term, per_page: 8 })
        setResults(r.data ?? [])
        setOpen(true)
      } catch { setResults([]) }
      finally { setLoading(false) }
    }, 250)
    return () => clearTimeout(t)
  }, [q, selected])

  // Close dropdown on outside click.
  useEffect(() => {
    const onDoc = (e) => { if (boxRef.current && !boxRef.current.contains(e.target)) setOpen(false) }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [])

  function pick(c) {
    setSelected(c)
    setQ('')
    setResults([])
    setOpen(false)
    onChange?.(c.id, c)
  }

  function clear() {
    setSelected(null)
    onChange?.(null, null)
  }

  if (selected) {
    return (
      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, background: '#e8f3fc', color: '#074a8c', borderRadius: 999, padding: '.3rem .7rem', fontSize: '.82rem', fontWeight: 600 }}>
        {selected.name || selected.wa_number || `Contact #${selected.id}`}
        <button type="button" onClick={clear} title="Remove" style={{ background: 'none', border: 'none', color: '#0a6cc4', cursor: 'pointer', fontSize: '.95rem', lineHeight: 1, padding: 0 }}>×</button>
      </span>
    )
  }

  return (
    <div ref={boxRef} style={{ position: 'relative', minWidth: 200 }}>
      <input
        value={q}
        onChange={e => setQ(e.target.value)}
        onFocus={() => results.length && setOpen(true)}
        placeholder={placeholder}
        style={{ width: '100%', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.85rem', boxSizing: 'border-box' }}
      />
      {open && (q.trim().length >= 2) && (
        <div style={{ position: 'absolute', zIndex: 20, top: '100%', left: 0, right: 0, marginTop: 4, background: '#fff', border: '1px solid #e5e7eb', borderRadius: 8, boxShadow: '0 8px 24px rgba(0,0,0,.08)', maxHeight: 240, overflowY: 'auto' }}>
          {loading ? (
            <div style={{ padding: '.6rem .7rem', fontSize: '.8rem', color: '#94a3b8' }}>Searching…</div>
          ) : results.length === 0 ? (
            <div style={{ padding: '.6rem .7rem', fontSize: '.8rem', color: '#94a3b8' }}>No matches</div>
          ) : results.map(c => (
            <button
              key={c.id} type="button" onClick={() => pick(c)}
              style={{ display: 'block', width: '100%', textAlign: 'left', background: 'none', border: 'none', borderBottom: '1px solid #f1f5f9', padding: '.5rem .7rem', cursor: 'pointer', fontSize: '.84rem' }}
            >
              <span style={{ fontWeight: 600, color: '#111827' }}>{c.name || '(no name)'}</span>
              <span style={{ color: '#94a3b8', marginLeft: 8 }}>{c.wa_number}{c.email ? ` · ${c.email}` : ''}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
