import { useState } from 'react'
import { api, saveToken } from '../api/client'
import AuthBrandPanel from '../components/AuthBrandPanel'
import { toast } from '../components/Toast'

export default function LoginPage({ onLogin, onShowRegister, onShowForgot }) {
  const [email, setEmail]       = useState('')
  const [password, setPassword] = useState('')
  const [error, setError]       = useState(null)
  const [loading, setLoading]   = useState(false)
  const [show, setShow]         = useState(false)

  async function handleSubmit(e) {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      const data = await api.post('/auth/login', { email, password })
      saveToken(data.token)
      onLogin?.(data.user)
    } catch (err) {
      if (err.status === 429) setError('Too many sign-in attempts. Please wait a few minutes and try again.')
      else if (!err.status) setError('Cannot reach TripSarthi. Check your internet connection and try again.')
      else if (err.status === 403) setError(err.message || 'This workspace is not active. Please contact support.')
      else setError(err.message ?? 'Login failed. Check your credentials.')
      toast.error('Could not sign you in', err.status === 401 || err.status === 422 ? 'Check your email and password and try again.' : undefined)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="lp-auth-shell" style={S.shell}>
      <AuthBrandPanel />

      {/* Right panel — form */}
      <div style={S.formPanel}>
        <div style={S.formBox}>
          <div style={S.formHeader}>
            <h2 style={S.formTitle}>Welcome back</h2>
            <p style={S.formSub}>Sign in to your account</p>
          </div>

          <form onSubmit={handleSubmit} style={S.form}>
            <div className="form-group">
              <label className="form-label" htmlFor="email">Email address</label>
              <input
                id="email"
                type="email"
                className="form-input"
                value={email}
                onChange={e => setEmail(e.target.value)}
                placeholder="you@company.com"
                required
                autoComplete="email"
                autoFocus
              />
            </div>

            <div className="form-group">
              <label className="form-label" htmlFor="password">Password</label>
              <input
                id="password"
                type={show ? 'text' : 'password'}
                className="form-input"
                value={password}
                onChange={e => setPassword(e.target.value)}
                placeholder="Your password"
                required
                autoComplete="current-password"
              />
            </div>

            <button type="button" onClick={() => setShow(v => !v)} aria-pressed={show} style={{ alignSelf: 'flex-start', marginTop: -6, background: 'none', border: 'none', padding: 0, color: '#0a6cc4', cursor: 'pointer', fontSize: 12.5 }}>{show ? 'Hide password' : 'Show password'}</button>
            {error && <div className="form-error" role="alert">{error}</div>}

            <button
              type="submit"
              disabled={loading}
              className="btn btn-primary w-full"
              style={{ padding: '.7rem', fontSize: '.9375rem', marginTop: '.25rem', justifyContent: 'center' }}
            >
              {loading ? 'Signing in…' : 'Sign in'}
            </button>
          </form>

          {onShowForgot && (
            <p style={{ ...S.hint, marginTop: '.5rem' }}>
              <button
                type="button"
                onClick={onShowForgot}
                style={{ background: 'none', border: 'none', color: '#0a6cc4', cursor: 'pointer', padding: 0, fontSize: 'inherit', textDecoration: 'underline' }}
              >
                Forgot your password?
              </button>
            </p>
          )}

          {onShowRegister && (
            <p style={{ ...S.hint, marginTop: '.5rem' }}>
              New here?{' '}
              <button
                type="button"
                onClick={onShowRegister}
                style={{ background: 'none', border: 'none', color: '#0a6cc4', cursor: 'pointer', padding: 0, fontSize: 'inherit', textDecoration: 'underline' }}
              >
                Create an account →
              </button>
            </p>
          )}
        </div>
      </div>
    </div>
  )
}

const S = {
  shell: {
    display: 'flex',
    minHeight: '100vh',
    fontFamily: 'var(--font)',
  },

  formPanel: {
    flex: 1,
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    padding: '2rem',
    background: '#f8fafc',
  },
  formBox: {
    width: '100%',
    maxWidth: 380,
    background: '#fff',
    border: '1px solid #e2e8f0',
    borderRadius: 14,
    padding: '2.25rem',
    boxShadow: '0 4px 24px rgba(15,23,42,.08)',
  },
  formHeader: { marginBottom: '1.75rem' },
  formTitle: { fontSize: '1.4rem', fontWeight: 700, color: '#0f172a', marginBottom: '.3rem' },
  formSub: { fontSize: '.875rem', color: '#64748b' },
  form: { display: 'flex', flexDirection: 'column', gap: '1rem' },
  hint: { marginTop: '1.25rem', fontSize: '.78rem', color: '#94a3b8', textAlign: 'center' },
  code: { background: '#f1f5f9', padding: '1px 5px', borderRadius: 4, fontFamily: 'monospace', fontSize: '.78rem' },
}
