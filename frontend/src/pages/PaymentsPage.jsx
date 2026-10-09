import { useState, useEffect } from 'react'
import { payments as api } from '../api/payments'
import { useToast } from '../components/Toast'

const STATUS_CFG = {
  created:   { bg: '#f3f4f6', color: '#374151', label: 'Created' },
  sent:      { bg: '#eff6ff', color: '#1d4ed8', label: 'Sent' },
  paid:      { bg: '#f0fdf4', color: '#15803d', label: 'Paid' },
  cancelled: { bg: '#fff1f2', color: '#be123c', label: 'Cancelled' },
  expired:   { bg: '#fffbeb', color: '#b45309', label: 'Expired' },
  failed:    { bg: '#fff1f2', color: '#be123c', label: 'Failed' },
}

export default function PaymentsPage() {
  const { toast } = useToast()
  const [config, setConfig] = useState(null)
  const [links, setLinks]   = useState([])
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState({ key_id: '', key_secret: '', webhook_secret: '' })
  const [saving, setSaving] = useState(false)

  useEffect(() => { load() }, [])

  async function load() {
    setLoading(true)
    try {
      const [c, l] = await Promise.all([api.getConfig(), api.links().catch(() => ({ data: [] }))])
      setConfig(c.data ?? { connected: false })
      setForm(f => ({ ...f, key_id: c.data?.key_id ?? '' }))
      setLinks(l.data ?? [])
    } catch (err) {
      toast.error('Failed to load payments', err?.message)
    }
    setLoading(false)
  }

  async function saveConfig() {
    if (!form.key_id.trim() || !form.key_secret.trim()) {
      toast.error('Key ID and Key Secret are required'); return
    }
    setSaving(true)
    try {
      await api.saveConfig(form)
      toast.success('Razorpay connected')
      setForm(f => ({ ...f, key_secret: '' }))
      load()
    } catch (err) {
      toast.error('Failed to save', err?.message)
    }
    setSaving(false)
  }

  // Supplied by the API from the app's public base URL — the browser origin
  // would hand the user a localhost URL Razorpay can never call.
  const webhookUrl = config?.webhook_url ?? ''

  return (
    <div className="page" style={{ maxWidth: 920 }}>
      <div className="page-header">
        <div>
          <h1 className="page-title">Payments</h1>
          <p style={{ color: 'var(--text-3)', fontSize: 13, marginTop: 4 }}>
            Connect your own Razorpay account to collect payments from contacts over WhatsApp.
          </p>
        </div>
      </div>

      {/* Config card */}
      <div className="card" style={{ padding: '1.25rem', marginBottom: '1.25rem' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
          <span style={{ fontWeight: 700 }}>Razorpay connection</span>
          {config?.connected
            ? <span style={{ fontSize: 12, fontWeight: 700, color: '#15803d', background: '#f0fdf4', borderRadius: 999, padding: '2px 10px' }}>Connected</span>
            : <span style={{ fontSize: 12, fontWeight: 700, color: '#b45309', background: '#fffbeb', borderRadius: 999, padding: '2px 10px' }}>Not connected</span>}
        </div>

        <div style={{ display: 'grid', gap: 10, maxWidth: 460 }}>
          <label style={lbl}>Key ID
            <input className="form-input" value={form.key_id} onChange={e => setForm(f => ({ ...f, key_id: e.target.value }))} placeholder="rzp_live_xxxxxxxx" />
          </label>
          <label style={lbl}>Key Secret
            <input className="form-input" type="password" value={form.key_secret} onChange={e => setForm(f => ({ ...f, key_secret: e.target.value }))} placeholder={config?.connected ? '•••••• (unchanged)' : 'Key secret'} />
          </label>
          <label style={lbl}>Webhook Secret <span style={{ color: 'var(--text-3)', fontWeight: 400 }}>(optional but recommended)</span>
            <input className="form-input" type="password" value={form.webhook_secret} onChange={e => setForm(f => ({ ...f, webhook_secret: e.target.value }))} placeholder={config?.has_webhook_secret ? '•••••• (unchanged)' : 'Webhook secret'} />
          </label>
          <button className="btn btn-primary" style={{ width: 'fit-content' }} onClick={saveConfig} disabled={saving}>
            {saving ? 'Saving…' : 'Save connection'}
          </button>
        </div>

        <div style={{ marginTop: 14, padding: '10px 12px', background: '#f9fafb', borderRadius: 8, fontSize: 12.5, color: '#6b7280' }}>
          In your Razorpay dashboard, add a webhook for <strong>payment_link.paid / cancelled / expired</strong> pointing to:
          <div style={{ fontFamily: 'monospace', marginTop: 4, color: '#374151', overflowWrap: 'anywhere' }}>{webhookUrl}</div>
        </div>
      </div>

      {/* Recent links */}
      <div className="card" style={{ overflow: 'hidden' }}>
        <div style={{ padding: '1rem 1.25rem', fontWeight: 700, borderBottom: '1px solid var(--border)' }}>Recent payment links</div>
        {loading ? (
          <div className="empty-state"><span className="empty-state-text">Loading…</span></div>
        ) : links.length === 0 ? (
          <div className="empty-state" style={{ padding: '40px 24px', textAlign: 'center', color: 'var(--text-3)' }}>
            No payment links yet. Send one from the Inbox or a flow.
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}><table className="data-table">
            <thead><tr><th>Amount</th><th>Description</th><th>Status</th><th>Link</th><th>Created</th></tr></thead>
            <tbody>
              {links.map(l => {
                const s = STATUS_CFG[l.status] ?? { bg: '#f3f4f6', color: '#374151', label: l.status }
                return (
                  <tr key={l.id}>
                    <td style={{ fontWeight: 600 }}>₹{(l.amount_paise / 100).toLocaleString('en-IN')}</td>
                    <td>{l.description || '—'}</td>
                    <td><span style={{ fontSize: 12, fontWeight: 700, background: s.bg, color: s.color, borderRadius: 999, padding: '2px 9px' }}>{s.label}</span></td>
                    <td>{l.short_url ? <a href={l.short_url} target="_blank" rel="noreferrer">Open ↗</a> : '—'}</td>
                    <td style={{ color: 'var(--text-3)', fontSize: 13 }}>{l.created_at?.slice(0, 16).replace('T', ' ')}</td>
                  </tr>
                )
              })}
            </tbody>
          </table></div>
        )}
      </div>
    </div>
  )
}

const lbl = { display: 'block', fontSize: 13, fontWeight: 600, color: '#374151' }
