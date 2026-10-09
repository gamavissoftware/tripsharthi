import { useState, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { travel } from '../api/travel'
import { toast } from '../components/Toast'

const LABEL = { before_3d: '3 days before due', due_today: 'On the due date', overdue_2d: '2 days overdue', overdue_5d: '5 days overdue', escalate_7d: '7 days overdue — alert a person' }
const STATUS_HINT = { approved: '✅ approved', pending: '⏳ awaiting Meta', draft: '📝 draft — submit it from Templates', rejected: '❌ rejected', paused: '⏸ paused', disabled: '⛔ disabled' }

export default function DunningSettingsPage() {
  const [cfg, setCfg] = useState(null)
  const [saving, setSaving] = useState(false)
  const load = () => travel.dunning().then(setCfg).catch(e => toast.error('Could not load', e.message))
  useEffect(() => { load() }, [])
  if (!cfg) return <div className="page">Loading…</div>

  const setStep = (i, patch) => setCfg(c => ({ ...c, steps: c.steps.map((s, j) => j === i ? { ...s, ...patch } : s) }))
  async function save() {
    setSaving(true)
    try { const r = await travel.saveDunning({ enabled: cfg.enabled, send_from_hour: cfg.send_from_hour, send_to_hour: cfg.send_to_hour, steps: cfg.steps }); setCfg(c => ({ ...c, ...r })); toast.success('Saved') }
    catch (e) { toast.error('Could not save', e.message) } finally { setSaving(false) }
  }
  async function setup() {
    try { await travel.setupDunningTemplates(); await load(); toast.success('Reminder templates created', 'Submit them for Meta approval from Templates.') }
    catch (e) { toast.error('Failed', e.message) }
  }
  const tplName = (id) => cfg.templates.find(t => t.id === id || String(t.id) === String(id))

  return (
    <div className="page" style={{ maxWidth: 820 }}>
      <div className="page-header"><h1 className="page-title">Payment reminders</h1>
        <label style={{ display: 'flex', gap: 8, alignItems: 'center', fontWeight: 700 }}><input type="checkbox" checked={cfg.enabled} onChange={e => setCfg({ ...cfg, enabled: e.target.checked })} /> {cfg.enabled ? 'On' : 'Off'}</label></div>

      <div className="card card-body" style={{ marginBottom: 14 }}>
        <p style={{ marginTop: 0 }}>When an instalment is coming due or late, TripSarthi messages the customer on WhatsApp with their payment link, and alerts you if it stays unpaid. Each reminder is sent <b>once</b>, only during the hours below, and never to customers who opted out.</p>
        <ul style={{ margin: 0, paddingLeft: 18, color: 'var(--text-2)', fontSize: 14 }}>
          <li>Inside the customer's 24-hour WhatsApp window, a plain message is sent.</li>
          <li>Outside it, WhatsApp only allows an <b>approved utility template</b> — pick one per step below. Without one, no message is sent and a task is created for you.</li>
          <li>Messages stay transactional (amount, booking, link). Promotional wording gets templates reclassified as marketing by Meta.</li>
        </ul>
      </div>

      <div className="card card-body" style={{ marginBottom: 14 }}>
        <h3 style={{ marginTop: 0 }}>Utility templates</h3>
        <p style={{ marginTop: 0, color: 'var(--text-2)' }}>Create ready-made reminder templates, then submit them for approval in <Link to="/templates">Templates</Link>.</p>
        <button className="btn btn-ghost" onClick={setup}>Create reminder templates</button>
      </div>

      <div className="card card-body" style={{ marginBottom: 14 }}>
        <h3 style={{ marginTop: 0 }}>Schedule</h3>
        <div style={{ display: 'flex', gap: 12, alignItems: 'center', marginBottom: 12, flexWrap: 'wrap' }}>
          <span>Send between</span>
          <input className="form-input" type="number" min="0" max="23" style={{ width: 80 }} value={cfg.send_from_hour} onChange={e => setCfg({ ...cfg, send_from_hour: Number(e.target.value) })} />
          <span>and</span>
          <input className="form-input" type="number" min="1" max="24" style={{ width: 80 }} value={cfg.send_to_hour} onChange={e => setCfg({ ...cfg, send_to_hour: Number(e.target.value) })} />
          <span style={{ color: 'var(--text-3)' }}>IST</span>
        </div>
        {cfg.steps.map((s, i) => (
          <div key={s.key} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, padding: '10px 0', borderTop: '1px solid var(--border)', alignItems: 'center', flexWrap: 'wrap' }}>
            <div><b>{LABEL[s.key] || s.key}</b><div style={{ fontSize: 12.5, color: 'var(--text-3)' }}>{s.kind === 'task' ? 'Creates a collection task for the booking owner' : 'WhatsApp message'}</div></div>
            {s.kind !== 'task' && (
              <div style={{ minWidth: 260 }}>
                <select className="form-select" value={s.template_id || ''} onChange={e => setStep(i, { template_id: e.target.value ? Number(e.target.value) : null })}>
                  <option value="">No template (plain message in-window only)</option>
                  {cfg.templates.map(t => <option key={t.id} value={t.id}>{t.name} — {t.meta_status}</option>)}
                </select>
                {s.template_id && tplName(s.template_id) && <small style={{ color: tplName(s.template_id).meta_status === 'approved' ? 'var(--success)' : 'var(--warning)' }}>{STATUS_HINT[tplName(s.template_id).meta_status]}</small>}
              </div>)}
          </div>
        ))}
      </div>
      <button className="btn btn-primary" disabled={saving} onClick={save}>{saving ? 'Saving…' : 'Save'}</button>
    </div>
  )
}
