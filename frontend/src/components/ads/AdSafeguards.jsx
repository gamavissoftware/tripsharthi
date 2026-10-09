import { useState, useEffect } from 'react'
import { travel } from '../../api/travel'
import { toast } from '../../components/Toast'

const METRICS = { cpl: 'Cost per lead is above', spend_no_leads: 'Spent this much with no leads', cost_per_booking: 'Cost per booking is above' }

/** Spend cap + automatic rules. The cap is mandatory; rules can only pause or notify, never raise spend. */
export default function AdSafeguards({ settings, onSaved }) {
  const [s, setS] = useState({ cap: settings.daily_spend_cap / 100, min: settings.min_daily_budget / 100, pct: settings.max_increase_pct, rules: settings.rules_enabled })
  const [rules, setRules] = useState([])
  const [r, setR] = useState({ name: '', platform: 'any', metric: 'cpl', threshold_rs: '', window_days: 3, min_spend_rs: '', action: 'pause' })
  const loadRules = () => travel.adRules().then(setRules).catch(() => {})
  useEffect(() => { loadRules() }, [])

  async function save(e) {
    e.preventDefault()
    try { await travel.saveAdSettings({ daily_spend_cap_rs: s.cap, min_daily_budget_rs: s.min, max_increase_pct: s.pct, rules_enabled: s.rules }); toast.success('Safeguards saved'); onSaved() }
    catch (er) { toast.error('Not saved', er.message) }
  }
  async function addRule(e) {
    e.preventDefault()
    try { await travel.saveAdRule(r); setR({ ...r, name: '', threshold_rs: '', min_spend_rs: '' }); loadRules(); toast.success('Rule added') } catch (er) { toast.error('Not saved', er.message) }
  }
  const row = { display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }

  return (
    <div className="card card-body">
      <h3 style={{ marginTop: 0 }}>Spend safeguards</h3>
      <p style={{ color: 'var(--text-2)', marginTop: 0 }}>Everything TripSarthi creates starts <b>paused</b>. Launching or raising a budget is checked against these limits; cutting spend and pausing are always allowed.</p>
      <form onSubmit={save}>
        <div style={{ ...row, marginBottom: 10 }}>
          <label>Daily spend cap (all live campaigns) ₹ <input className="form-input" type="number" min="0" style={{ width: 120 }} value={s.cap} onChange={e => setS({ ...s, cap: e.target.value })} /></label>
          <label>Minimum daily budget ₹ <input className="form-input" type="number" min="1" style={{ width: 100 }} value={s.min} onChange={e => setS({ ...s, min: e.target.value })} /></label>
          <label>Max raise per change % <input className="form-input" type="number" min="0" max="100" style={{ width: 80 }} value={s.pct} onChange={e => setS({ ...s, pct: e.target.value })} /></label>
        </div>
        <label style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 10 }}><input type="checkbox" checked={s.rules} onChange={e => setS({ ...s, rules: e.target.checked })} /> Run automatic rules (hourly)</label>
        <button className="btn btn-primary">Save safeguards</button>
      </form>

      <h4 style={{ marginBottom: 6 }}>Automatic rules</h4>
      <p style={{ color: 'var(--text-3)', marginTop: 0, fontSize: 13 }}>Rules only act on <b>live</b> campaigns and can only <b>pause</b> or <b>notify</b> you — never enable a campaign or raise a budget. Each rule fires at most once a day per campaign.</p>
      {rules.map(x => (
        <div key={x.id} style={{ ...row, justifyContent: 'space-between', padding: '8px 0', borderTop: '1px solid var(--border)' }}>
          <span><b>{x.name}</b> — {METRICS[x.metric]} ₹{Math.round(x.threshold / 100)} (last {x.window_days} days{Number(x.min_spend) ? `, after ₹${Math.round(x.min_spend / 100)} spend` : ''}) → <b>{x.action === 'pause' ? 'pause' : 'notify me'}</b>{x.platform !== 'any' ? ` · ${x.platform}` : ''}</span>
          <button className="btn btn-sm btn-ghost" onClick={async () => { await travel.deleteAdRule(x.id); loadRules() }}>Remove</button>
        </div>))}
      <form onSubmit={addRule} style={{ ...row, marginTop: 10 }}>
        <input className="form-input" style={{ width: 170 }} placeholder="Rule name" value={r.name} onChange={e => setR({ ...r, name: e.target.value })} required />
        <select className="form-select" style={{ width: 210 }} value={r.metric} onChange={e => setR({ ...r, metric: e.target.value })}>{Object.entries(METRICS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
        <input className="form-input" style={{ width: 100 }} type="number" min="1" placeholder="₹" value={r.threshold_rs} onChange={e => setR({ ...r, threshold_rs: e.target.value })} required />
        <label>over <input className="form-input" style={{ width: 60 }} type="number" min="1" max="30" value={r.window_days} onChange={e => setR({ ...r, window_days: e.target.value })} /> days</label>
        <input className="form-input" style={{ width: 130 }} type="number" min="0" placeholder="min spend ₹" value={r.min_spend_rs} onChange={e => setR({ ...r, min_spend_rs: e.target.value })} />
        <select className="form-select" style={{ width: 120 }} value={r.action} onChange={e => setR({ ...r, action: e.target.value })}><option value="pause">Pause it</option><option value="notify">Notify me</option></select>
        <select className="form-select" style={{ width: 110 }} value={r.platform} onChange={e => setR({ ...r, platform: e.target.value })}><option value="any">Any platform</option><option value="meta">Meta</option><option value="google">Google</option></select>
        <button className="btn btn-ghost">Add rule</button>
      </form>
    </div>
  )
}
