import { useState, useEffect } from 'react'
import { notifications as notifApi } from '../api/notifications'
import { toast } from '../components/Toast'
import { MessageCircle, Mail, Bell } from 'lucide-react'

const CHANNELS = [
  { key: 'whatsapp', icon: <MessageCircle size={22} strokeWidth={1.8} />, label: 'WhatsApp alert', desc: 'Get a WhatsApp message on your phone the moment a customer replies.' },
  { key: 'email',    icon: <Mail size={22} strokeWidth={1.8} />, label: 'Email alert',    desc: 'Email the reply preview to your inbox — works anywhere.' },
  { key: 'push',     icon: <Bell size={22} strokeWidth={1.8} />, label: 'Browser push',   desc: 'Desktop notification while TripSarthi is open in your browser.' },
]

function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)
  return Uint8Array.from([...raw].map(c => c.charCodeAt(0)))
}

export default function NotificationsPage() {
  const [form, setForm] = useState({ channels: [], alert_phone: '', alert_email: '', alert_template: '', alert_template_lang: 'en' })
  const [vapidKey, setVapidKey] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [pushBusy, setPushBusy] = useState(false)

  useEffect(() => { load() }, [])
  async function load() {
    try {
      const r = await notifApi.getSettings()
      const d = r.data ?? {}
      setForm(f => ({ ...f, ...d, channels: d.channels ?? [] }))
      setVapidKey(d.vapid_public_key ?? '')
    } catch (err) { toast.error('Could not load settings', err.message) }
    setLoading(false)
  }

  const set = (k, v) => setForm(f => ({ ...f, [k]: v }))
  const has = (c) => form.channels.includes(c)
  const toggle = (c) => set('channels', has(c) ? form.channels.filter(x => x !== c) : [...form.channels, c])

  async function save() {
    setSaving(true)
    try { await notifApi.saveSettings(form); toast.success('Saved', 'Reply alerts updated.') }
    catch (err) { toast.error('Save failed', err.message) }
    setSaving(false)
  }

  async function enablePush() {
    setPushBusy(true)
    try {
      if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        toast.error('Not supported', 'This browser does not support push notifications.'); return
      }
      if (!vapidKey) { toast.error('Push not configured', 'No VAPID key from the server.'); return }
      const perm = await Notification.requestPermission()
      if (perm !== 'granted') { toast.error('Permission denied', 'Allow notifications in your browser to enable push.'); return }

      const reg = await navigator.serviceWorker.register('/sw.js')
      await navigator.serviceWorker.ready
      const sub = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidKey),
      })
      await notifApi.subscribePush(JSON.parse(JSON.stringify(sub)))
      if (!has('push')) toggle('push')
      toast.success('Browser push enabled', 'You will get a desktop alert on new replies.')
    } catch (err) {
      toast.error('Could not enable push', err.message)
    } finally { setPushBusy(false) }
  }

  if (loading) return <div style={{ padding: '2rem', color: '#6b7280' }}>Loading…</div>

  return (
    <div style={{ maxWidth: 720, margin: '0 auto', padding: '1.5rem', width: '100%' }}>
      <h2 style={{ fontSize: '1.5rem', fontWeight: 800, color: '#111827', margin: 0 }}>Reply Alerts</h2>
      <p style={{ color: '#6b7280', margin: '.35rem 0 1.4rem' }}>
        Get notified when a customer replies and you're away from the inbox. You're alerted once per new conversation (no spam).
      </p>

      {CHANNELS.map(ch => (
        <div key={ch.key} style={{ display: 'flex', alignItems: 'flex-start', gap: 12, background: '#fff', border: '1px solid #e5e7eb', borderRadius: 12, padding: '1rem 1.1rem', marginBottom: '.8rem' }}>
          <div style={{ display: 'flex', alignItems: 'center', color: '#6b7280', marginTop: 2 }}>{ch.icon}</div>
          <div style={{ flex: 1 }}>
            <div style={{ fontWeight: 700, color: '#111827' }}>{ch.label}</div>
            <div style={{ fontSize: 13, color: '#6b7280', marginTop: 2 }}>{ch.desc}</div>
            {ch.key === 'whatsapp' && has('whatsapp') && (
              <div style={{ marginTop: 10, display: 'grid', gap: 8 }}>
                <input style={inp} value={form.alert_phone} onChange={e => set('alert_phone', e.target.value)} placeholder="Your WhatsApp number (e.g. 919812345678)" />
                <input style={inp} value={form.alert_template} onChange={e => set('alert_template', e.target.value)} placeholder="Approved alert template name (optional, for reliable delivery)" />
                <span style={{ fontSize: 11.5, color: '#9ca3af' }}>Tip: without a template, alerts only deliver if your number messaged the business in the last 24h. An approved utility template (2 variables) makes it always work.</span>
              </div>
            )}
            {ch.key === 'email' && has('email') && (
              <input style={{ ...inp, marginTop: 10 }} value={form.alert_email} onChange={e => set('alert_email', e.target.value)} placeholder="Alert email address" />
            )}
            {ch.key === 'push' && (
              <button onClick={enablePush} disabled={pushBusy} style={{ ...btnGhost, marginTop: 10 }}>
                {pushBusy ? 'Enabling…' : 'Enable on this device'}
              </button>
            )}
          </div>
          <label style={{ position: 'relative', display: 'inline-block', width: 44, height: 24, flexShrink: 0 }}>
            <input type="checkbox" checked={has(ch.key)} onChange={() => toggle(ch.key)} style={{ opacity: 0, width: 0, height: 0 }} />
            <span style={{ position: 'absolute', inset: 0, cursor: 'pointer', borderRadius: 999, transition: '.2s', background: has(ch.key) ? '#08569f' : '#cbd5e1' }}>
              <span style={{ position: 'absolute', height: 18, width: 18, left: has(ch.key) ? 23 : 3, top: 3, background: '#fff', borderRadius: '50%', transition: '.2s' }} />
            </span>
          </label>
        </div>
      ))}

      <button onClick={save} disabled={saving} style={{ ...btn, marginTop: 8 }}>{saving ? 'Saving…' : 'Save alert settings'}</button>
    </div>
  )
}

const inp = { width: '100%', padding: '.5rem .7rem', border: '1px solid #d1d5db', borderRadius: 8, fontSize: 14, boxSizing: 'border-box' }
const btn = { padding: '.6rem 1.3rem', background: '#08569f', color: '#fff', border: 'none', borderRadius: 10, fontWeight: 700, fontSize: 14, cursor: 'pointer' }
const btnGhost = { padding: '.45rem 1rem', background: '#e8f3fc', color: '#08569f', border: '1px solid #bcdcf6', borderRadius: 8, fontWeight: 700, fontSize: 13, cursor: 'pointer' }
