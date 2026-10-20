import { useRef, useState } from 'react'
import { partnerApi } from '../api/partner'
import { toast } from '../components/Toast'
import Field from '../components/FormField'
import { firstInvalid, shake } from '../lib/formFocus'
import { Users, IndianRupee, Hourglass, BadgeCheck, Copy, MessageCircle, Mail, Link2, Sparkles, Landmark, BellRing, KeyRound, Receipt, Wallet } from 'lucide-react'
import { inr, fmtDate } from './format'
import './partner.css'

const STATE = { paying: ['Paying', '#16a34a'], lapsed: ['Lapsed', '#d97706'], free: ['Free plan', '#6b7280'] }
const CSTATUS = { available: ['Ready to pay', '#d97706'], pending: ['On hold', '#6b7280'], paid: ['Paid', '#16a34a'], void: ['Cancelled', '#9ca3af'] }
const chip = (label, color) => <span className="pt-pill" style={{ color, background: color + '1f' }}>{label}</span>

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
      <div className="form-group" style={{ margin: 0 }}><label className="form-label" htmlFor="po-method">Receive money by *</label>
        <select id="po-method" required className="form-select" value={f.method} onChange={e => set('method', e.target.value)}><option value="upi">UPI</option><option value="bank">Bank account</option></select></div>
      {f.method === 'upi'
        ? <div className="form-group" style={{ margin: 0 }}><label className="form-label" htmlFor="po-upi">UPI ID *</label><input id="po-upi" className="form-input" value={f.upi} onChange={e => set('upi', e.target.value.trim())} placeholder="name@bank" required pattern="[A-Za-z0-9._\-]{2,}@[A-Za-z][A-Za-z0-9]{1,}" title="A UPI ID looks like name@bank, with no spaces" autoComplete="off" autoCapitalize="none" spellCheck={false} /></div>
        : <>
          <div className="form-group" style={{ margin: 0 }}><label className="form-label" htmlFor="po-name">Account holder name *</label><input id="po-name" className="form-input" value={f.account_name} onChange={e => set('account_name', e.target.value)} required minLength={2} maxLength={100} autoComplete="off" /></div>
          <div className="form-group" style={{ margin: 0 }}><label className="form-label" htmlFor="po-acno">Account number *</label><input id="po-acno" className="form-input" inputMode="numeric" value={f.account_no} onChange={e => set('account_no', e.target.value.replace(/\s+/g, ''))} required pattern="[0-9]{6,20}" title="Digits only, 6 to 20 of them, no spaces" autoComplete="off" /></div>
          <div className="form-group" style={{ margin: 0 }}><label className="form-label" htmlFor="po-ifsc">IFSC *</label><input id="po-ifsc" className="form-input" value={f.ifsc} onChange={e => set('ifsc', e.target.value.toUpperCase().trim())} placeholder="HDFC0001234" required maxLength={11} pattern="[A-Z]{4}0[A-Z0-9]{6}" title="An IFSC is 11 characters: 4 letters, a zero, then 6 letters or digits, like HDFC0001234" autoComplete="off" spellCheck={false} /></div>
        </>}
      <div className="form-group" style={{ margin: 0 }}><label className="form-label" htmlFor="po-pan">PAN (for tax)</label><input id="po-pan" className="form-input" value={f.pan} onChange={e => set('pan', e.target.value.toUpperCase().trim())} placeholder="ABCDE1234F" maxLength={10} pattern="[A-Z]{5}[0-9]{4}[A-Z]" title="A PAN is 10 characters: 5 letters, 4 digits, 1 letter, like ABCDE1234F" autoComplete="off" spellCheck={false} /></div>
      <div className="form-group" style={{ margin: 0 }}><label className="form-label" htmlFor="po-pw">Your password *</label><input id="po-pw" className="form-input" type="password" value={f.current_password} onChange={e => set('current_password', e.target.value)} required autoComplete="current-password" /><div style={{ fontSize: 11.5, color: 'var(--text-3)', marginTop: 3 }}>Needed to change where your money goes.</div></div>
      <div style={{ gridColumn: '1/-1' }}><button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Save payout details'}</button></div>
    </form>
  )
}

function PreferencesForm({ prefs, onSaved }) {
  const [phone, setPhone] = useState(prefs?.phone || '')
  const [wa, setWa] = useState(Boolean(prefs?.wa_marketing_opt_in))
  const [mail, setMail] = useState(Boolean(prefs?.email_marketing_opt_in))
  const [busy, setBusy] = useState(false)
  async function save(e) {
    e.preventDefault(); setBusy(true)
    try { await partnerApi.savePreferences({ phone, wa_marketing_opt_in: wa, email_marketing_opt_in: mail }); toast.success('Saved'); onSaved() }
    catch (err) { toast.error('Not saved', err.message) } finally { setBusy(false) }
  }
  return (
    <form onSubmit={save} style={{ display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit,minmax(240px,1fr))' }}>
      <div className="form-group" style={{ margin: 0 }}><label className="form-label" htmlFor="pr-phone">Your WhatsApp number</label><input id="pr-phone" className="form-input" type="tel" value={phone} onChange={e => setPhone(e.target.value)} placeholder="+91 98765 43210" autoComplete="tel" pattern="\+?[0-9 \-()]{10,18}" title="Enter the full number with country code, like +91 98765 43210" required={wa} /></div>
      <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start', fontSize: 13, color: 'var(--text-2)', lineHeight: 1.4, cursor: 'pointer' }}>
        <input type="checkbox" checked={wa} onChange={e => setWa(e.target.checked)} style={{ marginTop: 3 }} />
        <span>Send me partner program updates on WhatsApp. Untick to stop — or reply STOP to any message.</span>
      </label>
      <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start', fontSize: 13, color: 'var(--text-2)', lineHeight: 1.4, cursor: 'pointer' }}>
        <input type="checkbox" checked={mail} onChange={e => setMail(e.target.checked)} style={{ marginTop: 3 }} />
        <span>E-mail me partner program news and offers. Untick to stop — every e-mail also has an unsubscribe link.</span>
      </label>
      <div style={{ gridColumn: '1/-1' }}><button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button></div>
    </form>
  )
}

function PasswordForm() {
  const [f, setF] = useState({ current: '', next: '' })
  const [errs, setErrs] = useState({})
  const [show, setShow] = useState(false)
  const [busy, setBusy] = useState(false)
  const formRef = useRef(null)
  const check = (d) => {
    const n = {}
    if (!d.current) n.current = 'Enter your current password'
    if (!d.next) n.next = 'Choose a new password'
    else if (d.next.length < 10) n.next = `Use at least 10 characters (you have ${d.next.length})`
    else if (d.current && d.next === d.current) n.next = 'Your new password must be different from the current one'
    return n
  }
  const change = (k) => (e) => { const d = { ...f, [k]: e.target.value }; setF(d); if (errs.current || errs.next) { const n = check(d); setErrs(s => ({ current: s.current ? n.current : undefined, next: s.next ? n.next : undefined })) } }
  async function save(e) {
    e.preventDefault()
    const n = check(f); setErrs(n)
    const keys = Object.keys(n)
    if (keys.length) {
      shake(formRef.current); setTimeout(() => firstInvalid(formRef.current), 0)
      toast.warning(keys.length === 1 ? 'One thing needs fixing' : `${keys.length} things need fixing`, n[keys[0]]); return
    }
    setBusy(true)
    try { await partnerApi.changePassword(f.current, f.next); toast.success('Password changed'); setF({ current: '', next: '' }) }
    catch (err) { toast.error('Not changed', err.message) } finally { setBusy(false) }
  }
  return (
    <form onSubmit={save} noValidate ref={formRef} style={{ display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))' }}>
      <Field label="Current password" required error={errs.current}><input className="form-input" type={show ? 'text' : 'password'} value={f.current} onChange={change('current')} autoComplete="current-password" /></Field>
      <Field label="New password" required error={errs.next} hint="At least 10 characters."><input className="form-input" type={show ? 'text' : 'password'} value={f.next} onChange={change('next')} autoComplete="new-password" /></Field>
      <div style={{ gridColumn: '1/-1', display: 'flex', alignItems: 'center', gap: 14 }}>
        <button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Change password'}</button>
        <button type="button" className="btn btn-ghost btn-sm" onClick={() => setShow(v => !v)} aria-pressed={show}>{show ? 'Hide passwords' : 'Show passwords'}</button>
      </div>
    </form>
  )
}

async function copyText(text, what) {
  try { await navigator.clipboard.writeText(text); toast.success(`${what} copied`) } catch { toast.error('Could not copy', 'Select it and copy by hand.') }
}

function Empty({ title, text }) {
  return (
    <div className="pt-empty">
      <svg width="64" height="64" viewBox="0 0 64 64" fill="none" aria-hidden="true"><circle cx="32" cy="32" r="30" fill="#eef4fc" /><path d="M20 38l8-8 6 6 10-12" stroke="#0a6cc4" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" /><circle cx="44" cy="24" r="3" fill="#12a89e" /></svg>
      <b>{title}</b><span>{text}</span>
    </div>
  )
}

function Panel({ icon: Icon, title, count, children, flush }) {
  return (
    <section className="pt-panel">
      <div className="pt-panel-h"><h3>{Icon && <Icon size={18} color="#0a6cc4" />}{title}</h3>{count != null && <span className="count">{count}</span>}</div>
      {flush ? children : <div className="pt-panel-b">{children}</div>}
    </section>
  )
}

export default function PartnerDashboard({ d, reload }) {
  const b = d.balances, p = d.partner
  const first = p.name.split(' ')[0]
  const paying = d.referred.filter(r => r.state === 'paying').length
  const min = d.min_payout_paise || 100000
  const pct = Math.max(0, Math.min(100, Math.round((b.available_paise / min) * 100)))
  const link = d.links.app_link
  const shareText = `I run my travel business on TripSarthi - quotes, bookings, payments and WhatsApp in one place. Try it here: ${link}`
  const terms = `${p.commission_pct}% of everything your referred customers pay${p.commission_months === 0 ? ', for as long as they stay' : `, for ${p.commission_months} months after their first payment`}.`
  return (
    <>
      <section className="pt-hero">
        <h1 className="pt-hello"><small>Partner dashboard</small>Welcome back, {first}</h1>
        <p className="pt-sub">Share your link with travel agencies and tour operators. When they subscribe, you earn {terms}</p>
        <div className="pt-chips">
          <span className="pt-chip"><Sparkles size={14} /> {p.commission_pct}% commission</span>
          <span className="pt-chip">{p.commission_months === 0 ? 'Lifetime' : `${p.commission_months} months`} per customer</span>
          <span className="pt-chip">{p.hold_days === 0 ? 'No hold period' : `Payable ${p.hold_days} days after payment`}</span>
          <span className="pt-chip">Minimum payout {inr(min)}</span>
        </div>

        <div className="pt-share">
          <h3>Your personal referral link</h3>
          <p>Anyone who signs up through it is credited to you automatically.</p>
          <div className="pt-linkrow">
            <div className="pt-link"><Link2 size={16} color="#0a6cc4" style={{ flex: 'none' }} /><span>{link}</span></div>
            <button className="pt-btn primary" onClick={() => copyText(link, 'Link')}><Copy size={16} /> Copy link</button>
          </div>
          <div className="pt-sharebtns">
            <a className="pt-btn wa small" href={`https://wa.me/?text=${encodeURIComponent(shareText)}`} target="_blank" rel="noreferrer"><MessageCircle size={15} /> Share on WhatsApp</a>
            <a className="pt-btn soft small" href={`mailto:?subject=${encodeURIComponent('A tool I recommend for your travel business')}&body=${encodeURIComponent(shareText)}`}><Mail size={15} /> Send by e-mail</a>
            <div className="pt-code">Your code <b>{d.links.code}</b></div>
          </div>
          <div className="pt-alt">Prefer to show our website first? <button onClick={() => copyText(d.links.site_link, 'Website link')}>Copy the website link</button></div>
        </div>
      </section>

      <div className="pt-stats">
        <div className="pt-stat"><div className="pt-ico blue"><Users size={20} /></div><div className="l">Customers referred</div><div className="v">{d.referred.length}</div><div className="n">{paying} paying now</div></div>
        <div className="pt-stat"><div className="pt-ico teal"><IndianRupee size={20} /></div><div className="l">Total earned</div><div className="v">{inr(b.earned_paise)}</div><div className="n">excluding cancelled</div></div>
        <div className="pt-stat"><div className="pt-ico orange"><Hourglass size={20} /></div><div className="l">Ready to be paid</div><div className="v" style={b.available_paise > 0 ? { color: '#c46a00' } : undefined}>{inr(b.available_paise)}</div>
          <div className="pt-bar" aria-hidden="true"><i style={{ width: pct + '%' }} /></div>
          <div className="n">{b.available_paise >= min ? 'Eligible - included in the next payout' : `${inr(min - b.available_paise)} more to reach the ${inr(min)} minimum`}</div></div>
        <div className="pt-stat"><div className="pt-ico green"><BadgeCheck size={20} /></div><div className="l">Paid to you</div><div className="v" style={b.paid_paise > 0 ? { color: '#14804a' } : undefined}>{inr(b.paid_paise)}</div><div className="n">{b.pending_paise > 0 ? `${inr(b.pending_paise)} still on hold` : 'all time'}</div></div>
      </div>

      {d.referred.length === 0 && (
        <section className="pt-how">
          <h3>How it works</h3>
          <div className="pt-steps">
            <div className="pt-step"><span className="n">1</span><div><b>Share your link</b><span>Send it to travel agents on WhatsApp, e-mail or social media.</span></div></div>
            <div className="pt-step"><span className="n">2</span><div><b>They sign up and subscribe</b><span>You are credited automatically, with no codes to remember.</span></div></div>
            <div className="pt-step"><span className="n">3</span><div><b>You earn every month</b><span>{p.commission_pct}% of each payment lands in your earnings, paid out once it passes {inr(min)}.</span></div></div>
          </div>
        </section>)}

      <Panel icon={Users} title="Customers you referred" count={d.referred.length} flush>
        {d.referred.length === 0
          ? <Empty title="No referrals yet" text="Share your link above. Your first sign-up will show up here." />
          : <div className="pt-scroll"><table className="pt-table">
            <thead><tr>{['Workspace', 'Joined', 'Status', 'Plan', 'Payments', 'You earned'].map(h => <th key={h}>{h}</th>)}</tr></thead>
            <tbody>{d.referred.map((r, i) => <tr key={i}><td><b>{r.workspace}</b></td><td>{fmtDate(r.since)}</td><td>{chip(...STATE[r.state])}</td><td style={{ textTransform: 'capitalize' }}>{r.plan || '—'}</td><td>{r.payments}</td><td><b>{inr(r.earned_paise)}</b></td></tr>)}</tbody>
          </table></div>}
      </Panel>

      <Panel icon={Receipt} title="Your commissions" count={d.commissions.length} flush>
        {d.commissions.length === 0
          ? <Empty title="Nothing earned yet" text="A commission appears here the moment a customer you referred pays." />
          : <div className="pt-scroll"><table className="pt-table">
            <thead><tr>{['Earned', 'Customer', 'Plan', 'They paid', 'Rate', 'You earn', 'Payable from', 'Status'].map(h => <th key={h}>{h}</th>)}</tr></thead>
            <tbody>{d.commissions.map(c => <tr key={c.id}><td>{fmtDate(c.earned_at)}</td><td>{c.workspace}</td><td style={{ textTransform: 'capitalize' }}>{c.plan} · {c.cycle}</td><td>{inr(c.base_paise)}</td><td>{c.rate_pct}%</td><td><b>{inr(c.amount_paise)}</b></td><td>{c.status === 'paid' ? '—' : fmtDate(c.payable_after)}</td><td>{chip(...CSTATUS[c.status])}</td></tr>)}</tbody>
          </table></div>}
      </Panel>

      <Panel icon={Wallet} title="Payouts" count={d.payouts.length} flush>
        {d.payouts.length === 0
          ? <Empty title="No payouts yet" text={`Once your available balance reaches ${inr(min)}, the TripSarthi team pays you and the transfer is listed here.`} />
          : <div className="pt-scroll"><table className="pt-table">
            <thead><tr>{['Paid on', 'Amount', 'Method', 'Reference', 'Commissions'].map(h => <th key={h}>{h}</th>)}</tr></thead>
            <tbody>{d.payouts.map(o => <tr key={o.id}><td>{fmtDate(o.paid_at)}</td><td><b>{inr(o.amount_paise)}</b></td><td style={{ textTransform: 'uppercase' }}>{o.method}</td><td>{o.reference}</td><td>{o.commissions}</td></tr>)}</tbody>
          </table></div>}
      </Panel>

      <div className="pt-grid2">
        <Panel icon={Landmark} title="Where should we send your money?">
          {d.payout_details
            ? <div className="pt-ok">Saved: <b>{d.payout_details.method === 'upi' ? `UPI ${d.payout_details.upi}` : `${d.payout_details.account_name} · A/c ${d.payout_details.account_no} · ${d.payout_details.ifsc}`}</b>{d.payout_details.pan ? ` · PAN ${d.payout_details.pan}` : ''}. Saving new details replaces these.</div>
            : <div className="pt-warn">Add your payout details so we can pay you. They are stored encrypted and only the TripSarthi finance team can read them.</div>}
          <PayoutForm current={d.payout_details} onSaved={reload} />
        </Panel>
        <div style={{ display: 'grid', gap: 16 }}>
          <Panel icon={BellRing} title="Contact preferences"><PreferencesForm prefs={d.preferences} onSaved={reload} /></Panel>
          <Panel icon={KeyRound} title="Change password"><PasswordForm /></Panel>
        </div>
      </div>

      <p className="pt-note">Commissions are calculated on what customers actually pay (after any discount) and are before tax. If tax (TDS) applies to your payouts, it is deducted as the law requires. Questions? Reply to any e-mail from TripSarthi.</p>
    </>
  )
}
