import { useEffect, useState, useCallback } from 'react'
import { Link, useParams } from 'react-router-dom'
import { admin } from '../../api/admin'
import { inr } from '../../api/travel'
import { toast } from '../../components/Toast'
import { Kpi, Pill, InviteBox } from './AdminUi'
import { th, td, fmtDate, fmtDateTime, PLAN_COLOR, PARTNER_STATUS_COLOR } from './adminUtil'

const COMM_COLOR = { pending: 'var(--warning)', paid: 'var(--success)', void: 'var(--text-3)' }
const num = (v) => Number(v) || 0

function copy(text, what) { navigator.clipboard.writeText(text).then(() => toast.success(`${what} copied`)).catch(() => toast.error('Could not copy')) }

function EditTerms({ p, onSaved, onCancel }) {
  const [f, setF] = useState({ name: p.name, company: p.company || '', phone: p.phone || '', commission_pct: p.commission_pct, commission_months: p.commission_months, hold_days: p.hold_days, notes: p.notes || '' })
  const [busy, setBusy] = useState(false)
  const set = (k, v) => setF(x => ({ ...x, [k]: v }))
  async function save(e) {
    e.preventDefault(); setBusy(true)
    try { await admin.updatePartner(p.id, { ...f, commission_pct: Number(f.commission_pct), commission_months: Number(f.commission_months), hold_days: Number(f.hold_days) }); toast.success('Saved'); onSaved() }
    catch (err) { toast.error('Not saved', err.message) } finally { setBusy(false) }
  }
  const G = ({ label, hint, children }) => <div className="form-group" style={{ margin: 0 }}><label className="form-label">{label}</label>{children}{hint && <div style={{ fontSize: 11.5, color: 'var(--text-3)', marginTop: 3 }}>{hint}</div>}</div>
  return (
    <form onSubmit={save} className="card card-body" style={{ marginBottom: 14, display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))' }}>
      <div style={{ gridColumn: '1/-1', fontWeight: 700 }}>Edit partner</div>
      <div style={{ gridColumn: '1/-1', fontSize: 13, color: 'var(--text-3)' }}>New terms apply to FUTURE commissions only; each commission keeps the rate it was earned at.</div>
      {G({ label: 'Name', children: <input className="form-input" value={f.name} onChange={e => set('name', e.target.value)} required /> })}
      {G({ label: 'Company', children: <input className="form-input" value={f.company} onChange={e => set('company', e.target.value)} /> })}
      {G({ label: 'Phone', children: <input className="form-input" value={f.phone} onChange={e => set('phone', e.target.value)} /> })}
      {G({ label: 'Commission %', children: <input className="form-input" type="number" min="1" max="60" step="0.5" value={f.commission_pct} onChange={e => set('commission_pct', e.target.value)} required /> })}
      {G({ label: 'Months (0 = lifetime)', children: <input className="form-input" type="number" min="0" max="120" value={f.commission_months} onChange={e => set('commission_months', e.target.value)} required /> })}
      {G({ label: 'Hold (days)', children: <input className="form-input" type="number" min="0" max="180" value={f.hold_days} onChange={e => set('hold_days', e.target.value)} required /> })}
      <div style={{ gridColumn: '1/-1' }}>{G({ label: 'Internal notes', children: <input className="form-input" value={f.notes} onChange={e => set('notes', e.target.value)} /> })}</div>
      <div style={{ gridColumn: '1/-1', display: 'flex', gap: 8 }}><button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button><button type="button" className="btn btn-ghost" onClick={onCancel}>Cancel</button></div>
    </form>
  )
}

function PayoutPanel({ d, onDone }) {
  const [f, setF] = useState({ method: d.payout_details?.method === 'bank' ? 'bank' : 'upi', reference: '', note: '', pay_anyway: false })
  const [busy, setBusy] = useState(false)
  const avail = d.balances.available_paise
  const below = avail > 0 && avail < d.min_payout_paise
  const pd = d.payout_details
  async function save(e) {
    e.preventDefault()
    if (!window.confirm(`Record a payout of ${inr(avail)} to ${d.partner.name}? Do this AFTER you have made the transfer. It settles every available commission.`)) return
    setBusy(true)
    try { await admin.partnerPayout(d.partner.id, f); toast.success('Payout recorded', inr(avail)); setF({ ...f, reference: '', note: '' }); onDone() }
    catch (err) { toast.error('Not recorded', err.message) } finally { setBusy(false) }
  }
  return (
    <div className="card card-body" style={{ marginBottom: 14 }}>
      <div style={{ fontWeight: 700, marginBottom: 8 }}>Pay out</div>
      <div style={{ display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit,minmax(260px,1fr))' }}>
        <div style={{ background: 'var(--bg)', borderRadius: 10, padding: '10px 12px', fontSize: 13 }}>
          <div style={{ color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase', marginBottom: 4 }}>Pay to</div>
          {!pd && <span style={{ color: 'var(--danger)' }}>The partner has not added payout details yet.</span>}
          {pd?.method === 'upi' && <div>UPI: <b>{pd.upi}</b> <button className="btn btn-ghost btn-sm" onClick={() => copy(pd.upi, 'UPI ID')}>Copy</button></div>}
          {pd?.method === 'bank' && (<div style={{ lineHeight: 1.7 }}>
            <div>{pd.account_name}</div>
            <div>A/c <b>{pd.account_no}</b> <button className="btn btn-ghost btn-sm" onClick={() => copy(pd.account_no, 'Account number')}>Copy</button></div>
            <div>IFSC <b>{pd.ifsc}</b></div>
          </div>)}
          {pd?.pan && <div style={{ marginTop: 4, color: 'var(--text-3)' }}>PAN {pd.pan} (for TDS)</div>}
        </div>
        <form onSubmit={save} style={{ display: 'grid', gap: 8 }}>
          <div>Available now: <b style={{ fontSize: 18 }}>{inr(avail)}</b>{below && <span style={{ color: 'var(--warning)', fontSize: 12.5 }}> (below the {inr(d.min_payout_paise)} minimum)</span>}</div>
          <div style={{ display: 'flex', gap: 8 }}>
            <select className="form-select" value={f.method} onChange={e => setF({ ...f, method: e.target.value })}><option value="upi">UPI</option><option value="bank">Bank transfer</option><option value="other">Other</option></select>
            <input className="form-input" value={f.reference} onChange={e => setF({ ...f, reference: e.target.value })} placeholder="Bank / UPI reference (UTR)" required maxLength={100} />
          </div>
          <input className="form-input" value={f.note} onChange={e => setF({ ...f, note: e.target.value })} placeholder="Note (optional), e.g. October payout" maxLength={300} />
          {below && <label style={{ fontSize: 13, display: 'flex', gap: 6, alignItems: 'center' }}><input type="checkbox" checked={f.pay_anyway} onChange={e => setF({ ...f, pay_anyway: e.target.checked })} /> Pay anyway (below the minimum)</label>}
          <div><button className="btn btn-primary" disabled={busy || avail <= 0 || (below && !f.pay_anyway)}>{busy ? 'Recording…' : 'Record payout'}</button></div>
          <div style={{ fontSize: 12, color: 'var(--text-3)' }}>Make the transfer first, then record it here with the bank reference. TDS and GST on partner payments are not calculated — check with your CA.</div>
        </form>
      </div>
    </div>
  )
}

export default function AdminPartnerDetailPage() {
  const { id } = useParams()
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const [edit, setEdit] = useState(false)
  const [invite, setInvite] = useState(null)
  const load = useCallback(() => admin.partner(id).then(r => { setD(r); setErr('') }).catch(e => setErr(e.message || 'Not found')), [id])
  useEffect(() => { load() }, [load])

  if (err && !d) return <div className="page"><div className="card card-body" style={{ textAlign: 'center' }}><h3 style={{ marginTop: 0 }}>Partner not found</h3><p style={{ color: 'var(--text-3)' }}>{err}</p><Link className="btn btn-primary" to="/admin/partners">Back to partners</Link></div></div>
  if (!d) return <div className="page"><p style={{ color: 'var(--text-3)' }}>Loading…</p></div>
  const p = d.partner, b = d.balances

  async function setStatus(st) {
    const msg = st === 'suspended' ? `Suspend ${p.name}? They are signed out at once and earn no new commissions (existing balances are kept).` : `Reactivate ${p.name}?`
    if (!window.confirm(msg)) return
    try { await admin.partnerStatus(p.id, st); toast.success(st === 'suspended' ? 'Suspended' : 'Reactivated'); load() } catch (e) { toast.error('Not changed', e.message) }
  }
  async function newInvite() {
    if (!window.confirm('Issue a new one-time set-password link? (Use this to reset a forgotten password.)')) return
    try { const r = await admin.partnerInvite(p.id); setInvite(r.invite_url); load() } catch (e) { toast.error('Not issued', e.message) }
  }
  async function voidIt(c) {
    const reason = window.prompt(`Void the ${inr(c.amount_paise)} commission for ${c.tenant_name || 'this customer'}? It cannot be undone.\n\nReason (kept in the log):`)
    if (!reason) return
    try { await admin.voidCommission(c.id, reason); toast.success('Commission voided'); load() } catch (e) { toast.error('Not voided', e.message) }
  }

  return (
    <div className="page">
      <div style={{ marginBottom: 6 }}><Link to="/admin/partners" style={{ fontSize: 13 }}>← Partners</Link></div>
      <div className="page-header">
        <div>
          <h1 className="page-title" style={{ margin: 0 }}>{p.name} <Pill color={PARTNER_STATUS_COLOR[p.status]}>{p.status}</Pill></h1>
          <div style={{ color: 'var(--text-3)', fontSize: 13, marginTop: 2 }}>{p.company ? `${p.company} · ` : ''}{p.email}{p.phone ? ` · ${p.phone}` : ''} · {p.commission_pct}% for {p.commission_months === 0 ? 'life' : `${p.commission_months} months`}, {p.hold_days}-day hold</div>
        </div>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button className="btn btn-ghost" onClick={() => setEdit(e => !e)}>Edit</button>
          <button className="btn btn-ghost" onClick={newInvite}>{p.has_password ? 'Reset password link' : 'New invite link'}</button>
          {p.status === 'active' && <button className="btn btn-ghost" style={{ color: 'var(--danger)' }} onClick={() => setStatus('suspended')}>Suspend</button>}
          {p.status === 'suspended' && <button className="btn btn-primary" onClick={() => setStatus('active')}>Reactivate</button>}
        </div>
      </div>

      {invite && <InviteBox url={invite} onClose={() => setInvite(null)} />}
      {edit && <EditTerms p={{ ...p, notes: d.partner.notes }} onCancel={() => setEdit(false)} onSaved={() => { setEdit(false); load() }} />}

      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
        <Kpi label="Available to pay" value={inr(b.available_paise)} accent={b.available_paise > 0 ? 'var(--warning)' : undefined} sub="past the hold" />
        <Kpi label="On hold" value={inr(b.pending_paise)} sub="not yet payable" />
        <Kpi label="Paid out" value={inr(b.paid_paise)} accent="var(--success)" sub={`${d.payouts.length} payouts`} />
        <Kpi label="Voided" value={inr(b.void_paise)} sub="cancelled" />
      </div>

      <div className="card card-body" style={{ marginBottom: 14, display: 'grid', gap: 10, gridTemplateColumns: 'repeat(auto-fit,minmax(280px,1fr))', fontSize: 13 }}>
        <div><div style={{ color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase' }}>Referral code</div><b style={{ fontFamily: 'ui-monospace,monospace', fontSize: 15 }}>{p.code}</b></div>
        <div><div style={{ color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase' }}>App sign-up link</div><span style={{ wordBreak: 'break-all' }}>{p.links.app_link}</span> <button className="btn btn-ghost btn-sm" onClick={() => copy(p.links.app_link, 'Link')}>Copy</button></div>
        <div><div style={{ color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase' }}>Website link</div><span style={{ wordBreak: 'break-all' }}>{p.links.site_link}</span> <button className="btn btn-ghost btn-sm" onClick={() => copy(p.links.site_link, 'Link')}>Copy</button></div>
        {d.coupons.length > 0 && <div><div style={{ color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase' }}>Partner coupon codes</div>{d.coupons.map(c => <Pill key={c.id} color={c.active === '1' || c.active === 1 ? 'var(--success)' : 'var(--text-3)'}>{c.code}</Pill>)}</div>}
      </div>

      {p.status !== 'invited' && <PayoutPanel d={d} onDone={load} />}

      <h3 style={{ margin: '18px 0 8px' }}>Referred customers ({d.referred.length})</h3>
      <div className="card" style={{ overflowX: 'auto', marginBottom: 14 }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Workspace', 'Plan', 'Status', 'Referred', 'Via', 'Paid by customer', 'Commission'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {d.referred.length === 0 && <tr><td colSpan={7} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No referrals yet.</td></tr>}
            {d.referred.map(t => (
              <tr key={t.id}>
                <td style={td}><Link to={`/admin/customers?open=${t.id}`}><b>{t.name}</b></Link></td>
                <td style={td}><Pill color={PLAN_COLOR[t.plan]}>{t.plan}</Pill></td>
                <td style={td}>{num(t.paying) > 0 ? <Pill color="var(--success)">paying</Pill> : <span style={{ color: 'var(--text-3)' }}>{t.status}</span>}</td>
                <td style={td}>{fmtDate(t.referred_at)}</td>
                <td style={td}>{t.referral_source === 'coupon' ? 'partner coupon' : 'referral link'}</td>
                <td style={td}>{inr(num(t.paid_paise))}</td><td style={td}>{inr(num(t.commission_paise))}</td>
              </tr>))}
          </tbody>
        </table>
      </div>

      <h3 style={{ margin: '18px 0 8px' }}>Commissions</h3>
      <div className="card" style={{ overflowX: 'auto', marginBottom: 14 }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Earned', 'Customer', 'Plan', 'Customer paid', 'Rate', 'Commission', 'Payable from', 'Status', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {d.commissions.length === 0 && <tr><td colSpan={9} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No commissions yet — they appear when a referred customer pays.</td></tr>}
            {d.commissions.map(c => (
              <tr key={c.id}>
                <td style={td}>{fmtDateTime(c.earned_at)}</td><td style={td}>{c.tenant_name || `#${c.tenant_id}`}</td><td style={td}>{c.plan} · {c.cycle}</td>
                <td style={td}>{inr(num(c.base_paise))}</td><td style={td}>{num(c.rate_pct)}%</td><td style={td}><b>{inr(num(c.amount_paise))}</b></td><td style={td}>{fmtDate(c.payable_after)}</td>
                <td style={td}><Pill color={COMM_COLOR[c.status]}>{c.status}</Pill>{c.void_reason && <div style={{ fontSize: 11.5, color: 'var(--text-3)' }}>{c.void_reason}</div>}</td>
                <td style={td}>{c.status === 'pending' && <button className="btn btn-ghost btn-sm" style={{ color: 'var(--danger)' }} onClick={() => voidIt(c)}>Void</button>}</td>
              </tr>))}
          </tbody>
        </table>
      </div>

      <h3 style={{ margin: '18px 0 8px' }}>Payouts</h3>
      <div className="card" style={{ overflowX: 'auto', marginBottom: 14 }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Paid on', 'Amount', 'Method', 'Reference', 'Commissions', 'Note', 'By'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {d.payouts.length === 0 && <tr><td colSpan={7} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No payouts yet.</td></tr>}
            {d.payouts.map(o => <tr key={o.id}><td style={td}>{fmtDateTime(o.paid_at)}</td><td style={td}><b>{inr(num(o.amount_paise))}</b></td><td style={td}>{o.method}</td><td style={td}>{o.reference}</td><td style={td}>{o.commissions}</td><td style={td}>{o.note || '—'}</td><td style={td}>{o.created_by_name || '—'}</td></tr>)}
          </tbody>
        </table>
      </div>

      <h3 style={{ margin: '18px 0 8px' }}>Activity</h3>
      <div className="card card-body" style={{ fontSize: 13 }}>
        {d.events.length === 0 && <span style={{ color: 'var(--text-3)' }}>Nothing yet.</span>}
        {d.events.map(e => <div key={e.id} style={{ padding: '4px 0', borderBottom: '1px solid var(--border)' }}><span style={{ color: 'var(--text-3)' }}>{fmtDateTime(e.created_at)}</span> · <b>{e.action}</b>{e.detail ? ` — ${e.detail}` : ''} <span style={{ color: 'var(--text-3)' }}>({e.actor}{e.ip ? `, ${e.ip}` : ''})</span></div>)}
      </div>
    </div>
  )
}
