import { useState, useEffect } from 'react'
import { ai as api } from '../api/ai'
import { useToast } from '../components/Toast'

export default function AiPage() {
  const { toast } = useToast()
  const [cfg, setCfg] = useState(null)
  const [loading, setLoading] = useState(true)
  const [form, setForm] = useState({ api_key: '', model: '' })
  const [saving, setSaving] = useState(false)

  useEffect(() => { load() }, [])

  async function load() {
    setLoading(true)
    try {
      const r = await api.getConfig()
      setCfg(r.data)
      setForm(f => ({ ...f, model: r.data?.model ?? '' }))
    } catch (err) {
      toast.error('Failed to load AI settings', err?.message)
    }
    setLoading(false)
  }

  async function save() {
    if (!form.api_key.trim()) { toast.error('Enter your Anthropic API key'); return }
    setSaving(true)
    try {
      await api.saveConfig({ api_key: form.api_key.trim(), model: form.model.trim() || undefined })
      toast.success('AI key saved')
      setForm(f => ({ ...f, api_key: '' }))
      load()
    } catch (err) {
      toast.error('Failed to save', err?.message)
    }
    setSaving(false)
  }

  async function disconnect() {
    try { await api.deleteConfig(); toast.success('Reverted to platform AI key'); load() }
    catch (err) { toast.error('Failed to disconnect', err?.message) }
  }

  const u = cfg?.usage ?? { used: 0, limit: 0, remaining: 0, period: '' }
  const pct = u.limit > 0 ? Math.min(100, Math.round((u.used / u.limit) * 100)) : 0
  const aiEnabled = u.limit > 0 || cfg?.has_tenant_key

  return (
    <div className="page" style={{ maxWidth: 760 }}>
      <div className="page-header">
        <div>
          <h1 className="page-title">AI Assistant</h1>
          <p style={{ color: 'var(--text-3)', fontSize: 13, marginTop: 4 }}>
            Powers the inbox “Suggest reply” and the AI Reply flow node.
          </p>
        </div>
      </div>

      {/* Usage */}
      <div className="card" style={{ padding: '1.25rem', marginBottom: '1.25rem' }}>
        <div style={{ fontWeight: 700, marginBottom: 12 }}>This month’s usage</div>
        {loading ? <div style={{ color: 'var(--text-3)' }}>Loading…</div> : (
          <>
            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, marginBottom: 6 }}>
              <span>{u.used.toLocaleString('en-IN')} / {u.limit.toLocaleString('en-IN')} AI replies <span style={{ color: 'var(--text-3)' }}>({u.period})</span></span>
              <span style={{ color: 'var(--text-3)' }}>{u.remaining.toLocaleString('en-IN')} left</span>
            </div>
            <div style={{ height: 10, borderRadius: 5, background: '#f3f4f6', overflow: 'hidden' }}>
              <div style={{ width: `${pct}%`, height: '100%', borderRadius: 5, background: pct >= 90 ? '#dc2626' : pct >= 70 ? '#f59e0b' : '#10b981' }} />
            </div>
            {!aiEnabled && (
              <div style={{ marginTop: 10, fontSize: 13, color: '#b45309' }}>
                Your plan has no AI replies. Upgrade your plan, or add your own Anthropic key below to use AI.
              </div>
            )}
          </>
        )}
      </div>

      {/* Key config */}
      <div className="card" style={{ padding: '1.25rem' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
          <span style={{ fontWeight: 700 }}>Anthropic key</span>
          {cfg?.has_tenant_key
            ? <span style={{ fontSize: 12, fontWeight: 700, color: '#15803d', background: '#f0fdf4', borderRadius: 999, padding: '2px 10px' }}>Using your key</span>
            : cfg?.platform_key_available
              ? <span style={{ fontSize: 12, fontWeight: 700, color: '#1d4ed8', background: '#eff6ff', borderRadius: 999, padding: '2px 10px' }}>Using platform key</span>
              : <span style={{ fontSize: 12, fontWeight: 700, color: '#b45309', background: '#fffbeb', borderRadius: 999, padding: '2px 10px' }}>No key configured</span>}
        </div>
        <p style={{ fontSize: 13, color: 'var(--text-3)', marginTop: 0 }}>
          Optional — bring your own key to bill Anthropic directly and bypass the platform key.
        </p>

        <div style={{ display: 'grid', gap: 10, maxWidth: 460 }}>
          <label style={lbl}>API key
            <input className="form-input" type="password" value={form.api_key}
              onChange={e => setForm(f => ({ ...f, api_key: e.target.value }))}
              placeholder={cfg?.has_tenant_key ? '•••••• (unchanged)' : 'sk-ant-...'} />
          </label>
          <label style={lbl}>Model
            <input className="form-input" value={form.model}
              onChange={e => setForm(f => ({ ...f, model: e.target.value }))}
              placeholder="claude-3-5-haiku-latest" />
          </label>
          <div style={{ display: 'flex', gap: 10 }}>
            <button className="btn btn-primary" style={{ width: 'fit-content' }} onClick={save} disabled={saving}>
              {saving ? 'Saving…' : 'Save key'}
            </button>
            {cfg?.has_tenant_key && (
              <button className="btn btn-ghost" style={{ color: '#dc2626' }} onClick={disconnect}>Remove key</button>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}

const lbl = { display: 'block', fontSize: 13, fontWeight: 600, color: '#374151' }
