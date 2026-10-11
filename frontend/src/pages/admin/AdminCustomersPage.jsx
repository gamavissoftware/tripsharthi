import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { admin } from '../../api/admin'
import { inr } from '../../api/travel'
import { toast } from '../../components/Toast'
import { Pill, Kpi } from './AdminUi'
import { th, td, fmtDate, ago, STATUS_COLOR, PLAN_COLOR } from './adminUtil'
import { confirmAsk } from '../../components/confirmAsk'
import { LogIn, Download, Search, Building2, Wallet, Users, CalendarClock, AlertTriangle, IndianRupee } from 'lucide-react'

const PLANS = ['free', 'starter', 'growth', 'pro']
const lbl = { display: 'block', fontSize: 12, color: 'var(--text-3)', marginBottom: 4 }

/** Right-hand panel: one customer's plan, usage, people and subscription history, with the actions the owner can take. */
/** "Log in as customer": a reason (shown to the customer in their audit log), full access or view only, then the customer app opens in a new tab. */
function LoginAsCustomer({ tenant, startOpen = false }) {
  const [open, setOpen] = useState(startOpen)
  const [reason, setReason] = useState('')
  const [viewOnly, setViewOnly] = useState(false)
  const [busy, setBusy] = useState(false)
  const [link, setLink] = useState('')
  async function go() {
    setBusy(true)
    try {
      const r = await admin.impersonate(tenant.id, { reason, view_only: viewOnly })
      const url = `${r.app_url}/#/impersonate/${r.token}`
      setLink(url); setOpen(false); setReason('')
      if (!window.open(url, '_blank', 'noopener')) toast.info('Pop-up blocked', 'Use the "Open customer app" button below.')
    } catch (e) { toast.error('Could not sign in', e.message) }
    finally { setBusy(false) }
  }
  return (
    <div className="ad-impersonate">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
        <div><b>Log in as this customer</b><div style={{ fontSize: 12.5, color: 'var(--text-3)' }}>Opens their app as the workspace owner. The owner is not signed out or notified; the visit appears in their audit log.</div></div>
        <button className="btn btn-primary btn-sm" onClick={() => setOpen(o => !o)}><LogIn size={14} /> Log in as customer</button>
      </div>
      {open && <div className="card card-body" style={{ marginTop: 10, padding: 12 }}>
        <label style={{ fontSize: 12.5, fontWeight: 700 }}>Reason (the customer can see this in their audit log)</label>
        <textarea className="form-input" rows={2} maxLength={300} value={reason} onChange={e => setReason(e.target.value)} placeholder="e.g. Customer reported invoices will not generate (ticket TS-00012)" />
        <label style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 8, fontSize: 13.5 }}><input type="checkbox" checked={viewOnly} onChange={e => setViewOnly(e.target.checked)} /> View only (no changes can be made)</label>
        <div style={{ fontSize: 12, color: 'var(--text-3)', marginTop: 6 }}>Lasts up to 2 hours. Changing the owner's password or e-mail and paying for plans are always blocked inside a session.</div>
        <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
          <button className="btn btn-primary btn-sm" disabled={busy || reason.trim().length < 10} onClick={go}>{busy ? 'Opening…' : 'Open customer app'}</button>
          <button className="btn btn-ghost btn-sm" onClick={() => setOpen(false)}>Cancel</button>
        </div>
      </div>}
      {link && <div style={{ marginTop: 8 }}><a className="btn btn-ghost btn-sm" href={link} target="_blank" rel="noreferrer noopener">Open customer app again</a> <span style={{ fontSize: 12, color: 'var(--text-3)' }}>(single-use link; end the session from the orange bar in the customer app)</span></div>}
    </div>
  )
}

function CustomerDrawer({ id, loginOpen = false, onClose, onChanged }) {
  const [d, setD] = useState(null)
  const [plan, setPlan] = useState({ plan: 'growth', days: 30, amount_rs: 0, note: '' })
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => admin.tenant(id).then(r => { setD(r); setPlan(p => ({ ...p, plan: r.tenant.plan === 'free' ? 'growth' : r.tenant.plan })) }).catch(e => toast.error('Could not load', e.message)), [id])
  useEffect(() => { load() }, [load])

  async function savePlan(force = false) {
    setBusy(true)
    try { const r = await admin.setPlan(id, { ...plan, days: Number(plan.days), amount_rs: Number(plan.amount_rs), force }); setD(r); toast.success('Plan updated', `${r.tenant.name} is now on ${r.tenant.plan}`); onChanged() }
    catch (e) { if (e.status === 409 && (await confirmAsk(e.message + '\n\nOverride anyway?'))) { setBusy(false); return savePlan(true) } toast.error('Not changed', e.message) }
    finally { setBusy(false) }
  }
  async function setStatus(status) {
    if (status === 'suspended' && !reason.trim()) { toast.error('Reason needed', 'Say why — it is kept in the audit log.'); return }
    if (!(await confirmAsk(status === 'suspended' ? 'Suspend this workspace? Everyone is signed out and cannot sign in.' : status === 'cancelled' ? 'Cancel this workspace?' : 'Reactivate this workspace?'))) return
    setBusy(true)
    try { const r = await admin.setStatus(id, { status, reason }); setD(r); setReason(''); toast.success('Status updated'); onChanged() }
    catch (e) { toast.error('Not changed', e.message) } finally { setBusy(false) }
  }

  const t = d?.tenant
  return (
    <div style={{ position: 'fixed', inset: 0, zIndex: 60, display: 'flex', justifyContent: 'flex-end', background: 'rgba(10,31,68,.35)' }} onClick={onClose}>
      <div onClick={e => e.stopPropagation()} style={{ width: 'min(560px,100%)', background: 'var(--surface)', height: '100%', overflowY: 'auto', padding: 22, boxShadow: 'var(--shadow-lg)' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'start', gap: 10 }}>
          <div><h2 style={{ margin: 0 }}>{t?.name || 'Loading…'}</h2>{t && <div style={{ color: 'var(--text-3)', fontSize: 13 }}>#{t.id} · {t.slug} · joined {fmtDate(t.created_at)}</div>}</div>
          <button className="btn btn-ghost btn-sm" onClick={onClose}>✕</button>
        </div>
        {d && <>
          <div style={{ display: 'flex', gap: 8, margin: '12px 0' }}><Pill color={PLAN_COLOR[t.plan]}>{t.plan}</Pill><Pill color={STATUS_COLOR[t.status]}>{t.status}</Pill></div>
          {d.health && <div className="cu-health">
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}><Pill color={BILL_COLOR[d.health.billing.state]}>{d.health.billing.label}</Pill>
              <span style={{ fontSize: 13.5 }}>{d.health.billing.amount_paise > 0 ? `${rs(d.health.billing.amount_paise)}/${d.health.billing.cycle === 'annual' ? 'yr' : 'mo'} · ` : ''}{d.health.billing.renews_at ? `runs to ${fmtDate(d.health.billing.renews_at)}` : 'no running paid period'}{d.health.billing.lifetime_paise > 0 ? ` · ${rs(d.health.billing.lifetime_paise)} paid so far` : ''}</span></div>
            <div className="cu-sub" style={{ marginTop: 6 }}>Last seen {ago(d.health.last_active)} · WhatsApp: {WA[d.health.whatsapp]?.[0]}{d.health.partner ? ` · referred by ${d.health.partner}` : ''}</div>
            {d.health.flags.length > 0 ? <div className="cu-flags" style={{ marginTop: 8 }}>{d.health.flags.map(x => <Flag key={x.key} f={x} />)}</div> : <div className="cu-sub" style={{ marginTop: 6, color: 'var(--success)' }}>Nothing needs attention.</div>}
          </div>}
          <LoginAsCustomer tenant={t} startOpen={loginOpen} />

          <h4 style={{ margin: '16px 0 8px' }}>Usage vs plan limits</h4>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2,1fr)', gap: 8 }}>
            {[['Contacts', d.usage.contacts, d.limits.contacts], ['Agents', d.usage.users, d.limits.agents], ['Active flows', d.usage.active_flows, d.limits.active_flows], ['WhatsApp numbers', d.usage.whatsapp_numbers, d.limits.waba_numbers]].map(([n, u, l]) => (
              <div key={n} className="card card-body" style={{ padding: 10 }}><div style={{ color: 'var(--text-3)', fontSize: 12 }}>{n}</div><b>{u}</b> <span style={{ color: 'var(--text-3)' }}>/ {l >= 999 ? '∞' : l}</span></div>))}
            <div className="card card-body" style={{ padding: 10 }}><div style={{ color: 'var(--text-3)', fontSize: 12 }}>Trips · Bookings</div><b>{d.usage.trips}</b> · <b>{d.usage.bookings}</b></div>
          </div>

          <h4 style={{ margin: '18px 0 8px' }}>People</h4>
          {d.users.map(u => <div key={u.id} style={{ display: 'flex', justifyContent: 'space-between', borderTop: '1px solid var(--border)', padding: '7px 0', fontSize: 14 }}><span><b>{u.name}</b> <Pill>{u.role}</Pill><br /><a href={`mailto:${u.email}`}>{u.email}</a></span><span style={{ color: 'var(--text-3)', fontSize: 12.5 }}>last seen {ago(u.last_login_at)}</span></div>)}

          <h4 style={{ margin: '18px 0 8px' }}>Change plan</h4>
          <div className="card card-body" style={{ padding: 12 }}>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 8 }}>
              <div><label style={lbl}>Plan</label><select className="form-select" value={plan.plan} onChange={e => setPlan({ ...plan, plan: e.target.value })}>{PLANS.map(p => <option key={p}>{p}</option>)}</select></div>
              <div><label style={lbl}>For (days)</label><input className="form-input" type="number" min="1" max="3650" value={plan.days} onChange={e => setPlan({ ...plan, days: e.target.value })} disabled={plan.plan === 'free'} /></div>
              <div><label style={lbl}>Received ₹ (incl. GST)</label><input className="form-input" type="number" min="0" value={plan.amount_rs} onChange={e => setPlan({ ...plan, amount_rs: e.target.value })} disabled={plan.plan === 'free'} /></div>
            </div>
            <input className="form-input" style={{ marginTop: 8 }} placeholder="Note (e.g. paid by bank transfer, trial extension)" value={plan.note} onChange={e => setPlan({ ...plan, note: e.target.value })} />
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 8 }}><small style={{ color: 'var(--text-3)' }}>Ends on its own and drops to Free. ₹0 = complimentary. A paid amount gets a GST invoice (the amount is taken as received including GST).</small><button className="btn btn-primary btn-sm" disabled={busy} onClick={() => savePlan(false)}>Apply</button></div>
          </div>

          <h4 style={{ margin: '18px 0 8px' }}>Subscription history</h4>
          {d.subscriptions.length === 0 && <p style={{ color: 'var(--text-3)' }}>No subscriptions yet.</p>}
          {d.subscriptions.map(s => <div key={s.id} style={{ borderTop: '1px solid var(--border)', padding: '7px 0', fontSize: 13.5, display: 'flex', justifyContent: 'space-between' }}><span><b style={{ textTransform: 'capitalize' }}>{s.plan}</b> · {s.amount_paise ? inr(s.amount_paise) : '₹0'} {s.billing_cycle} {s.manual && <Pill color="var(--warning)">by hand</Pill>}</span><span><Pill color={STATUS_COLOR[s.status]}>{s.status}</Pill> <span style={{ color: 'var(--text-3)' }}>→ {fmtDate(s.current_period_end)}</span></span></div>)}

          <h4 style={{ margin: '18px 0 8px' }}>Workspace status</h4>
          {t.status === 'active'
            ? <div className="card card-body" style={{ padding: 12 }}>
                <input className="form-input" placeholder="Reason for suspending (kept in the audit log)" value={reason} onChange={e => setReason(e.target.value)} />
                <div style={{ display: 'flex', gap: 8, marginTop: 8 }}><button className="btn btn-sm" style={{ background: 'var(--danger)', color: '#fff' }} disabled={busy} onClick={() => setStatus('suspended')}>Suspend</button><button className="btn btn-ghost btn-sm" disabled={busy} onClick={() => setStatus('cancelled')}>Mark cancelled</button></div></div>
            : <button className="btn btn-primary btn-sm" disabled={busy} onClick={() => setStatus('active')}>Reactivate workspace</button>}

          {d.activity.length > 0 && <><h4 style={{ margin: '18px 0 8px' }}>Admin activity</h4>{d.activity.map((a, i) => <div key={i} style={{ fontSize: 12.5, color: 'var(--text-3)' }}>{a.action} · {fmtDate(a.created_at)}</div>)}</>}
        </>}
      </div>
    </div>
  )
}

const SEGMENTS = [
  ['all', 'All'], ['attention', 'Needs attention'], ['paying', 'Paying'], ['complimentary', 'Complimentary'], ['free', 'Free'], ['lapsed', 'Lapsed'], ['halted', 'Payment failed'],
  ['expiring', 'Ending soon'], ['inactive', 'Gone quiet'], ['not_activated', 'Not set up'], ['tickets', 'Open requests'], ['suspended', 'Suspended'], ['referred', 'Via partner'], ['new', 'New (7 days)'],
]
const SORTS = [['newest', 'Newest first'], ['active', 'Recently active'], ['messages', 'Most WhatsApp messages'], ['renewal', 'Renewal date'], ['revenue', 'Most paid'], ['contacts', 'Most contacts'], ['name', 'Name A–Z']]
const TONE = { danger: 'var(--danger)', warn: 'var(--warning)', info: 'var(--primary)', muted: 'var(--text-3)' }
const BILL_COLOR = { paying: 'var(--success)', complimentary: '#0e8f8c', free: '#64748b', lapsed: 'var(--warning)', halted: 'var(--danger)' }
const WA = { active: ['Connected', 'var(--success)'], pending: ['Pending', 'var(--warning)'], suspended: ['Suspended', 'var(--danger)'], none: ['Not connected', 'var(--text-3)'] }

const rs = (paise) => '₹' + Math.round(paise / 100).toLocaleString('en-IN')
const csvCell = (v) => { const t = String(v ?? ''); return /^[=+\-@]/.test(t) ? `"'${t.replace(/"/g, '""')}"` : /[",\n]/.test(t) ? `"${t.replace(/"/g, '""')}"` : t }   // no spreadsheet formulas from customer-typed text

function exportCsv(rows) {
  const head = ['ID', 'Workspace', 'Plan', 'Status', 'Billing', 'Monthly paid (INR)', 'Renews / ends', 'Lifetime paid (INR)', 'Owner', 'Owner e-mail', 'Owner phone', 'Users', 'Contacts', 'Trips', 'Bookings', 'WhatsApp', 'WhatsApp sent (30d)', 'WhatsApp received (30d)', 'WhatsApp failed (30d)', 'Last payment (INR)', 'Last payment date', 'Last active', 'Joined', 'Referred by', 'Needs attention']
  const lines = rows.map(r => [r.id, r.name, r.plan, r.status, r.billing.label, r.billing.state === 'paying' ? Math.round(r.billing.amount_paise / 100) : 0, r.billing.renews_at ?? '', Math.round(r.billing.lifetime_paise / 100), r.owner.name, r.owner.email, r.owner.phone,
    r.usage.users, r.usage.contacts, r.usage.trips, r.usage.bookings, WA[r.whatsapp]?.[0] ?? r.whatsapp, r.messages.sent, r.messages.received, r.messages.failed, r.last_payment ? Math.round(r.last_payment.amount_paise / 100) : '', r.last_payment?.at ?? '', r.last_active ?? '', r.created_at, r.partner ?? '', r.attention ? 'yes' : ''])
  const csv = [head, ...lines].map(l => l.map(csvCell).join(',')).join('\n')
  const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })); a.download = `tripsarthi-customers-${new Date().toISOString().slice(0, 10)}.csv`; a.click(); URL.revokeObjectURL(a.href)
}

function Flag({ f }) { return <span className="cu-flag" style={{ '--c': TONE[f.tone] ?? TONE.muted }}>{f.label}</span> }

function UsageBar({ n, limit }) {
  if (!limit || limit >= 999) return null
  const pct = Math.min(100, Math.round((n / limit) * 100))
  return <span className="cu-bar" title={`${n} of ${limit}`}><i style={{ width: pct + '%', background: pct >= 90 ? 'var(--danger)' : pct >= 70 ? 'var(--warning)' : 'var(--primary)' }} /></span>
}

export default function AdminCustomersPage() {
  const [sp, setSp] = useSearchParams()
  const [f, setF] = useState({ q: '', plan: '', status: '', segment: 'all', sort: 'newest', page: 1 })
  const [d, setD] = useState(null)
  const [exporting, setExporting] = useState(false)
  const open = Number(sp.get('open')) || null
  const load = useCallback(() => admin.tenants(f).then(setD).catch(e => toast.error('Could not load', e.message)), [f])
  useEffect(() => { const t = setTimeout(load, f.q ? 300 : 0); return () => clearTimeout(t) }, [load, f.q])
  const pages = d ? Math.max(1, Math.ceil(d.total / d.per_page)) : 1
  const S = d?.summary
  const seg = (k) => setF({ ...f, segment: k, page: 1 })

  async function doExport() {
    setExporting(true)
    try { const all = await admin.tenants({ ...f, page: 1, per_page: 1000 }); exportCsv(all.rows); toast.success('Exported', `${all.rows.length} customers (current filters)`) }
    catch (e) { toast.error('Export failed', e.message) } finally { setExporting(false) }
  }

  return (
    <div className="page">
      <div className="page-header">
        <div><h1 className="page-title">Customers</h1><div style={{ color: 'var(--text-3)', fontSize: 13.5 }}>Every workspace on TripSarthi: who they are, what they pay, whether they use it, and what needs you.</div></div>
        <button className="btn btn-ghost btn-sm" onClick={doExport} disabled={exporting || !d}><Download size={14} /> {exporting ? 'Preparing…' : 'Export CSV'}</button>
      </div>

      {S && <div className="cu-kpis">
        <Kpi icon={Building2} label="Customers" value={S.segments.all} sub={`${S.segments.new} new this week · ${S.new_30d} this month`} />
        <Kpi icon={Wallet} tone="#1a9d57" label="Paying" value={S.segments.paying} sub={`${rs(S.mrr_paise)} per month`} />
        <Kpi icon={Users} tone="#12a89e" label="Free & complimentary" value={S.segments.free + S.segments.complimentary} sub={`${S.segments.complimentary} complimentary · ${S.segments.lapsed} lapsed`} />
        <Kpi icon={CalendarClock} tone="#f98f26" label="Ending in 7 days" value={S.segments.expiring} sub={S.segments.halted ? `${S.segments.halted} payment failed` : 'no failed payments'} />
        <Kpi icon={AlertTriangle} tone="#d93025" label="Needs attention" value={S.segments.attention} sub={`${S.segments.tickets} with open requests`} />
        <Kpi icon={IndianRupee} tone="#0a6cc4" label="Collected so far" value={rs(S.lifetime_paise)} sub="all paid periods" />
      </div>}

      <div className="cu-chips">
        {SEGMENTS.map(([k, label]) => (k === 'all' || S?.segments[k] > 0 || f.segment === k) && (
          <button key={k} type="button" className={'cu-chip' + (f.segment === k ? ' on' : '')} onClick={() => seg(k)}>{label}{S && <b>{S.segments[k]}</b>}</button>
        ))}
      </div>

      <div className="cu-filters">
        <div className="cu-search"><Search size={15} /><input value={f.q} placeholder="Search workspace, owner name, e-mail, phone, partner or #id" onChange={e => setF({ ...f, q: e.target.value, page: 1 })} /></div>
        <select className="form-select" value={f.plan} onChange={e => setF({ ...f, plan: e.target.value, page: 1 })}><option value="">All plans</option>{PLANS.map(p => <option key={p}>{p}</option>)}</select>
        <select className="form-select" value={f.status} onChange={e => setF({ ...f, status: e.target.value, page: 1 })}><option value="">All statuses</option>{['active', 'suspended', 'cancelled'].map(p => <option key={p}>{p}</option>)}</select>
        <select className="form-select" value={f.sort} onChange={e => setF({ ...f, sort: e.target.value, page: 1 })}>{SORTS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
      </div>

      <div className="card" style={{ overflowX: 'auto' }}>
        <table className="cu-table">
          <thead><tr>{['Customer', 'Owner', 'Plan & billing', 'Last payment', 'Usage', 'WhatsApp (30 days)', 'Last active', ''].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {!d && <tr><td colSpan={8} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Loading…</td></tr>}
            {d && d.rows.length === 0 && <tr><td colSpan={8} style={{ ...td, textAlign: 'center', color: 'var(--text-3)', padding: 36 }}>No customers match these filters.</td></tr>}
            {d?.rows.map(r => (
              <tr key={r.id} onClick={() => setSp({ open: r.id })}>
                <td style={td}>
                  <div className="cu-name">{r.name}{r.status !== 'active' && <Pill color={STATUS_COLOR[r.status]}>{r.status}</Pill>}</div>
                  <div className="cu-sub">#{r.id} · {r.slug} · joined {fmtDate(r.created_at)}{r.partner ? ` · via ${r.partner}` : ''}</div>
                  {r.flags.length > 0 && <div className="cu-flags">{r.flags.filter(x => x.key !== 'suspended').map(x => <Flag key={x.key} f={x} />)}</div>}
                </td>
                <td style={td} onClick={e => e.stopPropagation()}>
                  <div>{r.owner.name || '—'}</div>
                  <div className="cu-sub">{r.owner.email && <a href={`mailto:${r.owner.email}`}>{r.owner.email}</a>}</div>
                  {r.owner.phone && <div className="cu-sub"><a href={`https://wa.me/${r.owner.phone.replace(/\D/g, '')}`} target="_blank" rel="noreferrer noopener">{r.owner.phone}</a></div>}
                </td>
                <td style={td}>
                  <div style={{ display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}><Pill color={PLAN_COLOR[r.plan]}>{r.plan}</Pill><Pill color={BILL_COLOR[r.billing.state]}>{r.billing.label}</Pill></div>
                  <div className="cu-sub">{r.billing.amount_paise > 0 ? `${rs(r.billing.amount_paise)}/${r.billing.cycle === 'annual' ? 'yr' : 'mo'} · ` : ''}{r.billing.renews_at ? `to ${fmtDate(r.billing.renews_at)}` : r.billing.state === 'free' ? 'never paid' : ''}</div>
                  {r.billing.lifetime_paise > 0 && <div className="cu-sub">{rs(r.billing.lifetime_paise)} paid so far</div>}
                </td>
                <td style={td}>
                  {r.last_payment
                    ? <><div><b>{rs(r.last_payment.amount_paise)}</b> <span className="cu-sub">{r.last_payment.plan}</span></div><div className="cu-sub">{fmtDate(r.last_payment.at)}{r.last_payment.offline ? ' · recorded by hand' : ''}</div></>
                    : <span className="cu-sub">No payment yet</span>}
                </td>
                <td style={td}>
                  <div className="cu-use"><span><b>{r.usage.contacts.toLocaleString('en-IN')}</b> contacts <UsageBar n={r.usage.contacts} limit={r.usage.contacts_limit} /></span></div>
                  <div className="cu-sub"><b>{r.usage.users}</b> {r.usage.users === 1 ? 'user' : 'users'} · <b>{r.usage.trips}</b> trips · <b>{r.usage.bookings}</b> bookings</div>
                </td>
                <td style={td}>
                  <Pill color={WA[r.whatsapp]?.[1]}>{WA[r.whatsapp]?.[0] ?? r.whatsapp}</Pill>
                  <div className="cu-sub"><b>{r.messages.sent.toLocaleString('en-IN')}</b> sent · <b>{r.messages.received.toLocaleString('en-IN')}</b> received</div>
                  {r.messages.failed > 0 && <div className="cu-sub" style={{ color: 'var(--danger)' }}>{r.messages.failed} failed</div>}
                </td>
                <td style={td}>{ago(r.last_active)}</td>
                <td style={{ ...td, whiteSpace: 'nowrap' }} onClick={e => e.stopPropagation()}>
                  <button className="btn btn-ghost btn-sm" onClick={() => setSp({ open: r.id })}>Open</button>{' '}
                  <button className="btn btn-ghost btn-sm" title="Log in as this customer" onClick={() => setSp({ open: r.id, login: 1 })}><LogIn size={13} /></button>
                </td>
              </tr>))}
          </tbody>
        </table>
      </div>
      {d && d.total > d.per_page && <div style={{ display: 'flex', gap: 10, justifyContent: 'center', marginTop: 12, alignItems: 'center' }}>
        <button className="btn btn-ghost btn-sm" disabled={f.page <= 1} onClick={() => setF({ ...f, page: f.page - 1 })}>← Prev</button><span>Page {f.page} of {pages} · {d.total} customers</span><button className="btn btn-ghost btn-sm" disabled={f.page >= pages} onClick={() => setF({ ...f, page: f.page + 1 })}>Next →</button></div>}
      {d && d.total <= d.per_page && d.total > 0 && <div style={{ textAlign: 'center', color: 'var(--text-3)', fontSize: 13, marginTop: 10 }}>{d.total} {d.total === 1 ? 'customer' : 'customers'}</div>}
      {open && <CustomerDrawer id={open} loginOpen={sp.get('login') === '1'} onClose={() => setSp({})} onChanged={load} />}
    </div>
  )
}
