import { useState, useEffect, useCallback, useMemo } from 'react'
import { Link } from 'react-router-dom'
import { travel } from '../api/travel'
import { toast } from '../components/Toast'

const lbl = { fontSize: 12.5, fontWeight: 700, display: 'block' }
const ST = { ready: ['#dcfce7', '#15803d', 'Ready'], creating: ['#fef9c3', '#a16207', 'Creating'], error: ['#fee2e2', '#b91c1c', 'Failed'] }

export default function AdAudiencesPage() {
  const [d, setD] = useState(null)
  const [conn, setConn] = useState(null)
  const [err, setErr] = useState('')
  const [form, setForm] = useState(null)       // null | 'custom' | 'lookalike'
  const [busy, setBusy] = useState('')
  const load = useCallback(() => travel.adAudiences().then(r => { setD(r); setErr('') }).catch(e => setErr(e.message)), [])
  useEffect(() => { load(); travel.adConnection().then(setConn).catch(() => {}) }, [load])
  const accounts = useMemo(() => conn?.meta?.ad_accounts || [], [conn])

  const [f, setF] = useState({ name: '', account_id: '', source_type: 'booked', source_ref: '', consent: false, seed: '', ratio: 1 })
  const set = (k) => (e) => setF(x => ({ ...x, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value }))
  const accountId = f.account_id || accounts[0]?.id || ''      // default to the first account without an effect

  async function submit(e) {
    e.preventDefault(); setBusy('save')
    try {
      if (form === 'custom') await travel.createAudience({ name: f.name, account_id: accountId, source_type: f.source_type, source_ref: f.source_type === 'segment' ? Number(f.source_ref) : undefined, consent_confirmed: f.consent })
      else await travel.createLookalike({ name: f.name, seed_audience_id: Number(f.seed), ratio_pct: Number(f.ratio) })
      toast.success('Audience created'); setForm(null); load()
    } catch (er) { toast.error('Not created', er.message) } finally { setBusy('') }
  }
  const act = async (key, fn, ok) => { setBusy(key); try { const r = await fn(); if (ok) toast.success(typeof ok === 'function' ? ok(r) : ok); load() } catch (e) { toast.error('Not done', e.message) } finally { setBusy('') } }

  const ready = (d?.audiences || []).filter(a => a.kind === 'custom' && a.status === 'ready' && a.member_count >= d.min_seed)
  return (
    <div className="page" style={{ maxWidth: 900 }}>
      <div className="page-header">
        <div><Link to="/ads/campaigns" style={{ fontSize: 13 }}>← Ad campaigns</Link><h1 className="page-title" style={{ margin: '4px 0' }}>Audiences</h1></div>
        <div style={{ display: 'flex', gap: 8 }}><button className="btn btn-primary" onClick={() => setForm('custom')}>+ Customer audience</button><button className="btn btn-ghost" disabled={ready.length === 0} title={ready.length ? '' : `Needs a customer audience with at least ${d?.min_seed} people`} onClick={() => setForm('lookalike')}>+ Lookalike</button></div>
      </div>
      <p style={{ color: 'var(--text-2)' }}>Show ads to people like your best customers, or <b>exclude</b> people who already booked. Audiences are built from your CRM and sent to Meta as <b>hashed</b> phone numbers and emails — never the raw details. Only contacts who agreed to be contacted are included, and a refresh removes anyone who has since opted out.</p>
      {err && <div style={{ background: '#fee2e2', padding: 10, borderRadius: 8, marginBottom: 12 }}>{err}</div>}
      {d && d.audiences.length === 0 && <div className="card card-body" style={{ color: 'var(--text-3)' }}>No audiences yet. Start with “Customers who have booked”, then create a lookalike from it. Meta needs at least {d.min_seed} matched people (1,000+ works best).</div>}
      {d && d.audiences.map(a => { const [bg, fg, label] = ST[a.status] || ST.error; return (
        <div key={a.id} className="card card-body" style={{ marginBottom: 10 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap' }}>
            <div><b>{a.name}</b> <span style={{ background: bg, color: fg, padding: '1px 8px', borderRadius: 999, fontSize: 11.5, fontWeight: 700 }}>{label}</span> <small style={{ color: 'var(--text-3)' }}>{a.kind === 'lookalike' ? `Lookalike ${a.params?.ratio_pct}% of India` : a.source_label} · {a.account_ref}</small></div>
            <span style={{ display: 'flex', gap: 6 }}>
              {a.kind === 'custom' && a.status === 'ready' && <button className="btn btn-sm btn-ghost" disabled={busy === 'r' + a.id} onClick={() => act('r' + a.id, () => travel.refreshAudience(a.id), r => `Added ${r.added}, removed ${r.removed}`)}>Refresh</button>}
              <button className="btn btn-sm btn-ghost" onClick={() => travel.audienceEstimate(a.id).then(r => toast.info?.('Meta’s estimate', r.estimate != null ? `About ${r.estimate.toLocaleString('en-IN')} people matched (Meta updates this over a day).` : 'Not available yet.')).catch(e => toast.error('Could not ask Meta', e.message))}>Match size</button>
              <button className="btn btn-sm btn-ghost" onClick={() => { if (window.confirm(`Delete “${a.name}” here and on Meta?`)) act('d' + a.id, () => travel.deleteAudience(a.id), 'Deleted') }}>Delete</button></span>
          </div>
          {a.kind === 'custom' && <div style={{ fontSize: 13, marginTop: 4 }}>{a.member_count.toLocaleString('en-IN')} people uploaded{a.member_count < (d.min_seed) && a.status === 'ready' ? <span style={{ color: 'var(--warning)' }}> — Meta needs at least {d.min_seed} to use it for ads</span> : ''}{a.last_synced_at ? <span style={{ color: 'var(--text-3)' }}> · updated {a.last_synced_at}</span> : ''}</div>}
          {a.last_error && a.status !== 'ready' && <div style={{ color: 'var(--danger)', fontSize: 13 }}>{a.last_error}</div>}
        </div>) })}

      {form && (
        <div onClick={() => setForm(null)} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', justifyContent: 'center', padding: 16, overflowY: 'auto' }}>
          <form onSubmit={submit} className="card card-body" onClick={e => e.stopPropagation()} style={{ width: '100%', maxWidth: 520, margin: 'auto', display: 'grid', gap: 10 }}>
            <h3 style={{ margin: 0 }}>{form === 'custom' ? 'New customer audience' : 'New lookalike audience'}</h3>
            <label style={lbl}>Name *<input className="form-input" required value={f.name} onChange={set('name')} placeholder={form === 'custom' ? 'Past customers' : 'Lookalike of past customers'} /></label>
            {form === 'custom' ? <>
              <label style={lbl}>Ad account *<select className="form-select" value={accountId} onChange={set('account_id')}>{accounts.map(a => <option key={a.id} value={a.id}>{a.name}</option>)}</select></label>
              <label style={lbl}>Who to include<select className="form-select" value={f.source_type} onChange={set('source_type')}>{Object.entries(d.sources).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
              {f.source_type === 'segment' && <label style={lbl}>Segment<select className="form-select" required value={f.source_ref} onChange={set('source_ref')}><option value="">Choose…</option>{d.segments.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}</select></label>}
              <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start', background: '#fef3c7', padding: 10, borderRadius: 8, fontSize: 13 }}><input type="checkbox" checked={f.consent} onChange={set('consent')} style={{ marginTop: 3 }} />
                <span>I confirm I have the right to use these customers’ details for advertising (their consent, or my privacy policy covers it). Their phone numbers and emails are hashed before being sent to Meta.</span></label>
            </> : <>
              <label style={lbl}>Based on<select className="form-select" required value={f.seed} onChange={set('seed')}><option value="">Choose a customer audience…</option>{ready.map(a => <option key={a.id} value={a.id}>{a.name} ({a.member_count.toLocaleString('en-IN')})</option>)}</select></label>
              <label style={lbl}>How wide: {f.ratio}% of India <input type="range" min="1" max="10" value={f.ratio} onChange={set('ratio')} style={{ width: '100%' }} /><small style={{ fontWeight: 400, color: 'var(--text-3)' }}>1% is the closest match to your customers; larger reaches more people with less similarity.</small></label>
            </>}
            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}><button type="button" className="btn btn-ghost" onClick={() => setForm(null)}>Cancel</button><button className="btn btn-primary" disabled={busy === 'save' || (form === 'custom' && !f.consent)}>{busy === 'save' ? 'Creating…' : 'Create'}</button></div>
          </form>
        </div>)}
    </div>
  )
}
