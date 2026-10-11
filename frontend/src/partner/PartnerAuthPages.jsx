import { useEffect, useState } from 'react'
import { partnerApi } from '../api/partner'

import { TrendingUp, Wallet, ShieldCheck } from 'lucide-react'
import './partner.css'

const PERKS = [
  [TrendingUp, 'Recurring commission', 'Earn a share of every subscription payment from the travel businesses you bring.'],
  [Wallet, 'Clear, simple payouts', 'Watch earnings build up in real time and get paid by UPI or bank transfer.'],
  [ShieldCheck, 'Your link, your credit', 'The first partner a customer signs up through keeps that customer. No disputes.'],
]

function Shell({ title, sub, children }) {
  return (
    <div className="pt-auth">
      <aside className="pt-auth-side">
        <div className="pt-brand"><span className="mark"><img src="/brand/tripsarthi-mark.png" alt="" width="30" height="30" /></span><span>Trip<span className="s">Sarthi</span></span><span className="pt-badge">Partners</span></div>
        <h1>Grow with the travel industry's smartest CRM.</h1>
        <p className="lead">Introduce travel agencies and tour operators to TripSarthi and earn recurring commission, with a dashboard that shows every rupee.</p>
        {PERKS.map(([Icon, t, d]) => <div key={t} className="pt-perk"><i><Icon size={18} /></i><div><b>{t}</b><span>{d}</span></div></div>)}
      </aside>
      <main className="pt-auth-main">
        <div className="pt-auth-card">
          <div className="pt-auth-mobile-brand"><img src="/brand/tripsarthi-mark.png" alt="" width="30" height="30" /><span>Trip<span style={{ color: '#12a89e' }}>Sarthi</span></span><span className="pt-badge">Partners</span></div>
          <h2>{title}</h2>
          <p className="sub">{sub}</p>
          {children}
        </div>
      </main>
    </div>
  )
}

export function PartnerLoginPage({ onLogin }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState(null)
  const [loading, setLoading] = useState(false)
  async function submit(e) {
    e.preventDefault(); setError(null); setLoading(true)
    try { const r = await partnerApi.login(email, password); onLogin(r.partner) }
    catch (err) { setError(err.status === 429 ? 'Too many attempts. Please wait a few minutes.' : (err.message || 'Sign-in failed.')) }
    finally { setLoading(false) }
  }
  return (
    <Shell title="Welcome back" sub="Sign in to your partner account">
      <form onSubmit={submit}>
        <div className="form-group"><label className="form-label" htmlFor="pe">Email address</label><input id="pe" type="email" className="form-input" value={email} onChange={e => setEmail(e.target.value)} required autoComplete="username" autoFocus /></div>
        <div className="form-group"><label className="form-label" htmlFor="pp">Password</label><input id="pp" type="password" className="form-input" value={password} onChange={e => setPassword(e.target.value)} required autoComplete="current-password" /></div>
        {error && <div className="form-error">{error}</div>}
        <button type="submit" disabled={loading} className="btn btn-primary w-full" style={{ padding: '.7rem', justifyContent: 'center', marginTop: '.25rem' }}>{loading ? 'Signing in…' : 'Sign in'}</button>
      </form>
      <p style={{ marginTop: '1.25rem', fontSize: '.8rem', color: '#6b7a93', lineHeight: 1.5 }}>Forgot your password, or not a partner yet? Write to your TripSarthi contact and we will send you a new link.</p>
    </Shell>
  )
}

/** Opened from the one-time link the TripSarthi team sends: #/set-password?token=... */
export function PartnerSetPasswordPage({ token, onDone }) {
  const [info, setInfo] = useState(null)
  const [problem, setProblem] = useState(null)
  const [pw, setPw] = useState('')
  const [pw2, setPw2] = useState('')
  const [wa, setWa] = useState(false)          // never pre-ticked
  const [error, setError] = useState(null)
  const [loading, setLoading] = useState(false)
  useEffect(() => { partnerApi.inviteInfo(token).then(setInfo).catch(e => setProblem(e.message || 'This link is not valid.')) }, [token])
  async function submit(e) {
    e.preventDefault(); setError(null)
    if (pw !== pw2) { setError('The two passwords do not match.'); return }
    setLoading(true)
    try { const r = await partnerApi.accept(token, pw, wa && Boolean(info?.has_phone)); onDone(r.partner, Boolean(r.token)) }
    catch (err) { setError(err.message || 'Could not set the password.') } finally { setLoading(false) }
  }
  return (
    <Shell title="Set your password" sub="One last step to open your partner dashboard">
      {problem && <div className="form-error">{problem}</div>}
      {!problem && !info && <div className="pt-skel" style={{ height: 120 }} />}
      {info && (
        <form onSubmit={submit}>
          <p style={{ marginTop: 0, color: '#374151', fontSize: '.9rem' }}>Welcome, <b>{info.name}</b>. Choose a password for <b>{info.email}</b>.</p>
          <div className="form-group"><label className="form-label" htmlFor="n1">New password</label><input id="n1" type="password" className="form-input" value={pw} onChange={e => setPw(e.target.value)} required minLength={10} autoComplete="new-password" autoFocus /><div style={{ fontSize: 12, color: '#6b7280', marginTop: 3 }}>At least 10 characters. A few random words work well.</div></div>
          <div className="form-group"><label className="form-label" htmlFor="n2">Repeat the password</label><input id="n2" type="password" className="form-input" value={pw2} onChange={e => setPw2(e.target.value)} required autoComplete="new-password" /></div>
          {info.has_phone && (
            <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start', margin: '0 0 12px', fontSize: 12.5, color: '#4b5563', lineHeight: 1.4, cursor: 'pointer' }}>
              <input type="checkbox" checked={wa} onChange={e => setWa(e.target.checked)} style={{ marginTop: 2 }} />
              <span>Send me partner program updates on WhatsApp (optional). You can change this any time in your dashboard.</span>
            </label>)}
          {error && <div className="form-error">{error}</div>}
          <button type="submit" disabled={loading} className="btn btn-primary w-full" style={{ padding: '.7rem', justifyContent: 'center' }}>{loading ? 'Saving…' : 'Set password and continue'}</button>
        </form>)}
    </Shell>
  )
}
