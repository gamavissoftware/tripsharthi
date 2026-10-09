import { useState, useEffect, useCallback } from 'react'
import { travel } from '../api/travel'
import { toast } from '../components/Toast'

const REASON = { category_off: 'category turned off', notifications_off: 'notifications off', quiet_hours: 'quiet hours', throttled: 'too many at once', no_device: 'no phone registered', staff_only: 'owners & admins only', DeviceNotRegistered: 'phone unreachable', duplicate: 'duplicate' }
const COLOR = { sent: '#15803d', delivered: '#15803d', queued: '#a16207', suppressed: '#64748b', error: '#b91c1c', failed: '#b91c1c' }

export default function MobileNotificationsPage() {
  const [d, setD] = useState(null)
  const [devices, setDevices] = useState([])
  const [log, setLog] = useState([])
  const [quiet, setQuiet] = useState({ start: '', end: '' })

  const load = useCallback(() => Promise.all([travel.pushPrefs(), travel.pushDevices(), travel.pushLog()]).then(([p, dv, l]) => {
    setD(p); setDevices(dv); setLog(l); setQuiet({ start: p.preferences.quiet_start || '', end: p.preferences.quiet_end || '' })
  }).catch(e => toast.error('Could not load', e.message)), [])
  useEffect(() => { Promise.resolve().then(load) }, [load])
  if (!d) return <div className="page">Loading…</div>
  const pr = d.preferences

  async function save(patch) {
    try { const r = await travel.savePushPrefs(patch); setD(x => ({ ...x, preferences: r })) } catch (e) { toast.error('Not saved', e.message); load() }
  }
  async function test() {
    try { const r = await travel.pushTest(); toast.success('Test sent', `to ${r.devices} phone${r.devices > 1 ? 's' : ''}`); setTimeout(load, 2000) } catch (e) { toast.error('Could not send', e.message) }
  }
  const saveQuiet = () => (quiet.start && quiet.end) || (!quiet.start && !quiet.end) ? save({ quiet_start: quiet.start || null, quiet_end: quiet.end || null }) : toast.error('Use both times', 'Set a start and an end, or clear both.')

  return (
    <div className="page" style={{ maxWidth: 760 }}>
      <div className="page-header"><h1 className="page-title">Mobile notifications</h1></div>
      <div className="card card-body" style={{ marginBottom: 14 }}>
        <p style={{ margin: 0 }}>Get alerts on your phone from the TripSarthi app: new leads, customer replies, quote views, payments and more. These settings apply to <b>your</b> account only.</p>
      </div>

      <div className="card card-body" style={{ marginBottom: 14 }}>
        <h3 style={{ marginTop: 0 }}>Your phones</h3>
        {devices.length === 0 ? <p style={{ color: 'var(--text-3)' }}>No phone yet. Install the TripSarthi app, sign in and allow notifications.</p> : devices.map(x => (
          <div key={x.id} style={{ display: 'flex', justifyContent: 'space-between', padding: '8px 0', borderTop: '1px solid var(--border)' }}>
            <span><b>{x.device_name || 'Phone'}</b> <small style={{ color: 'var(--text-3)' }}>{x.platform} · app {x.app_version || '?'}</small></span>
            <small style={{ color: 'var(--text-3)' }}>last seen {x.last_seen_at?.slice(0, 16)}</small>
          </div>))}
        <button className="btn btn-ghost" style={{ marginTop: 10 }} disabled={!devices.length} onClick={test}>Send me a test</button>
      </div>

      <div className="card card-body" style={{ marginBottom: 14 }}>
        <label style={{ display: 'flex', gap: 8, alignItems: 'center', fontWeight: 700 }}><input type="checkbox" checked={pr.enabled} onChange={e => save({ enabled: e.target.checked })} /> Send me notifications</label>
        <div style={{ opacity: pr.enabled ? 1 : .45, marginTop: 8 }}>
          {d.categories.map(c => (
            <label key={c.key} style={{ display: 'flex', gap: 10, padding: '8px 0', borderTop: '1px solid var(--border)', alignItems: 'flex-start' }}>
              <input type="checkbox" disabled={!pr.enabled} checked={!!pr.categories[c.key]} onChange={e => save({ categories: { [c.key]: e.target.checked } })} style={{ marginTop: 4 }} />
              <span><b>{c.label}</b><div style={{ fontSize: 13, color: 'var(--text-3)' }}>{c.hint}</div></span>
            </label>))}
        </div>
      </div>

      <div className="card card-body" style={{ marginBottom: 14 }}>
        <h3 style={{ marginTop: 0 }}>Quiet hours &amp; privacy</h3>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
          <span>No alerts between</span>
          <input className="form-input" type="time" style={{ width: 130 }} value={quiet.start} onChange={e => setQuiet(q => ({ ...q, start: e.target.value }))} onBlur={saveQuiet} />
          <span>and</span>
          <input className="form-input" type="time" style={{ width: 130 }} value={quiet.end} onChange={e => setQuiet(q => ({ ...q, end: e.target.value }))} onBlur={saveQuiet} />
          <small style={{ color: 'var(--text-3)' }}>India time. Everything still shows in your inbox.</small>
        </div>
        <label style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 12 }}><input type="checkbox" checked={pr.privacy === 'minimal'} onChange={e => save({ privacy: e.target.checked ? 'minimal' : 'full' })} /> Hide names and amounts on the lock screen</label>
      </div>

      <div className="card card-body">
        <h3 style={{ marginTop: 0 }}>Recent activity <small style={{ fontWeight: 400, color: 'var(--text-3)' }}>— including why something was not sent</small></h3>
        {log.length === 0 ? <p style={{ color: 'var(--text-3)' }}>Nothing yet.</p> : log.slice(0, 20).map(l => (
          <div key={l.id} style={{ display: 'flex', justifyContent: 'space-between', gap: 10, padding: '6px 0', borderTop: '1px solid var(--border)', fontSize: 13.5 }}>
            <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{l.title}</span>
            <span style={{ color: COLOR[l.status] || '#64748b', whiteSpace: 'nowrap' }}>{l.status}{l.reason ? ` · ${REASON[l.reason] || l.reason}` : ''} · {l.created_at?.slice(5, 16)}</span>
          </div>))}
      </div>
    </div>
  )
}
