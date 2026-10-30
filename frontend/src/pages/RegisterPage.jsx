import { useState, useRef } from 'react'
import { api, saveToken } from '../api/client'
import AuthBrandPanel from '../components/AuthBrandPanel'
import { readReferral, clearReferral } from '../lib/referral'
import { toast } from '../components/Toast'
import Field from '../components/FormField'
import { firstInvalid, shake } from '../lib/formFocus'
import { trackMeta } from '../lib/metaPixel'

const WEBSITE = typeof window !== 'undefined' && window.location.hostname === 'localhost' ? 'http://localhost:8080' : 'https://tripsarthi.com'

/** 0-4: length, mixed case, a digit, a symbol. Only a hint; the server enforces the 8-character minimum. */
function strength(pw) {
  let n = 0
  if (pw.length >= 8) n++
  if (pw.length >= 12) n++
  if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) n++
  if (/\d/.test(pw) && /[^A-Za-z0-9]/.test(pw)) n++
  return n
}
const digitsOnly = (v) => String(v || '').replace(/\D/g, '')

export default function RegisterPage({ onLogin, onShowLogin, onShowForgot }) {
  const [f, setF] = useState({ name: '', company: '', email: '', password: '', phone: '' })
  const [waOptIn, setWaOptIn]   = useState(false)   // never pre-ticked: consent has to be a choice
  const [emailOptIn, setEmailOptIn] = useState(false)
  const [show, setShow]         = useState(false)
  const [errs, setErrs]         = useState({})
  const [loading, setLoading]   = useState(false)
  const formRef = useRef(null)

  const check = (d, k) => {
    switch (k) {
      case 'name': return d.name.trim().length < 2 ? 'Please enter your full name' : ''
      case 'company': return d.company.trim().length < 2 ? 'Enter your travel company or agency name' : ''
      case 'email': return !d.email.trim() ? 'Enter your work email' : !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(d.email.trim()) ? 'This does not look like an email address. Check for typos' : ''
      case 'password': return d.password.length < 8 ? `Use at least 8 characters${d.password ? ` (you have ${d.password.length})` : ''}` : ''
      case 'phone': return d.phone.trim() && (digitsOnly(d.phone).length < 10 || digitsOnly(d.phone).length > 15) ? 'Enter the full number with country code, e.g. +91 98765 43210' : ''
      default: return ''
    }
  }
  const change = (k) => (e) => {
    const d = { ...f, [k]: e.target.value }; setF(d)
    if (errs[k]) setErrs(s => ({ ...s, [k]: check(d, k) }))          // a flagged field clears as soon as it is right
  }
  const blur = (k) => () => { if (f[k] || errs[k]) setErrs(s => ({ ...s, [k]: check(f, k) })) }
  const st = strength(f.password)

  async function handleSubmit(e) {
    e.preventDefault()
    const n = Object.fromEntries(['name', 'company', 'email', 'password', 'phone'].map(k => [k, check(f, k)]).filter(([, m]) => m))
    if (waOptIn && !f.phone.trim()) n.phone = 'Add your WhatsApp number, or untick the WhatsApp updates box'
    setErrs(n)
    const keys = Object.keys(n)
    if (keys.length) {
      shake(formRef.current); firstInvalid(formRef.current)
      toast.warning(keys.length === 1 ? 'One thing needs fixing' : `${keys.length} things need fixing`, n[keys[0]])
      return
    }
    setLoading(true)
    try {
      const ref = readReferral()
      const data = await api.post('/auth/register', { name: f.name.trim(), company: f.company.trim(), email: f.email.trim(), password: f.password, ...(f.phone.trim() ? { phone: f.phone.trim() } : {}), wa_opt_in: waOptIn, email_opt_in: emailOptIn, ...(ref ? { ref } : {}) })
      clearReferral()
      saveToken(data.token)
      trackMeta('CompleteRegistration', { content_name: 'TripSarthi Free Signup', status: true })
      toast.success('Welcome to TripSarthi', 'Your workspace is ready.')
      onLogin?.(data.user)
    } catch (err) {
      const fe = err.errors && typeof err.errors === 'object' ? err.errors : {}
      if (err.status === 409 || fe.email) setErrs({ email: 'An account with this email already exists.', dupe: true })
      else if (fe.password) setErrs({ password: String(fe.password).replace(/^The password field /, 'Your password ') })
      else if (fe.phone) setErrs({ phone: fe.phone })
      else if (fe.name || fe.company) setErrs({ ...(fe.name ? { name: 'Please enter a shorter name (255 characters at most)' } : {}), ...(fe.company ? { company: 'Please enter a shorter company name (255 characters at most)' } : {}) })
      else if (err.status === 429) toast.error('Too many attempts', 'Please wait a few minutes and try again.')
      else if (!err.status) toast.error('Cannot reach TripSarthi', 'Check your internet connection and try again.')
      else toast.error('We could not create your account', err.message ?? 'Please try again.')
      shake(formRef.current)
      setTimeout(() => firstInvalid(formRef.current), 0)
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
            <p style={S.formSub}>Start free. No card needed. Set up in about 5 minutes.</p>
          </div>

          <form onSubmit={handleSubmit} style={S.form} noValidate ref={formRef}>
            <Field label="Your full name" required error={errs.name}>
              <input type="text" className="form-input" value={f.name} onChange={change('name')} onBlur={blur('name')} placeholder="e.g. Rohit Sharma" autoComplete="name" autoFocus />
            </Field>
            <Field label="Travel company / agency name" required error={errs.company}>
              <input type="text" className="form-input" value={f.company} onChange={change('company')} onBlur={blur('company')} placeholder="e.g. Blue Horizon Holidays" autoComplete="organization" />
            </Field>
            <Field label="Work email" required error={errs.email}>
              <input type="email" className="form-input" value={f.email} onChange={change('email')} onBlur={blur('email')} placeholder="you@yourcompany.com" autoComplete="email" inputMode="email" autoCapitalize="none" spellCheck={false} />
            </Field>
            {errs.dupe && <div style={{ marginTop: -6, fontSize: 13 }}>Already registered? <button type="button" onClick={onShowLogin} style={S.link}>Sign in instead →</button> or {onShowForgot ? <button type="button" onClick={onShowForgot} style={S.link}>reset your password</button> : 'reset your password from the sign-in page'}.</div>}
            <Field label="Password" required error={errs.password} hint="At least 8 characters. A longer phrase is stronger."
              prefix={null}>
              <input type={show ? 'text' : 'password'} className="form-input" value={f.password} onChange={change('password')} onBlur={blur('password')} placeholder="Choose a password" autoComplete="new-password" />
            </Field>
            <div style={{ marginTop: -6, display: 'flex', alignItems: 'center', gap: 10 }}>
              <div style={{ flex: 1, display: 'flex', gap: 4 }} aria-hidden="true">{[0, 1, 2, 3].map(i => <span key={i} style={{ height: 4, flex: 1, borderRadius: 4, background: i < st ? ['#dc2626', '#f59e0b', '#16a34a', '#059669'][st - 1] : '#e2e8f0', transition: 'background .2s' }} />)}</div>
              <span style={{ fontSize: 12, color: '#64748b', minWidth: 46 }}>{f.password ? ['Weak', 'Okay', 'Good', 'Strong', 'Strong'][Math.max(0, st - 1)] : ''}</span>
              <button type="button" onClick={() => setShow(v => !v)} style={{ ...S.link, fontSize: 12.5 }} aria-pressed={show}>{show ? 'Hide' : 'Show'}</button>
            </div>

            <div style={{ borderTop: '1px solid #eef2f7', paddingTop: 12, display: 'grid', gap: 10 }}>
              <Field label="WhatsApp number (optional)" error={errs.phone}>
                <input type="tel" className="form-input" value={f.phone} onChange={change('phone')} onBlur={blur('phone')} placeholder="+91 98765 43210" autoComplete="tel" />
              </Field>
              <label style={S.check}>
                <input type="checkbox" checked={waOptIn} onChange={e => setWaOptIn(e.target.checked)} style={{ marginTop: 2 }} />
                <span>Send me product updates and offers from TripSarthi on WhatsApp. You can turn this off any time in your profile, or reply STOP.</span>
              </label>
              <label style={S.check}>
                <input type="checkbox" checked={emailOptIn} onChange={e => setEmailOptIn(e.target.checked)} style={{ marginTop: 2 }} />
                <span>E-mail me product news and offers from TripSarthi. Every e-mail has a one-click unsubscribe; account and billing messages are separate.</span>
              </label>
            </div>

            <button type="submit" disabled={loading} className="btn btn-primary w-full" style={{ padding: '.7rem', fontSize: '.9375rem', marginTop: '.25rem', justifyContent: 'center' }}>
              {loading ? 'Creating your workspace…' : 'Create account'}
            </button>
            <p style={{ margin: 0, fontSize: 12, color: '#64748b', textAlign: 'center', lineHeight: 1.5 }}>
              By creating an account you agree to our <a href={`${WEBSITE}/terms.html`} target="_blank" rel="noreferrer" style={{ color: '#0a6cc4' }}>Terms</a> and <a href={`${WEBSITE}/privacy.html`} target="_blank" rel="noreferrer" style={{ color: '#0a6cc4' }}>Privacy Policy</a>.
            </p>
          </form>

          <p style={S.footer}>
            Already have an account?{' '}
            <button type="button" onClick={onShowLogin} style={S.link}>Sign in →</button>
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
  check: { display: 'flex', gap: 8, alignItems: 'flex-start', fontSize: 12.5, color: '#4b5563', lineHeight: 1.4, cursor: 'pointer' },
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
