import { useState, useEffect, useRef } from 'react'
import { travel, inr } from '../../api/travel'
import { toast } from '../../components/Toast'
import AdImagePicker from './AdImagePicker'

const STEPS = ['Type', 'Details', 'Creative', 'Review']
const lbl = { display: 'block', fontSize: 12.5, fontWeight: 700, color: 'var(--text-2)', marginBottom: 4 }
const DG_CHANNELS = [['youtube_in_stream', 'YouTube In-Stream'], ['youtube_in_feed', 'YouTube In-Feed'], ['youtube_shorts', 'YouTube Shorts'], ['discover', 'Discover'], ['gmail', 'Gmail'], ['display', 'Display network'], ['maps', 'Maps']]
const DG_CTAS = ['Book now', 'Learn more', 'Get quote', 'Contact us', 'Sign up', 'Visit site']
const Counter = ({ v, max }) => <small style={{ color: (v || '').length > max ? 'var(--danger)' : 'var(--text-3)' }}>{(v || '').length}/{max}</small>

/** "kw" -> phrase, [kw] -> exact, plain -> default. One per line. */
function parseKeywords(text, def) {
  return text.split('\n').map(l => l.trim()).filter(Boolean).map(l => {
    if (/^\[.+\]$/.test(l)) return { text: l.slice(1, -1).trim(), match: 'EXACT' }
    if (/^".+"$/.test(l)) return { text: l.slice(1, -1).trim(), match: 'PHRASE' }
    return { text: l, match: def }
  })
}

function GeoPicker({ search, picked, setPicked, label }) {
  const [q, setQ] = useState('')
  const [hits, setHits] = useState([])
  const t = useRef()
  function type(v) {
    setQ(v)
    clearTimeout(t.current)
    if (v.trim().length < 2) { setHits([]); return }
    t.current = setTimeout(() => search(v.trim()).then(setHits).catch(() => setHits([])), 300)
  }
  return (
    <div>
      <label style={lbl}>{label}</label>
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 6 }}>
        {picked.map(p => <span key={p.key} style={{ background: 'var(--primary-light)', padding: '3px 9px', borderRadius: 999, fontSize: 13 }}>{p.name} <a style={{ cursor: 'pointer' }} onClick={() => setPicked(picked.filter(x => x.key !== p.key))}>✕</a></span>)}
        {picked.length === 0 && <small style={{ color: 'var(--text-3)' }}>All of India</small>}
      </div>
      <input className="form-input" placeholder="Search a city…" value={q} onChange={e => type(e.target.value)} />
      {hits.length > 0 && <div className="card" style={{ marginTop: 4 }}>{hits.map(h => <div key={h.key} style={{ padding: '7px 10px', cursor: 'pointer', borderTop: '1px solid var(--border)' }} onClick={() => { if (!picked.find(p => p.key === h.key)) setPicked([...picked, h]); setQ(''); setHits([]) }}>{h.name}{h.region ? `, ${h.region}` : ''}</div>)}</div>}
    </div>
  )
}

export default function AdCampaignWizard({ conn, onClose, onDone }) {
  const [step, setStep] = useState(0)
  const [dests, setDests] = useState([])
  const [forms, setForms] = useState([])
  const [kw, setKw] = useState('')
  const [kwMatch, setKwMatch] = useState('PHRASE')
  const [brief, setBrief] = useState({ price_from: '', trip_type: '', duration: '', usp: '' })
  const [busy, setBusy] = useState('')
  const [preview, setPreview] = useState(null)
  const [created, setCreated] = useState(null)
  const metaOk = conn?.meta?.connected && conn.meta.can_manage !== false
  const googleOk = conn?.google?.connected
  const [s, setS] = useState({
    platform: metaOk ? 'meta' : 'google', kind: metaOk ? 'lead_form' : 'search', name: '', destination_id: '', daily_budget_rs: '', start_date: '', end_date: '',
    account_id: conn?.meta?.ad_accounts?.[0]?.id || '', page_id: conn?.meta?.pages?.[0]?.id || '', lead_form_id: '', whatsapp_number: '',
    age_min: 25, age_max: 55, cities: [],
    primary_text: '', headline: '', description: '', image_url: '', image_asset_ids: [],
    use_sets: false, adsets: [{ name: '', budget_pct: 50, age_min: '', age_max: '', cities: [] }, { name: '', budget_pct: 50, age_min: '', age_max: '', cities: [] }], audiences: [],
    final_url: '', headlines: ['', '', ''], descriptions: ['', ''], negatives: '', languages: ['en'], geo: [],
  })
  const [audList, setAudList] = useState([])
  // Google Search ad groups: the editor below always edits groups[gi]; switching tabs saves it and loads the other.
  const [groups, setGroups] = useState([{ name: 'Ad group 1' }])
  const [gi, setGi] = useState(0)
  const [pm, setPm] = useState({ business_name: '', headlines: ['', '', ''], long_headlines: [''], descriptions: ['', ''], landscape: [], square: [], logo: [] })
  const set = (k) => (e) => setS(x => ({ ...x, [k]: e.target.value }))
  const dest = dests.find(d => String(d.id) === String(s.destination_id))

  useEffect(() => { travel.list('destinations').then(setDests).catch(() => {}); travel.adAudiences().then(r => setAudList(r.audiences.filter(a => a.status === 'ready' && a.usable))).catch(() => {}) }, [])
  useEffect(() => { if (s.platform === 'meta' && s.kind === 'lead_form' && s.page_id) travel.metaLeadForms(s.page_id).then(f => { setForms(f); setS(x => ({ ...x, lead_form_id: x.lead_form_id || f[0]?.id || '' })) }).catch(() => setForms([])) }, [s.platform, s.kind, s.page_id])

  const editorGroup = () => ({ name: groups[gi]?.name || `Ad group ${gi + 1}`, headlines: s.headlines, descriptions: s.descriptions, kw })
  function switchGroup(i) {
    const saved = groups.map((g, j) => j === gi ? editorGroup() : g)
    const next = saved[i]
    setGroups(saved); setGi(i)
    setS(x => ({ ...x, headlines: next.headlines || ['', '', ''], descriptions: next.descriptions || ['', ''] })); setKw(next.kw || '')
  }
  const addGroup = () => { if (groups.length < 5) { const saved = [...groups.map((g, j) => j === gi ? editorGroup() : g), { name: `Ad group ${groups.length + 1}`, headlines: ['', '', ''], descriptions: ['', ''], kw: '' }]; setGroups(saved); setGi(saved.length - 1); setS(x => ({ ...x, headlines: ['', '', ''], descriptions: ['', ''] })); setKw('') } }
  const removeGroup = () => { if (groups.length > 1) { const rest = groups.filter((_, j) => j !== gi); const next = rest[0]; setGroups(rest); setGi(0); setS(x => ({ ...x, headlines: next.headlines || ['', '', ''], descriptions: next.descriptions || ['', ''] })); setKw(next.kw || '') } }
  const [dg, setDg] = useState({ business_name: '', headlines: ['', ''], descriptions: [''], cta: 'Get quote', channels: 'all', picked: ['youtube_in_feed', 'discover', 'gmail'], bidding: 'conversions', landscape: [], square: [], portrait: [], tall: [], logo: [] })
  const setDgList = (k, i, v) => setDg(x => ({ ...x, [k]: x[k].map((t, j) => j === i ? v : t) }))
  const setPmList = (k, i, v) => setPm(x => ({ ...x, [k]: x[k].map((t, j) => j === i ? v : t) }))

  function targetingFor(src, base) {
    return { age_min: Number(src.age_min || base.age_min), age_max: Number(src.age_max || base.age_max), cities: (src.cities?.length ? src.cities : base.cities).map(c => ({ key: c.key, radius: 25 })) }
  }
  function payload() {
    const base = { platform: s.platform, kind: s.kind, name: s.name, destination_id: s.destination_id || null, daily_budget_rs: Number(s.daily_budget_rs), start_date: s.start_date || null, end_date: s.end_date || null }
    if (s.platform === 'meta') {
      const creative = { primary_text: s.primary_text, headline: s.headline, description: s.description }
      if (s.image_asset_ids[0]) creative.image_asset_id = s.image_asset_ids[0]; else creative.image_url = s.image_url
      const out = { ...base, account_id: s.account_id, page_id: s.page_id, lead_form_id: s.kind === 'lead_form' ? s.lead_form_id : null, whatsapp_number: s.kind === 'click_to_whatsapp' ? s.whatsapp_number : null,
        targeting: targetingFor(s, s), creative }
      if (s.audiences.length) out.audiences = s.audiences
      if (s.use_sets) out.adsets = s.adsets.map((a, i) => ({ name: a.name || `Ad set ${i + 1}`, budget_pct: Number(a.budget_pct), targeting: { ...(a.age_min ? { age_min: Number(a.age_min) } : {}), ...(a.age_max ? { age_max: Number(a.age_max) } : {}), ...(a.cities.length ? { cities: a.cities.map(c => ({ key: c.key, radius: 25 })) } : {}) } }))
      return out
    }
    const common = { ...base, customer_id: conn.google.customer_id, final_url: s.final_url, languages: s.languages, geo: s.geo.length ? s.geo.map(g => g.key) : undefined }
    if (s.kind === 'pmax') {
      return { ...common, pmax: { business_name: pm.business_name, headlines: pm.headlines.filter(Boolean), long_headlines: pm.long_headlines.filter(Boolean), descriptions: pm.descriptions.filter(Boolean), images: { landscape: pm.landscape, square: pm.square, logo: pm.logo } } }
    }
    if (s.kind === 'demand_gen') {
      return { ...common, demand_gen: { business_name: dg.business_name, headlines: dg.headlines.filter(Boolean), descriptions: dg.descriptions.filter(Boolean), cta: dg.cta, bidding: dg.bidding,
        channels: dg.channels === 'custom' ? dg.picked : dg.channels, images: { landscape: dg.landscape, square: dg.square, portrait: dg.portrait, tall: dg.tall, logo: dg.logo } } }
    }
    const all = groups.map((g, j) => j === gi ? editorGroup() : g)
    const one = (g) => ({ name: g.name, headlines: (g.headlines || []).filter(Boolean), descriptions: (g.descriptions || []).filter(Boolean), keywords: parseKeywords(g.kw || '', kwMatch) })
    const negatives = s.negatives.split('\n').map(x => x.trim()).filter(Boolean)
    return all.length === 1 ? { ...common, ...one(all[0]), negatives } : { ...common, ad_groups: all.map(one), negatives }
  }

  async function suggest() {
    setBusy('ai')
    try {
      const r = await travel.adCopy({ platform: s.platform, destination: dest?.name || brief.destination || s.name, trip_type: brief.trip_type, price_from: brief.price_from ? Number(brief.price_from) : undefined, duration: brief.duration, usp: brief.usp })
      if (s.platform === 'meta') setS(x => ({ ...x, primary_text: r.primary_texts?.[0] || x.primary_text, headline: r.headlines?.[0] || x.headline, _alts: r }))
      else { setS(x => ({ ...x, headlines: (r.headlines?.length ? r.headlines : x.headlines).slice(0, 15), descriptions: (r.descriptions?.length ? r.descriptions : x.descriptions).slice(0, 4), negatives: (r.negatives || []).join('\n') })); setKw((r.keywords || []).map(k => k.match === 'EXACT' ? `[${k.text}]` : k.match === 'PHRASE' ? `"${k.text}"` : k.text).join('\n')) }
      toast.success(r._source === 'ai' ? 'Suggestions ready' : 'Suggestions ready (template — no AI key configured)', 'Review and edit every line before continuing.')
      if (r.dropped?.length) toast.info?.('Some lines removed', `${r.dropped.length} suggestion(s) broke ad policy or price rules.`)
    } catch (e) { toast.error('Could not suggest copy', e.message) } finally { setBusy('') }
  }

  async function goReview() {
    setStep(3); setPreview(null)
    try { setPreview(await travel.previewCampaign(payload())) } catch (e) { setPreview({ ok: false, errors: [e.data?.violations?.join(' ') || e.message], warnings: [], notes: [] }) }
  }
  async function create() {
    setBusy('create')
    try { setCreated(await travel.createCampaign(payload())) } catch (e) { toast.error('Not created', e.data?.violations?.join('\n') || e.message) } finally { setBusy('') }
  }
  async function launchNow() {
    if (!window.confirm(`Launch “${created.name}” now? It will spend up to ${inr(created.daily_budget)} per day.`)) return
    setBusy('launch')
    try { await travel.launchCampaign(created.id); toast.success('Launched'); onDone() } catch (e) { toast.error('Not launched', e.data?.violations?.join('\n') || e.message) } finally { setBusy('') }
  }

  const tile = (active, disabled, onClick, title, sub) => (
    <div onClick={disabled ? undefined : onClick} style={{ flex: '1 1 220px', padding: 14, borderRadius: 12, border: '2px solid ' + (active ? 'var(--primary)' : 'var(--border)'), opacity: disabled ? .45 : 1, cursor: disabled ? 'not-allowed' : 'pointer', background: active ? 'var(--primary-light)' : 'var(--surface)' }}>
      <b>{title}</b><div style={{ fontSize: 13, color: 'var(--text-2)' }}>{sub}</div></div>)
  const grid = { display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: 12 }

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', justifyContent: 'center', padding: 16, overflowY: 'auto' }}>
      <div className="card" style={{ width: '100%', maxWidth: 780, margin: 'auto', padding: '1.25rem' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <h3 style={{ margin: 0 }}>New campaign</h3>
          <button className="btn btn-sm btn-ghost" onClick={onClose}>✕</button>
        </div>
        <div style={{ display: 'flex', gap: 6, margin: '10px 0 16px' }}>{STEPS.map((t, i) => <div key={t} style={{ flex: 1, textAlign: 'center', padding: '5px 0', borderRadius: 8, fontSize: 12.5, fontWeight: 700, background: i === step ? 'var(--primary)' : i < step ? 'var(--primary-light)' : 'var(--surface-2)', color: i === step ? '#fff' : 'var(--text-2)' }}>{i + 1}. {t}</div>)}</div>

        {created ? (
          <div>
            <h4 style={{ marginTop: 0 }}>✅ Created — and paused</h4>
            <p>“{created.name}” exists on {created.platform === 'meta' ? 'Meta' : 'Google'} but is <b>not spending</b>. {created.platform === 'meta' ? 'Meta reviews new ads, which can take a few hours.' : 'Google reviews new ads before they show.'} Launch it when you are ready.</p>
            <div style={{ display: 'flex', gap: 8 }}><button className="btn btn-success" disabled={busy === 'launch'} onClick={launchNow}>Launch now…</button><button className="btn btn-ghost" onClick={onDone}>Keep paused</button></div>
          </div>
        ) : step === 0 ? (
          <div>
            <label style={lbl}>Platform</label>
            <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', marginBottom: 14 }}>
              {tile(s.platform === 'meta', !metaOk, () => setS({ ...s, platform: 'meta', kind: 'lead_form' }), '🟦 Meta (Facebook + Instagram)', metaOk ? 'Lead forms or Click-to-WhatsApp' : 'Connect Meta with campaign permission in Ad Platforms')}
              {tile(s.platform === 'google', !googleOk, () => setS({ ...s, platform: 'google', kind: 'search' }), '🟥 Google Ads', googleOk ? 'Search, Performance Max or Demand Gen' : 'Connect Google in Ad Platforms')}
            </div>
            {s.platform === 'google' && <><label style={lbl}>Campaign type</label>
              <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                {tile(s.kind === 'search', false, () => setS({ ...s, kind: 'search' }), 'Search', 'Ads on Google results when people search for what you sell. You choose the keywords.')}
                {tile(s.kind === 'pmax', false, () => setS({ ...s, kind: 'pmax' }), 'Performance Max', 'Google shows your headlines and photos across Search, YouTube, Maps, Gmail and Discover, and finds the people most likely to enquire.')}
                {tile(s.kind === 'demand_gen', false, () => setS({ ...s, kind: 'demand_gen' }), 'Demand Gen', 'Photo ads in YouTube, Discover and Gmail feeds — for inspiring people who are not searching yet. Best with great destination photos.')}
              </div></>}
            {s.platform === 'meta' && <><label style={lbl}>Campaign type</label>
              <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                {tile(s.kind === 'lead_form', false, () => setS({ ...s, kind: 'lead_form' }), 'Lead form', 'People fill an instant form without leaving Facebook/Instagram. Leads flow into your CRM.')}
                {tile(s.kind === 'click_to_whatsapp', false, () => setS({ ...s, kind: 'click_to_whatsapp' }), 'Click-to-WhatsApp', 'The ad opens a WhatsApp chat with you. First message is free for 72 hours.')}
              </div></>}
            <div style={{ marginTop: 16, textAlign: 'right' }}><button className="btn btn-primary" disabled={!metaOk && !googleOk} onClick={() => setStep(1)}>Next</button></div>
          </div>
        ) : step === 1 ? (
          <div>
            <div style={grid}>
              <div><label style={lbl}>Campaign name *</label><input className="form-input" value={s.name} onChange={set('name')} placeholder="Bali Honeymoon – Nov" /></div>
              <div><label style={lbl}>Destination</label><select className="form-select" value={s.destination_id} onChange={set('destination_id')}><option value="">—</option>{dests.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}</select></div>
              <div><label style={lbl}>Daily budget (₹) *</label><input className="form-input" type="number" min="1" value={s.daily_budget_rs} onChange={set('daily_budget_rs')} /></div>
              <div><label style={lbl}>Start date</label><input className="form-input" type="date" value={s.start_date} onChange={set('start_date')} /></div>
              <div><label style={lbl}>End date (optional)</label><input className="form-input" type="date" value={s.end_date} onChange={set('end_date')} /></div>
            </div>
            <hr style={{ border: 0, borderTop: '1px solid var(--border)', margin: '14px 0' }} />
            {s.platform === 'meta' ? (
              <div style={grid}>
                <div><label style={lbl}>Ad account *</label><select className="form-select" value={s.account_id} onChange={set('account_id')}>{conn.meta.ad_accounts.map(a => <option key={a.id} value={a.id}>{a.name} ({a.currency || 'INR'})</option>)}</select></div>
                <div><label style={lbl}>Facebook Page *</label><select className="form-select" value={s.page_id} onChange={set('page_id')}>{conn.meta.pages.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}</select></div>
                {s.kind === 'lead_form' ? <div><label style={lbl}>Lead form *</label><select className="form-select" value={s.lead_form_id} onChange={set('lead_form_id')}><option value="">{forms.length ? 'Choose…' : 'No forms found on this Page'}</option>{forms.map(f => <option key={f.id} value={f.id}>{f.name}</option>)}</select></div>
                  : <div><label style={lbl}>WhatsApp number *</label><input className="form-input" value={s.whatsapp_number} onChange={set('whatsapp_number')} placeholder="+91…  (must be linked to the Page)" /></div>}
                <div><label style={lbl}>Age range</label><div style={{ display: 'flex', gap: 6 }}><input className="form-input" type="number" min="18" max="65" value={s.age_min} onChange={set('age_min')} /><input className="form-input" type="number" min="18" max="65" value={s.age_max} onChange={set('age_max')} /></div></div>
                <div style={{ gridColumn: '1 / -1' }}><GeoPicker label="Locations (blank = all of India)" search={travel.metaGeo} picked={s.cities} setPicked={(cities) => setS(x => ({ ...x, cities }))} /></div>
                <div style={{ gridColumn: '1 / -1' }}>
                  <label style={{ ...lbl, display: 'flex', gap: 6, alignItems: 'center' }}><input type="checkbox" checked={s.use_sets} onChange={e => setS(x => ({ ...x, use_sets: e.target.checked }))} /> Split the budget across several ad sets <small style={{ fontWeight: 400, color: 'var(--text-3)' }}>— test different places or ages side by side</small></label>
                  {s.use_sets && <div style={{ marginTop: 6 }}>
                    {s.adsets.map((a, i) => (
                      <div key={i} className="card card-body" style={{ marginBottom: 8 }}>
                        <div style={{ display: 'grid', gridTemplateColumns: '2fr 1fr 1fr 1fr auto', gap: 6, alignItems: 'end' }}>
                          <div><label style={lbl}>Name</label><input className="form-input" value={a.name} placeholder={`Ad set ${i + 1}`} onChange={e => setS(x => ({ ...x, adsets: x.adsets.map((r, j) => j === i ? { ...r, name: e.target.value } : r) }))} /></div>
                          <div><label style={lbl}>Budget %</label><input className="form-input" type="number" min="1" max="100" value={a.budget_pct} onChange={e => setS(x => ({ ...x, adsets: x.adsets.map((r, j) => j === i ? { ...r, budget_pct: e.target.value } : r) }))} /></div>
                          <div><label style={lbl}>Age from</label><input className="form-input" type="number" min="18" max="65" placeholder={String(s.age_min)} value={a.age_min} onChange={e => setS(x => ({ ...x, adsets: x.adsets.map((r, j) => j === i ? { ...r, age_min: e.target.value } : r) }))} /></div>
                          <div><label style={lbl}>to</label><input className="form-input" type="number" min="18" max="65" placeholder={String(s.age_max)} value={a.age_max} onChange={e => setS(x => ({ ...x, adsets: x.adsets.map((r, j) => j === i ? { ...r, age_max: e.target.value } : r) }))} /></div>
                          {s.adsets.length > 2 && <button type="button" className="btn btn-sm btn-ghost" onClick={() => setS(x => ({ ...x, adsets: x.adsets.filter((_, j) => j !== i) }))}>✕</button>}
                        </div>
                        <div style={{ marginTop: 6 }}><GeoPicker label="Locations for this ad set (blank = same as above)" search={travel.metaGeo} picked={a.cities} setPicked={(cities) => setS(x => ({ ...x, adsets: x.adsets.map((r, j) => j === i ? { ...r, cities } : r) }))} /></div>
                      </div>))}
                    <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                      {s.adsets.length < 5 && <button type="button" className="btn btn-sm btn-ghost" onClick={() => setS(x => ({ ...x, adsets: [...x.adsets, { name: '', budget_pct: 0, age_min: '', age_max: '', cities: [] }] }))}>+ Add an ad set</button>}
                      <small style={{ color: Math.abs(s.adsets.reduce((n, a) => n + Number(a.budget_pct || 0), 0) - 100) < 0.001 ? 'var(--text-3)' : 'var(--danger)' }}>Shares add up to {s.adsets.reduce((n, a) => n + Number(a.budget_pct || 0), 0)}% (must be 100%).</small>
                    </div></div>}
                </div>
                {audList.length > 0 && <div style={{ gridColumn: '1 / -1' }}>
                  <label style={lbl}>Audiences <small style={{ fontWeight: 400, color: 'var(--text-3)' }}>— e.g. exclude people who already booked, or target a lookalike of them</small></label>
                  {audList.map(a => { const cur = s.audiences.find(x => x.audience_id === a.id)?.mode || ''; return (
                    <div key={a.id} style={{ display: 'flex', gap: 8, alignItems: 'center', padding: '3px 0' }}>
                      <select className="form-select" style={{ width: 130 }} value={cur} onChange={e => setS(x => ({ ...x, audiences: [...x.audiences.filter(y => y.audience_id !== a.id), ...(e.target.value ? [{ audience_id: a.id, mode: e.target.value }] : [])] }))}><option value="">Not used</option><option value="include">Target</option><option value="exclude">Exclude</option></select>
                      <span>{a.name} <small style={{ color: 'var(--text-3)' }}>({a.kind === 'lookalike' ? 'lookalike' : `${a.member_count.toLocaleString('en-IN')} people`})</small></span></div>) })}
                </div>}
              </div>
            ) : (
              <div style={grid}>
                <div style={{ gridColumn: '1 / -1' }}><label style={lbl}>Landing page *</label><input className="form-input" value={s.final_url} onChange={set('final_url')} placeholder="https://yourwebsite.com/bali-honeymoon  (needs the TripSarthi form embed)" /></div>
                <div><label style={lbl}>Languages</label>{[['en', 'English'], ['hi', 'Hindi']].map(([k, n]) => <label key={k} style={{ display: 'block' }}><input type="checkbox" checked={s.languages.includes(k)} onChange={e => setS(x => ({ ...x, languages: e.target.checked ? [...x.languages, k] : x.languages.filter(l => l !== k) }))} /> {n}</label>)}</div>
                <div style={{ gridColumn: 'span 2' }}><GeoPicker label="Locations (blank = all of India)" search={(q) => travel.googleGeo(q).then(r => r.map(x => ({ key: x.id, name: x.name })))} picked={s.geo} setPicked={(geo) => setS(x => ({ ...x, geo }))} /></div>
              </div>)}
            <div style={{ marginTop: 16, display: 'flex', justifyContent: 'space-between' }}><button className="btn btn-ghost" onClick={() => setStep(0)}>Back</button><button className="btn btn-primary" disabled={!s.name || !s.daily_budget_rs} onClick={() => setStep(2)}>Next</button></div>
          </div>
        ) : step === 2 ? (
          <div>
            <div className="card card-body" style={{ background: 'var(--primary-light)', marginBottom: 14 }}>
              <b>✨ Suggest copy</b> <small style={{ color: 'var(--text-2)' }}>— a starting point only. Prices appear only if you enter one; policy checks run on every line.</small>
              <div style={{ ...grid, marginTop: 8 }}>
                <input className="form-input" placeholder="Price from per person (₹, optional)" type="number" value={brief.price_from} onChange={e => setBrief({ ...brief, price_from: e.target.value })} />
                <input className="form-input" placeholder="Trip type (honeymoon, family…)" value={brief.trip_type} onChange={e => setBrief({ ...brief, trip_type: e.target.value })} />
                <input className="form-input" placeholder="Duration (e.g. 5N/6D)" value={brief.duration} onChange={e => setBrief({ ...brief, duration: e.target.value })} />
                <input className="form-input" placeholder="What makes you different?" value={brief.usp} onChange={e => setBrief({ ...brief, usp: e.target.value })} />
              </div>
              <button className="btn btn-sm btn-primary" style={{ marginTop: 8 }} disabled={busy === 'ai' || !(dest || s.name)} onClick={suggest}>{busy === 'ai' ? 'Thinking…' : 'Suggest'}</button>
            </div>
            {s.platform === 'meta' ? (
              <div>
                <label style={lbl}>Ad text * <Counter v={s.primary_text} max={125} /></label>
                <textarea className="form-input" rows={3} value={s.primary_text} onChange={set('primary_text')} />
                {s._alts?.primary_texts?.length > 1 && <div style={{ margin: '4px 0' }}><small>Other options: </small>{s._alts.primary_texts.slice(1).map((t, i) => <a key={i} style={{ cursor: 'pointer', marginRight: 8, fontSize: 12 }} onClick={() => setS(x => ({ ...x, primary_text: t }))}>use #{i + 2}</a>)}</div>}
                <div style={{ ...grid, marginTop: 8 }}>
                  <div><label style={lbl}>Headline <Counter v={s.headline} max={40} /></label><input className="form-input" value={s.headline} onChange={set('headline')} /></div>
                  <div><label style={lbl}>Description (optional)</label><input className="form-input" value={s.description} onChange={set('description')} /></div>
                </div>
                <div style={{ marginTop: 10 }}><AdImagePicker label="Image *" max={1} value={s.image_asset_ids} onChange={v => setS(x => ({ ...x, image_asset_ids: v }))} hint="Upload a photo (square or 1.91:1 works best) or pick one you used before." /></div>
                {s.image_asset_ids.length === 0 && <details style={{ marginTop: 6 }}><summary style={{ cursor: 'pointer', fontSize: 13, color: 'var(--text-2)' }}>…or use an image link instead</summary>
                  <input className="form-input" style={{ marginTop: 6 }} value={s.image_url} onChange={set('image_url')} placeholder="https://…/bali.jpg (a public https:// link)" /></details>}
              </div>
            ) : s.kind === 'demand_gen' ? (
              <div>
                <div><label style={lbl}>Business name * <Counter v={dg.business_name} max={25} /></label><input className="form-input" value={dg.business_name} maxLength={25} onChange={e => setDg(x => ({ ...x, business_name: e.target.value }))} /></div>
                {[['headlines', 'Headlines * (1–5, max 30 characters)', 30, 1, 5], ['descriptions', 'Descriptions * (1–5, max 90 characters)', 90, 1, 5]].map(([k, title, max, min, cap]) => (
                  <div key={k} style={{ marginTop: 10 }}>
                    <label style={lbl}>{title}</label>
                    {dg[k].map((t, i) => <div key={i} style={{ display: 'flex', gap: 6, marginBottom: 4, alignItems: 'center' }}><input className="form-input" value={t} onChange={e => setDgList(k, i, e.target.value)} /><Counter v={t} max={max} />{dg[k].length > min && <a style={{ cursor: 'pointer' }} onClick={() => setDg(x => ({ ...x, [k]: x[k].filter((_, j) => j !== i) }))}>✕</a>}</div>)}
                    {dg[k].length < cap && <button type="button" className="btn btn-sm btn-ghost" onClick={() => setDg(x => ({ ...x, [k]: [...x[k], ''] }))}>+ add</button>}
                  </div>))}
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, marginTop: 12 }}>
                  <div><label style={lbl}>Button</label><select className="form-select" value={dg.cta} onChange={e => setDg(x => ({ ...x, cta: e.target.value }))}>{DG_CTAS.map(c => <option key={c}>{c}</option>)}</select></div>
                  <div><label style={lbl}>Optimise for</label><select className="form-select" value={dg.bidding} onChange={e => setDg(x => ({ ...x, bidding: e.target.value }))}><option value="conversions">Enquiries / conversions</option><option value="clicks">Clicks</option></select></div>
                </div>
                <div style={{ marginTop: 12 }}>
                  <label style={lbl}>Where to show</label>
                  <select className="form-select" value={dg.channels} onChange={e => setDg(x => ({ ...x, channels: e.target.value }))}><option value="all">All Google channels (recommended)</option><option value="owned">Google-owned only (YouTube, Discover, Gmail)</option><option value="custom">Let me choose…</option></select>
                  {dg.channels === 'custom' && <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12, marginTop: 8 }}>{DG_CHANNELS.map(([k, n]) => <label key={k} style={{ display: 'flex', gap: 4, alignItems: 'center' }}><input type="checkbox" checked={dg.picked.includes(k)} onChange={e => setDg(x => ({ ...x, picked: e.target.checked ? [...x.picked, k] : x.picked.filter(y => y !== k) }))} />{n}</label>)}</div>}
                </div>
                <div style={{ display: 'grid', gap: 14, marginTop: 14 }}>
                  <AdImagePicker label="Landscape images * (1.91:1, at least 600×314)" shape="landscape" max={10} value={dg.landscape} onChange={v => setDg(x => ({ ...x, landscape: v }))} hint="Add a landscape OR a square image (both is best). The ratio must be exact." />
                  <AdImagePicker label="Square images (1:1, at least 300×300)" shape="square" max={10} value={dg.square} onChange={v => setDg(x => ({ ...x, square: v }))} />
                  <AdImagePicker label="Portrait images (4:5, at least 480×600)" shape="portrait" max={5} value={dg.portrait} onChange={v => setDg(x => ({ ...x, portrait: v }))} hint="Show best in Discover and Shorts feeds." />
                  <AdImagePicker label="Logo * (square, at least 128×128)" shape="square" max={5} value={dg.logo} onChange={v => setDg(x => ({ ...x, logo: v }))} />
                </div>
                <small style={{ color: 'var(--text-3)', display: 'block', marginTop: 8 }}>Image ads only (video and carousel are not available here yet). The review step asks Google to validate everything before anything is created.</small>
              </div>
            ) : s.kind === 'pmax' ? (
              <div>
                <div><label style={lbl}>Business name * <Counter v={pm.business_name} max={25} /></label><input className="form-input" value={pm.business_name} maxLength={25} onChange={e => setPm(x => ({ ...x, business_name: e.target.value }))} /></div>
                {[['headlines', 'Headlines * (3–15, max 30 characters)', 30, 3, 15], ['long_headlines', 'Long headlines * (1–5, max 90 characters)', 90, 1, 5], ['descriptions', 'Descriptions * (2–5, max 90 — at least one under 60)', 90, 2, 5]].map(([k, title, max, min, cap]) => (
                  <div key={k} style={{ marginTop: 10 }}>
                    <label style={lbl}>{title}</label>
                    {pm[k].map((t, i) => <div key={i} style={{ display: 'flex', gap: 6, marginBottom: 4, alignItems: 'center' }}><input className="form-input" value={t} onChange={e => setPmList(k, i, e.target.value)} /><Counter v={t} max={max} />{pm[k].length > min && <a style={{ cursor: 'pointer' }} onClick={() => setPm(x => ({ ...x, [k]: x[k].filter((_, j) => j !== i) }))}>✕</a>}</div>)}
                    {pm[k].length < cap && <button type="button" className="btn btn-sm btn-ghost" onClick={() => setPm(x => ({ ...x, [k]: [...x[k], ''] }))}>+ add</button>}
                  </div>))}
                <div style={{ display: 'grid', gap: 14, marginTop: 14 }}>
                  <AdImagePicker label="Landscape images * (1.91:1, e.g. 1200×628)" shape="landscape" max={5} value={pm.landscape} onChange={v => setPm(x => ({ ...x, landscape: v }))} />
                  <AdImagePicker label="Square images * (1:1, e.g. 1200×1200)" shape="square" max={5} value={pm.square} onChange={v => setPm(x => ({ ...x, square: v }))} />
                  <AdImagePicker label="Logo (optional, square)" shape="square" max={1} value={pm.logo} onChange={v => setPm(x => ({ ...x, logo: v }))} hint="A square logo, ideally on a plain background." />
                </div>
                <small style={{ color: 'var(--text-3)', display: 'block', marginTop: 8 }}>Performance Max picks the best combination of your text and images for each person. The review step asks Google to validate everything before anything is created.</small>
              </div>
            ) : (
              <div>
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center', marginBottom: 10 }}>
                  {groups.map((g, i) => <button key={i} type="button" className={'btn btn-sm ' + (i === gi ? 'btn-primary' : 'btn-ghost')} onClick={() => switchGroup(i)}>{i === gi ? (g.name || `Ad group ${i + 1}`) : (g.name || `Ad group ${i + 1}`)}</button>)}
                  {groups.length < 5 && <button type="button" className="btn btn-sm btn-ghost" onClick={addGroup} title="A separate set of keywords with its own ads">+ Ad group</button>}
                  {groups.length > 1 && <button type="button" className="btn btn-sm btn-ghost" onClick={removeGroup}>Remove this group</button>}
                </div>
                {groups.length > 1 && <div style={{ marginBottom: 8 }}><label style={lbl}>Ad group name</label><input className="form-input" value={groups[gi]?.name || ''} onChange={e => setGroups(gs => gs.map((g, j) => j === gi ? { ...g, name: e.target.value } : g))} /></div>}
                <label style={lbl}>Headlines * (3–15, max 30 characters, no “!”)</label>
                {s.headlines.map((h, i) => <div key={i} style={{ display: 'flex', gap: 6, marginBottom: 4, alignItems: 'center' }}><input className="form-input" value={h} onChange={e => setS(x => ({ ...x, headlines: x.headlines.map((v, j) => j === i ? e.target.value : v) }))} /><Counter v={h} max={30} />{s.headlines.length > 3 && <a style={{ cursor: 'pointer' }} onClick={() => setS(x => ({ ...x, headlines: x.headlines.filter((_, j) => j !== i) }))}>✕</a>}</div>)}
                {s.headlines.length < 15 && <button className="btn btn-sm btn-ghost" onClick={() => setS(x => ({ ...x, headlines: [...x.headlines, ''] }))}>+ headline</button>}
                <label style={{ ...lbl, marginTop: 10 }}>Descriptions * (2–4, max 90 characters)</label>
                {s.descriptions.map((h, i) => <div key={i} style={{ display: 'flex', gap: 6, marginBottom: 4, alignItems: 'center' }}><input className="form-input" value={h} onChange={e => setS(x => ({ ...x, descriptions: x.descriptions.map((v, j) => j === i ? e.target.value : v) }))} /><Counter v={h} max={90} /></div>)}
                {s.descriptions.length < 4 && <button className="btn btn-sm btn-ghost" onClick={() => setS(x => ({ ...x, descriptions: [...x.descriptions, ''] }))}>+ description</button>}
                <div style={{ ...grid, marginTop: 10 }}>
                  <div><label style={lbl}>Keywords * (one per line; "phrase" · [exact])</label><textarea className="form-input" rows={6} value={kw} onChange={e => setKw(e.target.value)} placeholder={'"bali honeymoon package"\n[bali tour packages]'} />
                    <small>Plain lines use: <select value={kwMatch} onChange={e => setKwMatch(e.target.value)}><option value="PHRASE">phrase</option><option value="BROAD">broad</option><option value="EXACT">exact</option></select></small></div>
                  <div><label style={lbl}>Negative keywords (one per line)</label><textarea className="form-input" rows={6} value={s.negatives} onChange={set('negatives')} placeholder={'jobs\nfree'} /></div>
                </div>
              </div>)}
            <div style={{ marginTop: 16, display: 'flex', justifyContent: 'space-between' }}><button className="btn btn-ghost" onClick={() => setStep(1)}>Back</button><button className="btn btn-primary" onClick={goReview}>Review</button></div>
          </div>
        ) : (
          <div>
            {!preview ? <p>Checking with {s.platform === 'meta' ? 'Meta' : 'Google'}… (validation only — nothing is created)</p> : (<>
              {preview.errors?.length > 0 && <div style={{ background: '#fee2e2', padding: 12, borderRadius: 10, marginBottom: 10 }}><b>Fix these first</b><ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>{preview.errors.map((e, i) => <li key={i}>{e}</li>)}</ul></div>}
              {preview.warnings?.length > 0 && <div style={{ background: '#fef9c3', padding: 12, borderRadius: 10, marginBottom: 10 }}><b>Worth a look</b><ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>{preview.warnings.map((e, i) => <li key={i}>{e}</li>)}</ul></div>}
              {preview.ok && <div style={{ background: '#dcfce7', padding: 12, borderRadius: 10, marginBottom: 10 }}>✅ {preview.notes?.join(' ') || 'Looks good.'}</div>}
              <div className="card card-body" style={{ marginBottom: 10 }}>
                <div><b>{s.name}</b> · {s.platform === 'meta' ? (s.kind === 'lead_form' ? 'Meta lead form' : 'Meta Click-to-WhatsApp') : (s.kind === 'pmax' ? 'Google Performance Max' : s.kind === 'demand_gen' ? 'Google Demand Gen' : 'Google Search')}{s.platform === 'meta' && s.use_sets ? ` · ${s.adsets.length} ad sets` : ''}{s.platform === 'google' && s.kind === 'search' && groups.length > 1 ? ` · ${groups.length} ad groups` : ''}{s.audiences.length ? ` · ${s.audiences.length} audience${s.audiences.length > 1 ? 's' : ''}` : ''}</div>
                <div>Budget: <b>₹{Number(s.daily_budget_rs).toLocaleString('en-IN')}/day</b> — about {inr((preview.monthly_estimate ?? Number(s.daily_budget_rs) * 3000))} a month if it runs all month.</div>
                <div style={{ color: 'var(--text-3)' }}>It will be created <b>paused</b>. Nothing spends until you press Launch.</div>
                {preview.launch_blockers?.length > 0 && <div style={{ color: 'var(--warning)', marginTop: 6 }}>⚠ You will not be able to launch it yet: {preview.launch_blockers.join(' ')}</div>}
              </div></>)}
            <div style={{ marginTop: 12, display: 'flex', justifyContent: 'space-between' }}><button className="btn btn-ghost" onClick={() => setStep(2)}>Back</button>
              <button className="btn btn-primary" disabled={!preview?.ok || busy === 'create'} onClick={create}>{busy === 'create' ? 'Creating…' : 'Create (paused)'}</button></div>
          </div>)}
      </div>
    </div>
  )
}
