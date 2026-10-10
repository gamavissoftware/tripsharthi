import { Fragment, useEffect, useState, useCallback } from 'react'
import { admin } from '../../api/admin'
import { inr } from '../../api/travel'
import { toast } from '../../components/Toast'
import { Pill } from './AdminUi'
import { th, td, fmtDate, fmtDateTime, istDate, PLAN_COLOR } from './adminUtil'

const PLANS = ['starter', 'growth', 'pro']
const STATE_COLOR = { live: 'var(--success)', scheduled: 'var(--warning)', expired: 'var(--text-3)', ended: 'var(--text-3)', inactive: 'var(--text-3)', used_up: 'var(--danger)' }
const STATE_LABEL = { used_up: 'used up' }
const TABS = [['coupons', 'Coupon codes'], ['promotions', 'Promotions'], ['grants', 'Free days']]

function Field({ label, hint, children, style }) {
  return <div className="form-group" style={{ margin: 0, ...style }}><label className="form-label">{label}</label>{children}{hint && <div style={{ fontSize: 11.5, color: 'var(--text-3)', marginTop: 3 }}>{hint}</div>}</div>
}

function PlanPicker({ value, onChange, disabled }) {
  const on = new Set(value)
  return (
    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', paddingTop: 6 }}>
      {PLANS.map(p => (
        <label key={p} style={{ display: 'flex', gap: 5, alignItems: 'center', fontSize: 13, textTransform: 'capitalize' }}>
          <input type="checkbox" disabled={disabled} checked={on.has(p)} onChange={e => { const n = new Set(on); e.target.checked ? n.add(p) : n.delete(p); onChange([...n]) }} /> {p}
        </label>))}
      <span style={{ fontSize: 11.5, color: 'var(--text-3)' }}>{value.length === 0 ? 'none ticked = every plan' : ''}</span>
    </div>
  )
}

// ── Coupons ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
const BLANK_COUPON = { code: '', description: '', type: 'percent', value: 20, value_rs: 500, plans: [], cycle: 'any', duration_periods: 1, max_redemptions: '', max_per_tenant: 1, new_customers_only: false, starts_at: '', expires_at: '' }

function randomCode() { const a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; return Array.from({ length: 8 }, () => a[Math.floor(Math.random() * a.length)]).join('') }

function CouponForm({ initial, onSaved, onCancel }) {
  const editing = Boolean(initial?.id)
  const locked = editing && (initial.redeemed_count > 0 || initial.payments > 0)
  const [f, setF] = useState(() => editing
    ? { ...BLANK_COUPON, ...initial, value: initial.type === 'percent' ? initial.value : 20, value_rs: initial.type === 'flat' ? initial.value / 100 : 500, max_redemptions: initial.max_redemptions ?? '', starts_at: istDate(initial.starts_at), expires_at: istDate(initial.expires_at) }
    : BLANK_COUPON)
  const [busy, setBusy] = useState(false)
  const set = (k, v) => setF(x => ({ ...x, [k]: v }))
  async function save(e) {
    e.preventDefault(); setBusy(true)
    const body = { description: f.description, active: true, starts_at: f.starts_at || null, expires_at: f.expires_at || null, max_redemptions: f.max_redemptions === '' ? null : Number(f.max_redemptions) }
    if (!locked) Object.assign(body, { type: f.type, plans: f.plans, cycle: f.cycle, duration_periods: Number(f.duration_periods), max_per_tenant: Number(f.max_per_tenant), new_customers_only: f.new_customers_only, ...(f.type === 'percent' ? { value: Number(f.value) } : { value_rs: Number(f.value_rs) }) })
    try {
      const r = editing ? await admin.updateCoupon(initial.id, body) : await admin.createCoupon({ ...body, code: f.code })
      toast.success(editing ? 'Coupon updated' : 'Coupon created', r.code); onSaved()
    } catch (err) { toast.error('Not saved', err.message) } finally { setBusy(false) }
  }
  return (
    <form onSubmit={save} className="card card-body" style={{ marginBottom: 14, display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit,minmax(210px,1fr))' }}>
      <div style={{ gridColumn: '1/-1', fontWeight: 700 }}>{editing ? `Edit ${initial.code}` : 'New coupon code'}</div>
      {locked && <div style={{ gridColumn: '1/-1', fontSize: 13, color: 'var(--warning)' }}>This code has already been used, so its discount rules are locked (customers were promised them). You can still change the description, dates, total uses and switch it off.</div>}
      {!editing && <Field label="Code" hint="3-30 letters, numbers, - or _. Customers type it at checkout (not case-sensitive)."><div style={{ display: 'flex', gap: 6 }}><input className="form-input" value={f.code} onChange={e => set('code', e.target.value.toUpperCase())} required placeholder="DIWALI25" /><button type="button" className="btn btn-ghost btn-sm" onClick={() => set('code', randomCode())}>Generate</button></div></Field>}
      <Field label="Description (internal)"><input className="form-input" value={f.description} onChange={e => set('description', e.target.value)} placeholder="Diwali newsletter offer" /></Field>
      <Field label="Discount type"><select className="form-select" disabled={locked} value={f.type} onChange={e => set('type', e.target.value)}><option value="percent">Percent off</option><option value="flat">Flat ₹ off</option></select></Field>
      {f.type === 'percent'
        ? <Field label="Percent off" hint="1-90. A fully free period is a grant (Free days tab)."><input className="form-input" type="number" min="1" max="90" disabled={locked} value={f.value} onChange={e => set('value', e.target.value)} required /></Field>
        : <Field label="Amount off (₹)"><input className="form-input" type="number" min="1" step="1" disabled={locked} value={f.value_rs} onChange={e => set('value_rs', e.target.value)} required /></Field>}
      <Field label="Applies to plans"><PlanPicker value={f.plans} disabled={locked} onChange={v => set('plans', v)} /></Field>
      <Field label="Billing cycle"><select className="form-select" disabled={locked} value={f.cycle} onChange={e => set('cycle', e.target.value)}><option value="any">Monthly or annual</option><option value="monthly">Monthly only</option><option value="annual">Annual only</option></select></Field>
      <Field label="How many payments" hint="1 = first payment only. Later payments are discounted automatically."><select className="form-select" disabled={locked} value={f.duration_periods} onChange={e => set('duration_periods', e.target.value)}><option value={1}>First payment only</option><option value={3}>First 3 payments</option><option value={6}>First 6 payments</option><option value={12}>First 12 payments</option><option value={0}>Every renewal</option></select></Field>
      <Field label="Total customers (optional)" hint="Empty = unlimited."><input className="form-input" type="number" min="1" value={f.max_redemptions} onChange={e => set('max_redemptions', e.target.value)} /></Field>
      <Field label="Uses per customer" hint="For one-payment codes. Multi-payment codes run once per customer."><input className="form-input" type="number" min="1" disabled={locked} value={f.max_per_tenant} onChange={e => set('max_per_tenant', e.target.value)} /></Field>
      <Field label="Valid from (optional)"><input className="form-input" type="date" value={f.starts_at} onChange={e => set('starts_at', e.target.value)} /></Field>
      <Field label="Expires on (optional)" hint="Valid through the end of that day."><input className="form-input" type="date" value={f.expires_at} onChange={e => set('expires_at', e.target.value)} /></Field>
      <Field label="Who can use it"><label style={{ display: 'flex', gap: 6, alignItems: 'center', paddingTop: 8, fontSize: 13 }}><input type="checkbox" disabled={locked} checked={f.new_customers_only} onChange={e => set('new_customers_only', e.target.checked)} /> New customers only (never paid before)</label></Field>
      <div style={{ gridColumn: '1/-1', display: 'flex', gap: 8 }}><button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : editing ? 'Save changes' : 'Create coupon'}</button><button type="button" className="btn btn-ghost" onClick={onCancel}>Cancel</button></div>
    </form>
  )
}

function ruleText(c) {
  const v = c.type === 'percent' ? `${c.value}% off` : `${inr(c.value)} off`
  const dur = c.duration_periods === 1 ? 'first payment' : c.duration_periods === 0 ? 'every renewal' : `first ${c.duration_periods} payments`
  return `${v} · ${dur}`
}

function CouponsTab() {
  const [rows, setRows] = useState(null)
  const [form, setForm] = useState(null)          // null | {} (new) | coupon (edit)
  const [open, setOpen] = useState(null)          // coupon whose redemptions are shown
  const [reds, setReds] = useState(null)
  const load = useCallback(() => admin.coupons().then(setRows).catch(e => toast.error('Could not load', e.message)), [])
  useEffect(() => { load() }, [load])
  async function toggle(c) { try { await admin.updateCoupon(c.id, { active: !c.active }); load() } catch (e) { toast.error('Not changed', e.message) } }
  async function showReds(c) { if (open?.id === c.id) { setOpen(null); return } setOpen(c); setReds(null); try { setReds(await admin.couponRedemptions(c.id)) } catch (e) { toast.error('Could not load', e.message) } }
  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <p style={{ margin: 0, color: 'var(--text-3)', fontSize: 13 }}>Codes customers type at checkout. A code and an automatic promotion do not combine: the bigger discount wins.</p>
        {!form && <button className="btn btn-primary" onClick={() => setForm({})}>+ New coupon</button>}
      </div>
      {form && <CouponForm key={form.id ?? 'new'} initial={form.id ? form : null} onCancel={() => setForm(null)} onSaved={() => { setForm(null); load() }} />}
      <div className="card" style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Code', 'Discount', 'Plans', 'Valid', 'Used by', 'Discount given', 'Status', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {!rows && <tr><td colSpan={8} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Loading…</td></tr>}
            {rows?.length === 0 && <tr><td colSpan={8} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No coupons yet. Create one to offer a discount at checkout.</td></tr>}
            {rows?.map(c => (
              <Fragment key={c.id}>
                <tr>
                  <td style={td}><b style={{ fontFamily: 'ui-monospace,monospace' }}>{c.code}</b>{c.description && <div style={{ fontSize: 12, color: 'var(--text-3)' }}>{c.description}</div>}</td>
                  <td style={td}>{ruleText(c)}{c.new_customers_only && <div style={{ fontSize: 12, color: 'var(--text-3)' }}>new customers only</div>}</td>
                  <td style={td}>{c.plans.length ? c.plans.map(p => <Pill key={p} color={PLAN_COLOR[p]}>{p}</Pill>) : 'All'}{c.cycle !== 'any' && <div style={{ fontSize: 12, color: 'var(--text-3)' }}>{c.cycle} only</div>}</td>
                  <td style={td}>{c.starts_at && c.expires_at ? `${fmtDate(c.starts_at)} → ${fmtDate(c.expires_at)}` : c.starts_at ? `from ${fmtDate(c.starts_at)}` : c.expires_at ? `until ${fmtDate(c.expires_at)}` : 'no expiry'}</td>
                  <td style={td}>{c.redeemed_count}{c.max_redemptions != null && ` / ${c.max_redemptions}`}</td>
                  <td style={td}>{c.discount_paise != null ? <span title={`${c.payments} payments · ${inr(c.revenue_paise)} collected`}>{inr(c.discount_paise)}</span> : <span style={{ color: 'var(--text-3)' }}>—</span>}</td>
                  <td style={td}><Pill color={STATE_COLOR[c.state]}>{STATE_LABEL[c.state] || c.state}</Pill></td>
                  <td style={{ ...td, whiteSpace: 'nowrap' }}>
                    <button className="btn btn-ghost btn-sm" onClick={() => setForm(c)}>Edit</button>{' '}
                    <button className="btn btn-ghost btn-sm" onClick={() => toggle(c)}>{c.active ? 'Switch off' : 'Switch on'}</button>{' '}
                    {c.redeemed_count > 0 && <button className="btn btn-ghost btn-sm" onClick={() => showReds(c)}>{open?.id === c.id ? 'Hide uses' : 'Uses'}</button>}
                  </td>
                </tr>
                {open?.id === c.id && (
                  <tr><td colSpan={8} style={{ ...td, background: 'var(--bg)' }}>
                    {!reds ? 'Loading…' : reds.length === 0 ? 'No payments yet.' : (
                      <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                        <thead><tr>{['Customer', 'Plan', 'List', 'Discount', 'Paid', 'Status', 'When'].map(h => <th key={h} style={{ ...th, padding: '4px 8px' }}>{h}</th>)}</tr></thead>
                        <tbody>{reds.map(r => <tr key={r.id}><td style={{ padding: '4px 8px' }}>{r.tenant_name || `#${r.tenant_id}`}</td><td style={{ padding: '4px 8px' }}>{r.plan} · {r.cycle}</td><td style={{ padding: '4px 8px' }}>{inr(r.list_paise)}</td><td style={{ padding: '4px 8px' }}>−{inr(r.discount_paise)}</td><td style={{ padding: '4px 8px' }}>{inr(r.charged_paise)}</td><td style={{ padding: '4px 8px' }}>{r.status}</td><td style={{ padding: '4px 8px' }}>{fmtDateTime(r.applied_at || r.created_at)}</td></tr>)}</tbody>
                      </table>)}
                  </td></tr>)}
              </Fragment>))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

// ── Promotions ───────────────────────────────────────────────────────────────────────────────────────────────────────────
const BLANK_PROMO = { name: '', badge: '', percent: 15, plans: [], cycle: 'any', starts_at: '', ends_at: '' }

function PromoForm({ initial, onSaved, onCancel }) {
  const editing = Boolean(initial?.id)
  const [f, setF] = useState(editing ? { ...BLANK_PROMO, ...initial, starts_at: istDate(initial.starts_at), ends_at: istDate(initial.ends_at) } : BLANK_PROMO)
  const [busy, setBusy] = useState(false)
  const set = (k, v) => setF(x => ({ ...x, [k]: v }))
  async function save(e) {
    e.preventDefault(); setBusy(true)
    const body = { name: f.name, badge: f.badge, percent: Number(f.percent), plans: f.plans, cycle: f.cycle, starts_at: f.starts_at, ends_at: f.ends_at, active: true }
    try { editing ? await admin.updatePromotion(initial.id, body) : await admin.createPromotion(body); toast.success(editing ? 'Promotion updated' : 'Promotion created', f.name); onSaved() }
    catch (err) { toast.error('Not saved', err.message) } finally { setBusy(false) }
  }
  return (
    <form onSubmit={save} className="card card-body" style={{ marginBottom: 14, display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit,minmax(210px,1fr))' }}>
      <div style={{ gridColumn: '1/-1', fontWeight: 700 }}>{editing ? `Edit ${initial.name}` : 'New plan promotion'}</div>
      <Field label="Name (internal)"><input className="form-input" value={f.name} onChange={e => set('name', e.target.value)} required placeholder="Festive season 2026" /></Field>
      <Field label="Label customers see" hint="Shown next to the price, e.g. “Festive 15% off”."><input className="form-input" value={f.badge} onChange={e => set('badge', e.target.value)} maxLength={40} /></Field>
      <Field label="Percent off" hint="1-90"><input className="form-input" type="number" min="1" max="90" value={f.percent} onChange={e => set('percent', e.target.value)} required /></Field>
      <Field label="Plans"><PlanPicker value={f.plans} onChange={v => set('plans', v)} /></Field>
      <Field label="Billing cycle"><select className="form-select" value={f.cycle} onChange={e => set('cycle', e.target.value)}><option value="any">Monthly or annual</option><option value="monthly">Monthly only</option><option value="annual">Annual only</option></select></Field>
      <Field label="Starts"><input className="form-input" type="date" value={f.starts_at} onChange={e => set('starts_at', e.target.value)} required /></Field>
      <Field label="Ends" hint="Through the end of that day."><input className="form-input" type="date" value={f.ends_at} onChange={e => set('ends_at', e.target.value)} required /></Field>
      <div style={{ gridColumn: '1/-1', display: 'flex', gap: 8 }}><button className="btn btn-primary" disabled={busy}>{busy ? 'Saving…' : editing ? 'Save changes' : 'Create promotion'}</button><button type="button" className="btn btn-ghost" onClick={onCancel}>Cancel</button></div>
    </form>
  )
}

function PromotionsTab() {
  const [rows, setRows] = useState(null)
  const [form, setForm] = useState(null)
  const load = useCallback(() => admin.promotions().then(setRows).catch(e => toast.error('Could not load', e.message)), [])
  useEffect(() => { load() }, [load])
  async function toggle(p) { try { await admin.updatePromotion(p.id, { active: !p.active }); load() } catch (e) { toast.error('Not changed', e.message) } }
  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <p style={{ margin: 0, color: 'var(--text-3)', fontSize: 13 }}>A time-limited price cut shown to every customer on the Billing page. No code needed.</p>
        {!form && <button className="btn btn-primary" onClick={() => setForm({})}>+ New promotion</button>}
      </div>
      {form && <PromoForm key={form.id ?? 'new'} initial={form.id ? form : null} onCancel={() => setForm(null)} onSaved={() => { setForm(null); load() }} />}
      <div className="card" style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Promotion', 'Discount', 'Plans', 'Runs', 'Status', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {!rows && <tr><td colSpan={6} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Loading…</td></tr>}
            {rows?.length === 0 && <tr><td colSpan={6} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No promotions yet.</td></tr>}
            {rows?.map(p => (
              <tr key={p.id}>
                <td style={td}><b>{p.name}</b>{p.badge && <div style={{ fontSize: 12, color: 'var(--text-3)' }}>shows “{p.badge}”</div>}</td>
                <td style={td}>{p.percent}% off</td>
                <td style={td}>{p.plans.length ? p.plans.map(x => <Pill key={x} color={PLAN_COLOR[x]}>{x}</Pill>) : 'All'}{p.cycle !== 'any' && <div style={{ fontSize: 12, color: 'var(--text-3)' }}>{p.cycle} only</div>}</td>
                <td style={td}>{fmtDate(p.starts_at)} → {fmtDate(p.ends_at)}</td>
                <td style={td}><Pill color={STATE_COLOR[p.state]}>{p.state}</Pill></td>
                <td style={{ ...td, whiteSpace: 'nowrap' }}><button className="btn btn-ghost btn-sm" onClick={() => setForm(p)}>Edit</button>{' '}<button className="btn btn-ghost btn-sm" onClick={() => toggle(p)}>{p.active ? 'Switch off' : 'Switch on'}</button></td>
              </tr>))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

// ── Free days (trials + extensions) ─────────────────────────────────────────────────────────────────────────────────────────
function GrantsTab() {
  const [rows, setRows] = useState(null)
  const [q, setQ] = useState('')
  const [found, setFound] = useState([])
  const [cust, setCust] = useState(null)
  const [f, setF] = useState({ kind: 'trial', plan: 'growth', days: 14, reason: '' })
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => admin.grants().then(setRows).catch(e => toast.error('Could not load', e.message)), [])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    if (q.trim().length < 2) return undefined
    const t = setTimeout(() => admin.tenants({ q }).then(r => setFound(r.rows || [])).catch(() => setFound([])), 300)
    return () => clearTimeout(t)
  }, [q])
  async function save(e) {
    e.preventDefault(); if (!cust) { toast.error('Choose a customer first'); return }
    setBusy(true)
    try { await admin.grant({ tenant_id: cust.id, kind: f.kind, plan: f.kind === 'trial' ? f.plan : '', days: Number(f.days), reason: f.reason }); toast.success(f.kind === 'trial' ? 'Trial started' : 'Period extended', `${cust.name} · ${f.days} days`); setF({ ...f, reason: '' }); setCust(null); setQ(''); setFound([]); load() }
    catch (err) { toast.error('Not granted', err.message) } finally { setBusy(false) }
  }
  return (
    <div>
      <form onSubmit={save} className="card card-body" style={{ marginBottom: 14, display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit,minmax(210px,1fr))' }}>
        <div style={{ gridColumn: '1/-1', fontWeight: 700 }}>Give a customer free days</div>
        <Field label="Customer" hint={cust ? 'Selected' : 'Search by name or email (2+ letters)'} style={{ position: 'relative' }}>
          {cust
            ? <div style={{ display: 'flex', gap: 8, alignItems: 'center', paddingTop: 6 }}><b>{cust.name}</b><Pill color={PLAN_COLOR[cust.plan]}>{cust.plan}</Pill><button type="button" className="btn btn-ghost btn-sm" onClick={() => { setCust(null); setQ('') }}>Change</button></div>
            : <><input className="form-input" value={q} onChange={e => setQ(e.target.value)} placeholder="Sunrise Tours" />
              {found.length > 0 && <div className="card" style={{ position: 'absolute', zIndex: 5, left: 0, right: 0, top: '100%', maxHeight: 220, overflow: 'auto' }}>{found.slice(0, 8).map(t => <button type="button" key={t.id} onClick={() => { setCust(t); setFound([]) }} style={{ display: 'block', width: '100%', textAlign: 'left', padding: '8px 12px', background: 'none', border: 0, borderBottom: '1px solid var(--border)', cursor: 'pointer' }}><b>{t.name}</b> <span style={{ color: 'var(--text-3)', fontSize: 12 }}>{t.owner_email} · {t.plan}</span></button>)}</div>}</>}
        </Field>
        <Field label="What to give" hint={f.kind === 'trial' ? 'For a customer with no running plan.' : 'Adds days to their running paid period.'}>
          <select className="form-select" value={f.kind} onChange={e => setF({ ...f, kind: e.target.value })}><option value="trial">Free trial of a plan</option><option value="extension">Extend their current plan</option></select>
        </Field>
        {f.kind === 'trial' && <Field label="Plan"><select className="form-select" value={f.plan} onChange={e => setF({ ...f, plan: e.target.value })}>{PLANS.map(p => <option key={p}>{p}</option>)}</select></Field>}
        <Field label="Days" hint="1-365"><input className="form-input" type="number" min="1" max="365" value={f.days} onChange={e => setF({ ...f, days: e.target.value })} required /></Field>
        <Field label="Reason (kept in the audit log)" style={{ gridColumn: '1/-1' }}><input className="form-input" value={f.reason} onChange={e => setF({ ...f, reason: e.target.value })} required minLength={5} maxLength={300} placeholder="Demo for Sunrise Tours / goodwill after the 8 Oct outage" /></Field>
        <div style={{ gridColumn: '1/-1' }}><button className="btn btn-primary" disabled={busy || !cust}>{busy ? 'Saving…' : 'Grant'}</button></div>
      </form>
      <div className="card" style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['When', 'Customer', 'Given', 'Plan ends', 'Reason', 'By'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {!rows && <tr><td colSpan={6} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Loading…</td></tr>}
            {rows?.length === 0 && <tr><td colSpan={6} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No grants yet.</td></tr>}
            {rows?.map(g => (
              <tr key={g.id}>
                <td style={td}>{fmtDateTime(g.created_at)}</td>
                <td style={td}><b>{g.tenant_name || `#${g.tenant_id}`}</b></td>
                <td style={td}>{g.kind === 'trial' ? <>Trial <Pill color={PLAN_COLOR[g.plan]}>{g.plan}</Pill></> : 'Extension'} · {g.days} days</td>
                <td style={td}>{g.period_end_before ? `${fmtDate(g.period_end_before)} → ` : ''}{fmtDate(g.period_end_after)}</td>
                <td style={{ ...td, maxWidth: 320 }}>{g.reason}</td>
                <td style={td}>{g.granted_by_name || '—'}</td>
              </tr>))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

export default function AdminOffersPage() {
  const [tab, setTab] = useState('coupons')
  return (
    <div className="page">
      <div className="page-header"><h1 className="page-title">Offers</h1></div>
      <div style={{ display: 'flex', gap: 6, marginBottom: 16, flexWrap: 'wrap' }}>
        {TABS.map(([k, label]) => <button key={k} className={'btn btn-sm ' + (tab === k ? 'btn-primary' : 'btn-ghost')} onClick={() => setTab(k)}>{label}</button>)}
      </div>
      {tab === 'coupons' && <CouponsTab />}
      {tab === 'promotions' && <PromotionsTab />}
      {tab === 'grants' && <GrantsTab />}
    </div>
  )
}
