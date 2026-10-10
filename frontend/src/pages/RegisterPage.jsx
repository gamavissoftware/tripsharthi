import { useState } from 'react'
import { api, saveToken } from '../api/client'
import AuthBrandPanel from '../components/AuthBrandPanel'
import { readReferral, clearReferral } from '../lib/referral'

export default function RegisterPage({ onLogin, onShowLogin }) {
  const [name, setName]         = useState('')
  const [company, setCompany]   = useState('')
  const [email, setEmail]       = useState('')
  const [password, setPassword] = useState('')
  const [error, setError]       = useState(null)
  const [loading, setLoading]   = useState(false)

  async function handleSubmit(e) {
    e.preventDefault()
    setError(null)
    if (password.length < 8) {
      setError('Password must be at least 8 characters.')
      return
    }
    setLoading(true)
    try {
      const ref = readReferral()
      const data = await api.post('/auth/register', { name, company, email, password, ...(ref ? { ref } : {}) })
      clearReferral()
      saveToken(data.token)
      onLogin?.(data.user)
    } catch (err) {
      setError(err.message ?? 'Failed.')
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
            <h2 style={S.formTitle}>Create your account</h2>
            <p style={S.formSub}>Get started with TripSarthi for free</p>
          </div>

          <form onSubmit={handleSubmit} style={S.form}>
            <div className="form-group">
              <label className="form-label" htmlFor="name">Full Name</label>
              <input
                id="name"
                type="text"
                className="form-input"
                value={name}
                onChange={e => setName(e.target.value)}
                placeholder="Jane Smith"
                required
                autoComplete="name"
                autoFocus
              />
            </div>

            <div className="form-group">
              <label className="form-label" htmlFor="company">Company Name</label>
              <input
                id="company"
                type="text"
                className="form-input"
                value={company}
                onChange={e => setCompany(e.target.value)}
                placeholder="Acme Corp"
                required
                autoComplete="organization"
              />
            </div>

            <div className="form-group">
              <label className="form-label" htmlFor="email">Work Email</label>
              <input
                id="email"
                type="email"
                className="form-input"
                value={email}
                onChange={e => setEmail(e.target.value)}
                placeholder="jane@acme.com"
                required
                autoComplete="email"
              />
            </div>

            <div className="form-group">
              <label className="form-label" htmlFor="password">Password</label>
              <input
                id="password"
                type="password"
                className="form-input"
                value={password}
                onChange={e => setPassword(e.target.value)}
                placeholder="••••••••"
                required
                minLength={8}
                autoComplete="new-password"
              />
            </div>

            {error && <div className="form-error">{error}</div>}

            <button
              type="submit"
              disabled={loading}
              className="btn btn-primary w-full"
              style={{ padding: '.7rem', fontSize: '.9375rem', marginTop: '.25rem', justifyContent: 'center' }}
            >
              {loading ? 'Creating account…' : 'Create account'}
            </button>
          </form>

          <p style={S.footer}>
            Already have an account?{' '}
            <button
              type="button"
              onClick={onShowLogin}
              style={S.link}
            >
              Sign in →
            </button>
          </p>
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
    maxWidth: 420,
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
  footer: { marginTop: '1.25rem', fontSize: '.85rem', color: '#64748b', textAlign: 'center' },
  link: {
    background: 'none',
    border: 'none',
    padding: 0,
    color: '#0a6cc4',
    fontWeight: 600,
    fontSize: '.85rem',
    cursor: 'pointer',
    textDecoration: 'none',
  },
}
