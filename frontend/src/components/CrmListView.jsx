import { useState, useEffect, useCallback, useMemo } from 'react'
import { crm } from '../api/crm'
import { tags as tagsApi } from '../api/tags'
import { toast } from './Toast'
import CrmImportModal from './CrmImportModal'

const IMPORTABLE = ['account', 'deal', 'custom_object_record']

// ── Reusable CRM list (Phase G1) ─────────────────────────────────────────────
// A generic, filterable, paginated table driven by the unified /crm/list/:entity
// endpoint and its /meta. Supports saved views (personal + shared), a compact
// condition builder, free-text search, whitelisted sort, and pagination.
//
// Props:
//   entity         — registry key: contact | account | deal | ticket | custom_object_record
//   customObjectId — required when entity === 'custom_object_record'
//   onRowClick(row)— optional row handler (e.g. navigate to a detail page)

if (typeof document !== 'undefined' && !document.getElementById('lp-clv-css')) {
  const el = document.createElement('style')
  el.id = 'lp-clv-css'
  el.textContent = `
    .lp-clv-card { background:#fff; border:1px solid #e5e7eb; border-radius:16px; }
    .lp-clv-pill {
      padding:.4rem .8rem; border-radius:999px; border:1.5px solid #e5e7eb; background:#fff; font-size:13px;
      font-weight:600; color:#374151; cursor:pointer; white-space:nowrap; transition:all .12s;
    }
    .lp-clv-pill:hover { border-color:var(--primary,#0a6cc4); color:var(--primary,#0a6cc4); }
    .lp-clv-pill-on { background:var(--primary,#0a6cc4); border-color:var(--primary,#0a6cc4); color:#fff; }
    .lp-clv-input {
      padding:.5rem .7rem; border-radius:9px; border:1.5px solid #e5e7eb; background:#f9fafb; font-size:13.5px;
      color:#111827; outline:none; transition:border-color .15s, background .15s; box-sizing:border-box;
    }
    .lp-clv-input:focus { border-color:var(--primary,#0a6cc4); background:#fff; }
    .lp-clv-row { cursor:pointer; transition:background .12s; }
    .lp-clv-row:hover { background:#fafbff; }
    .lp-clv-th { padding:.7rem .9rem; font-weight:700; cursor:pointer; user-select:none; white-space:nowrap; }
    .lp-clv-btn {
      padding:.5rem .9rem; border-radius:9px; font-weight:700; font-size:13px; cursor:pointer;
      border:1.5px solid #e5e7eb; background:#fff; color:#374151; white-space:nowrap;
    }
    .lp-clv-btn-primary { background:var(--primary,#0a6cc4); color:#fff; border-color:transparent; }
  `
  document.head.appendChild(el)
}

const OPS = [
  { v: 'eq', label: 'is' }, { v: 'neq', label: 'is not' },
  { v: 'contains', label: 'contains' }, { v: 'in', label: 'is any of' },
  { v: 'gt', label: '>' }, { v: 'gte', label: '≥' }, { v: 'lt', label: '<' }, { v: 'lte', label: '≤' },
  { v: 'is_empty', label: 'is empty' }, { v: 'is_not_empty', label: 'is not empty' },
]
const NUMERIC_HINT = /(_score|_amount|_count|_id)$/
const NO_VALUE_OPS = ['is_empty', 'is_not_empty']
const labelize = (s) => String(s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())

const BADGE_TINTS = [
  ['#e8f3fc', '#08569f'], ['#dcfce7', '#15803d'], ['#fef9c3', '#a16207'],
  ['#dbeafe', '#1d4ed8'], ['#fee2e2', '#b91c1c'], ['#f1f5f9', '#475569'], ['#cffafe', '#0e7490'],
]
const tintFor = (s) => {
  let h = 0
  for (const ch of String(s)) h = (h * 31 + ch.charCodeAt(0)) >>> 0
  return BADGE_TINTS[h % BADGE_TINTS.length]
}
const BADGE_COLS = ['status', 'type', 'lifecycle_stage', 'priority', 'stage', 'category']
const rupees = (paise) => '₹' + (Number(paise || 0) / 100).toLocaleString('en-IN', { maximumFractionDigits: 0 })

export const TIER = {
  hot:  { label: 'Hot',  icon: '🔥', bg: '#fee2e2', color: '#b91c1c' },
  warm: { label: 'Warm', icon: '☀️', bg: '#fef9c3', color: '#a16207' },
  cold: { label: 'Cold', icon: '❄️', bg: '#e0f2fe', color: '#0369a1' },
}
export function TierBadge({ tier }) {
  const t = TIER[tier]
  if (!t) return <span style={{ color: '#d1d5db' }}>—</span>
  return <span style={{ padding: '.2rem .55rem', borderRadius: 999, fontSize: 11.5, fontWeight: 700, background: t.bg, color: t.color }}>{t.icon} {t.label}</span>
}

function Cell({ col, row, first, onRowClick }) {
  const val = row[col]
  if (first) {
    return <span style={{ fontWeight: 700, color: val ? '#111827' : '#9ca3af' }}>{val || `#${row.id}`}</span>
  }
  if (val === null || val === undefined || val === '') return <span style={{ color: '#d1d5db' }}>—</span>
  if (col.endsWith('_at') || col.endsWith('_date')) return <span style={{ color: '#6b7280', fontSize: 12.5, whiteSpace: 'nowrap' }}>{String(val).slice(0, 10)}</span>
  if (col.endsWith('_amount')) return <span style={{ fontWeight: 600 }}>{rupees(val)}</span>
  if (col === 'score_tier') return <TierBadge tier={val} />
  if (col === 'opt_in' || col.startsWith('is_')) return <span>{Number(val) ? '✓' : '✗'}</span>
  if (BADGE_COLS.includes(col)) {
    const [bg, color] = tintFor(val)
    return <span style={{ padding: '.2rem .6rem', borderRadius: 999, fontSize: 11.5, fontWeight: 700, background: bg, color }}>{labelize(val)}</span>
  }
  return <span style={{ color: '#374151' }}>{String(val)}</span>
}

export default function CrmListView({ entity, customObjectId, onRowClick }) {
  const [meta, setMeta] = useState(null)
  const [views, setViews] = useState([])
  const [activeView, setActiveView] = useState(null)   // saved-view id, or null = ad-hoc
  const [rows, setRows] = useState([])
  const [pager, setPager] = useState(null)
  const [columns, setColumns] = useState([])
  const [loading, setLoading] = useState(true)

  const [q, setQ] = useState('')
  const [match, setMatch] = useState('and')
  const [conditions, setConditions] = useState([])     // [{field, op, value}]
  const [sort, setSort] = useState(null)               // "field:dir"
  const [page, setPage] = useState(1)
  const [showSave, setShowSave] = useState(false)
  const [showImport, setShowImport] = useState(false)
  const [scope, setScope] = useState('all')            // all | mine | team (Phase K3)

  // Bulk selection (Phase G2)
  const [selected, setSelected] = useState(() => new Set())
  const [tagList, setTagList] = useState([])
  const [bulkField, setBulkField] = useState('')
  const [bulkValue, setBulkValue] = useState('')
  const [bulkTag, setBulkTag] = useState('')
  const [bulkBusy, setBulkBusy] = useState(false)

  // ── Load meta + saved views once per entity ──
  useEffect(() => {
    let alive = true
    setMeta(null)
    Promise.all([crm.listMeta(entity), crm.listViews(entity)])
      .then(([m, v]) => {
        if (!alive) return
        setMeta(m.data); setColumns(m.data.columns); setViews(v.data ?? [])
        setBulkField(m.data.bulk_set?.[0] ?? '')
        if (m.data.supports_tags) tagsApi.list().then(r => alive && setTagList(r.data ?? [])).catch(() => {})
      })
      .catch(err => toast.error('Failed to load list config', err?.message))
    return () => { alive = false }
  }, [entity])

  // ── Apply a saved view's filters/sort/columns into local state ──
  const applyView = useCallback((view) => {
    setActiveView(view?.id ?? null)
    setPage(1)
    if (view) {
      const f = view.filters ?? {}
      setMatch(f.match ?? 'and')
      setConditions(Array.isArray(f.conditions) ? f.conditions : [])
      setSort(view.sort ?? null)
      if (view.columns?.length) setColumns(view.columns)
    } else {
      setMatch('and'); setConditions([]); setSort(null)
      if (meta) setColumns(meta.columns)
    }
  }, [meta])

  const params = useMemo(() => {
    const p = { page, per_page: 25 }
    if (customObjectId) p.custom_object_id = customObjectId
    if (q.trim()) p.q = q.trim()
    if (sort) p.sort = sort
    if (scope !== 'all') p.scope = scope
    if (activeView) { p.view_id = activeView }
    else if (conditions.length) p.filters = JSON.stringify({ match, conditions })
    return p
  }, [page, customObjectId, q, sort, scope, activeView, conditions, match])

  const load = useCallback(async () => {
    if (!meta) return
    setLoading(true)
    setSelected(new Set())
    try {
      const res = await crm.list(entity, params)
      setRows(res.data ?? [])
      setPager(res.pager ?? null)
      if (res.columns?.length) setColumns(res.columns)
    } catch (err) {
      toast.error('Failed to load list', err?.message)
      setRows([]); setPager(null)
    }
    setLoading(false)
  }, [entity, meta, params])

  useEffect(() => { load() }, [load])

  function toggleSort(field) {
    if (!meta?.sortable.includes(field)) return
    setActiveView(null)
    setSort(prev => {
      const [f, d] = (prev || '').split(':')
      if (f === field) return `${field}:${d === 'asc' ? 'desc' : 'asc'}`
      return `${field}:asc`
    })
    setPage(1)
  }

  function addCondition() {
    const field = meta.filterable[0]
    if (!field) return
    setActiveView(null)
    setConditions(c => [...c, { field, op: 'eq', value: '' }])
  }
  function setCond(i, patch) {
    setActiveView(null)
    setConditions(c => c.map((x, j) => j === i ? { ...x, ...patch } : x))
    setPage(1)
  }
  function removeCond(i) { setActiveView(null); setConditions(c => c.filter((_, j) => j !== i)); setPage(1) }

  async function saveView(name, isShared) {
    try {
      const r = await crm.createView({
        entity_type: entity, name,
        filters: { match, conditions: conditions.filter(c => NO_VALUE_OPS.includes(c.op) || c.value !== '') },
        sort, columns, is_shared: isShared ? 1 : 0,
      })
      setViews(v => [...v, r.data])
      setActiveView(r.data.id)
      setShowSave(false)
      toast.success('View saved', name)
    } catch (err) { toast.error('Could not save view', err?.message) }
  }

  async function exportCsv() {
    const p = {}
    if (customObjectId) p.custom_object_id = customObjectId
    if (q.trim()) p.q = q.trim()
    if (conditions.length) p.filters = JSON.stringify({ match, conditions })
    try {
      const blob = await crm.exportCsv(entity, p)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `${entity}-export-${new Date().toISOString().slice(0, 10)}.csv`
      document.body.appendChild(a); a.click(); a.remove()
      URL.revokeObjectURL(url)
      toast.success('Export ready', 'Your CSV download has started.')
    } catch (err) { toast.error('Export failed', err?.message) }
  }

  async function deleteView(id, e) {
    e.stopPropagation()
    if (!confirm('Delete this saved view?')) return
    try {
      await crm.deleteView(id)
      setViews(v => v.filter(x => x.id !== id))
      if (activeView === id) applyView(null)
      toast.success('View deleted')
    } catch (err) { toast.error('Could not delete view', err?.message) }
  }

  // ── Bulk selection ──
  function toggleRow(id, e) {
    e.stopPropagation()
    setSelected(s => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n })
  }
  function toggleAll() {
    setSelected(s => s.size === rows.length ? new Set() : new Set(rows.map(r => r.id)))
  }
  async function runBulk(action, opts = {}) {
    const ids = [...selected]
    if (!ids.length) return
    setBulkBusy(true)
    try {
      const r = await crm.bulk(entity, { ids, action, ...opts })
      toast.success('Done', `${r.affected} ${entity}${r.affected === 1 ? '' : 's'} updated`)
      setBulkValue('')
      load()
    } catch (err) { toast.error('Bulk action failed', err?.message) }
    setBulkBusy(false)
  }

  if (!meta) return <div style={{ padding: '2rem', color: '#9ca3af' }}>Loading…</div>

  const total = pager?.total ?? rows.length
  const [sortField, sortDir] = (sort || '').split(':')
  const dirty = !activeView && (conditions.length > 0 || sort)

  return (
    <div>
      {/* Saved-view switcher */}
      <div style={{ display: 'flex', gap: '.5rem', flexWrap: 'wrap', alignItems: 'center', marginBottom: '.9rem' }}>
        <button className={`lp-clv-pill ${!activeView ? 'lp-clv-pill-on' : ''}`} onClick={() => applyView(null)}>All {entity}s</button>
        {views.map(v => (
          <button key={v.id} className={`lp-clv-pill ${activeView === v.id ? 'lp-clv-pill-on' : ''}`} onClick={() => applyView(v)}>
            {v.is_shared ? '👥 ' : ''}{v.name}
            {activeView === v.id && <span onClick={e => deleteView(v.id, e)} title="Delete view" style={{ marginLeft: 6, opacity: .8 }}>×</span>}
          </button>
        ))}
        {(dirty || conditions.length > 0) && (
          <button className="lp-clv-btn" onClick={() => setShowSave(true)}>💾 Save view</button>
        )}
        <div style={{ marginLeft: 'auto', display: 'flex', gap: '.5rem' }}>
          {IMPORTABLE.includes(entity) && <button className="lp-clv-btn" onClick={() => setShowImport(true)}>📥 Import CSV</button>}
          <button className="lp-clv-btn" onClick={exportCsv}>📤 Export CSV</button>
        </div>
      </div>

      {/* Filter bar */}
      <div className="lp-clv-card" style={{ padding: '.85rem 1rem', marginBottom: '1rem' }}>
        <div style={{ display: 'flex', gap: '.6rem', flexWrap: 'wrap', alignItems: 'center' }}>
          {meta.searchable?.length > 0 && (
            <input className="lp-clv-input" style={{ flex: '1 1 220px', minWidth: 180 }} value={q}
              onChange={e => { setQ(e.target.value); setPage(1) }} placeholder={`Search ${meta.searchable.join(', ')}…`} />
          )}
          <select className="lp-clv-input" value={match} onChange={e => { setActiveView(null); setMatch(e.target.value); setPage(1) }} style={{ fontWeight: 600 }}>
            <option value="and">Match all</option>
            <option value="or">Match any</option>
          </select>
          <select className="lp-clv-input" value={scope} onChange={e => { setScope(e.target.value); setPage(1) }} style={{ fontWeight: 600 }} title="Visibility scope">
            <option value="all">Everyone</option>
            <option value="mine">Mine</option>
            <option value="team">My team</option>
          </select>
          <button className="lp-clv-btn" onClick={addCondition}>＋ Filter</button>
        </div>

        {conditions.map((c, i) => {
          const numeric = NUMERIC_HINT.test(c.field)
          const ops = OPS.filter(o => numeric || !['gt', 'gte', 'lt', 'lte'].includes(o.v))
          return (
            <div key={i} style={{ display: 'flex', gap: '.5rem', flexWrap: 'wrap', alignItems: 'center', marginTop: '.6rem' }}>
              <select className="lp-clv-input" value={c.field} onChange={e => setCond(i, { field: e.target.value })}>
                {meta.filterable.map(f => <option key={f} value={f}>{labelize(f)}</option>)}
              </select>
              <select className="lp-clv-input" value={c.op} onChange={e => setCond(i, { op: e.target.value })}>
                {ops.map(o => <option key={o.v} value={o.v}>{o.label}</option>)}
              </select>
              {!NO_VALUE_OPS.includes(c.op) && (
                <input className="lp-clv-input" style={{ flex: '1 1 160px' }} value={c.value ?? ''}
                  onChange={e => setCond(i, { value: e.target.value })}
                  placeholder={c.op === 'in' ? 'comma,separated' : 'value'} />
              )}
              <button className="lp-clv-pill" onClick={() => removeCond(i)} title="Remove">×</button>
            </div>
          )
        })}
      </div>

      {/* Bulk action bar */}
      {selected.size > 0 && (
        <div className="lp-clv-card" style={{ padding: '.7rem 1rem', marginBottom: '.8rem', display: 'flex', gap: '.6rem', flexWrap: 'wrap', alignItems: 'center', background: '#f5f7ff', borderColor: '#bcdcf6' }}>
          <span style={{ fontWeight: 700, fontSize: 13, color: '#074a8c' }}>{selected.size} selected</span>
          {meta.bulk_set?.length > 0 && (
            <>
              <span style={{ color: '#94a3b8', fontSize: 13 }}>Set</span>
              <select className="lp-clv-input" value={bulkField} onChange={e => setBulkField(e.target.value)}>
                {meta.bulk_set.map(f => <option key={f} value={f}>{labelize(f)}</option>)}
              </select>
              <input className="lp-clv-input" style={{ width: 130 }} value={bulkValue} onChange={e => setBulkValue(e.target.value)} placeholder="value" />
              <button className="lp-clv-btn" disabled={bulkBusy || bulkValue === ''} onClick={() => runBulk('set', { field: bulkField, value: bulkValue })}>Apply</button>
            </>
          )}
          {meta.supports_tags && tagList.length > 0 && (
            <>
              <select className="lp-clv-input" value={bulkTag} onChange={e => setBulkTag(e.target.value)}>
                <option value="">tag…</option>
                {tagList.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
              </select>
              <button className="lp-clv-btn" disabled={bulkBusy || !bulkTag} onClick={() => runBulk('add_tag', { tag_id: Number(bulkTag) })}>+ Tag</button>
              <button className="lp-clv-btn" disabled={bulkBusy || !bulkTag} onClick={() => runBulk('remove_tag', { tag_id: Number(bulkTag) })}>− Tag</button>
            </>
          )}
          <button className="lp-clv-btn" style={{ color: '#b91c1c', borderColor: '#fecaca' }} disabled={bulkBusy}
            onClick={() => { if (confirm(`Delete ${selected.size} ${entity}(s)?`)) runBulk('delete') }}>🗑 Delete</button>
          <button className="lp-clv-pill" onClick={() => setSelected(new Set())} style={{ marginLeft: 'auto' }}>Clear</button>
        </div>
      )}

      {/* Table */}
      <div className="lp-clv-card" style={{ overflow: 'hidden' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '.7rem 1rem', borderBottom: '1px solid #f0f1f3' }}>
          <span style={{ fontSize: 13, color: '#6b7280' }}>{loading ? 'Loading…' : `${total.toLocaleString('en-IN')} ${meta.label?.toLowerCase() || entity}${total === 1 ? '' : 's'}`}</span>
        </div>
        {loading ? (
          <div style={{ padding: '2rem', textAlign: 'center', color: '#9ca3af' }}>Loading…</div>
        ) : rows.length === 0 ? (
          <div style={{ padding: '3rem 1rem', textAlign: 'center', color: '#9ca3af' }}>No {entity}s match.</div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14, minWidth: 600 }}>
              <thead>
                <tr style={{ textAlign: 'left', color: '#6b7280', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em', borderBottom: '1px solid #f0f1f3' }}>
                  <th style={{ padding: '.7rem .5rem .7rem .9rem', width: 28 }}>
                    <input type="checkbox" checked={rows.length > 0 && selected.size === rows.length} onChange={toggleAll} title="Select all on page" />
                  </th>
                  {columns.map(col => {
                    const sortable = meta.sortable.includes(col)
                    return (
                      <th key={col} className="lp-clv-th" onClick={() => toggleSort(col)} style={{ cursor: sortable ? 'pointer' : 'default' }}>
                        {labelize(col)}
                        {sortField === col && <span style={{ marginLeft: 4 }}>{sortDir === 'asc' ? '▲' : '▼'}</span>}
                      </th>
                    )
                  })}
                </tr>
              </thead>
              <tbody>
                {rows.map(row => (
                  <tr key={row.id} className="lp-clv-row" style={{ borderBottom: '1px solid #f6f7f9', background: selected.has(row.id) ? '#f5f7ff' : undefined }} onClick={() => onRowClick?.(row)}>
                    <td style={{ padding: '.65rem .5rem .65rem .9rem' }} onClick={e => toggleRow(row.id, e)}>
                      <input type="checkbox" checked={selected.has(row.id)} onChange={() => {}} onClick={e => toggleRow(row.id, e)} />
                    </td>
                    {columns.map((col, ci) => (
                      <td key={col} style={{ padding: '.65rem .9rem' }}>
                        <Cell col={col} row={row} first={ci === 0} onRowClick={onRowClick} />
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {pager && pager.pageCount > 1 && (
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '.8rem 1rem', borderTop: '1px solid #f0f1f3' }}>
            <span style={{ fontSize: 13, color: '#6b7280' }}>Page {pager.page} of {pager.pageCount}</span>
            <div style={{ display: 'flex', gap: '.5rem' }}>
              <button className="lp-clv-btn" disabled={page <= 1} onClick={() => setPage(p => p - 1)} style={{ opacity: page <= 1 ? .5 : 1 }}>← Prev</button>
              <button className="lp-clv-btn" disabled={page >= pager.pageCount} onClick={() => setPage(p => p + 1)} style={{ opacity: page >= pager.pageCount ? .5 : 1 }}>Next →</button>
            </div>
          </div>
        )}
      </div>

      {showSave && <SaveViewModal onClose={() => setShowSave(false)} onSave={saveView} />}
      {showImport && <CrmImportModal entity={entity} customObjectId={customObjectId} onClose={() => setShowImport(false)} onDone={load} />}
    </div>
  )
}

function SaveViewModal({ onClose, onSave }) {
  const [name, setName] = useState('')
  const [shared, setShared] = useState(false)
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, backdropFilter: 'blur(3px)' }}>
      <form onClick={e => e.stopPropagation()} onSubmit={e => { e.preventDefault(); if (name.trim()) onSave(name.trim(), shared) }}
        style={{ background: '#fff', borderRadius: 16, width: '100%', maxWidth: 420, padding: '1.5rem', boxShadow: '0 24px 64px rgba(0,0,0,.22)' }}>
        <h3 style={{ margin: '0 0 1rem', fontWeight: 800, fontSize: '1.05rem' }}>💾 Save view</h3>
        <label style={{ fontSize: '.8rem', fontWeight: 600, color: '#374151', display: 'block', marginBottom: 4 }}>View name</label>
        <input autoFocus value={name} onChange={e => setName(e.target.value)} placeholder="e.g. Hot leads"
          style={{ width: '100%', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.55rem .7rem', fontSize: '.9rem', boxSizing: 'border-box', marginBottom: '1rem' }} />
        <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: '.85rem', color: '#374151', marginBottom: '1.25rem', cursor: 'pointer' }}>
          <input type="checkbox" checked={shared} onChange={e => setShared(e.target.checked)} />
          Share with the whole team
        </label>
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '.5rem' }}>
          <button type="button" className="lp-clv-btn" onClick={onClose}>Cancel</button>
          <button type="submit" className="lp-clv-btn lp-clv-btn-primary" disabled={!name.trim()}>Save</button>
        </div>
      </form>
    </div>
  )
}
