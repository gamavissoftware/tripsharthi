import { useState, useEffect, useCallback } from 'react'
import { crm } from '../api/crm'
import { toast } from '../components/Toast'

// ── Find duplicates + merge (Phase G5) ───────────────────────────────────────
// Lists groups of likely-duplicate contacts/accounts. Within a group, pick the
// record to keep (primary); the rest merge into it and are soft-deleted.

const ENTITIES = [
  { key: 'contact', label: 'Contacts', icon: '👤', primaryField: 'name', subFields: ['wa_number', 'email'] },
  { key: 'account', label: 'Accounts', icon: '🏢', primaryField: 'name', subFields: ['domain', 'industry'] },
]

export default function DuplicatesPage() {
  const [entity, setEntity] = useState('contact')
  const [groups, setGroups] = useState([])
  const [loading, setLoading] = useState(true)
  const [primary, setPrimary] = useState({})   // groupIdx -> chosen primary id
  const [busy, setBusy] = useState(null)        // groupIdx being merged

  const cfg = ENTITIES.find(e => e.key === entity)

  const load = useCallback(async () => {
    setLoading(true)
    setPrimary({})
    try {
      const res = await crm.duplicates(entity)
      setGroups(res.data ?? [])
      // Default primary = first record of each group.
      const def = {}
      ;(res.data ?? []).forEach((g, i) => { def[i] = g.records[0]?.id })
      setPrimary(def)
    } catch (err) {
      toast.error('Failed to find duplicates', err?.message)
      setGroups([])
    }
    setLoading(false)
  }, [entity])

  useEffect(() => { load() }, [load])

  async function mergeGroup(g, idx) {
    const primaryId = primary[idx]
    const losers = g.records.map(r => r.id).filter(id => id !== primaryId)
    if (!primaryId || !losers.length) return
    if (!confirm(`Merge ${losers.length} record(s) into the selected one? The others will be moved to the recycle bin.`)) return
    setBusy(idx)
    try {
      const res = await crm.merge(entity, primaryId, losers)
      toast.success('Merged', `${res.merged} record(s) merged`)
      load()
    } catch (err) { toast.error('Merge failed', err?.message) }
    setBusy(null)
  }

  return (
    <div className="page" style={{ maxWidth: 880 }}>
      <div style={{ marginBottom: '1.25rem' }}>
        <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>🔀 Find Duplicates</h1>
        <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Spot records that share a phone, email, or domain, and merge them into one.</p>
      </div>

      <div style={{ display: 'flex', gap: 6, marginBottom: '1.25rem', flexWrap: 'wrap' }}>
        {ENTITIES.map(e => (
          <button key={e.key} onClick={() => setEntity(e.key)}
            style={{ padding: '.4rem .9rem', borderRadius: 999, border: '1px solid ' + (entity === e.key ? 'var(--primary,#0a6cc4)' : '#e5e7eb'),
              background: entity === e.key ? 'var(--primary,#0a6cc4)' : '#fff', color: entity === e.key ? '#fff' : '#475569',
              fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>{e.icon} {e.label}</button>
        ))}
      </div>

      {loading ? (
        <div style={{ padding: '2.5rem', textAlign: 'center', color: '#9ca3af' }}>Scanning…</div>
      ) : groups.length === 0 ? (
        <div style={{ textAlign: 'center', padding: '3rem 2rem', background: '#fff', borderRadius: 14, border: '1px solid #e5e7eb' }}>
          <div style={{ fontSize: '2.5rem' }}>✨</div>
          <h3 style={{ fontWeight: 800, fontSize: '1rem', margin: '.5rem 0 .25rem' }}>No duplicates found</h3>
          <p style={{ color: '#6b7280', fontSize: '.85rem' }}>Your {cfg.label.toLowerCase()} look clean.</p>
        </div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          {groups.map((g, idx) => (
            <div key={idx} style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, overflow: 'hidden' }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '.7rem 1rem', borderBottom: '1px solid #f0f1f3', background: '#fafbff' }}>
                <span style={{ fontSize: 13, color: '#475569' }}>
                  Same <strong>{g.field}</strong>: <code style={{ background: '#e8f3fc', padding: '1px 6px', borderRadius: 5 }}>{g.value}</code> · {g.records.length} records
                </span>
                <button onClick={() => mergeGroup(g, idx)} disabled={busy === idx}
                  style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 9, padding: '.45rem .9rem', fontWeight: 700, fontSize: 13, cursor: 'pointer' }}>
                  {busy === idx ? 'Merging…' : '🔀 Merge'}
                </button>
              </div>
              {g.records.map(r => (
                <label key={r.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '.7rem 1rem', borderTop: '1px solid #f6f7f9', cursor: 'pointer', background: primary[idx] === r.id ? '#f0fdf4' : '#fff' }}>
                  <input type="radio" name={`primary-${idx}`} checked={primary[idx] === r.id} onChange={() => setPrimary(p => ({ ...p, [idx]: r.id }))} />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontWeight: 700, fontSize: '.92rem', color: '#111827' }}>{r[cfg.primaryField] || `#${r.id}`}</div>
                    <div style={{ fontSize: '.75rem', color: '#94a3b8' }}>
                      {cfg.subFields.map(f => r[f]).filter(Boolean).join(' · ') || '—'} · #{r.id}
                    </div>
                  </div>
                  {primary[idx] === r.id && <span style={{ fontSize: 11, fontWeight: 700, color: '#15803d', background: '#dcfce7', borderRadius: 999, padding: '.15rem .55rem' }}>KEEP</span>}
                </label>
              ))}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
