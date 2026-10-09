import { useState, useEffect } from 'react'
import { crm } from '../api/crm'
import { toast } from '../components/Toast'

// ── Lead scoring weights editor (Phase H1) ───────────────────────────────────
// Pro-gated in SaaS (open in self-hosted). Edits per-tenant signal weights +
// Hot/Warm thresholds, then re-scores all contacts.

const LIFECYCLE = ['subscriber', 'lead', 'mql', 'sql', 'opportunity', 'customer', 'evangelist', 'other']
const SOURCES   = ['meta_lead_ads', 'google_lead_forms', 'web_form', 'whatsapp_inbound', 'shopify', 'woocommerce', 'manual', 'csv_import']
const labelize  = (s) => String(s).replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())

const numInput = { width: 70, border: '1px solid #e5e7eb', borderRadius: 8, padding: '.4rem .5rem', fontSize: '.85rem', textAlign: 'right' }

export default function ScoringPage() {
  const [cfg, setCfg] = useState(null)
  const [editable, setEditable] = useState(false)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    crm.scoringRules()
      .then(r => { setCfg({ weights: r.data.weights, hot: r.data.hot_threshold, warm: r.data.warm_threshold }); setEditable(r.data.editable) })
      .catch(e => toast.error('Failed to load scoring rules', e?.message))
  }, [])

  function setW(path, value) {
    const v = value === '' ? 0 : parseInt(value, 10)
    setCfg(c => {
      const w = structuredClone(c.weights)
      let node = w
      for (let i = 0; i < path.length - 1; i++) node = node[path[i]]
      node[path[path.length - 1]] = isNaN(v) ? 0 : v
      return { ...c, weights: w }
    })
  }

  async function save() {
    setSaving(true)
    try {
      const r = await crm.updateScoringRules({ weights: cfg.weights, hot_threshold: cfg.hot, warm_threshold: cfg.warm })
      toast.success('Scoring rules saved', `${r.recalculated} contacts re-scored`)
    } catch (e) { toast.error('Save failed', e?.message) }
    setSaving(false)
  }

  async function recalc() {
    try { const r = await crm.recalcScores(); toast.success('Re-scored', `${r.recalculated} contacts`) }
    catch (e) { toast.error('Recalc failed', e?.message) }
  }

  if (!cfg) return <div className="page"><div style={{ padding: '2rem', color: '#9ca3af' }}>Loading…</div></div>

  const w = cfg.weights
  const card = { background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, padding: '1.1rem 1.25rem', marginBottom: '1rem' }
  const row = (label, path) => (
    <div key={path.join('.')} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '.3rem 0' }}>
      <span style={{ fontSize: 13.5, color: '#374151' }}>{label}</span>
      <input style={numInput} type="number" disabled={!editable} value={path.reduce((o, k) => o?.[k], w) ?? 0} onChange={e => setW(path, e.target.value)} />
    </div>
  )

  return (
    <div className="page" style={{ maxWidth: 720 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '1.25rem', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>🎯 Lead Scoring</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Tune the signals that decide whether a lead is 🔥 Hot, ☀️ Warm, or ❄️ Cold.</p>
        </div>
        <button onClick={recalc} style={{ background: '#fff', border: '1.5px solid #e5e7eb', borderRadius: 10, padding: '.55rem 1rem', fontWeight: 700, cursor: 'pointer', fontSize: 13.5 }}>↻ Recompute now</button>
      </div>

      {!editable && (
        <div style={{ background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 12, padding: '.8rem 1rem', marginBottom: '1rem', fontSize: 13.5, color: '#92400e' }}>
          🔒 Editing scoring weights is a <strong>Pro</strong> feature. You can still recompute scores with the current rules.
        </div>
      )}

      <div style={card}>
        <h3 style={{ margin: '0 0 .5rem', fontSize: '.95rem', fontWeight: 800 }}>Tier thresholds</h3>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '.3rem 0' }}>
          <span style={{ fontSize: 13.5, color: '#374151' }}>🔥 Hot at or above</span>
          <input style={numInput} type="number" disabled={!editable} value={cfg.hot} onChange={e => setCfg(c => ({ ...c, hot: parseInt(e.target.value || '0', 10) }))} />
        </div>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '.3rem 0' }}>
          <span style={{ fontSize: 13.5, color: '#374151' }}>☀️ Warm at or above</span>
          <input style={numInput} type="number" disabled={!editable} value={cfg.warm} onChange={e => setCfg(c => ({ ...c, warm: parseInt(e.target.value || '0', 10) }))} />
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '1rem' }}>
        <div style={card}>
          <h3 style={{ margin: '0 0 .5rem', fontSize: '.95rem', fontWeight: 800 }}>Lifecycle stage</h3>
          {LIFECYCLE.map(s => row(labelize(s), ['lifecycle', s]))}
        </div>
        <div style={card}>
          <h3 style={{ margin: '0 0 .5rem', fontSize: '.95rem', fontWeight: 800 }}>Source quality</h3>
          {SOURCES.map(s => row(labelize(s), ['source', s]))}
        </div>
      </div>

      <div style={card}>
        <h3 style={{ margin: '0 0 .5rem', fontSize: '.95rem', fontWeight: 800 }}>Other signals</h3>
        {row('Has email', ['has_email'])}
        {row('Has account', ['has_account'])}
        {row('Deal value — high band (≥₹10L)', ['deal_band', 'high'])}
        {row('Deal value — mid band (≥₹1L)', ['deal_band', 'mid'])}
        {row('Deal value — low band', ['deal_band', 'low'])}
        {row('Engagement — per inbound message', ['engagement_each'])}
        {row('Engagement — cap', ['engagement_cap'])}
        {row('Task completion (× ratio)', ['task_completion'])}
        {row('Recency decay — per idle week', ['decay_per_week'])}
        {row('Recency decay — cap', ['decay_cap'])}
      </div>

      {editable && (
        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: '.5rem' }}>
          <button onClick={save} disabled={saving} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 10, padding: '.6rem 1.4rem', fontWeight: 700, cursor: 'pointer' }}>
            {saving ? 'Saving…' : 'Save & re-score'}
          </button>
        </div>
      )}
    </div>
  )
}
