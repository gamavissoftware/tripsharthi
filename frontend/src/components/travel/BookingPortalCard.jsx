import { useState } from 'react'
import { travel } from '../../api/travel'
import { toast } from '../Toast'

/** Staff controls for the customer portal link of one booking. */
export default function BookingPortalCard({ bookingId, cancelled }) {
  const [url, setUrl] = useState('')
  const [busy, setBusy] = useState(false)
  const get = async (rotate = false) => {
    if (rotate && !window.confirm('Create a new link? The old link will stop working immediately.')) return null
    setBusy(true)
    try { const r = rotate ? await travel.portalRotate(bookingId) : await travel.portalLink(bookingId); setUrl(r.url); return r.url } catch (e) { toast.error('Failed', e.message); return null } finally { setBusy(false) }
  }
  const copy = async () => { const u = url || await get(); if (!u) return; try { await navigator.clipboard.writeText(u); toast.success('Link copied', 'Anyone with this link can see the trip, pay and upload documents.') } catch { toast.error('Could not copy', u) } }
  const send = async () => {
    if (!window.confirm('Send the portal link to the customer on WhatsApp?')) return
    try { const r = await travel.sendDocWhatsApp('portal', bookingId); toast.success('Sent on WhatsApp', r.mode === 'template' ? 'Sent with the approved template.' : 'Link sent.') } catch (e) { toast.error('Not sent', e.message) }
  }
  return (
    <div className="card card-body">
      <h3 style={{ marginTop: 0 }}>Customer portal</h3>
      <p style={{ color: 'var(--text-2)', marginTop: 0 }}>One private link where the customer sees their itinerary, pays instalments, downloads invoices and vouchers, and uploads passports and photos.</p>
      {url && <input className="form-input" readOnly value={url} onFocus={e => e.target.select()} style={{ marginBottom: 8 }} />}
      <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
        <button className="btn btn-sm btn-primary" disabled={busy || cancelled} onClick={copy}>Copy link</button>
        <button className="btn btn-sm btn-ghost" disabled={busy || cancelled} onClick={send}>Send on WhatsApp</button>
        <button className="btn btn-sm btn-ghost" disabled={busy || cancelled} onClick={() => get(true)} title="Invalidate the current link">New link</button>
      </div>
    </div>)
}
