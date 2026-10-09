import { useState, useEffect } from 'react'
import { CheckCircle2 } from 'lucide-react'
import AuthBrandPanel from '../components/AuthBrandPanel'
import { api } from '../api/client'

export default function ResetPasswordPage({ onResetSuccess }) {
  const token = new URLSearchParams(window.location.hash.split('?')[1] ?? '').get('token') ?? ''

  const [password, setPassword]         = useState('')
  const [confirm, setConfirm]           = useState('')
  const [error, setError]               = useState(null)
  const [fieldErrors, setFieldErrors]   = useState({})
  const [success, setSuccess]           = useState(false)
  const [loading, setLoading]           = useState(false)

  useEffect(() => {
    if (success) {
      const t = setTimeout(() => onResetSuccess?.(), 2000)
      return () => clearTimeout(t)
    }
  }, [success, onResetSuccess])

  function validate() {
    const errs = {}
    if (password.length < 8) errs.password = 'Password must be at least 8 characters.'
    if (confirm !== password) errs.confirm = 'Passwords do not match.'
    return errs
  }

  async function handleSubmit(e) {
    e.preventDefault()
    setError(null)
    setFieldErrors({})

    const errs = validate()
    if (Object.keys(errs).length) {
      setFieldErrors(errs)
      return
    }

    setLoading(true)
    try {
      await api.post('/auth/reset-password', { token, password })
      setSuccess(true)
    } catch (err) {
      if (err.errors && typeof err.errors === 'object') {
        setFieldErrors(err.errors)
      } else {
        setError(err.message ?? 'Something went wrong. Please try again.')
      }
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
          {!token ? (
            <>
              <div style={S.formHeader}>
                <h2 style={S.formTitle}>Invalid link</h2>
              </div>
              <div className="form-error" style={{ marginBottom: 0 }}>
                Invalid reset link. Please request a new one.
              </div>
            </>
          ) : success ? (
            <>
              <div style={S.formHeader}>
                <h2 style={S.formTitle}>Set new password</h2>
              </div>
              <div style={S.successBox}>
                <div style={S.successIcon}><CheckCircle2 size={32} strokeWidth={1.5} style={{ color: '#16a34a' }} /></div>
                <p style={S.successText}>Password updated! Redirecting to login…</p>
              </div>
            </>
          ) : (
            <>
              <div style={S.formHeader}>
                <h2 style={S.formTitle}>Set new password</h2>
                <p style={S.formSub}>Choose a strong password for your account.</p>
              </div>

              <form onSubmit={handleSubmit} style={S.form}>
                <div className="form-group">
                  <label className="form-label" htmlFor="password">New password</label>
                  <input
                    id="password"
                    type="password"
                    className="form-input"
                    value={password}
                    onChange={e => setPassword(e.target.value)}
                    placeholder="Min. 8 characters"
                    required
                    autoComplete="new-password"
                    autoFocus
                  />
                  {fieldErrors.password && (
                    <div className="form-error">{fieldErrors.password}</div>
                  )}
                </div>

                <div className="form-group">
                  <label className="form-label" htmlFor="confirm">Confirm password</label>
                  <input
                    id="confirm"
                    type="password"
                    className="form-input"
                    value={confirm}
                    onChange={e => setConfirm(e.target.value)}
                    placeholder="Repeat your password"
                    required
                    autoComplete="new-password"
                  />
                  {fieldErrors.confirm && (
                    <div className="form-error">{fieldErrors.confirm}</div>
                  )}
                </div>

                {error && <div className="form-error">{error}</div>}

                <button
                  type="submit"
                  disabled={loading}
                  className="btn btn-primary w-full"
                  style={{ padding: '.7rem', fontSize: '.9375rem', marginTop: '.25rem', justifyContent: 'center' }}
                >
                  {loading ? 'Updating…' : 'Update password'}
                </button>
              </form>
            </>
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

  successBox: {
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    gap: '.75rem',
    padding: '1.25rem',
    background: '#f0fdf4',
    border: '1px solid #bbf7d0',
    borderRadius: 10,
  },
  successIcon: { fontSize: '2rem' },
  successText: {
    fontSize: '.9rem',
    color: '#166534',
    textAlign: 'center',
    margin: 0,
    lineHeight: 1.5,
  },
}
