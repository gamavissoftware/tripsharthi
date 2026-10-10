import { useEffect, useState } from 'react'
import { partnerApi } from '../api/partner'

const S = {
  page: { minHeight: '100vh', display: 'grid', placeItems: 'center', padding: '1.5rem', background: 'linear-gradient(150deg, #06214f 0%, #0a3b7d 55%, #0b7f8f 100%)' },
  card: { width: '100%', maxWidth: 420, background: '#fff', borderRadius: 16, padding: '2rem 1.75rem', boxShadow: '0 20px 50px rgba(0,0,0,.3)' },
  logoRow: { display: 'flex', alignItems: 'center', gap: '.75rem', marginBottom: '1.5rem' },
  tile: { width: 48, height: 48, borderRadius: 12, background: '#f3f6fb', display: 'grid', placeItems: 'center' },
  name: { fontSize: '1.3rem', fontWeight: 800, color: '#0a1f44', letterSpacing: '-.3px' },
  badge: { fontSize: '.65rem', fontWeight: 800, letterSpacing: '.6px', textTransform: 'uppercase', background: '#12a89e', color: '#fff', padding: '2px 7px', borderRadius: 6, verticalAlign: '3px' },
  sub: { fontSize: '.82rem', color: '#6b7280' },
  note: { marginTop: '1.25rem', fontSize: '.78rem', color: '#6b7280', lineHeight: 1.5 },
}

function Shell({ title, sub, children }) {
  return (
    <div style={S.page}><div style={S.card}>
      <div style={S.logoRow}>
        <span style={S.tile}><img src="/brand/tripsarthi-mark.png" alt="" width="34" height="34" style={{ display: 'block' }} /></span>
        <div><div style={S.name}>Trip<span style={{ color: '#12a89e' }}>Sarthi</span> <span style={S.badge}>Partners</span></div><div style={S.sub}>{sub}</div></div>
      </div>
      <h2 style={{ margin: '0 0 1rem', fontSize: '1.15rem', color: '#0a1f44' }}>{title}</h2>
      {children}
    </div></div>
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
    <Shell title="Sign in to your partner account" sub="Referral partner program">
      <form onSubmit={submit}>
        <div className="form-group"><label className="form-label" htmlFor="pe">Email address</label><input id="pe" type="email" className="form-input" value={email} onChange={e => setEmail(e.target.value)} required autoComplete="username" autoFocus /></div>
        <div className="form-group"><label className="form-label" htmlFor="pp">Password</label><input id="pp" type="password" className="form-input" value={password} onChange={e => setPassword(e.target.value)} required autoComplete="current-password" /></div>
        {error && <div className="form-error">{error}</div>}
        <button type="submit" disabled={loading} className="btn btn-primary w-full" style={{ padding: '.7rem', justifyContent: 'center', marginTop: '.25rem' }}>{loading ? 'Signing in…' : 'Sign in'}</button>
      </form>
      <p style={S.note}>Forgot your password, or not a partner yet? Write to your TripSarthi contact and we will send you a new link.</p>
    </Shell>
  )
}

/** Opened from the one-time link the TripSarthi team sends: #/set-password?token=... */
export function PartnerSetPasswordPage({ token, onDone }) {
  const [info, setInfo] = useState(null)
  const [problem, setProblem] = useState(null)
  const [pw, setPw] = useState('')
  const [pw2, setPw2] = useState('')
  const [error, setError] = useState(null)
  const [loading, setLoading] = useState(false)
  useEffect(() => { partnerApi.inviteInfo(token).then(setInfo).catch(e => setProblem(e.message || 'This link is not valid.')) }, [token])
  async function submit(e) {
    e.preventDefault(); setError(null)
    if (pw !== pw2) { setError('The two passwords do not match.'); return }
    setLoading(true)
    try { const r = await partnerApi.accept(token, pw); onDone(r.partner, Boolean(r.token)) }
    catch (err) { setError(err.message || 'Could not set the password.') } finally { setLoading(false) }
  }
  return (
    <Shell title="Set your password" sub="Referral partner program">
      {problem && <div className="form-error">{problem}</div>}
      {!problem && !info && <p style={{ color: '#6b7280' }}>Checking your link…</p>}
      {info && (
        <form onSubmit={submit}>
          <p style={{ marginTop: 0, color: '#374151', fontSize: '.9rem' }}>Welcome, <b>{info.name}</b>. Choose a password for <b>{info.email}</b>.</p>
          <div className="form-group"><label className="form-label" htmlFor="n1">New password</label><input id="n1" type="password" className="form-input" value={pw} onChange={e => setPw(e.target.value)} required minLength={10} autoComplete="new-password" autoFocus /><div style={{ fontSize: 12, color: '#6b7280', marginTop: 3 }}>At least 10 characters. A few random words work well.</div></div>
          <div className="form-group"><label className="form-label" htmlFor="n2">Repeat the password</label><input id="n2" type="password" className="form-input" value={pw2} onChange={e => setPw2(e.target.value)} required autoComplete="new-password" /></div>
          {error && <div className="form-error">{error}</div>}
          <button type="submit" disabled={loading} className="btn btn-primary w-full" style={{ padding: '.7rem', justifyContent: 'center' }}>{loading ? 'Saving…' : 'Set password and continue'}</button>
        </form>)}
    </Shell>
  )
}
