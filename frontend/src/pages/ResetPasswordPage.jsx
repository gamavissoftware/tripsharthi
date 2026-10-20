import { useState, useEffect, useRef } from 'react'
import { CheckCircle2 } from 'lucide-react'
import AuthBrandPanel from '../components/AuthBrandPanel'
import { api } from '../api/client'
import { toast } from '../components/Toast'
import Field from '../components/FormField'
import { firstInvalid, shake } from '../lib/formFocus'

export default function ResetPasswordPage({ onResetSuccess }) {
  const token = new URLSearchParams(window.location.hash.split('?')[1] ?? '').get('token') ?? ''

  const [password, setPassword]         = useState('')
  const [confirm, setConfirm]           = useState('')
  const [error, setError]               = useState(null)
  const [fieldErrors, setFieldErrors]   = useState({})
  const [success, setSuccess]           = useState(false)
  const [loading, setLoading]           = useState(false)
  const [show, setShow]                 = useState(false)
  const formRef = useRef(null)

  useEffect(() => {
    if (success) {
      const t = setTimeout(() => onResetSuccess?.(), 2000)
      return () => clearTimeout(t)
    }
  }, [success, onResetSuccess])

  function validate(pw = password, cf = confirm) {
    const errs = {}
    if (!pw) errs.password = 'Choose a new password'
    else if (pw.length < 8) errs.password = `Use at least 8 characters (you have ${pw.length})`
    if (!cf) errs.confirm = 'Type the password again to confirm'
    else if (cf !== pw) errs.confirm = 'The two passwords do not match'
    return errs
  }
  // a flagged field clears as soon as it is right
  const recheck = (pw, cf) => { if (Object.keys(fieldErrors).length) { const n = validate(pw, cf); setFieldErrors(s => ({ ...(s.password ? { password: n.password } : {}), ...(s.confirm ? { confirm: n.confirm } : {}) })) } }

  async function handleSubmit(e) {
    e.preventDefault()
    setError(null)
    setFieldErrors({})

    const errs = validate()
    if (Object.keys(errs).length) {
      setFieldErrors(errs)
      shake(formRef.current); firstInvalid(formRef.current)
      const keys = Object.keys(errs)
      toast.warning(keys.length === 1 ? 'One thing needs fixing' : `${keys.length} things need fixing`, errs[keys[0]])
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

              <form onSubmit={handleSubmit} style={S.form} noValidate ref={formRef}>
                <Field label="New password" required error={fieldErrors.password} hint="At least 8 characters. A longer phrase is stronger.">
                  <input
                    type={show ? 'text' : 'password'}
                    className="form-input"
                    value={password}
                    onChange={e => { setPassword(e.target.value); recheck(e.target.value, confirm) }}
                    placeholder="Min. 8 characters"
                    autoComplete="new-password"
                    autoFocus
                  />
                </Field>

                <Field label="Confirm password" required error={fieldErrors.confirm}>
                  <input
                    type={show ? 'text' : 'password'}
                    className="form-input"
                    value={confirm}
                    onChange={e => { setConfirm(e.target.value); recheck(password, e.target.value) }}
                    placeholder="Repeat your password"
                    autoComplete="new-password"
                  />
                </Field>
                <button type="button" onClick={() => setShow(v => !v)} aria-pressed={show} style={{ ...S.link, alignSelf: 'flex-start', marginTop: -6 }}>{show ? 'Hide passwords' : 'Show passwords'}</button>

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
  link: { background: 'none', border: 'none', color: '#0a6cc4', cursor: 'pointer', padding: 0, fontSize: '.8125rem', textDecoration: 'underline', fontFamily: 'var(--font)' },
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
