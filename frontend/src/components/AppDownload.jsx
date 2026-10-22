import { useEffect, useState } from 'react'
import { Smartphone, Download } from 'lucide-react'

const KEY = 'ts_mobile_app', TTL = 5 * 60 * 1000

/** Reads the public store links once per five minutes per tab. Never throws: a failure just means "no app to show". */
function load() {
  try { const c = JSON.parse(sessionStorage.getItem(KEY) || 'null'); if (c && Date.now() - c.at < TTL) return Promise.resolve(c.d) } catch { /* storage blocked */ }
  return fetch('/api/v1/public/mobile-app', { headers: { Accept: 'application/json' } })
    .then(r => (r.ok ? r.json() : null)).then(j => j && j.data)
    .then(d => { if (d) { try { sessionStorage.setItem(KEY, JSON.stringify({ at: Date.now(), d })) } catch { /* ignore */ } } return d })
}

function Btn({ href, label, sub }) {
  return (
    <a href={href} target="_blank" rel="noopener noreferrer"
      style={{ flex: '1 1 150px', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 10, padding: '.6rem .9rem', border: '1px solid var(--border, #cbd5e1)', borderRadius: 10, background: '#fff', color: '#0a1f44', textDecoration: 'none' }}>
      <Download size={18} aria-hidden="true" />
      <span style={{ display: 'grid', lineHeight: 1.2, textAlign: 'left' }}><small style={{ fontSize: 10.5, color: '#64748b' }}>{sub}</small><b style={{ fontSize: 14 }}>{label}</b></span>
    </a>)
}

/**
 * "Get the TripSarthi app" on the sign-in screen. It draws NOTHING until the TripSarthi team has saved a download link
 * (admin app -> Mobile app), so it is safe to ship before the phone app is published.
 */
export default function AppDownload({ style }) {
  const [d, setD] = useState(null)
  useEffect(() => { let on = true; load().then(x => { if (on) setD(x) }).catch(() => {}); return () => { on = false } }, [])
  if (!d || !d.available) return null
  return (
    <section aria-label="Get the TripSarthi app" style={{ marginTop: '1.5rem', paddingTop: '1.25rem', borderTop: '1px solid var(--border, #e5e7eb)', ...style }}>
      <p style={{ display: 'flex', alignItems: 'center', gap: 8, margin: '0 0 .6rem', fontWeight: 700, fontSize: '.9rem' }}><Smartphone size={16} aria-hidden="true" /> Get the TripSarthi app</p>
      <p style={{ margin: '0 0 .75rem', fontSize: '.8125rem', color: 'var(--text-3, #6b7280)' }}>Enquiries, WhatsApp chats and payments due, on your phone.</p>
      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
        {d.android && <Btn href={d.android} sub="Download for" label="Android" />}
        {d.ios && <Btn href={d.ios} sub="Download for" label="iPhone" />}
      </div>
    </section>
  )
}
