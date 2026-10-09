import { useState, useEffect, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { travel } from '../api/travel'
import { TRAVEL_TRIGGERS } from '../flow-builder/travelTriggers'
import { toast } from '../components/Toast'

const TRIG = Object.fromEntries(TRAVEL_TRIGGERS.map(t => [t.key, t]))
const CAT = { utility: ['#dcfce7', '#15803d'], marketing: ['#fef9c3', '#a16207'] }
const TPL = { approved: '✅ approved', pending: '⏳ awaiting Meta', draft: '📝 draft — submit to Meta', rejected: '❌ rejected', paused: '⏸ paused' }

const describe = (a) => {
  const c = a.config || {}
  const bits = []
  if (c.days) bits.push(`${c.days} day${c.days > 1 ? 's' : ''}`)
  if (c.audience && c.audience !== 'any') bits.push(c.audience === 'viewed' ? 'opened, not accepted' : 'never opened')
  if (c.days_before != null) bits.push(`${c.days_before} days before departure`)
  if (c.within_days) bits.push(`departing within ${c.within_days} days`)
  if (c.international === 'yes') bits.push('international trips')
  return bits.join(' · ')
}

export default function TravelAutomationsPage() {
  const [rows, setRows] = useState(null)
  const [busy, setBusy] = useState('')
  const load = useCallback(() => travel.automations().then(setRows).catch(e => toast.error('Could not load', e.message)), [])
  useEffect(() => { load() }, [load])

  async function install(keys) {
    setBusy(keys ? keys[0] : 'all')
    try { const r = await travel.installAutomations(keys); setRows(r.automations); toast.success('Installed as drafts', 'Submit the templates to Meta, then switch the flow on.') }
    catch (e) { toast.error('Could not install', e.message) } finally { setBusy('') }
  }
  if (!rows) return <div className="page">Loading…</div>
  const missing = rows.filter(r => !r.installed).length

  return (
    <div className="page" style={{ maxWidth: 900 }}>
      <div className="page-header"><h1 className="page-title">Travel automations</h1>
        {missing > 0 && <button className="btn btn-primary" disabled={!!busy} onClick={() => install(null)}>{busy === 'all' ? 'Installing…' : `Install all (${missing})`}</button>}</div>

      <div className="card card-body" style={{ marginBottom: 14 }}>
        <p style={{ margin: 0 }}>Ready-made WhatsApp automations that react to what happens with a trip — quote opened, booking confirmed, departure coming up, passport about to expire. Installing creates <b>drafts only</b>: nothing is sent until you submit the message templates to Meta and switch the flow on.</p>
        <ul style={{ margin: '8px 0 0', paddingLeft: 18, color: 'var(--text-2)', fontSize: 14 }}>
          <li>Each flow checks WhatsApp’s 24-hour window: inside it, a plain message; outside it, your approved template.</li>
          <li>Edit the wording or timing any time in the <Link to="/flows">flow builder</Link> — including the new travel triggers and filters (international only, honeymoons only…).</li>
          <li>Needs the hourly job <code>php spark travel:triggers</code> for the timed ones (quote gone quiet, departure, passport, trip start/end).</li>
        </ul>
      </div>

      {rows.map(a => {
        const t = TRIG[a.trigger]
        const Icon = t?.icon
        return (
          <div key={a.key} className="card card-body" style={{ marginBottom: 12 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', alignItems: 'flex-start' }}>
              <div style={{ flex: '1 1 380px' }}>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                  <b style={{ fontSize: 16 }}>{a.name}</b>
                  <span style={{ background: CAT[a.category][0], color: CAT[a.category][1], padding: '2px 8px', borderRadius: 999, fontSize: 11.5, fontWeight: 700 }}>{a.category} template</span>
                </div>
                <div style={{ color: 'var(--text-2)', margin: '4px 0' }}>{a.summary}</div>
                <div style={{ fontSize: 12.5, display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                  {t && <span style={{ background: t.bg, color: t.color, padding: '2px 9px', borderRadius: 999, fontWeight: 700, display: 'inline-flex', gap: 5, alignItems: 'center' }}><Icon size={13} /> {t.label}</span>}
                  {describe(a) && <span style={{ color: 'var(--text-3)' }}>{describe(a)}</span>}
                </div>
              </div>
              <div style={{ textAlign: 'right', minWidth: 190 }}>
                {a.installed ? (<>
                  <div style={{ fontSize: 13 }}>Flow: <b>{a.flow_status}</b></div>
                  <div style={{ fontSize: 13, color: a.template_status === 'approved' ? 'var(--success)' : 'var(--warning)' }}>Template: {TPL[a.template_status] || a.template_status}</div>
                  <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end', marginTop: 8 }}>
                    <Link className="btn btn-sm btn-ghost" to="/templates">Templates</Link>
                    <Link className="btn btn-sm btn-primary" to={`/flows/${a.flow_id}`}>Open flow</Link>
                  </div>
                </>) : <button className="btn btn-sm btn-primary" disabled={!!busy} onClick={() => install([a.key])}>{busy === a.key ? 'Installing…' : 'Install'}</button>}
              </div>
            </div>
          </div>)
      })}
    </div>
  )
}
