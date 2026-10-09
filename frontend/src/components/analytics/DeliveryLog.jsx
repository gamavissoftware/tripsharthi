import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Search, Download, ChevronLeft, ChevronRight, RefreshCw, X,
  MessageSquare, AlertTriangle, Info,
} from 'lucide-react'
import { analytics as analyticsApi } from '../../api/analytics'
import { toast } from '../Toast'
import {
  StatusPill, RepliedPill, RangePicker, dateTime, num, today, daysAgo, STATUS_META,
} from './reportUi'

const PER_PAGE = 50

const CATEGORY_LABELS = {
  marketing: 'Marketing', utility: 'Utility', authentication: 'Authentication',
  service: 'Service', free_form: 'Free-form (in window)',
}

/**
 * The delivery log: one row per message, naming the recipient and what happened
 * to it.
 *
 * Aggregates tell you 38% went unread; this is the screen that tells you which
 * 38%, so somebody can be called. It is deliberately the same filter vocabulary
 * as the overview above it — the CSV button exports exactly what is on screen.
 */
export default function DeliveryLog({ campaigns = [], lockedCampaignId = null, compact = false }) {
  const [range, setRange] = useState(
    lockedCampaignId
      // A campaign report is already scoped to one send; a date range on top of
      // it would only ever hide recipients.
      ? { preset: 'all', from: null, to: null }
      : { preset: '30d', from: daysAgo(29), to: today() }
  )
  const [search, setSearch]       = useState('')
  const [query, setQuery]         = useState('')   // debounced
  const [status, setStatus]       = useState('')
  const [category, setCategory]   = useState('')
  const [campaignId, setCampaign] = useState(lockedCampaignId ? String(lockedCampaignId) : '')
  const [replied, setReplied]     = useState('')
  const [page, setPage]           = useState(1)

  const [data, setData]       = useState({ rows: [], total: 0, page: 1, pages: 0 })
  const [loading, setLoading] = useState(true)
  const [exporting, setExporting] = useState(false)
  const [openRow, setOpenRow] = useState(null)

  // Debounce the search box so typing a phone number is one request, not ten.
  useEffect(() => {
    const t = setTimeout(() => { setQuery(search.trim()); setPage(1) }, 350)
    return () => clearTimeout(t)
  }, [search])

  const filters = useMemo(() => ({
    from: range.from, to: range.to,
    q: query || undefined,
    status: status || undefined,
    category: category || undefined,
    campaign_id: campaignId || undefined,
    replied: replied || undefined,
  }), [range, query, status, category, campaignId, replied])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const res = await analyticsApi.messages({ ...filters, page, per_page: PER_PAGE })
      setData(res?.data ?? { rows: [], total: 0, page: 1, pages: 0 })
    } catch (e) {
      toast.error(e.message || 'Could not load the delivery log')
      setData({ rows: [], total: 0, page: 1, pages: 0 })
    } finally {
      setLoading(false)
    }
  }, [filters, page])

  useEffect(() => { load() }, [load])

  const handleExport = async () => {
    setExporting(true)
    try {
      const { blob, filename, rows, total, truncated } = await analyticsApi.exportMessages(filters)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = filename
      a.click()
      URL.revokeObjectURL(url)

      truncated
        ? toast.error(`Exported the first ${num(rows)} of ${num(total)} rows — narrow the date range for the rest`)
        : toast.success(`Exported ${num(rows)} rows`)
    } catch (e) {
      toast.error(e.message || 'Export failed')
    } finally {
      setExporting(false)
    }
  }

  const resetFilters = () => {
    setSearch(''); setStatus(''); setCategory('')
    if (!lockedCampaignId) setCampaign('')
    setReplied(''); setPage(1)
  }

  const hasFilters = Boolean(query || status || category || replied || (campaignId && !lockedCampaignId))
  const from = data.total === 0 ? 0 : (data.page - 1) * PER_PAGE + 1
  const to   = Math.min(data.page * PER_PAGE, data.total)

  return (
    <div>
      {/* ── Filters ── */}
      <div className="filter-bar" style={{ borderRadius: 'var(--r-md) var(--r-md) 0 0' }}>
        <div className="filter-search" style={{ position: 'relative' }}>
          <Search
            size={15} strokeWidth={2}
            style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-3)' }}
          />
          <input
            className="form-input"
            style={{ paddingLeft: 32, width: '100%' }}
            placeholder="Search name, number or message…"
            value={search}
            onChange={e => setSearch(e.target.value)}
          />
        </div>

        <select className="form-select" value={status} onChange={e => { setStatus(e.target.value); setPage(1) }}>
          <option value="">Any status</option>
          {Object.entries(STATUS_META).map(([key, m]) => (
            <option key={key} value={key}>{m.label}</option>
          ))}
        </select>

        <select className="form-select" value={replied} onChange={e => { setReplied(e.target.value); setPage(1) }}>
          <option value="">Replied or not</option>
          <option value="yes">Replied</option>
          <option value="no">No reply</option>
        </select>

        <select className="form-select" value={category} onChange={e => { setCategory(e.target.value); setPage(1) }}>
          <option value="">Any category</option>
          {Object.entries(CATEGORY_LABELS).map(([key, label]) => (
            <option key={key} value={key}>{label}</option>
          ))}
        </select>

        {!lockedCampaignId && (
          <select className="form-select" value={campaignId} onChange={e => { setCampaign(e.target.value); setPage(1) }}>
            <option value="">All traffic</option>
            <option value="none">Flows &amp; inbox only</option>
            {campaigns.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        )}

        {!lockedCampaignId && <RangePicker value={range} onChange={r => { setRange(r); setPage(1) }} />}

        {hasFilters && (
          <button className="btn btn-ghost btn-sm" onClick={resetFilters}>
            <X size={14} strokeWidth={2} /> Clear
          </button>
        )}

        <div style={{ marginLeft: 'auto', display: 'flex', gap: '.5rem' }}>
          <button className="btn btn-ghost btn-sm" onClick={load} disabled={loading} title="Reload">
            <RefreshCw size={14} strokeWidth={2} />
          </button>
          <button className="btn btn-primary btn-sm" onClick={handleExport} disabled={exporting || data.total === 0}>
            <Download size={14} strokeWidth={2} /> {exporting ? 'Exporting…' : 'Export CSV'}
          </button>
        </div>
      </div>

      {/* ── Table ── */}
      <div className="card" style={{ borderRadius: '0 0 var(--r-md) var(--r-md)', overflow: 'hidden' }}>
        <div style={{ overflowX: 'auto' }}>
          <table className="data-table">
            <thead>
              <tr>
                <th>Recipient</th>
                {!compact && <th>Campaign / Template</th>}
                <th>Status</th>
                <th>Sent</th>
                <th>Delivered</th>
                <th>Read</th>
                <th>Reply</th>
              </tr>
            </thead>
            <tbody>
              {loading && data.rows.length === 0 && (
                Array.from({ length: 6 }).map((_, i) => (
                  <tr key={`sk-${i}`}>
                    <td colSpan={compact ? 6 : 7}>
                      <div style={{ height: 16, borderRadius: 4, background: 'var(--surface-2)' }} />
                    </td>
                  </tr>
                ))
              )}

              {!loading && data.rows.length === 0 && (
                <tr>
                  <td colSpan={compact ? 6 : 7}>
                    <div className="empty-state">
                      <div className="empty-state-icon"><MessageSquare /></div>
                      <div className="empty-state-text">
                        {hasFilters
                          ? 'No messages match these filters'
                          : 'No messages sent in this period'}
                      </div>
                    </div>
                  </td>
                </tr>
              )}

              {data.rows.map(row => (
                <LogRow
                  key={row.id} row={row} compact={compact}
                  open={openRow === row.id}
                  onToggle={() => setOpenRow(openRow === row.id ? null : row.id)}
                />
              ))}
            </tbody>
          </table>
        </div>

        <div className="pagination">
          <span>
            {data.total === 0 ? 'No rows' : `${num(from)}–${num(to)} of ${num(data.total)}`}
          </span>
          <button
            className="btn btn-ghost btn-sm" disabled={data.page <= 1 || loading}
            onClick={() => setPage(p => Math.max(1, p - 1))}
          ><ChevronLeft size={14} strokeWidth={2} /> Prev</button>
          <button
            className="btn btn-ghost btn-sm" disabled={data.page >= data.pages || loading}
            onClick={() => setPage(p => p + 1)}
          >Next <ChevronRight size={14} strokeWidth={2} /></button>
        </div>
      </div>
    </div>
  )
}

// ── One message ──────────────────────────────────────────────────────────────
function LogRow({ row, open, onToggle, compact }) {
  const cols = compact ? 6 : 7

  return (
    <>
      <tr onClick={onToggle} style={open ? { background: 'var(--surface-2)' } : undefined}>
        <td className="cell-name">
          <div>{row.contact_name || <span style={{ color: 'var(--text-3)' }}>Unnamed</span>}</div>
          <div style={{
            fontFamily: 'ui-monospace, "SF Mono", monospace', fontSize: '.75rem',
            color: 'var(--text-3)', letterSpacing: '-.02em',
          }}>{row.wa_number ?? '—'}</div>
        </td>

        {!compact && (
          <td>
            {row.campaign_name
              ? (
                <>
                  <div style={{ color: 'var(--text-2)' }}>{row.campaign_name}</div>
                  {row.template_name && (
                    <div style={{ fontSize: '.73rem', color: 'var(--text-3)' }}>
                      {row.template_name}{row.variant ? ` · variant ${row.variant}` : ''}
                    </div>
                  )}
                </>
              )
              : <span style={{ color: 'var(--text-3)' }}>Flow / inbox</span>}
          </td>
        )}

        <td><StatusPill status={row.status} title={row.error || undefined} /></td>
        <td className="cell-mono">{dateTime(row.sent_at) ?? '—'}</td>
        <td className="cell-mono">{dateTime(row.delivered_at) ?? '—'}</td>
        <td className="cell-mono">
          {row.read_at
            ? <span style={{ color: '#1d4ed8', fontWeight: 600 }}>{dateTime(row.read_at)}</span>
            : '—'}
        </td>
        <td><RepliedPill at={row.replied_at} /></td>
      </tr>

      {open && (
        <tr>
          <td colSpan={cols} style={{ background: 'var(--surface-2)', padding: '1rem 1.1rem' }}>
            <Detail row={row} />
          </td>
        </tr>
      )}
    </>
  )
}

function Detail({ row }) {
  const steps = [
    { label: 'Queued',    at: row.created_at },
    { label: 'Sent',      at: row.sent_at },
    { label: 'Delivered', at: row.delivered_at },
    { label: 'Read',      at: row.read_at },
    { label: 'Replied',   at: row.replied_at },
  ]

  return (
    <div style={{ display: 'flex', gap: '2rem', flexWrap: 'wrap' }}>
      <div style={{ flex: '1 1 320px', minWidth: 260 }}>
        <div style={{
          fontSize: '.69rem', fontWeight: 600, letterSpacing: '.08em',
          textTransform: 'uppercase', color: 'var(--text-3)', marginBottom: '.6rem',
        }}>Timeline</div>

        {steps.map(step => (
          <div key={step.label} style={{
            display: 'flex', alignItems: 'center', gap: '.6rem',
            fontSize: '.8rem', marginBottom: '.35rem',
            opacity: step.at ? 1 : 0.45,
          }}>
            <span style={{
              width: 8, height: 8, borderRadius: '50%', flexShrink: 0,
              background: step.at ? 'var(--primary)' : 'var(--border-strong)',
            }} />
            <span style={{ width: 80, fontWeight: 600, color: 'var(--text-2)' }}>{step.label}</span>
            <span style={{ color: 'var(--text-3)', fontVariantNumeric: 'tabular-nums' }}>
              {dateTime(step.at) ?? 'not yet'}
            </span>
          </div>
        ))}

        {row.error && (
          <div style={{
            marginTop: '.85rem', padding: '.6rem .75rem', borderRadius: 'var(--r-sm)',
            background: 'var(--danger-light)', color: '#991b1b',
            fontSize: '.8rem', display: 'flex', gap: '.5rem', alignItems: 'flex-start',
          }}>
            <AlertTriangle size={15} strokeWidth={2} style={{ flexShrink: 0, marginTop: 1 }} />
            <span>{row.error}</span>
          </div>
        )}
      </div>

      <div style={{ flex: '2 1 380px', minWidth: 280 }}>
        <div style={{
          fontSize: '.69rem', fontWeight: 600, letterSpacing: '.08em',
          textTransform: 'uppercase', color: 'var(--text-3)', marginBottom: '.6rem',
        }}>Message</div>

        <div style={{
          padding: '.7rem .85rem', background: 'var(--surface)', borderRadius: 'var(--r-sm)',
          border: '1px solid var(--border)', fontSize: '.85rem', color: 'var(--text-2)',
          whiteSpace: 'pre-wrap', wordBreak: 'break-word',
        }}>{row.preview || <em style={{ color: 'var(--text-3)' }}>No text content</em>}</div>

        <div style={{
          display: 'flex', gap: '1.25rem', flexWrap: 'wrap',
          marginTop: '.75rem', fontSize: '.75rem', color: 'var(--text-3)',
        }}>
          <span>Type: <strong style={{ color: 'var(--text-2)' }}>{row.type}</strong></span>
          <span>Category: <strong style={{ color: 'var(--text-2)' }}>
            {CATEGORY_LABELS[row.category] ?? row.category ?? 'uncategorised'}
          </strong></span>
          <span>{row.billable ? 'Billed by Meta' : 'Free'}</span>
          {row.wa_message_id && (
            <span style={{ fontFamily: 'ui-monospace, monospace' }}>{row.wa_message_id}</span>
          )}
        </div>

        {row.status === 'sent' && (
          <div style={{
            marginTop: '.75rem', fontSize: '.76rem', color: 'var(--text-3)',
            display: 'flex', gap: '.4rem', alignItems: 'flex-start',
          }}>
            <Info size={13} strokeWidth={2} style={{ flexShrink: 0, marginTop: 2 }} />
            <span>
              WhatsApp accepted this message but has not confirmed delivery — usually a phone
              that is off or has no data. It is still billed.
            </span>
          </div>
        )}
      </div>
    </div>
  )
}
