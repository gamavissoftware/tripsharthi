import { Plane, MessageCircle, FileText, Megaphone, ShieldCheck } from 'lucide-react'

const POINTS = [
  [Plane, 'Enquiry → quote → booking → payment, in one place'],
  [MessageCircle, 'WhatsApp automation that follows up for you'],
  [FileText, 'GST invoices, e-invoicing & TCS built in'],
  [Megaphone, 'Run Meta & Google ads and see the real ROI'],
  [ShieldCheck, 'Your data stays yours — built for Indian compliance'],
]

/** Left-hand brand panel shared by the sign-in / sign-up screens. */
export default function AuthBrandPanel() {
  return (
    <div className="lp-auth-brand" style={S.brand}>
      <div style={S.glow} aria-hidden="true" />
      <div style={S.inner}>
        <div style={S.logoRow}>
          <span style={S.tile}><img src="/brand/tripsarthi-mark.png" alt="" width="40" height="40" style={{ display: 'block' }} /></span>
          <span style={S.name}>Trip<span style={{ color: '#2fd0c2' }}>Sarthi</span></span>
        </div>
        <h1 className="lp-auth-hide" style={S.tagline}>Your travel business.<br /><span style={S.accent}>Simplified.</span></h1>
        <div className="lp-auth-hide" style={S.list}>
          {POINTS.map(([Icon, text]) => (
            <div key={text} style={S.row}><span style={S.icon}><Icon size={15} strokeWidth={2} /></span><span style={S.text}>{text}</span></div>
          ))}
        </div>
      </div>
    </div>
  )
}

const S = {
  brand: { flex: '0 0 45%', position: 'relative', overflow: 'hidden', background: 'linear-gradient(150deg, #06214f 0%, #0a3b7d 48%, #0b7f8f 100%)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '3rem 2.5rem' },
  glow: { position: 'absolute', right: -120, bottom: -140, width: 420, height: 420, borderRadius: '50%', background: 'radial-gradient(circle, rgba(249,143,38,.35), rgba(249,143,38,0) 65%)' },
  inner: { position: 'relative', maxWidth: 400 },
  logoRow: { display: 'flex', alignItems: 'center', gap: '.7rem', marginBottom: '2.4rem' },
  tile: { width: 52, height: 52, borderRadius: 14, background: '#fff', display: 'grid', placeItems: 'center', boxShadow: '0 6px 18px rgba(0,0,0,.25)' },
  name: { fontSize: '1.6rem', fontWeight: 800, color: '#fff', letterSpacing: '-.4px' },
  tagline: { fontSize: '2.1rem', fontWeight: 750, color: '#fff', lineHeight: 1.18, letterSpacing: '-.6px', marginBottom: '2rem' },
  accent: { color: '#ffb25c' },
  list: { display: 'flex', flexDirection: 'column', gap: '.9rem' },
  row: { display: 'flex', alignItems: 'center', gap: '.75rem' },
  icon: { width: 28, height: 28, borderRadius: 8, background: 'rgba(255,255,255,.12)', color: '#bfe9ff', display: 'grid', placeItems: 'center', flexShrink: 0 },
  text: { color: '#cfe0f5', fontSize: '.92rem' },
}
