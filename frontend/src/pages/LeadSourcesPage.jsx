import { useState, useEffect, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { travel } from '../api/travel'
import { toast } from '../components/Toast'

const ST = { created: ['#dcfce7', '#15803d', 'New lead'], existing: ['#e0f2fe', '#0369a1', 'Added to open enquiry'], duplicate: ['#f1f5f9', '#475569', 'Duplicate ignored'], rejected: ['#fee2e2', '#b91c1c', 'Rejected'], error: ['#fee2e2', '#b91c1c', 'Error'] }
const lbl = { fontSize: 12.5, fontWeight: 700, display: 'block' }

function SourceForm({ src, fields, onClose, onDone }) {
  const [f, setF] = useState({ name: src?.name || '', kind: src?.kind || 'webhook', email_match: src?.email_match || '', create_trip: src ? src.create_trip : true, enabled: src ? src.enabled : true,
    map: Object.entries(src?.field_map || {}).map(([alias, field]) => ({ alias, field })) })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')
  const set = (k) => (e) => setF(x => ({ ...x, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }))
  async function submit(e) {
    e.preventDefault(); setBusy(true); setErr('')
    try {
      const field_map = Object.fromEntries(f.map.filter(m => m.alias.trim() && m.field).map(m => [m.alias.trim(), m.field]))
      await travel.saveLeadSource({ name: f.name, kind: f.kind, email_match: f.email_match, create_trip: f.create_trip, enabled: f.enabled, field_map }, src?.id)
      toast.success('Saved'); onDone()
    } catch (er) { setErr(er.message) } finally { setBusy(false) }
  }
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', justifyContent: 'center', padding: 16, overflowY: 'auto' }}>
      <div className="card card-body" onClick={e => e.stopPropagation()} style={{ width: '100%', maxWidth: 560, margin: 'auto' }}>
        <h3 style={{ marginTop: 0 }}>{src ? `Edit ${src.name}` : 'New lead source'}</h3>
        <form onSubmit={submit} style={{ display: 'grid', gap: 10 }}>
          <label style={lbl}>Name *<input className="form-input" required value={f.name} onChange={set('name')} placeholder="TravelTriangle, Justdial, Partner website…" /></label>
          <label style={lbl}>How do leads arrive?
            <select className="form-select" value={f.kind} disabled={!!src} onChange={set('kind')}><option value="webhook">The portal posts to a webhook URL</option><option value="email">The portal emails me (read from my connected inbox)</option></select></label>
          {f.kind === 'email' && <label style={lbl}>Portal's sender address or domain *<input className="form-input" required value={f.email_match} onChange={set('email_match')} placeholder="@justdial.com or leads@portal.com" /></label>}
          <label><input type="checkbox" checked={f.create_trip} onChange={set('create_trip')} /> Create a trip enquiry for each new lead</label>
          {src && <label><input type="checkbox" checked={f.enabled} onChange={set('enabled')} /> Enabled</label>}
          <div>
            <div style={lbl}>Their field names <small style={{ fontWeight: 400, color: 'var(--text-3)' }}>(optional — common names like "Mobile No" and "Customer Name" are understood already)</small></div>
            {f.map.map((m, i) => (
              <div key={i} style={{ display: 'flex', gap: 6, marginTop: 6 }}>
                <input className="form-input" placeholder="Their field name" value={m.alias} onChange={e => setF(x => ({ ...x, map: x.map.map((r, j) => j === i ? { ...r, alias: e.target.value } : r) }))} />
                <select className="form-select" value={m.field} onChange={e => setF(x => ({ ...x, map: x.map.map((r, j) => j === i ? { ...r, field: e.target.value } : r) }))}>{fields.map(k => <option key={k} value={k}>{k.replace('_', ' ')}</option>)}</select>
                <button type="button" className="btn btn-sm btn-ghost" onClick={() => setF(x => ({ ...x, map: x.map.filter((_, j) => j !== i) }))}>✕</button>
              </div>))}
            <button type="button" className="btn btn-sm btn-ghost" style={{ marginTop: 6 }} onClick={() => setF(x => ({ ...x, map: [...x.map, { alias: '', field: fields[0] }] }))}>+ Add a field name</button>
          </div>
          {err && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8 }}>{err}</div>}
          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}><button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button><button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button></div>
        </form>
      </div>
    </div>)
}

function Events({ id }) {
  const [ev, setEv] = useState(null)
  useEffect(() => { travel.leadSourceEvents(id).then(setEv).catch(() => setEv([])) }, [id])
  if (!ev) return <small style={{ color: 'var(--text-3)' }}>Loading…</small>
  if (ev.length === 0) return <small style={{ color: 'var(--text-3)' }}>Nothing received yet.</small>
  return <div style={{ fontSize: 13 }}>{ev.slice(0, 8).map(e => { const [bg, fg, label] = ST[e.status] || ST.error; return (
    <div key={e.id} style={{ display: 'flex', gap: 8, alignItems: 'center', padding: '3px 0', flexWrap: 'wrap' }}>
      <span style={{ background: bg, color: fg, padding: '1px 8px', borderRadius: 999, fontSize: 11.5, fontWeight: 700 }}>{label}</span><span style={{ color: 'var(--text-3)' }}>{e.at}</span>
      {e.trip_id && <Link to={`/trips/${e.trip_id}`}>open enquiry</Link>}{e.reason && <span style={{ color: 'var(--text-2)' }}>{e.reason}</span>}</div>) })}</div>
}

export default function LeadSourcesPage() {
  const [d, setD] = useState(null)
  const [err, setErr] = useState('')
  const [edit, setEdit] = useState(null)
  const load = useCallback(() => travel.leadSources().then(r => { setD(r); setErr('') }).catch(e => setErr(e.message)), [])
  useEffect(() => { load() }, [load])
  const url = (s) => d.webhook_base + s.token
  const copy = async (t) => { try { await navigator.clipboard.writeText(t); toast.success('Copied') } catch { toast.error('Could not copy', t) } }
  const rotate = async (s) => { if (!window.confirm('Create a new URL? The old one stops working immediately — update the portal with the new one.')) return; try { await travel.rotateLeadSource(s.id); load() } catch (e) { toast.error('Failed', e.message) } }
  const remove = async (s) => { if (!window.confirm(`Delete ${s.name}? Leads already received stay in your CRM.`)) return; try { await travel.deleteLeadSource(s.id); load() } catch (e) { toast.error('Failed', e.message) } }

  return (
    <div className="page" style={{ maxWidth: 900 }}>
      <div className="page-header"><h1 className="page-title">Lead sources</h1><button className="btn btn-primary" onClick={() => setEdit({})}>+ Add source</button></div>
      <p style={{ color: 'var(--text-2)' }}>Bring enquiries from travel portals, aggregators and partner sites straight into your CRM. Each lead becomes a contact and a trip enquiry, tagged with the portal's name, and the assigned agent gets a phone alert. The same person is never duplicated, and a lead sent twice is ignored.</p>
      {err && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8 }}>{err}</div>}
      {d && d.sources.length === 0 && <div className="card card-body" style={{ color: 'var(--text-3)' }}>No sources yet. Add one, then paste its URL into the portal's "lead webhook" setting — or choose email and we'll read the portal's notification emails.</div>}
      {d && d.sources.map(s => (
        <div key={s.id} className="card card-body" style={{ marginBottom: 12, opacity: s.enabled ? 1 : 0.6 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap' }}>
            <div><b>{s.name}</b> <small style={{ color: 'var(--text-3)' }}>{s.kind === 'email' ? `emails from ${s.email_match}` : 'webhook'} · {s.received_count} received{s.last_received_at ? ` · last ${s.last_received_at}` : ''}{!s.enabled ? ' · disabled' : ''}</small></div>
            <span style={{ display: 'flex', gap: 6 }}><button className="btn btn-sm btn-ghost" onClick={() => setEdit(s)}>Edit</button>{s.kind === 'webhook' && <button className="btn btn-sm btn-ghost" onClick={() => rotate(s)}>New URL</button>}<button className="btn btn-sm btn-ghost" onClick={() => remove(s)}>Delete</button></span>
          </div>
          {s.kind === 'webhook' && <div style={{ display: 'flex', gap: 6, margin: '8px 0' }}><input className="form-input" readOnly value={url(s)} onFocus={e => e.target.select()} /><button className="btn btn-sm" onClick={() => copy(url(s))}>Copy</button></div>}
          {s.kind === 'webhook' && <details style={{ fontSize: 12.5, color: 'var(--text-2)', marginBottom: 8 }}><summary>Test it</summary>
            <pre style={{ whiteSpace: 'pre-wrap', background: 'var(--bg-2, #f8fafc)', padding: 8, borderRadius: 6 }}>{`curl -X POST '${url(s)}' -H 'Content-Type: application/json' -d '{"name":"Test Traveller","mobile":"9876543210","destination":"Bali","adults":2}'`}</pre></details>}
          <Events id={s.id} />
        </div>))}
      {edit && d && <SourceForm src={edit.id ? edit : null} fields={d.fields} onClose={() => setEdit(null)} onDone={() => { setEdit(null); load() }} />}
    </div>
  )
}
