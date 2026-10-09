import { useState, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../../api/client'

const STATUS_TINT = {
  draft:    { bg: '#f1f5f9', c: '#475569' },
  sent:     { bg: '#dbeafe', c: '#1d4ed8' },
  accepted: { bg: '#dcfce7', c: '#15803d' },
  declined: { bg: '#fee2e2', c: '#b91c1c' },
  expired:  { bg: '#fef3c7', c: '#92400e' },
}
const money = (n, cur = 'INR') => {
  try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: cur, maximumFractionDigits: 0 }).format(Number(n || 0)) }
  catch { return `${cur} ${Number(n || 0).toLocaleString()}` }
}

/**
 * Quotations tied to a lead — the quotes across all of the contact's deals
 * (CRM richness, matches the reference lead-detail Quotations section).
 */
export default function QuotationsPanel({ contactId }) {
  const [quotes, setQuotes] = useState(null)
  const navigate = useNavigate()

  useEffect(() => {
    api.get(`/contacts/${contactId}/quotations`).then(r => setQuotes(r.data ?? [])).catch(() => setQuotes([]))
  }, [contactId])

  return (
    <div className="lp-cd-card">
      <div style={{ fontSize: 15, fontWeight: 800, color: '#111827', marginBottom: '.85rem', display: 'flex', alignItems: 'center', gap: 6 }}>📄 Quotations</div>
      {quotes === null ? (
        <div style={{ color: '#9ca3af', fontSize: 13.5 }}>Loading…</div>
      ) : quotes.length === 0 ? (
        <div style={{ color: '#9ca3af', fontSize: 13.5 }}>No quotations yet. Generate one from any of this lead's deals.</div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {quotes.map(q => {
            const st = STATUS_TINT[(q.status || 'draft').toLowerCase()] || STATUS_TINT.draft
            return (
              <div key={q.id} onClick={() => q.deal_id && navigate(`/deals/${q.deal_id}`)}
                style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, padding: '.6rem .7rem', border: '1px solid #eef0f3', borderRadius: 10, cursor: q.deal_id ? 'pointer' : 'default' }}>
                <div style={{ minWidth: 0 }}>
                  <div style={{ fontWeight: 700, fontSize: 13.5, color: '#111827' }}>{q.number || `Quote #${q.id}`}</div>
                  <div style={{ fontSize: 12, color: '#6b7280', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                    {q.deal_title || '—'}{q.valid_until ? ` · valid till ${q.valid_until}` : ''}
                  </div>
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexShrink: 0 }}>
                  <span style={{ fontWeight: 700, fontSize: 13.5, color: '#111827' }}>{money(q.total, q.currency)}</span>
                  <span style={{ fontSize: 11.5, fontWeight: 700, textTransform: 'capitalize', background: st.bg, color: st.c, borderRadius: 999, padding: '.15rem .55rem' }}>{q.status || 'draft'}</span>
                </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
