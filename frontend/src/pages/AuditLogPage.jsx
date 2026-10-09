import { useState, useEffect, useCallback, Fragment } from 'react'
import { crm } from '../api/crm'
import { toast } from '../components/Toast'

// ── Audit log (Phase K1) ─────────────────────────────────────────────────────

const ACTION = {
  created:  { label: 'Created',  bg: '#dcfce7', color: '#15803d' },
  updated:  { label: 'Updated',  bg: '#dbeafe', color: '#1d4ed8' },
  deleted:  { label: 'Deleted',  bg: '#fee2e2', color: '#b91c1c' },
  status:   { label: 'Status',   bg: '#fef9c3', color: '#a16207' },
  merged:   { label: 'Merged',   bg: '#ede9fe', color: '#6d28d9' },
  restored: { label: 'Restored', bg: '#dcfce7', color: '#15803d' },
  purged:   { label: 'Purged',   bg: '#fee2e2', color: '#b91c1c' },
  exported: { label: 'Exported', bg: '#e0f2fe', color: '#0369a1' },
  bulk_set:    { label: 'Bulk set',    bg: '#f1f5f9', color: '#475569' },
  bulk_delete: { label: 'Bulk delete', bg: '#fee2e2', color: '#b91c1c' },
  bulk_add_tag:    { label: 'Bulk tag',   bg: '#f1f5f9', color: '#475569' },
  bulk_remove_tag: { label: 'Bulk untag', bg: '#f1f5f9', color: '#475569' },
}
const ENTITIES = ['', 'contact', 'account', 'deal', 'ticket', 'custom_object_record']

export default function AuditLogPage() {
  const [rows, setRows] = useState([])
  const [pager, setPager] = useState(null)
  const [loading, setLoading] = useState(true)
  const [forbidden, setForbidden] = useState(false)
  const [filters, setFilters] = useState({ entity_type: '', action: '' })
  const [page, setPage] = useState(1)
  const [openId, setOpenId] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    const params = Object.fromEntries(Object.entries({ ...filters, page, per_page: 30 }).filter(([, v]) => v !== ''))
    try {
      const r = await crm.auditLogs(params)
      setRows(r.data ?? []); setPager(r.pager ?? null)
    } catch (e) {
      if (e?.status === 403) setForbidden(true)
      else toast.error('Failed to load audit log', e?.message)
    }
    setLoading(false)
  }, [filters, page])
  useEffect(() => { load() }, [load])

  if (forbidden) return <div className="page"><div style={{ padding: '3rem', textAlign: 'center', color: '#9ca3af' }}>🔒 Only owners and admins can view the audit log.</div></div>

  return (
    <div className="page" style={{ maxWidth: 980 }}>
      <div style={{ marginBottom: '1.25rem' }}>
        <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>📜 Audit Log</h1>
        <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Who changed what, and when.</p>
      </div>

      <div style={{ display: 'flex', gap: 8, marginBottom: '1rem', flexWrap: 'wrap' }}>
        <select value={filters.entity_type} onChange={e => { setFilters(f => ({ ...f, entity_type: e.target.value })); setPage(1) }} style={sel}>
          {ENTITIES.map(x => <option key={x} value={x}>{x ? x.replace(/_/g, ' ') : 'All entities'}</option>)}
        </select>
        <select value={filters.action} onChange={e => { setFilters(f => ({ ...f, action: e.target.value })); setPage(1) }} style={sel}>
          <option value="">All actions</option>
          {Object.keys(ACTION).map(a => <option key={a} value={a}>{ACTION[a].label}</option>)}
        </select>
      </div>

      <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, overflow: 'hidden' }}>
        {loading ? <div style={{ padding: '2.5rem', textAlign: 'center', color: '#9ca3af' }}>Loading…</div>
          : rows.length === 0 ? <div style={{ padding: '3rem', textAlign: 'center', color: '#9ca3af' }}>No audit entries.</div>
            : (
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                <thead><tr style={{ textAlign: 'left', color: '#6b7280', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em', borderBottom: '1px solid #f0f1f3' }}>
                  <th style={th}>When</th><th style={th}>Actor</th><th style={th}>Action</th><th style={th}>Entity</th><th style={th}></th>
                </tr></thead>
                <tbody>
                  {rows.map(r => {
                    const a = ACTION[r.action] ?? { label: r.action, bg: '#f1f5f9', color: '#475569' }
                    const hasDiff = r.before || r.after
                    return (
                      <Fragment key={r.id}>
                        <tr style={{ borderTop: '1px solid #f6f7f9', cursor: hasDiff ? 'pointer' : 'default' }} onClick={() => hasDiff && setOpenId(openId === r.id ? null : r.id)}>
                          <td style={{ ...td, color: '#6b7280', whiteSpace: 'nowrap' }}>{r.created_at?.slice(0, 16)}</td>
                          <td style={{ ...td, fontWeight: 600 }}>{r.actor}</td>
                          <td style={td}><span style={{ background: a.bg, color: a.color, borderRadius: 999, padding: '.12rem .55rem', fontSize: 11.5, fontWeight: 700 }}>{a.label}</span></td>
                          <td style={{ ...td, textTransform: 'capitalize' }}>{r.entity_type?.replace(/_/g, ' ')}{r.entity_id ? ` #${r.entity_id}` : ''}</td>
                          <td style={{ ...td, textAlign: 'right', color: '#cbd5e1' }}>{hasDiff ? (openId === r.id ? '▲' : '▼') : ''}</td>
                        </tr>
                        {openId === r.id && hasDiff && (
                          <tr><td colSpan={5} style={{ padding: '.6rem 1rem', background: '#fafbff', borderTop: '1px solid #f6f7f9' }}>
                            <pre style={{ margin: 0, fontSize: 11.5, color: '#475569', whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{JSON.stringify({ before: r.before, after: r.after }, null, 2)}</pre>
                            {r.ip && <div style={{ fontSize: 11, color: '#94a3b8', marginTop: 4 }}>IP {r.ip}</div>}
                          </td></tr>
                        )}
                      </Fragment>
                    )
                  })}
                </tbody>
              </table>
            )}
        {pager && pager.pageCount > 1 && (
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '.8rem 1rem', borderTop: '1px solid #f0f1f3' }}>
            <span style={{ fontSize: 13, color: '#6b7280' }}>Page {pager.page} of {pager.pageCount} · {pager.total} entries</span>
            <div style={{ display: 'flex', gap: 8 }}>
              <button disabled={page <= 1} onClick={() => setPage(p => p - 1)} style={{ ...btn, opacity: page <= 1 ? .5 : 1 }}>← Prev</button>
              <button disabled={page >= pager.pageCount} onClick={() => setPage(p => p + 1)} style={{ ...btn, opacity: page >= pager.pageCount ? .5 : 1 }}>Next →</button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
const sel = { border: '1px solid #e5e7eb', borderRadius: 9, padding: '.5rem .7rem', fontSize: 13, fontWeight: 600, textTransform: 'capitalize' }
const th = { padding: '.6rem 1rem', fontWeight: 700 }
const td = { padding: '.6rem 1rem', color: '#374151' }
const btn = { background: '#fff', border: '1.5px solid #e5e7eb', borderRadius: 9, padding: '.45rem .9rem', fontWeight: 700, fontSize: 13, cursor: 'pointer' }
