import { useState } from 'react'
import { MailCheck } from 'lucide-react'
import AuthBrandPanel from '../components/AuthBrandPanel'
import { api } from '../api/client'

export default function ForgotPasswordPage({ onBackToLogin }) {
  const [email, setEmail]     = useState('')
  const [error, setError]     = useState(null)
  const [success, setSuccess] = useState(false)
  const [loading, setLoading] = useState(false)

  async function handleSubmit(e) {
    e.preventDefault()
    setError(null)
    setLoading(true)
    try {
      await api.post('/auth/forgot-password', { email })
      setSuccess(true)
    } catch (err) {
      setError(err.message ?? 'Something went wrong. Please try again.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="lp-auth-shell" style={S.shell}>
      {/* Left panel — branding */}
      <AuthBrandPanel />

      {/* Right panel — form */}
      <div style={S.formPanel}>
        <div style={S.formBox}>
          <div style={S.formHeader}>
            <h2 style={S.formTitle}>Forgot password</h2>
            <p style={S.formSub}>Enter your email and we'll send a reset link.</p>
          </div>

          {success ? (
            <div style={S.successBox}>
              <div style={S.successIcon}><MailCheck size={32} strokeWidth={1.5} style={{ color: '#16a34a' }} /></div>
              <p style={S.successText}>
                Check your inbox — a reset link has been sent.
              </p>
            </div>
          ) : (
            <form onSubmit={handleSubmit} style={S.form}>
              <div className="form-group">
                <label className="form-label" htmlFor="email">Email address</label>
                <input
                  id="email"
                  type="email"
                  className="form-input"
                  value={email}
                  onChange={e => setEmail(e.target.value)}
                  placeholder="you@example.com"
                  required
                  autoComplete="email"
                  autoFocus
                />
              </div>

              {error && <div className="form-error">{error}</div>}

              <button
                type="submit"
                disabled={loading}
                className="btn btn-primary w-full"
                style={{ padding: '.7rem', fontSize: '.9375rem', marginTop: '.25rem', justifyContent: 'center' }}
              >
                {loading ? 'Sending…' : 'Send reset link'}
              </button>
            </form>
          )}

          <div style={S.backRow}>
            <button
              type="button"
              onClick={onBackToLogin}
              style={S.backLink}
            >
              ← Back to login
            </button>
          </div>
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

  successBox: {
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    gap: '.75rem',
    padding: '1.25rem',
    background: '#f0fdf4',
    border: '1px solid #bbf7d0',
    borderRadius: 10,
    marginBottom: '.5rem',
  },
  successIcon: { fontSize: '2rem' },
  successText: {
    fontSize: '.9rem',
    color: '#166534',
    textAlign: 'center',
    margin: 0,
    lineHeight: 1.5,
  },

  backRow: { marginTop: '1.5rem', textAlign: 'center' },
  backLink: {
    background: 'none',
    border: 'none',
    color: '#0a6cc4',
    cursor: 'pointer',
    padding: 0,
    fontSize: '.875rem',
    textDecoration: 'underline',
    fontFamily: 'var(--font)',
  },
}
