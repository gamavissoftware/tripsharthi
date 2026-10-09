import { useState, useEffect } from 'react'
import { ecommerce as api } from '../api/ecommerce'
import { useToast } from '../components/Toast'
import { ShoppingBag, Store } from 'lucide-react'

const PLATFORMS = [
  {
    key: 'shopify', name: 'Shopify', icon: <ShoppingBag size={22} strokeWidth={1.8} />,
    storeLabel: 'Shop domain', storePlaceholder: 'your-store.myshopify.com',
    help: 'In Shopify admin → Settings → Notifications → Webhooks, add webhooks (orders/create, fulfillments/create, checkouts/create) pointing to the URL below. Use the signing secret shown there.',
  },
  {
    key: 'woocommerce', name: 'WooCommerce', icon: <Store size={22} strokeWidth={1.8} />,
    storeLabel: 'Store URL', storePlaceholder: 'https://yourstore.com',
    help: 'In WooCommerce → Settings → Advanced → Webhooks, add webhooks (Order created, Order updated) with delivery URL below and a secret.',
  },
]

function PlatformCard({ p }) {
  const { toast } = useToast()
  const [state, setState] = useState(null)
  const [form, setForm] = useState({ store: '', webhook_secret: '', default_country_code: '' })
  const [busy, setBusy] = useState(false)

  useEffect(() => { load() }, [])
  async function load() {
    try {
      const r = await api.get(p.key)
      setState(r.data)
      setForm(f => ({ ...f, store: r.data?.store ?? '', default_country_code: r.data?.default_country_code ?? '' }))
    } catch (err) { toast.error(`Failed to load ${p.name}`, err?.message) }
  }

  async function connect() {
    if (!form.store.trim() || !form.webhook_secret.trim()) { toast.error('Store and webhook secret are required'); return }
    setBusy(true)
    try { await api.connect(p.key, form); toast.success(`${p.name} connected`); setForm(f => ({ ...f, webhook_secret: '' })); load() }
    catch (err) { toast.error('Failed to connect', err?.message) }
    setBusy(false)
  }

  async function disconnect() {
    setBusy(true)
    try { await api.disconnect(p.key); toast.success(`${p.name} disconnected`); load() }
    catch (err) { toast.error('Failed to disconnect', err?.message) }
    setBusy(false)
  }

  return (
    <div className="card" style={{ padding: '1.25rem', marginBottom: '1.25rem' }}>
      <div style={{ display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 10, marginBottom: 12 }}>
        <span style={{ display: 'inline-flex', alignItems: 'center', color: 'var(--text-3)' }}>{p.icon}</span>
        <span style={{ fontWeight: 700, fontSize: 16 }}>{p.name}</span>
        {state?.connected
          ? <span style={{ fontSize: 12, fontWeight: 700, color: '#15803d', background: '#f0fdf4', borderRadius: 999, padding: '2px 10px' }}>Connected</span>
          : <span style={{ fontSize: 12, fontWeight: 700, color: '#b45309', background: '#fffbeb', borderRadius: 999, padding: '2px 10px' }}>Not connected</span>}
        {state?.connected && <button className="btn btn-ghost btn-sm" style={{ marginLeft: 'auto', color: '#dc2626' }} onClick={disconnect} disabled={busy}>Disconnect</button>}
      </div>

      <div style={{ display: 'grid', gap: 10, maxWidth: 460 }}>
        <label style={lbl}>{p.storeLabel}
          <input className="form-input" value={form.store} onChange={e => setForm(f => ({ ...f, store: e.target.value }))} placeholder={p.storePlaceholder} />
        </label>
        <label style={lbl}>Webhook secret
          <input className="form-input" type="password" value={form.webhook_secret} onChange={e => setForm(f => ({ ...f, webhook_secret: e.target.value }))} placeholder={state?.connected ? '•••••• (unchanged if blank)' : 'signing secret'} />
        </label>
        <label style={lbl}>Default country code <span style={{ color: 'var(--text-3)', fontWeight: 400 }}>(for phones without +, e.g. 91)</span>
          <input className="form-input" value={form.default_country_code} onChange={e => setForm(f => ({ ...f, default_country_code: e.target.value }))} placeholder="91" />
        </label>
        <button className="btn btn-primary" style={{ width: 'fit-content' }} onClick={connect} disabled={busy}>
          {busy ? 'Saving…' : 'Save connection'}
        </button>
      </div>

      <div style={{ marginTop: 14, padding: '10px 12px', background: '#f9fafb', borderRadius: 8, fontSize: 12.5, color: '#6b7280' }}>
        {p.help}
        <div style={{ fontFamily: 'monospace', marginTop: 6, color: '#374151', overflowWrap: 'anywhere' }}>{state?.webhook_url}</div>
      </div>
    </div>
  )
}

export default function EcommercePage() {
  return (
    <div className="page" style={{ maxWidth: 820 }}>
      <div className="page-header">
        <div>
          <h1 className="page-title">Store Integrations</h1>
          <p style={{ color: 'var(--text-3)', fontSize: 13, marginTop: 4 }}>
            Connect Shopify or WooCommerce to trigger WhatsApp flows on new orders, fulfillment, and abandoned carts.
          </p>
        </div>
      </div>
      {PLATFORMS.map(p => <PlatformCard key={p.key} p={p} />)}
      <div style={{ fontSize: 13, color: 'var(--text-3)', marginTop: 8 }}>
        Tip: build a flow with the <strong>Order Placed</strong>, <strong>Order Fulfilled</strong>, or <strong>Abandoned Cart</strong> trigger to send the actual WhatsApp messages.
      </div>
    </div>
  )
}

const lbl = { display: 'block', fontSize: 13, fontWeight: 600, color: '#374151' }
