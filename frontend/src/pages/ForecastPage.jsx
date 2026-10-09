import { useState, useEffect, useCallback } from 'react'
import { crm } from '../api/crm'
import { api } from '../api/client'
import { toast } from '../components/Toast'
import { fmt } from '../components/Charts'

// ── Forecasting + targets (Phase I3) ─────────────────────────────────────────

const inr = fmt.inr

function TargetModal({ members, onClose, onSaved }) {
  const [f, setF] = useState({ user_id: '', metric: 'won_value', period_start: '', period_end: '', target_amount: '' })
  const [saving, setSaving] = useState(false)
  const set = (k, v) => setF(p => ({ ...p, [k]: v }))
  async function save(e) {
    e.preventDefault()
    if (!f.user_id || !f.period_start || !f.period_end) { toast.error('User and period are required'); return }
    setSaving(true)
    try {
      await crm.createTarget({ ...f, user_id: Number(f.user_id), target_amount: f.metric === 'won_value' ? Math.round(parseFloat(f.target_amount || 0) * 100) : Number(f.target_amount || 0) })
      toast.success('Target set'); onSaved()
    } catch (e) { toast.error('Save failed', e?.message) }
    setSaving(false)
  }
  const s = { width: '100%', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .6rem', fontSize: '.9rem', boxSizing: 'border-box' }
  const l = { display: 'block', fontSize: '.78rem', fontWeight: 700, color: '#374151', margin: '.7rem 0 .25rem' }
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, backdropFilter: 'blur(3px)' }}>
      <form onClick={e => e.stopPropagation()} onSubmit={save} style={{ background: '#fff', borderRadius: 16, width: '100%', maxWidth: 420, padding: '1.5rem' }}>
        <h3 style={{ margin: '0 0 .5rem', fontWeight: 800, fontSize: '1.05rem' }}>🎯 Set sales target</h3>
        <label style={l}>Rep</label>
        <select style={s} value={f.user_id} onChange={e => set('user_id', e.target.value)}>
          <option value="">Choose…</option>
          {members.map(m => <option key={m.id} value={m.id}>{m.name}</option>)}
        </select>
        <label style={l}>Metric</label>
        <select style={s} value={f.metric} onChange={e => set('metric', e.target.value)}>
          <option value="won_value">Won value (₹)</option><option value="won_count">Won deals (count)</option>
        </select>
        <div style={{ display: 'flex', gap: 10 }}>
          <div style={{ flex: 1 }}><label style={l}>From</label><input type="date" style={s} value={f.period_start} onChange={e => set('period_start', e.target.value)} /></div>
          <div style={{ flex: 1 }}><label style={l}>To</label><input type="date" style={s} value={f.period_end} onChange={e => set('period_end', e.target.value)} /></div>
        </div>
        <label style={l}>Target ({f.metric === 'won_value' ? '₹' : 'deals'})</label>
        <input type="number" style={s} value={f.target_amount} onChange={e => set('target_amount', e.target.value)} placeholder={f.metric === 'won_value' ? '500000' : '10'} />
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, marginTop: '1.25rem' }}>
          <button type="button" onClick={onClose} style={btn(false)}>Cancel</button>
          <button type="submit" disabled={saving} style={btn(true)}>{saving ? 'Saving…' : 'Save'}</button>
        </div>
      </form>
    </div>
  )
}
const btn = (p) => ({ padding: '.55rem 1.1rem', borderRadius: 9, fontWeight: 700, fontSize: 13.5, cursor: 'pointer', border: '1.5px solid ' + (p ? 'transparent' : '#e5e7eb'), background: p ? 'var(--primary,#0a6cc4)' : '#fff', color: p ? '#fff' : '#374151' })

export default function ForecastPage() {
  const [forecast, setForecast] = useState(null)
  const [rows, setRows] = useState([])
  const [members, setMembers] = useState([])
  const [period, setPeriod] = useState({ from: new Date().toISOString().slice(0, 8) + '01', to: new Date().toISOString().slice(0, 10) })
  const [showTarget, setShowTarget] = useState(false)

  const load = useCallback(async () => {
    try {
      const [f, a] = await Promise.all([crm.forecast(), crm.attainment(period.from, period.to)])
      setForecast(f.data); setRows(a.data ?? [])
    } catch (e) { toast.error('Failed to load forecast', e?.message) }
  }, [period])

  useEffect(() => { load() }, [load])
  useEffect(() => { api.get('/team').then(r => setMembers(r.data ?? [])).catch(() => {}) }, [])

  const maxW = Math.max(1, ...(forecast?.by_owner ?? []).map(o => o.weighted))

  return (
    <div className="page" style={{ maxWidth: 960 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '1.25rem', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>🔮 Forecast</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Weighted pipeline and target attainment.</p>
        </div>
        <button onClick={() => setShowTarget(true)} style={btn(true)}>🎯 Set target</button>
      </div>

      {/* Forecast KPIs */}
      <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap', marginBottom: '1.25rem' }}>
        {[['Open pipeline', forecast?.total_open], ['Weighted forecast', forecast?.total_weighted]].map(([label, v]) => (
          <div key={label} style={{ flex: '1 1 220px', background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, padding: '1.1rem 1.25rem' }}>
            <div style={{ fontSize: 12.5, color: '#6b7280', fontWeight: 600 }}>{label}</div>
            <div style={{ fontSize: 28, fontWeight: 800, color: '#111827' }}>{inr(v ?? 0)}</div>
          </div>
        ))}
      </div>

      {/* By owner */}
      {forecast?.by_owner?.length > 0 && (
        <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, padding: '1.1rem 1.25rem', marginBottom: '1.25rem' }}>
          <div style={{ fontSize: 13, fontWeight: 800, marginBottom: 10 }}>Weighted forecast by rep</div>
          {forecast.by_owner.map(o => (
            <div key={o.owner_id} style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6, fontSize: 12.5 }}>
              <span style={{ width: 120, color: '#475569', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{o.name}</span>
              <div style={{ flex: 1, background: '#f1f5f9', borderRadius: 6, height: 18 }}>
                <div style={{ width: `${(o.weighted / maxW) * 100}%`, height: '100%', background: '#0a6cc4', borderRadius: 6 }} />
              </div>
              <span style={{ width: 110, textAlign: 'right', fontWeight: 700 }}>{inr(o.weighted)}</span>
            </div>
          ))}
        </div>
      )}

      {/* Attainment table */}
      <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, overflow: 'hidden' }}>
        <div className="lp-wrap-mobile" style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '.8rem 1.1rem', borderBottom: '1px solid #f0f1f3' }}>
          <span style={{ fontSize: 13, fontWeight: 800 }}>Attainment</span>
          <div style={{ display: 'flex', gap: 6, alignItems: 'center', fontSize: 12.5 }}>
            <input type="date" value={period.from} onChange={e => setPeriod(p => ({ ...p, from: e.target.value }))} style={{ border: '1px solid #e5e7eb', borderRadius: 7, padding: '.3rem .4rem' }} />
            <span style={{ color: '#94a3b8' }}>→</span>
            <input type="date" value={period.to} onChange={e => setPeriod(p => ({ ...p, to: e.target.value }))} style={{ border: '1px solid #e5e7eb', borderRadius: 7, padding: '.3rem .4rem' }} />
          </div>
        </div>
        {rows.length === 0 ? (
          <div style={{ padding: '2rem', textAlign: 'center', color: '#9ca3af' }}>No data for this period.</div>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
            <thead><tr style={{ textAlign: 'left', color: '#6b7280', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em' }}>
              <th style={th}>Rep</th><th style={th}>Won</th><th style={th}>Target</th><th style={th}>Attainment</th><th style={th}>Weighted</th>
            </tr></thead>
            <tbody>
              {rows.map(r => {
                const isVal = r.metric === 'won_value'
                const f = isVal ? inr : (x => x)
                const pct = r.attainment_pct
                return (
                  <tr key={r.user_id} style={{ borderTop: '1px solid #f6f7f9' }}>
                    <td style={{ ...td, fontWeight: 700 }}>{r.name}</td>
                    <td style={td}>{f(r.actual)}</td>
                    <td style={td}>{r.target ? f(r.target) : '—'}</td>
                    <td style={td}>{pct != null ? (
                      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                        <span style={{ width: 60, height: 7, background: '#f1f5f9', borderRadius: 4, overflow: 'hidden' }}>
                          <span style={{ display: 'block', height: '100%', width: `${Math.min(100, pct)}%`, background: pct >= 100 ? '#16a34a' : pct >= 60 ? '#f59e0b' : '#ef4444' }} />
                        </span>
                        <span style={{ fontWeight: 700, color: pct >= 100 ? '#15803d' : '#374151' }}>{pct}%</span>
                      </span>
                    ) : '—'}</td>
                    <td style={{ ...td, color: '#0a6cc4', fontWeight: 700 }}>{inr(r.weighted)}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
      </div>

      {showTarget && <TargetModal members={members} onClose={() => setShowTarget(false)} onSaved={() => { setShowTarget(false); load() }} />}
    </div>
  )
}
const th = { padding: '.6rem 1.1rem', fontWeight: 700 }
const td = { padding: '.6rem 1.1rem', color: '#374151' }
