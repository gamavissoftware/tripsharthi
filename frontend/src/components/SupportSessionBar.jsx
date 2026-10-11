import { useEffect, useState } from 'react'
import { ShieldAlert } from 'lucide-react'
import { api } from '../api/client'
import { isImpersonating, endLocalSupportSession, adminUrl } from '../lib/impersonation'

/** Shown only while a TripSarthi team member is inside this workspace through the admin app's "log in as customer". */
export default function SupportSessionBar() {
  const [info, setInfo] = useState(null)
  const on = isImpersonating()
  useEffect(() => {
    if (!on) return undefined
    let alive = true
    api.get('/auth/me').then(r => { if (alive) setInfo(r.support_session ?? null) }).catch(() => {})
    return () => { alive = false }
  }, [on])
  if (!on) return null

  async function end() {
    try { await api.post('/auth/logout', {}) } catch { /* the session may already be over */ }
    endLocalSupportSession()
    window.close()                                  // works when the admin app opened this tab
    window.location.href = adminUrl()               // otherwise go back to the admin app
  }
  return (
    <div className="imp-bar" role="status">
      <ShieldAlert size={16} />
      <span><b>TripSarthi support session</b> · {info?.view_only ? 'view only' : 'full access'}{info?.expires_at ? ` · ends ${new Date(info.expires_at.replace(' ', 'T') + 'Z').toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}` : ''}</span>
      <button type="button" onClick={end}>End session</button>
    </div>
  )
}
