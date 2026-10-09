import { useState, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { travel, inr } from '../api/travel'
import { toast } from '../components/Toast'
import { Gstr3bTab, HsnTab, FilingsTab } from '../components/gst/GstTabs'

const thisMonth = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` }
const th = { padding: '7px 8px', textAlign: 'right', color: 'var(--text-3)', fontSize: 11.5, textTransform: 'uppercase' }
const td = { padding: '7px 8px', textAlign: 'right' }

const FILES = [
  ['gstr1.json', 'GSTR-1 (JSON)', 'Upload to the GST portal with the offline tool, or hand it to your CA. Sections: B2B, B2CL, B2CS, credit notes, document summary and the HSN table.'],
  ['register.csv', 'Sales register (CSV)', 'One line per invoice and credit note — opens in Excel. Credit notes are negative.'],
  ['tally.xml', 'Tally vouchers (XML)', 'Tally → Import Data → Vouchers. Create the ledgers first: Tour Package Sales, Output CGST/SGST/IGST, TCS Payable, and one per customer.'],
]

export default function GstExportsPage() {
  const [period, setPeriod] = useState(thisMonth())
  const [data, setData] = useState(null)
  const [err, setErr] = useState('')
  const [busy, setBusy] = useState(false)
  const [tab, setTab] = useState('gstr1')

  useEffect(() => {
    if (tab !== 'gstr1') return undefined
    let live = true
    travel.gstSummary(period).then(r => { if (live) { setData(r); setErr('') } }).catch(e => { if (live) { setData(null); setErr(e.message) } })
    return () => { live = false }
  }, [period, tab])

  const get = async (file) => {
    setBusy(true)
    try { await travel.gstDownload(period, file) } catch (e) { toast.error('Could not download', e.message) } finally { setBusy(false) }
  }
  const s = data?.summary
  const rows = s ? [['Tax invoices', s.invoices, 1], ['Credit notes', s.credit_notes, -1], ['Net for the month', s.net, 0]] : []

  return (
    <div className="page" style={{ maxWidth: 900 }}>
      <div className="page-header"><h1 className="page-title">GST & accounting exports</h1></div>
      <p style={{ color: 'var(--text-2)' }}>Everything issued in a month, ready for your CA, the GST portal and Tally: GSTR-1, GSTR-3B, the HSN/SAC summary and a filing tracker. Only <b>issued</b> tax invoices and credit notes count — receipts and bills of supply carry no GST.</p>
      <div className="card card-body" style={{ marginBottom: 14 }}>
        <label style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-2)' }}>Month</label>
        <input className="form-input" type="month" value={period} max={thisMonth()} onChange={e => e.target.value && setPeriod(e.target.value)} style={{ maxWidth: 200 }} />
        {err && tab === 'gstr1' && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8, marginTop: 10 }}>{err}{/GSTIN/.test(err) && <> <Link to="/settings/business">Open business profile</Link></>}</div>}
      </div>
      <div style={{ display: 'flex', gap: 6, marginBottom: 14, flexWrap: 'wrap' }}>
        {[['gstr1', 'GSTR-1 & exports'], ['gstr3b', 'GSTR-3B'], ['hsn', 'HSN summary'], ['filings', 'Filing tracker']].map(([k, l]) => <button key={k} className={'btn btn-sm ' + (tab === k ? 'btn-primary' : 'btn-ghost')} onClick={() => setTab(k)}>{l}</button>)}
      </div>
      {tab === 'gstr3b' && <Gstr3bTab period={period} download={get} busy={busy} />}
      {tab === 'hsn' && <HsnTab period={period} download={get} busy={busy} />}
      {tab === 'filings' && <FilingsTab />}

      {tab === 'gstr1' && s && <>
        {data.warnings.length > 0 && (
          <div style={{ background: '#fef3c7', border: '1px solid #f59e0b', padding: 12, borderRadius: 8, marginBottom: 14 }}>
            <b>Check before filing</b>
            <ul style={{ margin: '6px 0 0 18px' }}>{data.warnings.map((w, i) => <li key={i}>{w}</li>)}</ul>
          </div>)}
        <div className="card card-body" style={{ marginBottom: 14, overflowX: 'auto' }}>
          <h3 style={{ marginTop: 0 }}>Summary for {period} <small style={{ fontWeight: 400, color: 'var(--text-3)' }}>· GSTIN {data.gstin}</small></h3>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13.5 }}>
            <thead><tr><th style={{ ...th, textAlign: 'left' }}></th><th style={th}>Count</th><th style={th}>Taxable value</th><th style={th}>CGST</th><th style={th}>SGST</th><th style={th}>IGST</th><th style={th}>TCS</th><th style={th}>Total</th></tr></thead>
            <tbody>{rows.map(([label, r, sign]) => (
              <tr key={label} style={{ borderTop: '1px solid var(--border)', fontWeight: sign === 0 ? 700 : 400 }}>
                <td style={{ ...td, textAlign: 'left' }}>{label}</td><td style={td}>{r.count ?? ''}</td>
                {['taxable', 'cgst', 'sgst', 'igst', 'tcs', 'total'].map(k => <td key={k} style={td}>{sign === -1 ? '−' : ''}{inr(r[k])}</td>)}
              </tr>))}</tbody>
          </table>
          <div style={{ fontSize: 12.5, color: 'var(--text-3)', marginTop: 8 }}>
            B2B {s.sections.b2b} · B2C large {s.sections.b2cl} · B2C small rows {s.sections.b2cs_rows} · credit notes to GST customers {s.sections.cdnr} · to others {s.sections.cdnur}
            {(s.skipped.receipt + s.skipped.bill_of_supply + s.skipped.cancelled) > 0 && <> · not reported: {s.skipped.receipt} receipts, {s.skipped.bill_of_supply} bills of supply, {s.skipped.cancelled} cancelled</>}
          </div>
        </div>
        <div className="card card-body">
          <h3 style={{ marginTop: 0 }}>Download</h3>
          <div style={{ display: 'grid', gap: 10 }}>{FILES.map(([file, title, hint]) => (
            <div key={file} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
              <div style={{ flex: '1 1 320px' }}><b>{title}</b><div style={{ fontSize: 12.5, color: 'var(--text-3)' }}>{hint}</div></div>
              <button className="btn btn-primary btn-sm" disabled={busy} onClick={() => get(file)}>Download</button>
            </div>))}</div>
          <p style={{ fontSize: 12.5, color: 'var(--text-3)', marginBottom: 0 }}>These files are a convenience, not tax advice: have your CA validate the first month's return (rates, place of supply, TCS treatment and your aggregate turnover, which the portal asks for) before you rely on it.</p>
        </div>
      </>}
    </div>
  )
}
