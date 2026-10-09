import { useState, useEffect, useMemo } from 'react'
import { api } from '../api/client'
import { toast } from '../components/Toast'
import { Crown, Shield, Headphones, Eye, EyeOff, Check } from 'lucide-react'

// ── Inject styles once ──────────────────────────────────────────────────────
if (typeof document !== 'undefined' && !document.getElementById('lp-prof-css')) {
  const el = document.createElement('style')
  el.id = 'lp-prof-css'
  el.textContent = `
    @keyframes lp-prof-in   { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
    @keyframes lp-prof-spin { to { transform:rotate(360deg); } }
    .lp-prof-card {
      background:#fff; border:1px solid #e5e7eb; border-radius:18px; padding:1.5rem 1.6rem;
      margin-bottom:1.25rem; animation: lp-prof-in .25s ease both;
    }
    .lp-prof-label { font-size:13px; font-weight:700; color:#374151; margin-bottom:.4rem; display:block; }
    .lp-prof-input {
      width:100%; padding:.62rem .85rem; border-radius:10px; border:1.5px solid #e5e7eb;
      background:#f9fafb; font-size:14px; color:#111827; outline:none;
      transition:border-color .15s, background .15s; box-sizing:border-box;
    }
    .lp-prof-input:focus { border-color:var(--primary,#0a6cc4); background:#fff; }
    .lp-prof-input.invalid { border-color:#fca5a5; background:#fef2f2; }
    .lp-prof-err { font-size:12px; color:#dc2626; margin-top:.35rem; }
    .lp-prof-eye {
      position:absolute; right:10px; top:50%; transform:translateY(-50%);
      background:none; border:none; cursor:pointer; font-size:15px; opacity:.55; padding:2px;
    }
    .lp-prof-eye:hover { opacity:.9; }
  `
  document.head.appendChild(el)
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const ROLE_STYLE = {
  owner: { label: 'Owner', bg: 'linear-gradient(135deg,#ede9fe,#ddd6fe)', color: '#6d28d9', icon: <Crown size={13} strokeWidth={2} /> },
  admin: { label: 'Admin', bg: 'linear-gradient(135deg,#dbeafe,#bfdbfe)', color: '#1d4ed8', icon: <Shield size={13} strokeWidth={2} /> },
  agent: { label: 'Agent', bg: '#f3f4f6', color: '#4b5563', icon: <Headphones size={13} strokeWidth={2} /> },
}

function initialsOf(name) {
  return (name || 'U').trim().split(/\s+/).map(w => w[0]).join('').substring(0, 2).toUpperCase()
}

// ── Password strength ───────────────────────────────────────────────────────
function scorePassword(pw) {
  if (!pw) return 0
  let s = 0
  if (pw.length >= 8) s++
  if (pw.length >= 12) s++
  if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) s++
  if (/\d/.test(pw)) s++
  if (/[^A-Za-z0-9]/.test(pw)) s++
  return Math.min(s, 4)
}
const STRENGTH = [
  { label: '', color: '#e5e7eb' },
  { label: 'Weak',       color: '#dc2626' },
  { label: 'Fair',       color: '#f59e0b' },
  { label: 'Good',       color: '#2563eb' },
  { label: 'Strong',     color: '#16a34a' },
]

// ── Password input with show/hide ───────────────────────────────────────────
function PwInput({ value, onChange, placeholder, invalid, autoComplete }) {
  const [show, setShow] = useState(false)
  return (
    <div style={{ position: 'relative' }}>
      <input
        type={show ? 'text' : 'password'}
        className={`lp-prof-input ${invalid ? 'invalid' : ''}`}
        value={value}
        onChange={onChange}
        placeholder={placeholder}
        autoComplete={autoComplete}
        style={{ paddingRight: 38 }}
      />
      <button type="button" className="lp-prof-eye" onClick={() => setShow(s => !s)} tabIndex={-1} title={show ? 'Hide' : 'Show'}>
        {show ? <EyeOff size={15} strokeWidth={2} /> : <Eye size={15} strokeWidth={2} />}
      </button>
    </div>
  )
}

function Spinner() {
  return <span style={{ width: 14, height: 14, border: '2px solid rgba(255,255,255,.4)', borderTopColor: '#fff', borderRadius: '50%', animation: 'lp-prof-spin .7s linear infinite', display: 'inline-block' }} />
}

// ── Page ────────────────────────────────────────────────────────────────────
export default function ProfilePage() {
  const [user, setUser]       = useState(null)
  const [loading, setLoading] = useState(true)

  // Profile form
  const [name, setName]   = useState('')
  const [email, setEmail] = useState('')
  const [pErrors, setPErrors] = useState({})
  const [pSaving, setPSaving] = useState(false)

  // Password form
  const [curPw, setCurPw]       = useState('')
  const [newPw, setNewPw]       = useState('')
  const [confirmPw, setConfirmPw] = useState('')
  const [pwErrors, setPwErrors] = useState({})
  const [pwSaving, setPwSaving] = useState(false)

  useEffect(() => {
    api.get('/auth/me')
      .then(res => {
        const u = res?.user ?? null
        setUser(u)
        setName(u?.name || '')
        setEmail(u?.email || '')
      })
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [])

  const profileDirty = user && (name !== (user.name || '') || email !== (user.email || ''))
  const pwScore = useMemo(() => scorePassword(newPw), [newPw])

  async function handleProfileSave(e) {
    e.preventDefault()
    const errs = {}
    if (!name.trim())  errs.name = 'Name is required.'
    if (!email.trim()) errs.email = 'Email is required.'
    else if (!EMAIL_RE.test(email)) errs.email = 'Enter a valid email address.'
    setPErrors(errs)
    if (Object.keys(errs).length) return

    setPSaving(true)
    try {
      const res = await api.put('/auth/profile', { name: name.trim(), email: email.trim() })
      const u = res?.user ?? null
      setUser(u)
      setName(u?.name || ''); setEmail(u?.email || '')
      toast.success('Profile updated', 'Your account details have been saved.')
    } catch (err) {
      if (err.errors && typeof err.errors === 'object') setPErrors(err.errors)
      else toast.error('Update failed', err.message ?? 'Please try again.')
    } finally {
      setPSaving(false)
    }
  }

  async function handlePasswordChange(e) {
    e.preventDefault()
    const errs = {}
    if (!curPw) errs.current_password = 'Current password is required.'
    if (!newPw) errs.new_password = 'New password is required.'
    else if (newPw.length < 8) errs.new_password = 'Must be at least 8 characters.'
    if (!confirmPw) errs.confirm_password = 'Please confirm your new password.'
    else if (newPw !== confirmPw) errs.confirm_password = 'Passwords do not match.'
    setPwErrors(errs)
    if (Object.keys(errs).length) return

    setPwSaving(true)
    try {
      await api.put('/auth/change-password', { current_password: curPw, new_password: newPw })
      setCurPw(''); setNewPw(''); setConfirmPw('')
      toast.success('Password changed', 'Use your new password next time you sign in.')
    } catch (err) {
      if (err.errors && typeof err.errors === 'object') setPwErrors(err.errors)
      else toast.error('Could not change password', err.message ?? 'Please try again.')
    } finally {
      setPwSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="page">
        <div style={{ height: 28, width: 200, borderRadius: 8, background: '#e5e7eb', marginBottom: 24 }} />
        <div style={{ height: 120, borderRadius: 18, background: '#f3f4f6', marginBottom: 20 }} />
        <div style={{ height: 240, borderRadius: 18, background: '#f3f4f6' }} />
      </div>
    )
  }

  const role = ROLE_STYLE[user?.role] ?? ROLE_STYLE.agent

  return (
    <div className="page" style={{ maxWidth: 760 }}>
      {/* Header */}
      <div style={{ marginBottom: '1.5rem' }}>
        <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>
          My Profile
        </h1>
        <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>
          Manage your personal details and account security.
        </p>
      </div>

      {/* Identity hero */}
      <div style={{
        display: 'flex', alignItems: 'center', gap: '1.1rem',
        background: 'linear-gradient(135deg,#f8fafc,#e8f3fc)',
        border: '1px solid #e5e7eb', borderRadius: 18, padding: '1.5rem 1.6rem',
        marginBottom: '1.25rem', animation: 'lp-prof-in .2s ease',
      }}>
        <div style={{
          width: 62, height: 62, borderRadius: 16, flexShrink: 0,
          background: 'var(--primary,#0a6cc4)', color: '#fff',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          fontSize: 24, fontWeight: 800, boxShadow: '0 6px 18px var(--primary-ring,rgba(10,108,196,.35))',
        }}>
          {initialsOf(user?.name)}
        </div>
        <div style={{ minWidth: 0, flex: 1 }}>
          <div style={{ fontSize: 19, fontWeight: 800, color: '#111827' }}>{user?.name || '—'}</div>
          <div style={{ fontSize: 13.5, color: '#6b7280', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{user?.email}</div>
        </div>
        <span style={{
          display: 'inline-flex', alignItems: 'center', gap: '.35rem',
          padding: '.35rem .85rem', borderRadius: 999, fontSize: 12.5, fontWeight: 700,
          background: role.bg, color: role.color, whiteSpace: 'nowrap',
        }}>
          {role.icon} {role.label}
        </span>
      </div>

      {/* Profile details */}
      <form onSubmit={handleProfileSave} className="lp-prof-card">
        <div style={{ fontSize: 15, fontWeight: 800, color: '#111827', marginBottom: '1.1rem' }}>Personal details</div>

        <div style={{ marginBottom: '1.1rem' }}>
          <label className="lp-prof-label">Full name</label>
          <input
            className={`lp-prof-input ${pErrors.name ? 'invalid' : ''}`}
            value={name}
            onChange={e => { setName(e.target.value); if (pErrors.name) setPErrors(x => ({ ...x, name: null })) }}
            placeholder="Your full name"
          />
          {pErrors.name && <div className="lp-prof-err">{pErrors.name}</div>}
        </div>

        <div style={{ marginBottom: '1.25rem' }}>
          <label className="lp-prof-label">Email address</label>
          <input
            type="email"
            className={`lp-prof-input ${pErrors.email ? 'invalid' : ''}`}
            value={email}
            onChange={e => { setEmail(e.target.value); if (pErrors.email) setPErrors(x => ({ ...x, email: null })) }}
            placeholder="you@company.com"
          />
          {pErrors.email && <div className="lp-prof-err">{pErrors.email}</div>}
        </div>

        <button
          type="submit"
          disabled={pSaving || !profileDirty}
          style={{
            padding: '.62rem 1.3rem', borderRadius: 10, border: 'none',
            background: (pSaving || !profileDirty) ? '#c7cdd6' : 'var(--primary,#0a6cc4)',
            color: '#fff', fontWeight: 700, fontSize: 14, cursor: (pSaving || !profileDirty) ? 'not-allowed' : 'pointer',
            display: 'inline-flex', alignItems: 'center', gap: '.45rem',
          }}
        >
          {pSaving ? <><Spinner /> Saving…</> : profileDirty ? 'Save changes' : 'Saved'}
        </button>
      </form>

      {/* Change password */}
      <form onSubmit={handlePasswordChange} className="lp-prof-card">
        <div style={{ fontSize: 15, fontWeight: 800, color: '#111827', marginBottom: '.3rem' }}>Change password</div>
        <div style={{ fontSize: 12.5, color: '#9ca3af', marginBottom: '1.1rem' }}>Choose a strong password you don't use elsewhere.</div>

        <div style={{ marginBottom: '1.1rem' }}>
          <label className="lp-prof-label">Current password</label>
          <PwInput
            value={curPw}
            onChange={e => { setCurPw(e.target.value); if (pwErrors.current_password) setPwErrors(x => ({ ...x, current_password: null })) }}
            placeholder="Enter current password"
            invalid={!!pwErrors.current_password}
            autoComplete="current-password"
          />
          {pwErrors.current_password && <div className="lp-prof-err">{pwErrors.current_password}</div>}
        </div>

        <div style={{ marginBottom: '1.1rem' }}>
          <label className="lp-prof-label">New password</label>
          <PwInput
            value={newPw}
            onChange={e => { setNewPw(e.target.value); if (pwErrors.new_password) setPwErrors(x => ({ ...x, new_password: null })) }}
            placeholder="At least 8 characters"
            invalid={!!pwErrors.new_password}
            autoComplete="new-password"
          />
          {/* Strength meter */}
          {newPw && (
            <div style={{ marginTop: '.55rem' }}>
              <div style={{ display: 'flex', gap: 5 }}>
                {[1, 2, 3, 4].map(i => (
                  <div key={i} style={{
                    flex: 1, height: 5, borderRadius: 999,
                    background: i <= pwScore ? STRENGTH[pwScore].color : '#e5e7eb',
                    transition: 'background .2s',
                  }} />
                ))}
              </div>
              {STRENGTH[pwScore].label && (
                <div style={{ fontSize: 11.5, fontWeight: 600, color: STRENGTH[pwScore].color, marginTop: 4 }}>
                  {STRENGTH[pwScore].label} password
                </div>
              )}
            </div>
          )}
          {pwErrors.new_password && <div className="lp-prof-err">{pwErrors.new_password}</div>}
        </div>

        <div style={{ marginBottom: '1.25rem' }}>
          <label className="lp-prof-label">Confirm new password</label>
          <PwInput
            value={confirmPw}
            onChange={e => { setConfirmPw(e.target.value); if (pwErrors.confirm_password) setPwErrors(x => ({ ...x, confirm_password: null })) }}
            placeholder="Repeat new password"
            invalid={!!pwErrors.confirm_password}
            autoComplete="new-password"
          />
          {pwErrors.confirm_password && <div className="lp-prof-err">{pwErrors.confirm_password}</div>}
          {confirmPw && !pwErrors.confirm_password && newPw === confirmPw && (
            <div style={{ display: 'inline-flex', alignItems: 'center', gap: '.25rem', fontSize: 12, color: '#16a34a', marginTop: '.35rem', fontWeight: 600 }}><Check size={12} strokeWidth={2.5} /> Passwords match</div>
          )}
        </div>

        <button
          type="submit"
          disabled={pwSaving}
          style={{
            padding: '.62rem 1.3rem', borderRadius: 10, border: 'none',
            background: pwSaving ? '#c7cdd6' : 'var(--primary,#0a6cc4)',
            color: '#fff', fontWeight: 700, fontSize: 14, cursor: pwSaving ? 'not-allowed' : 'pointer',
            display: 'inline-flex', alignItems: 'center', gap: '.45rem',
          }}
        >
          {pwSaving ? <><Spinner /> Updating…</> : 'Change password'}
        </button>
      </form>
    </div>
  )
}
