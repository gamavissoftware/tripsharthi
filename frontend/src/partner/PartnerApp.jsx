import { useState, useEffect, useCallback } from 'react'
import { LogOut } from 'lucide-react'
import { ToastProvider } from '../components/Toast'
import { isLoggedIn } from '../api/client'
import { partnerApi } from '../api/partner'
import { PartnerLoginPage, PartnerSetPasswordPage } from './PartnerAuthPages'
import PartnerDashboard from './PartnerDashboard'

/** #/set-password?token=<48 hex> from the invite link */
function inviteToken() {
  const m = window.location.hash.match(/^#\/set-password\?(?:.*&)?token=([a-f0-9]{48})(?:&|$)/)
  return m ? m[1] : null
}

function Portal({ onLogout }) {
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const load = useCallback(() => partnerApi.dashboard().then(r => { setD(r); setErr('') }).catch(e => { if (e.status !== 401) setErr(e.message || 'Could not load your dashboard.') }), [])
  useEffect(() => { load() }, [load])
  return (
    <div style={{ minHeight: '100vh', background: 'var(--bg)' }}>
      <header style={{ background: '#0a1f44', color: '#fff', padding: '12px 20px', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <span style={{ background: '#fff', borderRadius: 8, padding: 3, display: 'grid', placeItems: 'center' }}><img src="/brand/tripsarthi-mark.png" alt="" width="26" height="26" /></span>
          <b style={{ fontSize: '1.1rem' }}>Trip<span style={{ color: '#2fd0c2' }}>Sarthi</span></b>
          <span style={{ fontSize: '.65rem', fontWeight: 800, letterSpacing: '.6px', textTransform: 'uppercase', background: '#12a89e', padding: '2px 7px', borderRadius: 6 }}>Partners</span>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 14, fontSize: 13 }}>
          <span style={{ opacity: .85 }}>{d?.partner?.name}</span>
          <button onClick={onLogout} className="btn btn-sm" style={{ background: 'rgba(255,255,255,.12)', color: '#fff', border: 0, display: 'flex', gap: 6, alignItems: 'center' }}><LogOut size={14} /> Sign out</button>
        </div>
      </header>
      <main style={{ maxWidth: 1040, margin: '0 auto', padding: '20px 16px' }}>
        <h1 style={{ fontSize: '1.4rem', margin: '0 0 16px' }}>{d ? `Welcome, ${d.partner.name.split(' ')[0]}` : 'Partner dashboard'}</h1>
        {err && <div className="form-error" style={{ marginBottom: 12 }}>{err}</div>}
        {!d && !err && <p style={{ color: 'var(--text-3)' }}>Loading…</p>}
        {d && <PartnerDashboard d={d} reload={load} />}
      </main>
    </div>
  )
}

export default function PartnerApp() {
  const [authed, setAuthed] = useState(isLoggedIn)
  const [token, setToken] = useState(inviteToken)
  const [note, setNote] = useState('')

  useEffect(() => {
    const out = () => setAuthed(false)                                            // any 401 (expired / revoked session) lands here
    const onHash = () => setToken(inviteToken())
    window.addEventListener('tp:unauthorized', out); window.addEventListener('hashchange', onHash)
    return () => { window.removeEventListener('tp:unauthorized', out); window.removeEventListener('hashchange', onHash) }
  }, [])

  async function logout() { await partnerApi.logout(); setAuthed(false); window.location.hash = '#/' }

  return (
    <ToastProvider>
      {token
        ? <PartnerSetPasswordPage token={token} onDone={(_p, signedIn) => { window.location.hash = '#/'; setToken(null); setAuthed(signedIn); if (!signedIn) setNote('Password saved. Your account is not active, so you cannot sign in yet.') }} />
        : authed
          ? <Portal onLogout={logout} />
          : <><PartnerLoginPage onLogin={() => { setAuthed(true); setNote('') }} />{note && <div style={{ position: 'fixed', bottom: 16, left: 16, right: 16, textAlign: 'center', color: '#fff' }}>{note}</div>}</>}
    </ToastProvider>
  )
}
