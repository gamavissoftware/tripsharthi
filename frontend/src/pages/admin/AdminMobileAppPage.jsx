import { useEffect, useState } from 'react'
import { admin } from '../../api/admin'
import { toast } from '../../components/Toast'
import { Smartphone, Link2, CheckCircle2, CircleSlash } from 'lucide-react'
import { Kpi } from './AdminUi'

/** Where to download the phone app. Saving a link makes the "Get the app" buttons appear on the customer login screen and on tripsarthi.com. */
export default function AdminMobileAppPage() {
  const [st, setSt] = useState(null)
  const [f, setF] = useState({ android: '', ios: '' })
  const [busy, setBusy] = useState(false)
  const fill = (d) => setF({ android: d.android || '', ios: d.ios || '' })
  useEffect(() => { admin.mobileApp().then(d => { setSt(d); fill(d) }).catch(e => toast.error('Could not load', e.message)) }, [])

  async function save(e) {
    e.preventDefault(); setBusy(true)
    try { const d = await admin.saveMobileApp(f); setSt(d); fill(d); try { sessionStorage.removeItem('ts_mobile_app') } catch { /* ignore */ } toast.success(d.available ? 'Saved - the download buttons are live' : 'Saved - no download links, so the buttons are hidden') }
    catch (err) { toast.error('Not saved', err.message) }
    setBusy(false)
  }
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value })

  if (!st) return <div className="page"><div className="page-header"><h1 className="page-title">Mobile app</h1></div><div className="card card-body" style={{ color: 'var(--text-3)' }}>Loading…</div></div>
  return (
    <div className="page">
      <div className="page-header"><div><h1 className="page-title">Mobile app</h1><p className="page-sub">Where customers download the TripSarthi phone app. Leave a box empty to hide that button.</p></div></div>

      <div className="card card-body" style={{ marginBottom: 14, display: 'flex', alignItems: 'center', gap: 12, borderLeft: `4px solid ${st.available ? '#059669' : '#d97706'}` }}>
        {st.available ? <CheckCircle2 color="#059669" aria-hidden="true" /> : <CircleSlash color="#d97706" aria-hidden="true" />}
        <div><b>{st.available ? 'Download buttons are showing' : 'Download buttons are hidden'}</b>
          <div style={{ color: 'var(--text-3)', fontSize: 13 }}>{st.available ? 'They appear on the customer sign-in screen (app.tripsarthi.com) and on tripsarthi.com (home page and footer).' : 'Nothing is shown to visitors until you save at least one link below - so it is safe to leave this empty until the app is published.'}</div></div>
      </div>

      <div className="stat-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(200px,1fr))', gap: 12, marginBottom: 14 }}>
        <Kpi icon={Smartphone} tone={st.android ? '#059669' : '#d97706'} label="Android" value={st.android ? 'Link saved' : 'No link'} />
        <Kpi icon={Smartphone} tone={st.ios ? '#059669' : '#d97706'} label="iPhone" value={st.ios ? 'Link saved' : 'No link'} />
      </div>

      <form className="card card-body" onSubmit={save} noValidate style={{ display: 'grid', gap: 14, maxWidth: 760 }}>
        <div className="form-group" style={{ margin: 0 }}>
          <label className="form-label" htmlFor="ma-android">Android download link</label>
          <input id="ma-android" className="form-input" type="url" inputMode="url" placeholder="https://play.google.com/store/apps/details?id=…" value={f.android} onChange={set('android')} autoComplete="off" />
          <small style={{ color: 'var(--text-3)' }}>The Google Play page, or a direct https link to the installable file while you are still testing.</small>
        </div>
        <div className="form-group" style={{ margin: 0 }}>
          <label className="form-label" htmlFor="ma-ios">iPhone download link</label>
          <input id="ma-ios" className="form-input" type="url" inputMode="url" placeholder="https://apps.apple.com/in/app/…" value={f.ios} onChange={set('ios')} autoComplete="off" />
          <small style={{ color: 'var(--text-3)' }}>The App Store page, or a TestFlight invitation link (testflight.apple.com).</small>
        </div>
        <div><button className="btn btn-primary" disabled={busy}><Link2 size={15} aria-hidden="true" /> {busy ? 'Saving…' : 'Save links'}</button></div>
      </form>
    </div>
  )
}
