import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { Wallet, BadgeCheck, CalendarClock, Gift, TriangleAlert, ShoppingCart, Download, Search } from 'lucide-react'
import { admin } from '../../api/admin'
import { openPdf } from '../../api/client'
import { toast } from '../../components/Toast'
import { Pill, Kpi } from './AdminUi'
import { th, td, fmtDate, PLAN_COLOR } from './adminUtil'

const VIEWS = [['all', 'All'], ['paying', 'Paying'], ['ending', 'Ending soon'], ['complimentary', 'Complimentary'], ['halted', 'Payment failed'], ['pending', 'Checkout not paid'], ['ended', 'Ended']]
const STATE = { paying: ['Paying', 'var(--success)'], complimentary: ['Complimentary', '#0e8f8c'], halted: ['Payment failed', 'var(--danger)'], pending: ['Not paid', 'var(--warning)'], ended: ['Ended', 'var(--text-3)'] }
const SOURCE = { online: 'Razorpay', offline: 'Recorded by hand', complimentary: 'Given free by hand', legacy: 'Old recurring plan', pending: 'Checkout started' }
const SORTS = [['newest', 'Newest first'], ['ending', 'Ending soonest'], ['amount', 'Highest amount'], ['customer', 'Customer A–Z']]
const inr2 = (p) => '₹' + (Number(p || 0) / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const rs = (p) => '₹' + Math.round((p || 0) / 100).toLocaleString('en-IN')
const csvCell = (v) => { const t = String(v ?? ''); return /^[=+\-@]/.test(t) ? `"'${t.replace(/"/g, '""')}"` : /[",\n]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t }

function exportCsv(rows) {
  const head = ['ID', 'Customer', 'Owner e-mail', 'Plan', 'Cycle', 'State', 'Source', 'Price before GST (INR)', 'GST (INR)', 'Total charged (INR)', 'Offer', 'Discount (INR)', 'Period start', 'Period end', 'Payment reference', 'Invoice']
  const lines = rows.map(r => [r.id, r.tenant_name, r.owner_email, r.plan, r.billing_cycle, r.state, SOURCE[r.source], (r.price_paise / 100).toFixed(2), r.gst_paise != null ? (r.gst_paise / 100).toFixed(2) : '', (r.amount_paise / 100).toFixed(2), r.offer?.label ?? '', r.offer ? (r.offer.discount_paise / 100).toFixed(2) : '', r.period_start ?? '', r.period_end ?? '', r.payment_ref ?? '', r.invoice?.number ?? ''])
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + [head, ...lines].map(l => l.map(csvCell).join(',')).join('\n')], { type: 'text/csv;charset=utf-8' })); a.download = `tripsarthi-subscriptions-${new Date().toISOString().slice(0, 10)}.csv`; a.click(); URL.revokeObjectURL(a.href)
}

function TimeLeft({ r }) {
  if (r.days_left === null) return <span className="cu-sub">{r.state === 'ended' && r.period_end ? `ended ${fmtDate(r.period_end)}` : '—'}</span>
  const tone = r.days_left <= 3 ? 'var(--danger)' : r.days_left <= 7 ? 'var(--warning)' : 'var(--primary)'
  return (
    <div style={{ minWidth: 130 }}>
      <div style={{ fontWeight: 700, color: r.ending_soon ? tone : 'inherit' }}>{r.days_left <= 0 ? 'ends today' : `${r.days_left} day${r.days_left === 1 ? '' : 's'} left`}</div>
      <div className="sub-bar"><i style={{ width: `${r.progress ?? 0}%`, background: tone }} /></div>
      <div className="cu-sub">{fmtDate(r.period_start)} → {fmtDate(r.period_end)}</div>
    </div>
  )
}

export default function AdminSubscriptionsPage() {
  const [f, setF] = useState({ q: '', view: 'all', plan: '', source: '', sort: 'newest', page: 1 })
  const [d, setD] = useState(null)
  const [exporting, setExporting] = useState(false)
  useEffect(() => {
    let alive = true
    const t = setTimeout(() => { admin.subscriptions({ ...f, per_page: 25 }).then(r => { if (alive) setD(r) }).catch(e => toast.error('Could not load', e.message)) }, f.q ? 300 : 0)
    return () => { alive = false; clearTimeout(t) }
  }, [f])
  const S = d?.summary
  const pages = d ? Math.max(1, Math.ceil(d.total / d.per_page)) : 1
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value, page: 1 })
  const views = useMemo(() => VIEWS.filter(([k]) => k === 'all' || S?.views[k] > 0 || f.view === k), [S, f.view])

  async function doExport() {
    setExporting(true)
    try { const all = await admin.subscriptions({ ...f, page: 1, per_page: 1000 }); exportCsv(all.rows); toast.success('Exported', `${all.rows.length} rows (current filters)`) } catch (e) { toast.error('Export failed', e.message) } finally { setExporting(false) }
  }

  return (
    <div className="page">
      <div className="page-header">
        <div><h1 className="page-title">Subscriptions</h1><div style={{ color: 'var(--text-3)', fontSize: 13.5 }}>Every plan period: what the customer paid, how long it has left, where the money came from and which GST invoice it belongs to.</div></div>
        <button className="btn btn-ghost btn-sm" onClick={doExport} disabled={exporting || !d}><Download size={14} /> {exporting ? 'Preparing…' : 'Export CSV'}</button>
      </div>

      {S && <div className="cu-kpis">
        <Kpi icon={BadgeCheck} tone="#1a9d57" label="Paying now" value={S.views.paying} sub={`MRR ${rs(S.mrr_paise)} · ARR ${rs(S.arr_paise)}`} />
        <Kpi icon={Wallet} tone="#0a6cc4" label="Collected, last 30 days" value={rs(S.last_30_days.total)} sub={`${S.last_30_days.n} payment${S.last_30_days.n === 1 ? '' : 's'} · ${rs(S.last_30_days.price)} + ${rs(S.last_30_days.gst)} GST`} />
        <Kpi icon={CalendarClock} tone="#f98f26" label="Ending within 7 days" value={S.views.ending} sub={S.ended_30d ? `${S.ended_30d} paid plan${S.ended_30d === 1 ? '' : 's'} ended in 30 days` : 'no paid plans ended lately'} />
        <Kpi icon={Gift} tone="#12a89e" label="Complimentary" value={S.views.complimentary} sub="free periods given by hand" />
        <Kpi icon={TriangleAlert} tone={S.views.halted ? '#d93025' : '#8a99b3'} label="Payment failed" value={S.views.halted} accent={S.views.halted ? '#d93025' : undefined} sub="plans to chase" />
        <Kpi icon={ShoppingCart} tone={S.abandoned_checkouts_7d ? '#f59e0b' : '#8a99b3'} label="Checkouts not paid" value={S.abandoned_checkouts_7d} sub="started in the last 7 days" />
      </div>}

      <div className="cu-chips">
        {views.map(([k, label]) => <button key={k} type="button" className={'cu-chip' + (f.view === k ? ' on' : '')} onClick={() => setF({ ...f, view: k, page: 1 })}>{label}{S && <b>{S.views[k]}</b>}</button>)}
      </div>
      <div className="cu-filters">
        <div className="cu-search"><Search size={15} /><input value={f.q} placeholder="Search customer, owner e-mail, payment reference, invoice no. or coupon" onChange={set('q')} /></div>
        <select className="form-select" value={f.plan} onChange={set('plan')}><option value="">All plans</option>{['starter', 'growth', 'pro', 'free'].map(p => <option key={p}>{p}</option>)}</select>
        <select className="form-select" value={f.source} onChange={set('source')}><option value="">All sources</option>{Object.entries(SOURCE).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        <select className="form-select" value={f.sort} onChange={set('sort')}>{SORTS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
      </div>

      <div className="card" style={{ overflowX: 'auto' }}>
        <table className="cu-table" style={{ minWidth: 1020 }}>
          <thead><tr>{['Customer', 'Plan', 'Amount', 'Time left', 'Status', 'Source', 'Invoice', ''].map(h => <th key={h} style={{ ...th, textAlign: h === 'Amount' ? 'right' : 'left' }}>{h}</th>)}</tr></thead>
          <tbody>
            {!d && <tr><td colSpan={8} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Loading…</td></tr>}
            {d && d.rows.length === 0 && <tr><td colSpan={8} style={{ ...td, textAlign: 'center', color: 'var(--text-3)', padding: 36 }}>No subscriptions match these filters.</td></tr>}
            {d?.rows.map(r => {
              const [label, color] = STATE[r.state]
              return (
                <tr key={r.id} style={{ cursor: 'default' }}>
                  <td style={td}><Link to={`/admin/customers?open=${r.tenant_id}`} className="cu-name" style={{ textDecoration: 'none' }}>{r.tenant_name}</Link><div className="cu-sub">{r.owner_email || r.owner_name}{r.partner ? ` · via ${r.partner}` : ''}</div></td>
                  <td style={td}><Pill color={PLAN_COLOR[r.plan]}>{r.plan}</Pill><div className="cu-sub" style={{ textTransform: 'capitalize' }}>{r.billing_cycle}</div></td>
                  <td style={{ ...td, textAlign: 'right' }}>
                    {r.amount_paise > 0 ? <><b>{inr2(r.amount_paise)}</b>
                      <div className="cu-sub">{r.gst_basis === 'extra' ? `${inr2(r.price_paise)} + GST ${inr2(r.gst_paise)}` : 'GST included'}</div></> : <span className="cu-sub">₹0 (free)</span>}
                    {r.offer && <div><span className="sub-offer" title={r.offer.kind === 'coupon' ? 'Coupon code' : 'Automatic promotion'}>{r.offer.label} · −{rs(r.offer.discount_paise)}</span></div>}
                  </td>
                  <td style={td}><TimeLeft r={r} /></td>
                  <td style={td}><Pill color={color}>{label}</Pill>{r.ending_soon && <div className="cu-sub" style={{ color: 'var(--warning)', fontWeight: 600 }}>ending soon</div>}</td>
                  <td style={td}>{SOURCE[r.source]}{r.payment_ref && <div className="cu-sub" style={{ fontFamily: 'ui-monospace,monospace' }}>{r.payment_ref}</div>}</td>
                  <td style={td}>{r.invoice ? <button type="button" className="iv-link" onClick={() => openPdf(`/admin/invoices/${r.invoice.id}/pdf`).catch(e => toast.error('Could not open', e.message))}>{r.invoice.number}</button> : (r.amount_paise > 0 && r.state !== 'pending' ? <Link to="/admin/invoices" className="cu-sub" style={{ color: 'var(--warning)', fontWeight: 600 }}>no invoice yet</Link> : <span className="cu-sub">—</span>)}</td>
                  <td style={{ ...td, whiteSpace: 'nowrap' }}><Link to={`/admin/customers?open=${r.tenant_id}`} className="btn btn-ghost btn-sm">Customer</Link></td>
                </tr>)
            })}
          </tbody>
        </table>
      </div>
      {d && d.total > d.per_page && <div style={{ display: 'flex', gap: 10, justifyContent: 'center', marginTop: 12, alignItems: 'center' }}>
        <button className="btn btn-ghost btn-sm" disabled={f.page <= 1} onClick={() => setF({ ...f, page: f.page - 1 })}>← Prev</button><span>Page {f.page} of {pages} · {d.total} subscriptions</span><button className="btn btn-ghost btn-sm" disabled={f.page >= pages} onClick={() => setF({ ...f, page: f.page + 1 })}>Next →</button></div>}
    </div>
  )
}
