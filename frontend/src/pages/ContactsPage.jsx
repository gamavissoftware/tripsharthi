import { useState, useEffect, useCallback } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Plus, Upload, Download, Search, User } from 'lucide-react'
import { contacts as contactsApi } from '../api/contacts'
import { tags as tagsApi } from '../api/tags'
import { toast } from '../components/Toast'

// ── Inject styles once ──────────────────────────────────────────────────────
if (typeof document !== 'undefined' && !document.getElementById('lp-contacts-css')) {
  const el = document.createElement('style')
  el.id = 'lp-contacts-css'
  el.textContent = `
    @keyframes lp-ct-in { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
    .lp-ct-card { background:#fff; border:1px solid #e5e7eb; border-radius:18px; animation:lp-ct-in .25s ease both; }
    .lp-ct-input {
      padding:.55rem .8rem .55rem 2.1rem; border-radius:10px; border:1.5px solid #e5e7eb; background:#f9fafb;
      font-size:14px; color:#111827; outline:none; transition:border-color .15s, background .15s; width:100%; box-sizing:border-box;
    }
    .lp-ct-input:focus { border-color:var(--primary,#0a6cc4); background:#fff; }
    .lp-ct-select {
      padding:.55rem .8rem; border-radius:10px; border:1.5px solid #e5e7eb; background:#fff; font-size:13.5px;
      color:#374151; outline:none; cursor:pointer; transition:border-color .15s; font-weight:500;
    }
    .lp-ct-select:focus { border-color:var(--primary,#0a6cc4); }
    .lp-ct-row { cursor:pointer; transition:background .12s; }
    .lp-ct-row:hover { background:#fafbff; }
    .lp-ct-btn {
      padding:.55rem 1rem; border-radius:10px; font-weight:700; font-size:13.5px; cursor:pointer;
      display:inline-flex; align-items:center; gap:.4rem; border:1.5px solid transparent; text-decoration:none; white-space:nowrap;
    }
    .lp-ct-btn-primary { background:var(--primary,#0a6cc4); color:#fff; box-shadow:0 2px 10px var(--primary-ring,rgba(10,108,196,.35)); }
    .lp-ct-btn-ghost { background:#fff; color:#374151; border-color:#e5e7eb; }
    .lp-ct-btn-ghost:hover { background:#f9fafb; }
  `
  document.head.appendChild(el)
}

const STATUS = {
  new:       { label: 'New',       bg: '#e8f3fc', color: '#08569f' },
  contacted: { label: 'Contacted', bg: '#fef9c3', color: '#a16207' },
  qualified: { label: 'Qualified', bg: '#dbeafe', color: '#1d4ed8' },
  won:       { label: 'Won',       bg: '#dcfce7', color: '#15803d' },
  lost:      { label: 'Lost',      bg: '#fee2e2', color: '#b91c1c' },
}
const AVATAR_TINTS = [
  ['#ede9fe', '#6d28d9'], ['#dbeafe', '#1d4ed8'], ['#dcfce7', '#15803d'],
  ['#fee2e2', '#b91c1c'], ['#fef3c7', '#b45309'], ['#cffafe', '#0e7490'],
]
const initials = (name) => (name || '#').trim().split(/\s+/).map(w => w[0]).join('').slice(0, 2).toUpperCase()
const tintFor = (id) => AVATAR_TINTS[Number(id || 0) % AVATAR_TINTS.length]

export default function ContactsPage() {
  const navigate = useNavigate()
  const [rows, setRows]       = useState([])
  const [tagList, setTagList] = useState([])
  const [pager, setPager]     = useState(null)
  const [catList, setCatList] = useState([])
  const [filters, setFilters] = useState({ status: '', source: '', tag_id: '', category: '', q: '' })
  const [page, setPage]       = useState(1)
  const [loading, setLoading] = useState(true)
  const [exporting, setExporting] = useState(false)

  useEffect(() => { tagsApi.list().then(r => setTagList(r.data ?? [])).catch(() => {}) }, [])
  useEffect(() => { contactsApi.categories().then(r => setCatList(r.data ?? [])).catch(() => {}) }, [])

  const load = useCallback(async () => {
    setLoading(true)
    const params = Object.fromEntries(
      Object.entries({ ...filters, page, per_page: 25 }).filter(([, v]) => v !== '')
    )
    try {
      const res = await contactsApi.list(params)
      setRows(res.data ?? [])
      setPager(res.pager ?? null)
    } catch (err) {
      toast.error('Failed to load contacts', err?.message)
      setRows([]); setPager(null)
    }
    setLoading(false)
  }, [filters, page])

  useEffect(() => { load() }, [load])

  async function detachTag(contactId, tagId, e) {
    e.stopPropagation()
    const prev = rows
    setRows(rs => rs.map(c => c.id === contactId ? { ...c, tags: (c.tags ?? []).filter(t => t.id !== tagId) } : c))
    try {
      await contactsApi.detachTag(contactId, tagId)
    } catch (err) {
      setRows(prev)   // revert
      toast.error('Failed to remove tag', err?.message)
    }
  }

  function setFilter(k, v) { setFilters(f => ({ ...f, [k]: v })); setPage(1) }
  function clearFilters() { setFilters({ status: '', source: '', tag_id: '', category: '', q: '' }); setPage(1) }

  async function handleExport() {
    setExporting(true)
    try {
      const blob = await contactsApi.exportCsv(filters)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `contacts-export-${new Date().toISOString().slice(0, 10)}.csv`
      document.body.appendChild(a); a.click(); a.remove()
      URL.revokeObjectURL(url)
      toast.success('Export ready', 'Your CSV download has started.')
    } catch (err) {
      toast.error('Export failed', err?.message)
    }
    setExporting(false)
  }

  const hasFilters = filters.q || filters.status || filters.source || filters.tag_id || filters.category
  const total = pager?.total ?? rows.length

  return (
    <div className="page">
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', flexWrap: 'wrap', gap: '.75rem', marginBottom: '1.4rem' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>
            Contacts {!loading && <span style={{ fontSize: 14, fontWeight: 600, color: '#9ca3af' }}>· {total.toLocaleString('en-IN')}</span>}
          </h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Your audience — import, segment, and reach out on WhatsApp.</p>
        </div>
        <div style={{ display: 'flex', gap: '.6rem', flexWrap: 'wrap' }}>
          <Link to="/contacts/new" className="lp-ct-btn lp-ct-btn-primary"><Plus size={15} strokeWidth={2} /> New contact</Link>
          <Link to="/imports" className="lp-ct-btn lp-ct-btn-ghost"><Upload size={15} strokeWidth={2} /> Import</Link>
          <button className="lp-ct-btn lp-ct-btn-ghost" onClick={handleExport} disabled={exporting}>
            {exporting ? 'Exporting…' : <><Download size={15} strokeWidth={2} /> Export</>}
          </button>
        </div>
      </div>

      {/* Filter bar */}
      <div className="lp-ct-card" style={{ padding: '.9rem 1rem', marginBottom: '1.1rem', display: 'flex', gap: '.7rem', flexWrap: 'wrap', alignItems: 'center' }}>
        <div style={{ position: 'relative', flex: '1 1 240px', minWidth: 200 }}>
          <span style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', display: 'flex', alignItems: 'center', opacity: .5 }}><Search size={15} strokeWidth={2} /></span>
          <input className="lp-ct-input" value={filters.q} onChange={e => setFilter('q', e.target.value)} placeholder="Search name, number or email…" />
        </div>
        <select
          className="lp-ct-select"
          value={filters.category}
          onChange={e => setFilter('category', e.target.value)}
          style={{ maxWidth: 230 }}
          title="Filter by company category"
        >
          <option value="">All categories</option>
          {catList.map(c => <option key={c} value={c}>{c}</option>)}
        </select>
        <select className="lp-ct-select" value={filters.status} onChange={e => setFilter('status', e.target.value)}>
          <option value="">All statuses</option>
          {Object.entries(STATUS).map(([v, s]) => <option key={v} value={v}>{s.label}</option>)}
        </select>
        <select className="lp-ct-select" value={filters.source} onChange={e => setFilter('source', e.target.value)}>
          <option value="">All sources</option>
          {['manual', 'csv_import', 'web_form', 'meta_lead_ads'].map(v => <option key={v} value={v}>{v.replace(/_/g, ' ')}</option>)}
        </select>
        <select className="lp-ct-select" value={filters.tag_id} onChange={e => setFilter('tag_id', e.target.value)}>
          <option value="">All tags</option>
          {tagList.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
        </select>
        {hasFilters && (
          <button onClick={clearFilters} style={{ background: 'none', border: 'none', color: 'var(--primary,#0a6cc4)', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>Clear</button>
        )}
      </div>

      {/* Table */}
      <div className="lp-ct-card" style={{ overflow: 'hidden' }}>
        {loading ? (
          <div style={{ padding: '1rem 1.25rem' }}>
            {[1, 2, 3, 4, 5].map(i => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: '.85rem', padding: '.7rem 0' }}>
                <div style={{ width: 38, height: 38, borderRadius: '50%', background: '#f3f4f6' }} />
                <div style={{ flex: 1 }}>
                  <div style={{ height: 10, width: '25%', background: '#f3f4f6', borderRadius: 5, marginBottom: 6 }} />
                  <div style={{ height: 8, width: '40%', background: '#f3f4f6', borderRadius: 5 }} />
                </div>
              </div>
            ))}
          </div>
        ) : rows.length === 0 ? (
          <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', padding: '56px 24px', gap: 16, textAlign: 'center' }}>
            <div style={{ width: 72, height: 72, borderRadius: 20, background: 'var(--primary-light,#e8f3fc)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 32 }}>
              {hasFilters ? <Search size={32} strokeWidth={1.4} /> : <User size={32} strokeWidth={1.4} />}
            </div>
            <div>
              <div style={{ fontSize: 17, fontWeight: 700, color: '#111827', marginBottom: 6 }}>
                {hasFilters ? 'No contacts match your filters' : 'No contacts yet'}
              </div>
              <div style={{ fontSize: 14, color: '#9ca3af', maxWidth: 340, lineHeight: 1.5 }}>
                {hasFilters ? 'Try adjusting your search or filters.' : 'Start building your audience by importing a CSV or adding contacts one by one.'}
              </div>
            </div>
            {!hasFilters && (
              <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', justifyContent: 'center' }}>
                <Link to="/contacts/new" className="lp-ct-btn lp-ct-btn-primary"><Plus size={15} strokeWidth={2} /> Add contact</Link>
                <Link to="/imports" className="lp-ct-btn lp-ct-btn-ghost"><Upload size={15} strokeWidth={2} /> Import CSV</Link>
              </div>
            )}
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14, minWidth: 980 }}>
              <thead>
                <tr style={{ textAlign: 'left', color: '#6b7280', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em', borderBottom: '1px solid #f0f1f3' }}>
                  <th style={{ padding: '.7rem 1.25rem', fontWeight: 700 }}>Name</th>
                  <th style={{ padding: '.7rem .6rem', fontWeight: 700 }}>Contact</th>
                  <th style={{ padding: '.7rem .6rem', fontWeight: 700 }}>Company</th>
                  <th style={{ padding: '.7rem .6rem', fontWeight: 700 }}>Status</th>
                  <th style={{ padding: '.7rem .6rem', fontWeight: 700 }}>Tags</th>
                  <th style={{ padding: '.7rem .6rem', fontWeight: 700 }}>Source</th>
                  <th style={{ padding: '.7rem 1.25rem', fontWeight: 700, whiteSpace: 'nowrap' }}>Added</th>
                </tr>
              </thead>
              <tbody>
                {rows.map(c => {
                  const st = STATUS[c.status] ?? { label: c.status, bg: '#f3f4f6', color: '#4b5563' }
                  const [abg, acol] = tintFor(c.id)
                  return (
                    <tr key={c.id} className="lp-ct-row" style={{ borderBottom: '1px solid #f6f7f9' }} onClick={() => navigate(`/contacts/${c.id}`)}>
                      <td style={{ padding: '.7rem 1.25rem' }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '.7rem' }}>
                          <div style={{ width: 36, height: 36, borderRadius: '50%', background: abg, color: acol, fontWeight: 800, fontSize: 13, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>{initials(c.name)}</div>
                          <div style={{ minWidth: 0 }}>
                            <div style={{ fontWeight: 700, color: c.name ? '#111827' : '#9ca3af' }}>{c.name || '—'}</div>
                            {c.job_title && (
                              <div style={{ fontSize: 12, color: '#9ca3af', marginTop: 1 }}>{c.job_title}</div>
                            )}
                          </div>
                        </div>
                      </td>
                      <td style={{ padding: '.7rem .6rem', whiteSpace: 'nowrap' }}>
                        <div style={{ fontFamily: 'monospace', fontSize: 13, color: '#374151' }}>{c.wa_number}</div>
                        {c.email && (
                          <div style={{ fontSize: 12, color: '#9ca3af', marginTop: 2, fontFamily: 'inherit' }}>{c.email}</div>
                        )}
                      </td>
                      <td style={{ padding: '.7rem .6rem', maxWidth: 260 }}>
                        {c.company_name || c.business_type ? (
                          <>
                            {c.company_name && (
                              <div style={{ fontWeight: 600, color: '#374151', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={c.company_name}>
                                {c.company_name}
                              </div>
                            )}
                            {(c.business_type || c.company_industry) && (
                              <div style={{ fontSize: 11.5, color: '#6b7280', marginTop: 2, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={c.business_type || c.company_industry}>
                                {c.business_type || c.company_industry}
                              </div>
                            )}
                          </>
                        ) : <span style={{ color: '#d1d5db' }}>—</span>}
                      </td>
                      <td style={{ padding: '.7rem .6rem' }}>
                        <span style={{ padding: '.2rem .6rem', borderRadius: 999, fontSize: 11.5, fontWeight: 700, background: st.bg, color: st.color }}>{st.label}</span>
                      </td>
                      <td style={{ padding: '.7rem .6rem' }}>
                        {c.tags?.length ? (
                          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4, alignItems: 'center' }}>
                            {c.tags.slice(0, 3).map(t => (
                              <span key={t.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 3, padding: '.15rem .5rem', borderRadius: 999, fontSize: 11, fontWeight: 600, background: (t.color ?? '#0a6cc4') + '22', color: t.color ?? '#0a6cc4' }}>
                                {t.name}
                                <button onClick={e => detachTag(c.id, t.id, e)} title={`Remove ${t.name}`} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'inherit', fontSize: 13, lineHeight: 1, padding: 0, opacity: .7 }}>×</button>
                              </span>
                            ))}
                            {c.tags.length > 3 && <span style={{ fontSize: 11, color: '#9ca3af', fontWeight: 600 }}>+{c.tags.length - 3}</span>}
                          </div>
                        ) : <span style={{ color: '#d1d5db' }}>—</span>}
                      </td>
                      <td style={{ padding: '.7rem .6rem', color: '#6b7280', fontSize: 12.5, textTransform: 'capitalize', whiteSpace: 'nowrap' }}>{c.source?.replace(/_/g, ' ') ?? '—'}</td>
                      <td style={{ padding: '.7rem 1.25rem', color: '#6b7280', fontSize: 12.5, whiteSpace: 'nowrap' }}>{c.created_at?.slice(0, 10) ?? '—'}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}

        {/* Pagination */}
        {pager && pager.pageCount > 1 && (
          <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '.85rem 1.25rem', borderTop: '1px solid #f0f1f3' }}>
            <span style={{ fontSize: 13, color: '#6b7280' }}>Page {pager.currentPage} of {pager.pageCount}</span>
            <div style={{ display: 'flex', gap: '.5rem' }}>
              <button className="lp-ct-btn lp-ct-btn-ghost" disabled={page <= 1} onClick={() => setPage(p => p - 1)} style={{ opacity: page <= 1 ? .5 : 1 }}>← Prev</button>
              <button className="lp-ct-btn lp-ct-btn-ghost" disabled={page >= pager.pageCount} onClick={() => setPage(p => p + 1)} style={{ opacity: page >= pager.pageCount ? .5 : 1 }}>Next →</button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
