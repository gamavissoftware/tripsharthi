import { useState, useEffect, useCallback } from 'react'
import { LogOut } from 'lucide-react'
import { ToastProvider } from '../components/Toast'
import { isLoggedIn } from '../api/client'
import { partnerApi } from '../api/partner'
import { PartnerLoginPage, PartnerSetPasswordPage } from './PartnerAuthPages'
import PartnerDashboard from './PartnerDashboard'
import './partner.css'

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
  const name = d?.partner?.name || ''
  const initials = name.split(' ').filter(Boolean).map(w => w[0]).join('').substring(0, 2).toUpperCase() || 'P'
  return (
    <div className="pt-shell">
      <header className="pt-nav"><div className="pt-nav-in">
        <div className="pt-brand"><span className="mark"><img src="/brand/tripsarthi-mark.png" alt="" width="26" height="26" /></span><span>Trip<span className="s">Sarthi</span></span><span className="pt-badge">Partners</span></div>
        <div className="pt-user">
          {name && <><span className="pt-avatar">{initials}</span><span className="nm">{name}</span></>}
          <button onClick={onLogout} className="pt-out"><LogOut size={14} /> Sign out</button>
        </div>
      </div></header>
      <main className="pt-main">
        {err && <div className="pt-warn">{err}</div>}
        {!d && !err && <><div className="pt-skel" style={{ height: 300 }} /><div className="pt-stats">{[1, 2, 3, 4].map(i => <div key={i} className="pt-skel" style={{ height: 130 }} />)}</div></>}
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
