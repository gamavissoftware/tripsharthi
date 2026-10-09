import { useState, useEffect, useCallback } from 'react'
import { crm } from '../api/crm'
import { toast } from '../components/Toast'

// ── Recycle bin (Phase G6) ───────────────────────────────────────────────────
// Browse soft-deleted CRM records per entity, then restore or permanently purge.

const ENTITIES = [
  { key: 'contact', label: 'Contacts', icon: '👤' },
  { key: 'account', label: 'Accounts', icon: '🏢' },
  { key: 'deal',    label: 'Deals',    icon: '💼' },
  { key: 'ticket',  label: 'Tickets',  icon: '🎫' },
]
const labelize = (s) => String(s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())

export default function RecycleBinPage() {
  const [entity, setEntity] = useState('contact')
  const [rows, setRows] = useState([])
  const [columns, setColumns] = useState([])
  const [selected, setSelected] = useState(() => new Set())
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setSelected(new Set())
    try {
      const res = await crm.recycleList(entity)
      setRows(res.data ?? [])
      setColumns(res.columns ?? [])
    } catch (err) {
      toast.error('Failed to load recycle bin', err?.message)
      setRows([]); setColumns([])
    }
    setLoading(false)
  }, [entity])

  useEffect(() => { load() }, [load])

  function toggle(id) {
    setSelected(s => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })
  }
  function toggleAll() {
    setSelected(s => s.size === rows.length ? new Set() : new Set(rows.map(r => r.id)))
  }

  async function restore() {
    const ids = [...selected]
    if (!ids.length) return
    setBusy(true)
    try { const r = await crm.recycleRestore(entity, ids); toast.success('Restored', `${r.restored} record(s)`); load() }
    catch (err) { toast.error('Restore failed', err?.message) }
    setBusy(false)
  }
  async function purge() {
    const ids = [...selected]
    if (!ids.length) return
    if (!confirm(`Permanently delete ${ids.length} record(s)? This cannot be undone.`)) return
    setBusy(true)
    try { const r = await crm.recyclePurge(entity, ids); toast.success('Permanently deleted', `${r.purged} record(s)`); load() }
    catch (err) { toast.error('Purge failed', err?.message) }
    setBusy(false)
  }

  return (
    <div className="page" style={{ maxWidth: 980 }}>
      <div style={{ marginBottom: '1.25rem' }}>
        <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>🗑 Recycle Bin</h1>
        <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Restore deleted records, or remove them permanently.</p>
      </div>

      {/* Entity tabs */}
      <div style={{ display: 'flex', gap: 6, marginBottom: '1rem', flexWrap: 'wrap' }}>
        {ENTITIES.map(e => (
          <button key={e.key} onClick={() => setEntity(e.key)}
            style={{ padding: '.4rem .9rem', borderRadius: 999, border: '1px solid ' + (entity === e.key ? 'var(--primary,#0a6cc4)' : '#e5e7eb'),
              background: entity === e.key ? 'var(--primary,#0a6cc4)' : '#fff', color: entity === e.key ? '#fff' : '#475569',
              fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>{e.icon} {e.label}</button>
        ))}
      </div>

      {/* Action bar */}
      {selected.size > 0 && (
        <div style={{ display: 'flex', gap: '.6rem', alignItems: 'center', background: '#f5f7ff', border: '1px solid #bcdcf6', borderRadius: 12, padding: '.6rem 1rem', marginBottom: '.8rem' }}>
          <span style={{ fontWeight: 700, fontSize: 13, color: '#074a8c' }}>{selected.size} selected</span>
          <button onClick={restore} disabled={busy} style={btn('#fff', '#374151')}>↩ Restore</button>
          <button onClick={purge} disabled={busy} style={{ ...btn('#fff', '#b91c1c'), borderColor: '#fecaca' }}>🗑 Delete forever</button>
          <button onClick={() => setSelected(new Set())} style={{ ...btn('transparent', '#6b7280'), marginLeft: 'auto', border: 'none' }}>Clear</button>
        </div>
      )}

      <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, overflow: 'hidden' }}>
        {loading ? (
          <div style={{ padding: '2.5rem', textAlign: 'center', color: '#9ca3af' }}>Loading…</div>
        ) : rows.length === 0 ? (
          <div style={{ padding: '3rem 1rem', textAlign: 'center', color: '#9ca3af' }}>
            <div style={{ fontSize: '2.2rem', marginBottom: 8 }}>✨</div>The {entity} recycle bin is empty.
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14, minWidth: 560 }}>
              <thead>
                <tr style={{ textAlign: 'left', color: '#6b7280', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em', borderBottom: '1px solid #f0f1f3' }}>
                  <th style={{ padding: '.7rem .5rem .7rem .9rem', width: 28 }}>
                    <input type="checkbox" checked={selected.size === rows.length && rows.length > 0} onChange={toggleAll} />
                  </th>
                  {columns.map(c => <th key={c} style={{ padding: '.7rem .9rem', fontWeight: 700 }}>{labelize(c)}</th>)}
                  <th style={{ padding: '.7rem .9rem', fontWeight: 700, whiteSpace: 'nowrap' }}>Deleted</th>
                </tr>
              </thead>
              <tbody>
                {rows.map(r => (
                  <tr key={r.id} style={{ borderBottom: '1px solid #f6f7f9', background: selected.has(r.id) ? '#f5f7ff' : undefined, cursor: 'pointer' }} onClick={() => toggle(r.id)}>
                    <td style={{ padding: '.6rem .5rem .6rem .9rem' }}>
                      <input type="checkbox" checked={selected.has(r.id)} onChange={() => toggle(r.id)} onClick={e => e.stopPropagation()} />
                    </td>
                    {columns.map((c, i) => (
                      <td key={c} style={{ padding: '.6rem .9rem', fontWeight: i === 0 ? 700 : 400, color: i === 0 ? '#111827' : '#374151' }}>
                        {r[c] === null || r[c] === undefined || r[c] === '' ? <span style={{ color: '#d1d5db' }}>—</span> : String(r[c]).slice(0, 40)}
                      </td>
                    ))}
                    <td style={{ padding: '.6rem .9rem', color: '#9ca3af', fontSize: 12.5, whiteSpace: 'nowrap' }}>{r.deleted_at?.slice(0, 16) ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}

const btn = (bg, color) => ({ padding: '.5rem .9rem', borderRadius: 9, fontWeight: 700, fontSize: 13, cursor: 'pointer', border: '1.5px solid #e5e7eb', background: bg, color })
