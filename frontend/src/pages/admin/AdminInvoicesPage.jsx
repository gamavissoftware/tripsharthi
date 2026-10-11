import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Download, FileArchive, Receipt, Wallet, Landmark, ChevronLeft, ChevronRight, AlertTriangle, FileText, Send, RotateCcw } from 'lucide-react'
import { admin } from '../../api/admin'
import { downloadFile, openPdf } from '../../api/client'
import { toast } from '../../components/Toast'
import { Pill, Kpi } from './AdminUi'
import { th, td, fmtDate, fmtDateTime } from './adminUtil'
import AdminInvoiceSettings from './AdminInvoiceSettings'
import AdminCreditNoteModal from './AdminCreditNoteModal'

const inr2 = (p) => '₹' + (Number(p || 0) / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const thisMonth = () => new Date().toISOString().slice(0, 7)
const shiftMonth = (m, n) => { const [y, mo] = m.split('-').map(Number); const d = new Date(Date.UTC(y, mo - 1 + n, 1)); return d.toISOString().slice(0, 7) }
const monthLabel = (m) => new Date(m + '-01T00:00:00Z').toLocaleDateString('en-IN', { month: 'long', year: 'numeric', timeZone: 'UTC' })
const fyList = () => { const d = new Date(); const y = d.getMonth() >= 3 ? d.getFullYear() : d.getFullYear() - 1; return [0, 1, 2, 3].map(i => `${y - i}-${String(y - i + 1).slice(2)}`) }

/** Filters -> API parameters. The period is one of: a month, a financial year, a custom range, or everything. */
function toParams(f, extra = {}) {
  const p = { q: f.q, buyer_type: f.buyer_type, supply_type: f.supply_type, plan: f.plan, source: f.source, pos: f.pos, email: f.email, min: f.min, max: f.max, ...extra }
  if (f.mode === 'month') p.month = f.month
  else if (f.mode === 'fy') p.fy = f.fy
  else if (f.mode === 'range') { p.from = f.from; p.to = f.to }
  return p
}

const PLANS = ['starter', 'growth', 'pro']

function MissingBanner({ rows, onDone }) {
  const [busy, setBusy] = useState(false)
  if (!rows?.length) return null
  async function issue() {
    setBusy(true)
    try {
      const r = await admin.issueMissing(rows.map(x => x.id))
      if (r.issued) toast.success(`${r.issued} invoice${r.issued > 1 ? 's' : ''} issued`, 'Dated today and e-mailed to the customers')
      if (r.failed?.length) toast.error('Some could not be issued', r.failed.join(' · '))
      onDone()
    } catch (e) { toast.error('Could not issue', e.message) } finally { setBusy(false) }
  }
  return (
    <div className="iv-missing">
      <AlertTriangle size={18} />
      <div style={{ flex: 1 }}>
        <b>{rows.length} paid {rows.length === 1 ? 'period has' : 'periods have'} no GST invoice yet</b>
        <div style={{ fontSize: 13 }}>{rows.slice(0, 4).map(r => `${r.tenant_name} ${inr2(r.amount_paise)}${r.offline ? ' (offline)' : ''}`).join(' · ')}{rows.length > 4 ? ` · +${rows.length - 4} more` : ''}. Issuing dates each invoice <b>today</b> (the numbering cannot be back-dated) and e-mails it to the customer.</div>
      </div>
      <button className="btn btn-primary btn-sm" disabled={busy} onClick={issue}>{busy ? 'Issuing…' : `Issue ${rows.length === 1 ? 'invoice' : 'invoices'}`}</button>
    </div>
  )
}

function Filters({ f, setF }) {
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value, page: 1 })
  return (
    <div className="iv-filters">
      <div className="iv-period">
        <select className="form-select" value={f.mode} onChange={e => setF({ ...f, mode: e.target.value, page: 1 })}>
          <option value="month">Month</option><option value="fy">Financial year</option><option value="range">Custom dates</option><option value="all">All time</option>
        </select>
        {f.mode === 'month' && <div className="iv-month"><button type="button" className="btn btn-ghost btn-sm" onClick={() => setF({ ...f, month: shiftMonth(f.month, -1), page: 1 })} aria-label="Previous month"><ChevronLeft size={16} /></button>
          <input type="month" className="form-input" value={f.month} max={thisMonth()} onChange={e => e.target.value && setF({ ...f, month: e.target.value, page: 1 })} />
          <button type="button" className="btn btn-ghost btn-sm" disabled={f.month >= thisMonth()} onClick={() => setF({ ...f, month: shiftMonth(f.month, 1), page: 1 })} aria-label="Next month"><ChevronRight size={16} /></button></div>}
        {f.mode === 'fy' && <select className="form-select" value={f.fy} onChange={set('fy')}>{fyList().map(y => <option key={y} value={y}>FY {y}</option>)}</select>}
        {f.mode === 'range' && <><input type="date" className="form-input" value={f.from} onChange={set('from')} /><span>to</span><input type="date" className="form-input" value={f.to} onChange={set('to')} /></>}
      </div>
      <input className="form-input iv-q" placeholder="Search invoice no., customer, GSTIN, e-mail, payment ref or #workspace" value={f.q} onChange={set('q')} />
      <select className="form-select" value={f.buyer_type} onChange={set('buyer_type')}><option value="">B2B + B2C</option><option value="b2b">B2B (with GSTIN)</option><option value="b2c">B2C (no GSTIN)</option></select>
      <select className="form-select" value={f.supply_type} onChange={set('supply_type')}><option value="">All tax types</option><option value="intra">CGST + SGST</option><option value="inter">IGST</option></select>
      <select className="form-select" value={f.plan} onChange={set('plan')}><option value="">All plans</option>{PLANS.map(p => <option key={p} value={p}>{p}</option>)}</select>
      <select className="form-select" value={f.source} onChange={set('source')}><option value="">All payments</option><option value="online">Online (Razorpay)</option><option value="offline">Offline (by hand)</option></select>
      <select className="form-select" value={f.email} onChange={set('email')}><option value="">Any e-mail status</option><option value="sent">E-mailed</option><option value="pending">Not e-mailed</option><option value="failed">E-mail failed</option></select>
      <div className="iv-amt"><input type="number" min="0" className="form-input" placeholder="Min ₹" value={f.min} onChange={set('min')} /><input type="number" min="0" className="form-input" placeholder="Max ₹" value={f.max} onChange={set('max')} /></div>
    </div>
  )
}

function InvoicesTab({ f, setF, reloadKey, onChanged }) {
  const [d, setD] = useState(null)
  const [pending, setPending] = useState(0)
  const [busy, setBusy] = useState('')
  const [cnFor, setCnFor] = useState(null)
  const navigate = useNavigate()
  const params = useMemo(() => toParams(f, { sort: f.sort, page: f.page, per_page: 25 }), [f])
  useEffect(() => {
    let alive = true
    const t = setTimeout(() => {
      admin.invoices(params).then(r => { if (alive) setD(r) }).catch(e => toast.error('Could not load', e.message))
      admin.invoices({ ...toParams(f, { email: 'pending' }), per_page: 5 }).then(r => { if (alive) setPending(r.total) }).catch(() => {})
    }, f.q ? 300 : 0)
    return () => { alive = false; clearTimeout(t) }
  }, [params, f, reloadKey])
  const pages = d ? Math.max(1, Math.ceil(d.total / d.per_page)) : 1
  const T = d?.totals

  async function dl(kind) {
    setBusy(kind)
    try { await downloadFile(`/admin/invoices/${kind}?` + new URLSearchParams(Object.fromEntries(Object.entries(toParams(f)).filter(([, v]) => v !== '' && v != null))).toString(), kind === 'export' ? 'invoices.csv' : 'invoices.zip') }
    catch (e) { toast.error('Download failed', e.message) } finally { setBusy('') }
  }
  async function resend(r) {
    setBusy('r' + r.id)
    try { const x = await admin.resendInvoice(r.id); toast.success('E-mailed', `Sent to ${x.to}`); onChanged() } catch (e) { toast.error('Not sent', e.message); onChanged() } finally { setBusy('') }
  }

  return (
    <>
      <div className="iv-kpis">
        <Kpi icon={Receipt} label="Invoices" value={T?.count ?? '…'} sub={pending > 0 ? `${pending} not e-mailed yet` : 'all e-mailed'} />
        <Kpi icon={Wallet} tone="#12a89e" label="Taxable value" value={T ? inr2(T.taxable) : '…'} sub="before GST" />
        <Kpi icon={Landmark} tone="#f98f26" label="GST collected" value={T ? inr2(T.gst) : '…'} sub={T ? `CGST ${inr2(T.cgst)} · SGST ${inr2(T.sgst)} · IGST ${inr2(T.igst)}` : ''} />
        <Kpi icon={FileText} tone="#1a9d57" label="Total invoiced" value={T ? inr2(T.total) : '…'} sub="what customers paid" />
      </div>

      <div className="iv-actions">
        <select className="form-select" value={f.sort} onChange={e => setF({ ...f, sort: e.target.value, page: 1 })}><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="amount">Highest amount</option><option value="customer">Customer A–Z</option></select>
        <span style={{ flex: 1 }} />
        <button className="btn btn-ghost btn-sm" disabled={!!busy || !d?.total} onClick={() => dl('export')}><Download size={14} /> {busy === 'export' ? 'Preparing…' : 'Export CSV'}</button>
        <button className="btn btn-ghost btn-sm" disabled={!!busy || !d?.total} onClick={() => dl('bundle')}><FileArchive size={14} /> {busy === 'bundle' ? 'Preparing…' : 'Download all PDFs (ZIP)'}</button>
      </div>

      <div className="card" style={{ overflowX: 'auto' }}>
        <table className="iv-table">
          <thead><tr>{['Invoice', 'Customer', 'Place of supply', 'Plan & period', 'Taxable', 'GST', 'Total', 'E-mail', ''].map(h => <th key={h} style={{ ...th, textAlign: ['Taxable', 'GST', 'Total'].includes(h) ? 'right' : 'left' }}>{h}</th>)}</tr></thead>
          <tbody>
            {!d && <tr><td colSpan={9} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Loading…</td></tr>}
            {d && d.rows.length === 0 && <tr><td colSpan={9} style={{ ...td, textAlign: 'center', color: 'var(--text-3)', padding: 36 }}>No invoices match these filters.</td></tr>}
            {d?.rows.map(r => (
              <tr key={r.id}>
                <td style={td}><button type="button" className="iv-link" onClick={() => openPdf(`/admin/invoices/${r.id}/pdf`).catch(e => toast.error('Could not open', e.message))}>{r.number}</button><div className="cu-sub">{fmtDate(r.issue_date)}</div></td>
                <td style={td}>
                  <div style={{ fontWeight: 700 }}>{r.buyer_name} <Pill color={r.buyer_type === 'b2b' ? '#0a6cc4' : '#64748b'}>{r.buyer_type.toUpperCase()}</Pill></div>
                  <div className="cu-sub">{r.buyer_gstin ? `GSTIN ${r.buyer_gstin} · ` : ''}<button type="button" className="iv-link soft" onClick={() => navigate('/admin/customers?open=' + r.tenant_id)}>workspace #{r.tenant_id}</button></div>
                </td>
                <td style={td}>{r.place_name}<div className="cu-sub">{r.supply_type === 'intra' ? 'CGST + SGST' : 'IGST'}</div></td>
                <td style={td}><div style={{ textTransform: 'capitalize' }}>{r.plan} · {r.billing_cycle}</div><div className="cu-sub">{r.source === 'offline' ? 'Offline, recorded by hand' : `Razorpay ${r.payment_ref ?? ''}`}</div></td>
                <td style={{ ...td, textAlign: 'right' }}>{inr2(r.taxable_value)}</td>
                <td style={{ ...td, textAlign: 'right' }}>{inr2(r.gst)}<div className="cu-sub">{r.supply_type === 'intra' ? `${inr2(r.cgst)} + ${inr2(r.sgst)}` : `IGST ${r.gst_rate}%`}</div></td>
                <td style={{ ...td, textAlign: 'right', fontWeight: 800 }}>{inr2(r.total)}{r.credited > 0 && <div className="cu-sub" style={{ color: 'var(--danger)', fontWeight: 600 }}>{r.credited >= r.total ? 'Fully credited' : `Credited ${inr2(r.credited)}`}</div>}</td>
                <td style={td}>{r.emailed_at ? <><Pill color="var(--success)">E-mailed</Pill><div className="cu-sub">{fmtDateTime(r.emailed_at)}</div><div className="cu-sub">{r.email_to}</div></>
                  : r.email_error ? <span title={r.email_error}><Pill color="var(--danger)">Failed</Pill><div className="cu-sub" style={{ maxWidth: 170 }}>{r.email_error}</div></span> : <Pill color="var(--warning)">Not sent</Pill>}</td>
                <td style={{ ...td, whiteSpace: 'nowrap' }}>
                  <button className="btn btn-ghost btn-sm" onClick={() => openPdf(`/admin/invoices/${r.id}/pdf`).catch(e => toast.error('Could not open', e.message))}>PDF</button>{' '}
                  <button className="btn btn-ghost btn-sm" disabled={busy === 'r' + r.id} onClick={() => resend(r)} title="E-mail this invoice to the customer again"><Send size={13} /> {r.emailed_at ? 'Resend' : 'Send'}</button>{' '}
                  <button className="btn btn-ghost btn-sm" disabled={r.credited >= r.total} onClick={() => setCnFor(r)} title="Issue a credit note (refund) against this invoice"><RotateCcw size={13} /> Credit note</button>
                </td>
              </tr>))}
          </tbody>
        </table>
      </div>
      {d && d.total > d.per_page && <div style={{ display: 'flex', gap: 10, justifyContent: 'center', marginTop: 12, alignItems: 'center' }}>
        <button className="btn btn-ghost btn-sm" disabled={f.page <= 1} onClick={() => setF({ ...f, page: f.page - 1 })}>← Prev</button><span>Page {f.page} of {pages} · {d.total} invoices</span><button className="btn btn-ghost btn-sm" disabled={f.page >= pages} onClick={() => setF({ ...f, page: f.page + 1 })}>Next →</button></div>}
      {cnFor && <AdminCreditNoteModal invoice={cnFor} onClose={() => setCnFor(null)} onDone={() => { setCnFor(null); onChanged() }} />}
    </>
  )
}

function CreditNotesTab({ f, setF, reloadKey, onChanged }) {
  const [d, setD] = useState(null)
  const [busy, setBusy] = useState('')
  const params = useMemo(() => toParams(f, { sort: f.sort, page: f.page, per_page: 25 }), [f])
  useEffect(() => {
    let alive = true
    const t = setTimeout(() => { admin.creditNotes(params).then(r => { if (alive) setD(r) }).catch(e => toast.error('Could not load', e.message)) }, f.q ? 300 : 0)
    return () => { alive = false; clearTimeout(t) }
  }, [params, f.q, reloadKey])
  const T = d?.totals; const pages = d ? Math.max(1, Math.ceil(d.total / d.per_page)) : 1
  async function resend(r) {
    setBusy('r' + r.id)
    try { const x = await admin.resendCreditNote(r.id); toast.success('E-mailed', `Sent to ${x.to}`) } catch (e) { toast.error('Not sent', e.message) } finally { setBusy(''); onChanged() }
  }
  return (
    <>
      <div className="iv-kpis">
        <Kpi icon={RotateCcw} tone="#d93025" label="Credit notes" value={T?.count ?? '…'} sub="refund documents" />
        <Kpi icon={Wallet} tone="#12a89e" label="Taxable value credited" value={T ? inr2(T.taxable) : '…'} />
        <Kpi icon={Landmark} tone="#f98f26" label="GST credited" value={T ? inr2(T.gst) : '…'} sub={T ? `CGST ${inr2(T.cgst)} · SGST ${inr2(T.sgst)} · IGST ${inr2(T.igst)}` : ''} />
        <Kpi icon={FileText} tone="#1a9d57" label="Total refunded" value={T ? inr2(T.total) : '…'} sub="credited to customers" />
      </div>
      <div className="card" style={{ overflowX: 'auto' }}>
        <table className="iv-table">
          <thead><tr>{['Credit note', 'Customer', 'Against invoice', 'Reason', 'Taxable', 'GST', 'Total credited', 'E-mail', ''].map(h => <th key={h} style={{ ...th, textAlign: ['Taxable', 'GST', 'Total credited'].includes(h) ? 'right' : 'left' }}>{h}</th>)}</tr></thead>
          <tbody>
            {!d && <tr><td colSpan={9} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Loading…</td></tr>}
            {d && d.rows.length === 0 && <tr><td colSpan={9} style={{ ...td, textAlign: 'center', color: 'var(--text-3)', padding: 36 }}>No credit notes in this period. Issue one from the Invoices tab with the Credit note button.</td></tr>}
            {d?.rows.map(r => (
              <tr key={r.id}>
                <td style={td}><button type="button" className="iv-link" onClick={() => openPdf(`/admin/credit-notes/${r.id}/pdf`).catch(e => toast.error('Could not open', e.message))}>{r.number}</button><div className="cu-sub">{fmtDate(r.issue_date)}</div></td>
                <td style={td}><div style={{ fontWeight: 700 }}>{r.buyer_name} <Pill color={r.buyer_type === 'b2b' ? '#0a6cc4' : '#64748b'}>{r.buyer_type.toUpperCase()}</Pill></div><div className="cu-sub">{r.buyer_gstin ? `GSTIN ${r.buyer_gstin} · ` : ''}workspace #{r.tenant_id}</div></td>
                <td style={td}>{r.invoice_number}<div className="cu-sub">{fmtDate(r.invoice_date)}</div></td>
                <td style={{ ...td, maxWidth: 240 }}>{r.reason}{r.refund_ref && <div className="cu-sub">Refund ref: {r.refund_ref}</div>}</td>
                <td style={{ ...td, textAlign: 'right' }}>{inr2(r.taxable_value)}</td>
                <td style={{ ...td, textAlign: 'right' }}>{inr2(r.gst)}</td>
                <td style={{ ...td, textAlign: 'right', fontWeight: 800 }}>{inr2(r.total)}</td>
                <td style={td}>{r.emailed_at ? <><Pill color="var(--success)">E-mailed</Pill><div className="cu-sub">{fmtDateTime(r.emailed_at)}</div></> : r.email_error ? <span title={r.email_error}><Pill color="var(--danger)">Failed</Pill></span> : <Pill color="var(--warning)">Not sent</Pill>}</td>
                <td style={{ ...td, whiteSpace: 'nowrap' }}>
                  <button className="btn btn-ghost btn-sm" onClick={() => openPdf(`/admin/credit-notes/${r.id}/pdf`).catch(e => toast.error('Could not open', e.message))}>PDF</button>{' '}
                  <button className="btn btn-ghost btn-sm" disabled={busy === 'r' + r.id} onClick={() => resend(r)}><Send size={13} /> {r.emailed_at ? 'Resend' : 'Send'}</button>
                </td>
              </tr>))}
          </tbody>
        </table>
      </div>
      {d && d.total > d.per_page && <div style={{ display: 'flex', gap: 10, justifyContent: 'center', marginTop: 12, alignItems: 'center' }}>
        <button className="btn btn-ghost btn-sm" disabled={f.page <= 1} onClick={() => setF({ ...f, page: f.page - 1 })}>← Prev</button><span>Page {f.page} of {pages} · {d.total} credit notes</span><button className="btn btn-ghost btn-sm" disabled={f.page >= pages} onClick={() => setF({ ...f, page: f.page + 1 })}>Next →</button></div>}
    </>
  )
}

const SumRow = ({ r, first, second }) => <tr><td style={td}>{first}</td><td style={td}>{second}</td><td style={{ ...td, textAlign: 'right' }}>{r.n}</td><td style={{ ...td, textAlign: 'right' }}>{inr2(r.taxable)}</td><td style={{ ...td, textAlign: 'right' }}>{inr2(r.cgst)}</td><td style={{ ...td, textAlign: 'right' }}>{inr2(r.sgst)}</td><td style={{ ...td, textAlign: 'right' }}>{inr2(r.igst)}</td><td style={{ ...td, textAlign: 'right', fontWeight: 700 }}>{inr2(r.total)}</td></tr>

function SumTable({ title, note, heads, rows, render }) {
  return (
    <div className="card" style={{ marginBottom: 14, overflowX: 'auto' }}>
      <div style={{ padding: '14px 18px 4px' }}><b>{title}</b>{note && <div className="cu-sub">{note}</div>}</div>
      <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 760 }}>
        <thead><tr>{heads.map((h, i) => <th key={h + i} style={{ ...th, textAlign: i < 2 ? 'left' : 'right' }}>{h}</th>)}</tr></thead>
        <tbody>{rows.length === 0 ? <tr><td colSpan={8} style={{ ...td, color: 'var(--text-3)' }}>Nothing in this period.</td></tr> : rows.map(render)}</tbody>
      </table>
    </div>
  )
}

function SummaryTab({ f }) {
  const [s, setS] = useState(null)
  const params = useMemo(() => toParams(f), [f])
  useEffect(() => { let alive = true; admin.invoiceSummary(params).then(r => { if (alive) setS(r) }).catch(e => toast.error('Could not load', e.message)); return () => { alive = false } }, [params])
  if (!s) return <div className="card card-body" style={{ color: 'var(--text-3)' }}>Loading…</div>
  const H = ['', '', 'Invoices', 'Taxable', 'CGST', 'SGST', 'IGST', 'Total']
  const T = s.totals
  return (
    <>
      <div className="iv-kpis">
        <Kpi icon={Receipt} label="Invoices" value={T.count} /><Kpi icon={Wallet} tone="#12a89e" label="Taxable value" value={inr2(T.taxable)} />
        <Kpi icon={Landmark} tone="#f98f26" label="Output GST" value={inr2(T.gst)} sub={`CGST ${inr2(T.cgst)} · SGST ${inr2(T.sgst)} · IGST ${inr2(T.igst)}`} /><Kpi icon={FileText} tone="#1a9d57" label="Total invoiced" value={inr2(T.total)} />
      </div>
      {s.credit_notes.count > 0 && <div className="iv-kpis">
        <Kpi icon={RotateCcw} tone="#d93025" label="Credit notes" value={s.credit_notes.count} sub={`−${inr2(s.credit_notes.total)} credited`} />
        <Kpi icon={Landmark} tone="#f98f26" label="Net GST payable" value={inr2(s.net.gst)} sub={`invoices ${inr2(T.gst)} − credit notes ${inr2(s.credit_notes.gst)}`} />
        <Kpi icon={Wallet} tone="#12a89e" label="Net taxable value" value={inr2(s.net.taxable)} /><Kpi icon={FileText} tone="#1a9d57" label="Net invoiced" value={inr2(s.net.total)} />
      </div>}
      <SumTable title="B2B: registered customers (by GSTIN)" note="Goes in the B2B section of GSTR-1, one line per invoice (the invoice list / CSV has them)." heads={['GSTIN', 'Customer', ...H.slice(2)]} rows={s.b2b} render={r => <SumRow key={r.gstin} r={r} first={r.gstin} second={r.name} />} />
      <SumTable title="B2C: customers without a GSTIN (by place of supply)" note="Summarised state-wise and rate-wise in GSTR-1 (B2CS)." heads={['Place of supply', 'GST rate', ...H.slice(2)]} rows={s.b2c} render={r => <SumRow key={r.pos + r.rate} r={r} first={`${r.place_name} (${r.pos})`} second={`${r.rate}%`} />} />
      {s.cn_b2b.length > 0 && <SumTable title="Credit notes: registered customers (CDNR in GSTR-1)" note="Reduce the output tax of the B2B invoices above." heads={['GSTIN', 'Customer', ...H.slice(2)]} rows={s.cn_b2b} render={r => <SumRow key={r.gstin} r={r} first={r.gstin} second={r.name} />} />}
      {s.cn_b2c.length > 0 && <SumTable title="Credit notes: customers without a GSTIN (by place of supply)" note="Netted against the B2C summary above (CDNUR / B2CS adjustments)." heads={['Place of supply', 'GST rate', ...H.slice(2)]} rows={s.cn_b2c} render={r => <SumRow key={r.pos + r.rate} r={r} first={`${r.place_name} (${r.pos})`} second={`${r.rate}%`} />} />}
      <SumTable title="By plan" heads={['Plan', 'Cycle', ...H.slice(2)]} rows={s.by_plan} render={r => <SumRow key={r.plan + r.cycle} r={r} first={<span style={{ textTransform: 'capitalize' }}>{r.plan}</span>} second={r.cycle} />} />
      {s.by_month.length > 1 && <SumTable title="By month" heads={['Month', '', ...H.slice(2)]} rows={s.by_month} render={r => <SumRow key={r.month} r={r} first={monthLabel(r.month)} second="" />} />}
      <div className="cu-sub" style={{ margin: '4px 2px 20px' }}>The invoice tables above are before credit notes; the net figures subtract them. E-invoicing and reverse charge are not handled here. Have your CA check the first return built from them.</div>
    </>
  )
}

export default function AdminInvoicesPage() {
  const [tab, setTab] = useState('invoices')
  const [f, setF] = useState({ mode: 'month', month: thisMonth(), fy: fyList()[0], from: '', to: '', q: '', buyer_type: '', supply_type: '', plan: '', source: '', pos: '', email: '', min: '', max: '', sort: 'newest', page: 1 })
  const [missing, setMissing] = useState([])
  const [key, setKey] = useState(0)
  const loadMissing = useCallback(() => admin.invoiceMissing().then(r => setMissing(r.rows)).catch(() => {}), [])
  useEffect(() => { loadMissing() }, [loadMissing, key])
  const reload = () => setKey(k => k + 1)
  const label = f.mode === 'month' ? monthLabel(f.month) : f.mode === 'fy' ? `FY ${f.fy}` : f.mode === 'range' ? `${f.from || '…'} to ${f.to || '…'}` : 'All time'
  return (
    <div className="page">
      <div className="page-header">
        <div><h1 className="page-title">GST invoices</h1><div style={{ color: 'var(--text-3)', fontSize: 13.5 }}>Tax invoices TripSarthi issues to its customers, e-mailed automatically when they pay. Showing <b>{label}</b>.</div></div>
      </div>
      <MissingBanner rows={missing} onDone={reload} />
      <div className="iv-tabs">{[['invoices', 'Invoices'], ['credit', 'Credit notes'], ['summary', 'GST summary'], ['settings', 'Invoice settings']].map(([k, l]) => <button key={k} type="button" className={tab === k ? 'on' : ''} onClick={() => setTab(k)}>{l}</button>)}</div>
      {tab !== 'settings' && <Filters f={f} setF={setF} />}
      {tab === 'invoices' && <InvoicesTab f={f} setF={setF} reloadKey={key} onChanged={reload} />}
      {tab === 'credit' && <CreditNotesTab f={f} setF={setF} reloadKey={key} onChanged={reload} />}
      {tab === 'summary' && <SummaryTab f={f} />}
      {tab === 'settings' && <AdminInvoiceSettings />}
    </div>
  )
}
