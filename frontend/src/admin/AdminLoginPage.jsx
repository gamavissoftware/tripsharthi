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
    <div style={S.page}>
      <div style={S.card}>
        <div style={S.logoRow}>
          <span style={S.tile}><img src="/brand/tripsarthi-mark.png" alt="" width="34" height="34" style={{ display: 'block' }} /></span>
          <div>
            <div style={S.name}>Trip<span style={{ color: '#2fd0c2' }}>Sarthi</span> <span style={S.badge}>Admin</span></div>
            <div style={S.sub}>Platform team sign-in</div>
          </div>
        </div>
        <form onSubmit={submit}>
          <div className="form-group">
            <label className="form-label" htmlFor="admin-email">Email address</label>
            <input id="admin-email" type="email" className="form-input" value={email} onChange={e => setEmail(e.target.value)} required autoComplete="username" autoFocus />
          </div>
          <div className="form-group">
            <label className="form-label" htmlFor="admin-password">Password</label>
            <input id="admin-password" type="password" className="form-input" value={password} onChange={e => setPassword(e.target.value)} required autoComplete="current-password" />
          </div>
          {error && <div className="form-error">{error}</div>}
          <button type="submit" disabled={loading} className="btn btn-primary w-full" style={{ padding: '.7rem', justifyContent: 'center', marginTop: '.25rem' }}>
            {loading ? 'Signing in…' : 'Sign in'}
          </button>
        </form>
        <p style={S.note}><ShieldCheck size={13} style={{ verticalAlign: '-2px' }} /> Restricted area. Sessions end after 12 hours or 2 hours of inactivity, and sign-ins are logged.</p>
      </div>
    </div>
  )
}

const S = {
  page: { minHeight: '100vh', display: 'grid', placeItems: 'center', padding: '1.5rem', background: 'linear-gradient(150deg, #06214f 0%, #0a3b7d 55%, #0b7f8f 100%)' },
  card: { width: '100%', maxWidth: 400, background: '#fff', borderRadius: 16, padding: '2rem 1.75rem', boxShadow: '0 20px 50px rgba(0,0,0,.3)' },
  logoRow: { display: 'flex', alignItems: 'center', gap: '.75rem', marginBottom: '1.5rem' },
  tile: { width: 48, height: 48, borderRadius: 12, background: '#f3f6fb', display: 'grid', placeItems: 'center' },
  name: { fontSize: '1.3rem', fontWeight: 800, color: '#0a1f44', letterSpacing: '-.3px' },
  badge: { fontSize: '.65rem', fontWeight: 800, letterSpacing: '.6px', textTransform: 'uppercase', background: '#0a1f44', color: '#fff', padding: '2px 7px', borderRadius: 6, verticalAlign: '3px' },
  sub: { fontSize: '.82rem', color: '#6b7280' },
  note: { marginTop: '1.25rem', fontSize: '.74rem', color: '#6b7280', lineHeight: 1.5 },
}
