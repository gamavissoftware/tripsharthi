import { useState } from 'react'
import { partnerApi } from '../api/partner'
import { toast } from '../components/Toast'
import { inr, fmtDate, th, td } from './format'

const STATE = { paying: ['Paying', '#16a34a'], lapsed: ['Lapsed', '#d97706'], free: ['Free plan', '#6b7280'] }
const CSTATUS = { available: ['Ready to pay', '#d97706'], pending: ['On hold', '#6b7280'], paid: ['Paid', '#16a34a'], void: ['Cancelled', '#9ca3af'] }
const chip = (label, color) => <span style={{ display: 'inline-block', padding: '2px 9px', borderRadius: 999, fontSize: 12, fontWeight: 600, color, background: color + '1f' }}>{label}</span>

function Stat({ label, value, sub, color }) {
  return (
    <div className="card card-body" style={{ flex: '1 1 190px', minWidth: 170 }}>
      <div style={{ color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase', letterSpacing: '.04em' }}>{label}</div>
      <div style={{ fontSize: 26, fontWeight: 800, color: color || 'var(--text)', marginTop: 4, letterSpacing: '-.02em' }}>{value}</div>
      {sub && <div style={{ color: 'var(--text-3)', fontSize: 12.5, marginTop: 2 }}>{sub}</div>}
    </div>
  )
}

function CopyRow({ label, value }) {
  async function copy() { try { await navigator.clipboard.writeText(value); toast.success(`${label} copied`) } catch { toast.error('Could not copy', 'Select it and copy by hand.') } }
  return (
    <div style={{ marginBottom: 10 }}>
      <div style={{ fontSize: 12, color: 'var(--text-3)', textTransform: 'uppercase', marginBottom: 3 }}>{label}</div>
      <div style={{ display: 'flex', gap: 8 }}><input className="form-input" readOnly value={value} onFocus={e => e.target.select()} style={{ fontFamily: 'ui-monospace,monospace', fontSize: 13 }} /><button className="btn btn-primary" onClick={copy}>Copy</button></div>
    </div>
  )
}

function PayoutForm({ current, onSaved }) {
  const [f, setF] = useState({ method: current?.method === 'bank' ? 'bank' : 'upi', upi: '', account_name: '', account_no: '', ifsc: '', pan: '', current_password: '' })
  const [busy, setBusy] = useState(false)
  const set = (k, v) => setF(x => ({ ...x, [k]: v }))
  async function save(e) {
    e.preventDefault(); setBusy(true)
    try { await partnerApi.savePayout(f); toast.success('Payout details saved'); setF(x => ({ ...x, current_password: '', upi: '', account_no: '', pan: '' })); onSaved() }
    catch (err) { toast.error('Not saved', err.message) } finally { setBusy(false) }
  }
  return (
    <form onSubmit={save} style={{ display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))' }}>
      <div className="form-group" style={{ margin: 0 }}><label className="form-label">Receive money by</label>
        <select className="form-select" value={f.method} onChange={e => set('method', e.target.value)}><option value="upi">UPI</option><option value="bank">Bank account</option></select></div>
      {f.method === 'upi'
        ? <div className="form-group" style={{ margin: 0 }}><label className="form-label">UPI ID</label><input className="form-input" value={f.upi} onChange={e => set('upi', e.target.value)} placeholder="name@bank" required /></div>
        : <>
          <div className="form-group" style={{ margin: 0 }}><label className="form-label">Account holder name</label><input className="form-input" value={f.account_name} onChange={e => set('account_name', e.target.value)} required /></div>
          <div className="form-group" style={{ margin: 0 }}><label className="form-label">Account number</label><input className="form-input" inputMode="numeric" value={f.account_no} onChange={e => set('account_no', e.target.value)} required /></div>
          <div className="form-group" style={{ margin: 0 }}><label className="form-label">IFSC</label><input className="form-input" value={f.ifsc} onChange={e => set('ifsc', e.target.value.toUpperCase())} placeholder="HDFC0001234" required /></div>
        </>}
      <div className="form-group" style={{ margin: 0 }}><label className="form-label">PAN (for tax)</label><input className="form-input" value={f.pan} onChange={e => set('pan', e.target.value.toUpperCase())} placeholder="ABCDE1234F" maxLength={10} /></div>
      <div className="form-group" style={{ margin: 0 }}><label className="form-label">Your password</label><input className="form-input" type="password" value={f.current_password} onChange={e => set('current_password', e.target.value)} required autoComplete="current-password" /><div style={{ fontSize: 11.5, color: 'var(--text-3)', marginTop: 3 }}>Needed to change where your money goes.</div></div>
      <div style={{ gridColumn: '1/-1' }}><button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Save payout details'}</button></div>
    </form>
  )
}

function PasswordForm() {
  const [f, setF] = useState({ current: '', next: '' })
  const [busy, setBusy] = useState(false)
  async function save(e) {
    e.preventDefault(); setBusy(true)
    try { await partnerApi.changePassword(f.current, f.next); toast.success('Password changed'); setF({ current: '', next: '' }) }
    catch (err) { toast.error('Not changed', err.message) } finally { setBusy(false) }
  }
  return (
    <form onSubmit={save} style={{ display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))' }}>
      <div className="form-group" style={{ margin: 0 }}><label className="form-label">Current password</label><input className="form-input" type="password" value={f.current} onChange={e => setF({ ...f, current: e.target.value })} required autoComplete="current-password" /></div>
      <div className="form-group" style={{ margin: 0 }}><label className="form-label">New password</label><input className="form-input" type="password" minLength={10} value={f.next} onChange={e => setF({ ...f, next: e.target.value })} required autoComplete="new-password" /><div style={{ fontSize: 11.5, color: 'var(--text-3)', marginTop: 3 }}>At least 10 characters.</div></div>
      <div style={{ gridColumn: '1/-1' }}><button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Change password'}</button></div>
    </form>
  )
}

export default function PartnerDashboard({ d, reload }) {
  const b = d.balances, p = d.partner
  const paying = d.referred.filter(r => r.state === 'paying').length
  const terms = `${p.commission_pct}% of everything your referred customers pay${p.commission_months === 0 ? ', for as long as they stay' : `, for ${p.commission_months} months after their first payment`}. ${p.hold_days === 0 ? 'Commissions are payable as soon as they are earned.' : `Each commission becomes payable ${p.hold_days} days after the payment.`}`
  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <div className="card card-body">
        <h2 style={{ margin: '0 0 4px', fontSize: '1.05rem' }}>Your referral link</h2>
        <p style={{ margin: '0 0 12px', color: 'var(--text-3)', fontSize: 13 }}>Share it anywhere. When someone signs up through it and subscribes, you earn. {terms}</p>
        <CopyRow label="Sign-up link (goes straight to the app)" value={d.links.app_link} />
        <CopyRow label="Website link (shows our site, then sign-up)" value={d.links.site_link} />
        <div style={{ fontSize: 13, color: 'var(--text-3)' }}>Your code: <b style={{ fontFamily: 'ui-monospace,monospace', color: 'var(--text)' }}>{d.links.code}</b> — the link already contains it.</div>
      </div>

      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
        <Stat label="Customers referred" value={d.referred.length} sub={`${paying} paying now`} />
        <Stat label="Total earned" value={inr(b.earned_paise)} sub="excluding cancelled" />
        <Stat label="Ready to be paid" value={inr(b.available_paise)} color={b.available_paise > 0 ? '#d97706' : undefined} sub={`paid once it reaches ${inr(d.min_payout_paise)}`} />
        <Stat label="Paid to you" value={inr(b.paid_paise)} color="#16a34a" sub={b.pending_paise > 0 ? `${inr(b.pending_paise)} still on hold` : 'all time'} />
      </div>

      <div className="card">
        <div style={{ padding: '14px 16px 4px', fontWeight: 700 }}>Customers you referred</div>
        <div style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Workspace', 'Joined', 'Status', 'Plan', 'Payments', 'You earned'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {d.referred.length === 0 && <tr><td colSpan={6} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No one yet — share your link to get started.</td></tr>}
            {d.referred.map((r, i) => <tr key={i}><td style={td}><b>{r.workspace}</b></td><td style={td}>{fmtDate(r.since)}</td><td style={td}>{chip(...STATE[r.state])}</td><td style={{ ...td, textTransform: 'capitalize' }}>{r.plan || '—'}</td><td style={td}>{r.payments}</td><td style={td}><b>{inr(r.earned_paise)}</b></td></tr>)}
          </tbody>
        </table></div>
      </div>

      <div className="card">
        <div style={{ padding: '14px 16px 4px', fontWeight: 700 }}>Your commissions</div>
        <div style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Earned', 'Customer', 'Plan', 'They paid', 'Rate', 'You earn', 'Payable from', 'Status'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {d.commissions.length === 0 && <tr><td colSpan={8} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Commissions appear here when a customer you referred pays.</td></tr>}
            {d.commissions.map(c => <tr key={c.id}><td style={td}>{fmtDate(c.earned_at)}</td><td style={td}>{c.workspace}</td><td style={{ ...td, textTransform: 'capitalize' }}>{c.plan} · {c.cycle}</td><td style={td}>{inr(c.base_paise)}</td><td style={td}>{c.rate_pct}%</td><td style={td}><b>{inr(c.amount_paise)}</b></td><td style={td}>{fmtDate(c.payable_after)}</td><td style={td}>{chip(...CSTATUS[c.status])}</td></tr>)}
          </tbody>
        </table></div>
      </div>

      <div className="card">
        <div style={{ padding: '14px 16px 4px', fontWeight: 700 }}>Payouts</div>
        <div style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Paid on', 'Amount', 'Method', 'Reference', 'Commissions'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {d.payouts.length === 0 && <tr><td colSpan={5} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No payouts yet.</td></tr>}
            {d.payouts.map(o => <tr key={o.id}><td style={td}>{fmtDate(o.paid_at)}</td><td style={td}><b>{inr(o.amount_paise)}</b></td><td style={{ ...td, textTransform: 'uppercase' }}>{o.method}</td><td style={td}>{o.reference}</td><td style={td}>{o.commissions}</td></tr>)}
          </tbody>
        </table></div>
      </div>

      <div className="card card-body">
        <h2 style={{ margin: '0 0 4px', fontSize: '1.05rem' }}>Where should we send your money?</h2>
        {d.payout_details
          ? <p style={{ margin: '0 0 12px', fontSize: 13, color: 'var(--text-3)' }}>Saved: <b style={{ color: 'var(--text)' }}>{d.payout_details.method === 'upi' ? `UPI ${d.payout_details.upi}` : `${d.payout_details.account_name} · A/c ${d.payout_details.account_no} · ${d.payout_details.ifsc}`}</b>{d.payout_details.pan ? ` · PAN ${d.payout_details.pan}` : ''}. Saving again replaces it.</p>
          : <p style={{ margin: '0 0 12px', fontSize: 13, color: '#d97706' }}>Add your payout details so we can pay you. They are stored encrypted and only the TripSarthi finance team can read them.</p>}
        <PayoutForm current={d.payout_details} onSaved={reload} />
      </div>

      <div className="card card-body">
        <h2 style={{ margin: '0 0 12px', fontSize: '1.05rem' }}>Change password</h2>
        <PasswordForm />
      </div>
      <p style={{ fontSize: 12, color: 'var(--text-3)', margin: '0 0 24px' }}>Commissions are calculated on what customers actually pay (after any discount) and are before tax. If tax (TDS) applies to your payouts, it is deducted as the law requires.</p>
    </div>
  )
}
