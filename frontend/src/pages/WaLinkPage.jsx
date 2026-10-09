import { useState, useEffect } from 'react'
import { Download } from 'lucide-react'
import QRCode from 'qrcode'
import { useToast } from '../components/Toast'

export default function WaLinkPage() {
  const { toast } = useToast()
  const [number, setNumber] = useState('')
  const [message, setMessage] = useState('')
  const [qr, setQr] = useState('')

  const digits = number.replace(/\D/g, '')
  const link = digits
    ? `https://wa.me/${digits}${message ? '?text=' + encodeURIComponent(message) : ''}`
    : ''

  useEffect(() => {
    if (!link) { setQr(''); return }
    QRCode.toDataURL(link, { width: 256, margin: 1 }, (err, url) => { if (!err) setQr(url) })
  }, [link])

  function copy(text, label) {
    navigator.clipboard?.writeText(text)
    toast.success(`${label} copied`)
  }

  function downloadQr() {
    if (!qr) return
    const a = document.createElement('a')
    a.href = qr
    a.download = `whatsapp-qr-${digits}.png`
    a.click()
  }

  const snippet = link
    ? `<a href="${link}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:8px;background:#25D366;color:#fff;padding:10px 18px;border-radius:8px;font-family:sans-serif;font-weight:600;text-decoration:none;">\n  <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.946C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 018.413 3.488 11.824 11.824 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24z"/></svg>\n  Chat on WhatsApp\n</a>`
    : ''

  return (
    <div className="page" style={{ maxWidth: 860 }}>
      <div className="page-header">
        <div>
          <h1 className="page-title">WhatsApp Link & QR</h1>
          <p style={{ color: 'var(--text-3)', fontSize: 13, marginTop: 4 }}>
            Create a click-to-chat link with a prefilled message, a scannable QR code, and an embeddable button.
          </p>
        </div>
      </div>

      <div className="lp-stack-mobile" style={{ display: 'grid', gridTemplateColumns: '1fr 280px', gap: '1.25rem', alignItems: 'start' }}>
        {/* Form + outputs */}
        <div className="card" style={{ padding: '1.25rem' }}>
          <label style={lbl}>WhatsApp number (with country code)</label>
          <input className="form-input" value={number} onChange={e => setNumber(e.target.value)} placeholder="+91 98765 43210" />

          <label style={{ ...lbl, marginTop: 14 }}>Prefilled message (optional)</label>
          <textarea className="form-input" style={{ minHeight: 80, resize: 'vertical' }} value={message}
            onChange={e => setMessage(e.target.value)} placeholder="Hi! I'm interested in your products." />

          {link ? (
            <>
              <div style={{ marginTop: 18 }}>
                <div style={miniLabel}>Click-to-chat link</div>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                  <input className="form-input" readOnly value={link} style={{ fontFamily: 'monospace', fontSize: 12.5 }} />
                  <button className="btn btn-ghost" onClick={() => copy(link, 'Link')}>Copy</button>
                </div>
                <a href={link} target="_blank" rel="noreferrer" style={{ fontSize: 13, marginTop: 6, display: 'inline-block' }}>Open ↗</a>
              </div>

              <div style={{ marginTop: 18 }}>
                <div style={miniLabel}>Embeddable button (HTML)</div>
                <textarea className="form-input" readOnly value={snippet} style={{ minHeight: 90, fontFamily: 'monospace', fontSize: 11.5 }} />
                <button className="btn btn-ghost" style={{ marginTop: 6 }} onClick={() => copy(snippet, 'Snippet')}>Copy snippet</button>
              </div>
            </>
          ) : (
            <div style={{ marginTop: 18, fontSize: 13, color: 'var(--text-3)' }}>
              Enter a number to generate the link & QR.
            </div>
          )}
        </div>

        {/* QR preview */}
        <div className="card" style={{ padding: '1.25rem', textAlign: 'center' }}>
          <div style={miniLabel}>QR code</div>
          {qr ? (
            <>
              <img src={qr} alt="WhatsApp QR" style={{ width: 220, height: 220, margin: '8px auto', display: 'block', borderRadius: 8 }} />
              <button className="btn btn-primary" onClick={downloadQr}><Download size={15} strokeWidth={2} /> Download PNG</button>
            </>
          ) : (
            <div style={{ width: 220, height: 220, margin: '8px auto', display: 'flex', alignItems: 'center', justifyContent: 'center', border: '2px dashed var(--border)', borderRadius: 8, color: 'var(--text-3)', fontSize: 13 }}>
              QR preview
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

const lbl = { display: 'block', fontSize: 13, fontWeight: 600, color: '#374151', marginBottom: 6 }
const miniLabel = { fontSize: 12, fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '.04em', marginBottom: 6 }
