import { useState, useEffect } from 'react'
import { crm } from '../api/crm'
import { toast } from '../components/Toast'

// ── Working hours (Phase H5) ─────────────────────────────────────────────────
// Drives business-hours-aware ticket SLA timers.

const DAYS = [[1, 'Mon'], [2, 'Tue'], [3, 'Wed'], [4, 'Thu'], [5, 'Fri'], [6, 'Sat'], [7, 'Sun']]
const HOURS = Array.from({ length: 25 }, (_, h) => h)

export default function BusinessHoursPage() {
  const [cfg, setCfg] = useState(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => { crm.businessHours().then(r => setCfg(r.data)).catch(e => toast.error('Failed to load', e?.message)) }, [])

  function toggleDay(d) {
    setCfg(c => ({ ...c, workdays: c.workdays.includes(d) ? c.workdays.filter(x => x !== d) : [...c.workdays, d].sort() }))
  }

  async function save() {
    if (cfg.end_hour <= cfg.start_hour) { toast.error('End hour must be after start hour'); return }
    setSaving(true)
    try { const r = await crm.updateBusinessHours(cfg); setCfg(r.data); toast.success('Working hours saved') }
    catch (e) { toast.error('Save failed', e?.message) }
    setSaving(false)
  }

  if (!cfg) return <div className="page"><div style={{ padding: '2rem', color: '#9ca3af' }}>Loading…</div></div>

  const fld = { border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.9rem' }
  return (
    <div className="page" style={{ maxWidth: 560 }}>
      <div style={{ marginBottom: '1.25rem' }}>
        <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>🕘 Working Hours</h1>
        <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Ticket SLA timers only count down during these hours.</p>
      </div>

      <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, padding: '1.25rem' }}>
        <label style={{ fontSize: '.8rem', fontWeight: 700, color: '#374151', display: 'block', marginBottom: 8 }}>Working days</label>
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: '1.25rem' }}>
          {DAYS.map(([d, label]) => (
            <button key={d} onClick={() => toggleDay(d)}
              style={{ padding: '.4rem .8rem', borderRadius: 8, border: '1.5px solid ' + (cfg.workdays.includes(d) ? 'var(--primary,#0a6cc4)' : '#e5e7eb'), background: cfg.workdays.includes(d) ? 'var(--primary,#0a6cc4)' : '#fff', color: cfg.workdays.includes(d) ? '#fff' : '#475569', fontSize: 13, fontWeight: 700, cursor: 'pointer' }}>{label}</button>
          ))}
        </div>

        <div style={{ display: 'flex', gap: 16, alignItems: 'flex-end' }}>
          <div>
            <label style={{ fontSize: '.8rem', fontWeight: 700, color: '#374151', display: 'block', marginBottom: 4 }}>Start</label>
            <select style={fld} value={cfg.start_hour} onChange={e => setCfg(c => ({ ...c, start_hour: Number(e.target.value) }))}>
              {HOURS.slice(0, 24).map(h => <option key={h} value={h}>{String(h).padStart(2, '0')}:00</option>)}
            </select>
          </div>
          <span style={{ color: '#9ca3af', paddingBottom: 8 }}>→</span>
          <div>
            <label style={{ fontSize: '.8rem', fontWeight: 700, color: '#374151', display: 'block', marginBottom: 4 }}>End</label>
            <select style={fld} value={cfg.end_hour} onChange={e => setCfg(c => ({ ...c, end_hour: Number(e.target.value) }))}>
              {HOURS.slice(1).map(h => <option key={h} value={h}>{String(h).padStart(2, '0')}:00</option>)}
            </select>
          </div>
        </div>

        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: '1.5rem' }}>
          <button onClick={save} disabled={saving} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 10, padding: '.6rem 1.4rem', fontWeight: 700, cursor: 'pointer' }}>{saving ? 'Saving…' : 'Save'}</button>
        </div>
      </div>
    </div>
  )
}
