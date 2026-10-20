import { useState } from 'react'
import { ShieldCheck } from 'lucide-react'
import { adminAuth } from '../api/adminAuth'

/** Sign-in for TripSarthi staff. Deliberately plain: no sign-up, no "forgot password" (accounts are granted on the server). */
export default function AdminLoginPage({ onLogin }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState(null)
  const [loading, setLoading] = useState(false)

  async function submit(e) {
    e.preventDefault()
    setError(null); setLoading(true)
    try { const r = await adminAuth.login(email, password); onLogin(r.user) }
    catch (err) { setError(err.status === 429 ? 'Too many attempts. Please wait a few minutes.' : (err.message || 'Sign-in failed.')) }
    finally { setLoading(false) }
  }

  return (
    <div className="ad ad-auth">
      <aside className="ad-auth-side">
        <div className="ad-brand"><span className="mark"><img src="/brand/tripsarthi-mark.png" alt="" width="30" height="30" /></span><span>Trip<span className="s">Sarthi</span></span><span className="ad-badge">Admin</span></div>
        <h1>Run the platform from one calm place.</h1>
        <p>Customers, subscriptions, offers, partners, support, WhatsApp and e-mail marketing, all in the TripSarthi control room.</p>
      </aside>
      <main className="ad-auth-main">
        <div className="ad-auth-card">
          <div className="ad-mobile-brand"><img src="/brand/tripsarthi-mark.png" alt="" width="30" height="30" /><span>Trip<span style={{ color: '#12a89e' }}>Sarthi</span></span><span className="ad-badge" style={{ background: '#0a1f44' }}>Admin</span></div>
          <h2>Welcome back</h2>
          <p className="sub">Platform team sign-in</p>
          <form onSubmit={submit}>
            <div className="form-group">
              <label className="form-label" htmlFor="admin-email">Email address *</label>
              <input id="admin-email" type="email" className="form-input" value={email} onChange={e => setEmail(e.target.value)} required autoComplete="username" inputMode="email" autoCapitalize="none" spellCheck={false} autoFocus />
            </div>
            <div className="form-group">
              <label className="form-label" htmlFor="admin-password">Password *</label>
              <input id="admin-password" type="password" className="form-input" value={password} onChange={e => setPassword(e.target.value)} required autoComplete="current-password" />
            </div>
            {error && <div className="form-error">{error}</div>}
            <button type="submit" disabled={loading} className="btn btn-primary w-full" style={{ padding: '.75rem', justifyContent: 'center', marginTop: '.25rem' }}>
              {loading ? 'Signing in…' : 'Sign in'}
            </button>
          </form>
          <p className="note"><ShieldCheck size={13} style={{ verticalAlign: '-2px' }} /> Restricted area. Sessions end after 12 hours or 2 hours of inactivity, and sign-ins are logged.</p>
        </div>
      </main>
    </div>
  )
}
