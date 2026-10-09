import { useState, useEffect, useCallback } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { travel, inr } from '../api/travel'
import { toast } from '../components/Toast'
import { metaAds } from '../api/metaAds'
import { social as socialApi } from '../api/social'
import { Link } from 'react-router-dom'

const EVENT_LABEL = { Lead: 'Enquiry received', QuoteSent: 'Quote sent', Purchase: 'Booking confirmed (with value)' }
const DELIVERY = { sent: ['#dcfce7', '#15803d'], pending: ['#fef9c3', '#a16207'], failed: ['#fee2e2', '#b91c1c'], skipped: ['#f1f5f9', '#475569'] }

function Badge({ ok, children }) {
  return <span style={{ background: ok ? '#dcfce7' : '#f1f5f9', color: ok ? '#15803d' : '#475569', padding: '3px 10px', borderRadius: 999, fontSize: 12, fontWeight: 700 }}>{children}</span>
}

function EventToggles({ names, value, onChange }) {
  return (
    <div className="form-group">
      <label className="form-label">Report these events</label>
      <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap' }}>
        {names.map(n => (
          <label key={n} style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
            <input type="checkbox" checked={value.includes(n)} onChange={e => onChange(e.target.checked ? [...value, n] : value.filter(x => x !== n))} /> {EVENT_LABEL[n] || n}
          </label>))}
      </div>
    </div>
  )
}

function MetaCard({ st, names, reload }) {
  const [f, setF] = useState({ dataset_id: st.dataset_id, access_token: '', waba_id: st.waba_id, test_event_code: st.test_event_code, events: st.events })
  const [busy, setBusy] = useState('')
  const set = (k) => (e) => setF(s => ({ ...s, [k]: e.target.value }))
  const run = async (key, fn, ok) => { setBusy(key); try { const r = await fn(); if (ok) toast.success(ok(r)); return r } catch (e) { toast.error('Not saved', e.message) } finally { setBusy('') } }

  return (
    <div className="card card-body" style={{ marginBottom: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <h3 style={{ margin: 0 }}>🟦 Meta — Conversions API</h3><Badge ok={st.connected}>{st.connected ? `Connected · ${st.dataset_name || st.dataset_id}` : 'Not connected'}</Badge>
      </div>
      <p style={{ color: 'var(--text-2)' }}>Tells Meta which leads became quotes and bookings, so your ads find more people who actually book. Works for lead ads and Click-to-WhatsApp ads.</p>
      <form onSubmit={async (e) => { e.preventDefault(); await run('save', async () => { const r = await travel.saveMeta(f); setF(s => ({ ...s, access_token: '' })); await reload(); return r }, r => `Verified with Meta: ${r.dataset_name || r.dataset_id}`) }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(240px,1fr))', gap: 12 }}>
          <div className="form-group"><label className="form-label">Dataset (Pixel) ID *</label><input className="form-input" value={f.dataset_id} onChange={set('dataset_id')} placeholder="e.g. 1234567890123456" inputMode="numeric" /></div>
          <div className="form-group"><label className="form-label">Access token {st.has_token ? '(saved — leave blank to keep)' : '*'}</label>
            <input className="form-input" type="password" autoComplete="off" value={f.access_token} onChange={set('access_token')} placeholder={st.has_token ? '••••••••••••' : 'Paste system-user token'} /></div>
          <div className="form-group"><label className="form-label">WhatsApp Business Account ID <small style={{ color: 'var(--text-3)' }}>(Click-to-WhatsApp)</small></label><input className="form-input" value={f.waba_id} onChange={set('waba_id')} inputMode="numeric" /></div>
          <div className="form-group"><label className="form-label">Test Event Code <small style={{ color: 'var(--text-3)' }}>(for the test button)</small></label><input className="form-input" value={f.test_event_code} onChange={set('test_event_code')} placeholder="TEST12345" /></div>
        </div>
        <EventToggles names={names} value={f.events} onChange={(events) => setF(s => ({ ...s, events }))} />
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button className="btn btn-primary" disabled={busy === 'save'}>{busy === 'save' ? 'Verifying with Meta…' : 'Save & verify'}</button>
          {st.connected && <button type="button" className="btn btn-ghost" disabled={busy === 'test'} onClick={() => run('test', () => travel.testMeta(), r => `Meta received ${r.events_received} test event — check Events Manager → Test events`)}>{busy === 'test' ? 'Sending…' : 'Send test event'}</button>}
          {st.connected && <button type="button" className="btn btn-ghost" style={{ color: 'var(--danger)' }} onClick={async () => { if (window.confirm('Disconnect Meta? Events will stop being reported.')) { await run('d', () => travel.disconnectAd('meta'), () => 'Disconnected'); reload() } }}>Disconnect</button>}
        </div>
      </form>
      <details style={{ marginTop: 14, color: 'var(--text-2)', fontSize: 13.5 }}>
        <summary style={{ cursor: 'pointer', fontWeight: 700 }}>Where do I find these?</summary>
        <ol style={{ paddingLeft: 18 }}>
          <li>Events Manager → your dataset → <b>Settings</b> → Conversions API → <b>Generate access token</b> (or use a System User token from Business Settings).</li>
          <li>The Dataset ID is shown at the top of the dataset page.</li>
          <li>To test: Events Manager → <b>Test events</b> → copy the Test Event Code, paste it here, press “Send test event”.</li>
          <li>“Conversion Leads” optimisation works only with instant-form lead ads and needs roughly 200 leads a month; the model learns over 2–4 weeks.</li>
        </ol>
      </details>
    </div>
  )
}


/** Meta campaign MANAGEMENT: the same Facebook Login, but asking for ads_management + pages_manage_ads. */
function MetaManageCard({ pickerOpen, onPickerDone }) {
  const [conn, setConn] = useState(null)
  const [accounts, setAccounts] = useState(null)
  const [chosen, setChosen] = useState([])
  const [busy, setBusy] = useState(false)
  const reload = useCallback(() => travel.adConnection().then(setConn).catch(() => {}), [])
  useEffect(() => { reload() }, [reload])
  useEffect(() => {
    if (!pickerOpen) return
    metaAds.accounts().then(r => { setAccounts(r.data ?? []); setChosen((r.data ?? []).slice(0, 1).map(a => a.id)) }).catch(e => toast.error('Could not list ad accounts', e.message))
  }, [pickerOpen])

  async function connect() {
    setBusy(true)
    try { const r = await socialApi.oauthStart('ads_manage'); window.location.href = r.data.auth_url } catch (e) { toast.error('Could not start Meta login', e.message); setBusy(false) }
  }
  async function savePick() {
    try { await metaAds.select((accounts || []).filter(a => chosen.includes(a.id)).map(a => ({ id: a.id, name: a.name, currency: a.currency }))); toast.success('Ad accounts saved'); setAccounts(null); onPickerDone(); reload() }
    catch (e) { toast.error('Not saved', e.message) }
  }
  const m = conn?.meta
  return (
    <div className="card card-body" style={{ marginBottom: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <h3 style={{ margin: 0 }}>🟦 Meta — campaign management</h3>
        <Badge ok={!!m?.connected && m.can_manage !== false}>{m?.connected ? (m.can_manage === false ? 'Reporting only' : 'Can manage campaigns') : 'Not connected'}</Badge>
      </div>
      <p style={{ color: 'var(--text-2)' }}>Create, launch, pause and budget Facebook/Instagram campaigns from TripSarthi. Needs a Meta login with <b>ads_management</b> permission. Everything starts paused, and your spend cap applies.</p>
      {conn?.mock && <div style={{ background: '#fef9c3', padding: 10, borderRadius: 10, marginBottom: 10 }}>🧪 Demo mode: Meta is simulated, so no login is needed.</div>}
      {m?.error && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 10, marginBottom: 10 }}>{m.error}</div>}
      {accounts && (
        <div style={{ marginBottom: 10 }}>
          <label className="form-label">Which ad accounts should TripSarthi manage?</label>
          {accounts.length === 0 && <p>No ad accounts were found for this login.</p>}
          {accounts.map(a => <label key={a.id} style={{ display: 'block' }}><input type="checkbox" checked={chosen.includes(a.id)} onChange={e => setChosen(c => e.target.checked ? [...c, a.id] : c.filter(x => x !== a.id))} /> {a.name} <small style={{ color: 'var(--text-3)' }}>({a.currency})</small></label>)}
          <button className="btn btn-primary btn-sm" style={{ marginTop: 8 }} disabled={!chosen.length} onClick={savePick}>Save accounts</button>
        </div>)}
      {m?.connected && m.ad_accounts?.length > 0 && <p style={{ margin: '0 0 10px' }}>Managing: {m.ad_accounts.map(a => a.name).join(', ')} · {m.pages?.length || 0} Page(s) available</p>}
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        {!conn?.mock && <button className="btn btn-primary" disabled={busy} onClick={connect}>{m?.connected ? 'Reconnect with campaign permission' : 'Connect Meta'}</button>}
        {(m?.connected || conn?.mock) && <Link className="btn btn-ghost" to="/ads/campaigns">Open Ad campaigns →</Link>}
      </div>
      <small style={{ display: 'block', marginTop: 8, color: 'var(--text-3)' }}>For customers other than your own team, Meta requires App Review for ads_management and Business Verification — start these early; they take weeks.</small>
    </div>
  )
}

function GoogleCard({ st, names, reload }) {
  const [customers, setCustomers] = useState(null)
  const [cid, setCid] = useState(st.customer_id)
  const [mcc, setMcc] = useState(st.login_customer_id)
  const [actions, setActions] = useState(null)
  const [map, setMap] = useState({ ...(st.conversion_actions || {}) })
  const [events, setEvents] = useState(st.events)
  const [busy, setBusy] = useState('')
  const run = async (key, fn, ok) => { setBusy(key); try { const r = await fn(); if (ok) toast.success(ok(r)); return r } catch (e) { toast.error('Failed', e.message) } finally { setBusy('') } }

  useEffect(() => { if (st.connected && !st.customer_id) travel.googleCustomers().then(setCustomers).catch(() => setCustomers([])) }, [st.connected, st.customer_id])
  useEffect(() => { if (st.customer_id) travel.googleActions().then(setActions).catch(e => { setActions([]); toast.error('Could not load conversion actions', e.message) }) }, [st.customer_id])

  return (
    <div className="card card-body" style={{ marginBottom: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <h3 style={{ margin: 0 }}>🟥 Google Ads — offline conversions</h3>
        <Badge ok={st.connected && !!st.customer_id}>{st.connected ? (st.customer_id ? `Connected · ${st.customer_id}` : 'Choose an account') : 'Not connected'}</Badge>
      </div>
      <p style={{ color: 'var(--text-2)' }}>Reports quotes and bookings back to Google using the click ID captured from your ads, so Smart Bidding learns which clicks become trips.</p>

      {!st.server_configured && <div style={{ background: '#fef9c3', padding: 12, borderRadius: 10 }}>Google Ads isn’t set up on this server yet. An administrator must add <code>GOOGLE_ADS_CLIENT_ID</code> and <code>GOOGLE_ADS_CLIENT_SECRET</code> (OAuth client) to the backend environment, with this redirect URI authorised: <code style={{ wordBreak: 'break-all' }}>{st.redirect_uri}</code></div>}

      {st.server_configured && !st.connected && (
        <button className="btn btn-primary" disabled={busy === 'go'} onClick={async () => { const r = await run('go', () => travel.googleStart()); if (r?.auth_url) window.location.href = r.auth_url }}>Connect with Google</button>)}

      {st.connected && !st.customer_id && (
        <div>
          <div className="form-group"><label className="form-label">Google Ads account</label>
            {customers === null ? <small>Loading your accounts…</small> : customers.length > 0 ? (
              <select className="form-select" value={cid} onChange={e => setCid(e.target.value)}><option value="">Choose…</option>{customers.map(c => <option key={c.id} value={c.id}>{c.id}</option>)}</select>
            ) : <input className="form-input" placeholder="10-digit customer ID" value={cid} onChange={e => setCid(e.target.value)} />}</div>
          <div className="form-group"><label className="form-label">Manager (MCC) account ID <small style={{ color: 'var(--text-3)' }}>— only if you access this account through a manager account</small></label>
            <input className="form-input" value={mcc} onChange={e => setMcc(e.target.value)} placeholder="optional" /></div>
          <button className="btn btn-primary" disabled={!cid || busy === 'acct'} onClick={async () => { await run('acct', () => travel.googleAccount({ customer_id: cid, login_customer_id: mcc }), () => 'Account saved'); reload() }}>Use this account</button>
        </div>)}

      {st.connected && st.customer_id && (
        <div>
          <h4 style={{ marginBottom: 6 }}>Match events to Google conversion actions</h4>
          {actions === null && <small>Loading conversion actions…</small>}
          {actions?.length === 0 && <div style={{ background: '#fef9c3', padding: 12, borderRadius: 10, marginBottom: 10 }}>No “Import from clicks” conversion actions found. In Google Ads: Goals → Conversions → New conversion action → <b>Import</b> → <b>CRM or other data sources → Track conversions from clicks</b>. Create one per event, then reload this page.</div>}
          {actions?.length > 0 && names.map(n => (
            <div key={n} style={{ display: 'flex', gap: 12, alignItems: 'center', padding: '8px 0', borderTop: '1px solid var(--border)', flexWrap: 'wrap' }}>
              <div style={{ flex: '1 1 200px' }}><b>{EVENT_LABEL[n] || n}</b></div>
              <select className="form-select" style={{ flex: '2 1 240px' }} value={map[n] || ''} onChange={e => setMap(m => ({ ...m, [n]: e.target.value }))}>
                <option value="">Don’t report</option>{actions.map(a => <option key={a.resource_name} value={a.resource_name}>{a.name}</option>)}
              </select>
            </div>))}
          <EventToggles names={names} value={events} onChange={setEvents} />
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <button className="btn btn-primary" disabled={busy === 'map' || !actions?.length} onClick={() => run('map', async () => { const r = await travel.googleMapping({ conversion_actions: map, events }); await reload(); return r }, () => 'Mapping saved')}>Save mapping</button>
            <button className="btn btn-ghost" onClick={async () => { await run('acct', () => travel.googleAccount({ customer_id: '' })); reload() }}>Change account</button>
          </div>
        </div>)}

      {st.connected && <div style={{ marginTop: 12 }}><button className="btn btn-ghost" style={{ color: 'var(--danger)' }} onClick={async () => { if (window.confirm('Disconnect Google Ads?')) { await run('d', () => travel.disconnectAd('google'), () => 'Disconnected'); reload() } }}>Disconnect</button></div>}
      <small style={{ display: 'block', marginTop: 10, color: 'var(--text-3)' }}>Google accepts click IDs up to 90 days old. Leads that didn’t come from a Google ad (no click ID) are skipped automatically.</small>
    </div>
  )
}

function DeliveryLog() {
  const [d, setD] = useState(null)
  const load = useCallback(() => travel.deliveries().then(setD).catch(() => {}), [])
  useEffect(() => { load() }, [load])
  if (!d) return null
  const c = d.counts || {}
  return (
    <div className="card card-body">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
        <h3 style={{ margin: 0 }}>Delivery log</h3>
        <span style={{ color: 'var(--text-2)' }}>Sent <b>{c.sent || 0}</b> · Pending <b>{c.pending || 0}</b> · Failed <b>{c.failed || 0}</b> · Skipped <b>{c.skipped || 0}</b></span>
      </div>
      {d.events.length === 0 ? <p style={{ color: 'var(--text-3)' }}>Nothing yet. Events appear here when a lead that came from an ad becomes an enquiry, a quote or a booking.</p> : (
        <div style={{ overflowX: 'auto' }}><table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13.5, marginTop: 8 }}>
          <thead><tr style={{ textAlign: 'left', color: 'var(--text-3)', fontSize: 12, textTransform: 'uppercase' }}>{['When', 'Platform', 'Event', 'Value', 'Status', ''].map(h => <th key={h} style={{ padding: '8px 10px' }}>{h}</th>)}</tr></thead>
          <tbody>{d.events.map(e => (
            <tr key={e.id} style={{ borderTop: '1px solid var(--border)' }} title={e.response || ''}>
              <td style={{ padding: '8px 10px' }}>{e.event_time?.slice(0, 16)}</td><td style={{ padding: '8px 10px' }}>{e.platform}</td><td style={{ padding: '8px 10px' }}>{e.event_name}</td>
              <td style={{ padding: '8px 10px' }}>{Number(e.value_amount) ? inr(e.value_amount) : '—'}</td>
              <td style={{ padding: '8px 10px' }}><span style={{ background: DELIVERY[e.status]?.[0], color: DELIVERY[e.status]?.[1], padding: '2px 8px', borderRadius: 999, fontSize: 12, fontWeight: 700 }}>{e.status}</span></td>
              <td style={{ padding: '8px 10px' }}>{['failed', 'skipped'].includes(e.status) && <button className="btn btn-sm btn-ghost" onClick={async () => { try { await travel.retryDelivery(e.id); toast.success('Queued for retry'); load() } catch (er) { toast.error('Failed', er.message) } }}>Retry</button>}</td>
            </tr>))}</tbody></table></div>)}
    </div>
  )
}

export default function AdPlatformsPage() {
  const [st, setSt] = useState(null)
  const [err, setErr] = useState('')
  const [pickerOpen, setPickerOpen] = useState(false)
  const { search } = useLocation()
  const nav = useNavigate()
  const reload = useCallback(() => travel.adPlatforms().then(setSt).catch(e => setErr(e.message)), [])
  useEffect(() => { reload() }, [reload])

  // Back from Google's consent screen: #/settings/ads?google_connected=1 | ?google_error=...
  useEffect(() => {
    const q = new URLSearchParams(search)
    if (q.get('google_connected')) { toast.success('Google connected', 'Now choose your ad account.'); nav('/settings/ads', { replace: true }); reload() }
    else if (q.get('meta_ads_state')) {
      const state = q.get('meta_ads_state'); nav('/settings/ads', { replace: true })
      metaAds.connect(state).then(() => { toast.success('Meta connected', 'Now choose the ad accounts to manage.'); setPickerOpen(true) }).catch(e => toast.error('Could not connect Meta', e.message))
    }
    else if (q.get('meta_ads_error')) { toast.error('Meta connection failed', q.get('meta_ads_error')); nav('/settings/ads', { replace: true }) }
    else if (q.get('google_error')) { toast.error('Google connection failed', q.get('google_error')); nav('/settings/ads', { replace: true }) }
  }, [search, nav, reload])

  if (err) return <div className="page"><div className="card card-body">{err.includes('403') || /permission|forbidden/i.test(err) ? 'Only owners and admins can manage ad platforms.' : err}</div></div>
  if (!st) return <div className="page">Loading…</div>
  return (
    <div className="page" style={{ maxWidth: 860 }}>
      <div className="page-header"><h1 className="page-title">Ad platforms</h1></div>
      <div className="card card-body" style={{ marginBottom: 16 }}>
        <p style={{ margin: 0 }}>Connect your own Meta and Google accounts so TripSarthi can report <b>enquiries, quotes and confirmed bookings</b> back to the ad platforms. Your credentials are encrypted and never shown again after saving. Lead capture from Meta and Google lead forms is set up under <a href="#/settings/integrations">Integrations</a>.</p>
      </div>
      <MetaCard st={st.meta} names={st.event_names} reload={reload} key={'m' + st.meta.dataset_id + st.meta.has_token} />
      <MetaManageCard pickerOpen={pickerOpen} onPickerDone={() => setPickerOpen(false)} />
      <GoogleCard st={st.google} names={st.event_names} reload={reload} key={'g' + st.google.connected + st.google.customer_id} />
      <DeliveryLog />
    </div>
  )
}
