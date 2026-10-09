import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { admin } from '../../api/admin'
import { inr } from '../../api/travel'
import { toast } from '../../components/Toast'
import { Pill } from './AdminUi'
import { th, td, fmtDate, ago, STATUS_COLOR, PLAN_COLOR } from './adminUtil'

const PLANS = ['free', 'starter', 'growth', 'pro']
const lbl = { display: 'block', fontSize: 12, color: 'var(--text-3)', marginBottom: 4 }

/** Right-hand panel: one customer's plan, usage, people and subscription history, with the actions the owner can take. */
function CustomerDrawer({ id, onClose, onChanged }) {
  const [d, setD] = useState(null)
  const [plan, setPlan] = useState({ plan: 'growth', days: 30, amount_rs: 0, note: '' })
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => admin.tenant(id).then(r => { setD(r); setPlan(p => ({ ...p, plan: r.tenant.plan === 'free' ? 'growth' : r.tenant.plan })) }).catch(e => toast.error('Could not load', e.message)), [id])
  useEffect(() => { load() }, [load])

  async function savePlan(force = false) {
    setBusy(true)
    try { const r = await admin.setPlan(id, { ...plan, days: Number(plan.days), amount_rs: Number(plan.amount_rs), force }); setD(r); toast.success('Plan updated', `${r.tenant.name} is now on ${r.tenant.plan}`); onChanged() }
    catch (e) { if (e.status === 409 && window.confirm(e.message + '\n\nOverride anyway?')) { setBusy(false); return savePlan(true) } toast.error('Not changed', e.message) }
    finally { setBusy(false) }
  }
  async function setStatus(status) {
    if (status === 'suspended' && !reason.trim()) { toast.error('Reason needed', 'Say why — it is kept in the audit log.'); return }
    if (!window.confirm(status === 'suspended' ? 'Suspend this workspace? Everyone is signed out and cannot sign in.' : status === 'cancelled' ? 'Cancel this workspace?' : 'Reactivate this workspace?')) return
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
              <div><label style={lbl}>Received ₹</label><input className="form-input" type="number" min="0" value={plan.amount_rs} onChange={e => setPlan({ ...plan, amount_rs: e.target.value })} disabled={plan.plan === 'free'} /></div>
            </div>
            <input className="form-input" style={{ marginTop: 8 }} placeholder="Note (e.g. paid by bank transfer, trial extension)" value={plan.note} onChange={e => setPlan({ ...plan, note: e.target.value })} />
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 8 }}><small style={{ color: 'var(--text-3)' }}>Ends on its own and drops to Free. ₹0 = complimentary.</small><button className="btn btn-primary btn-sm" disabled={busy} onClick={() => savePlan(false)}>Apply</button></div>
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

export default function AdminCustomersPage() {
  const [sp, setSp] = useSearchParams()
  const [f, setF] = useState({ q: '', plan: '', status: '', page: 1 })
  const [d, setD] = useState(null)
  const open = Number(sp.get('open')) || null
  const load = useCallback(() => admin.tenants(f).then(setD).catch(e => toast.error('Could not load', e.message)), [f])
  useEffect(() => { const t = setTimeout(load, f.q ? 300 : 0); return () => clearTimeout(t) }, [load, f.q])
  const pages = d ? Math.max(1, Math.ceil(d.total / d.per_page)) : 1
  return (
    <div className="page">
      <div className="page-header"><h1 className="page-title">Customers</h1>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <input className="form-input" style={{ width: 230 }} placeholder="Search name, email, workspace…" value={f.q} onChange={e => setF({ ...f, q: e.target.value, page: 1 })} />
          <select className="form-select" value={f.plan} onChange={e => setF({ ...f, plan: e.target.value, page: 1 })}><option value="">All plans</option>{PLANS.map(p => <option key={p}>{p}</option>)}</select>
          <select className="form-select" value={f.status} onChange={e => setF({ ...f, status: e.target.value, page: 1 })}><option value="">All statuses</option>{['active', 'suspended', 'cancelled'].map(p => <option key={p}>{p}</option>)}</select>
        </div>
      </div>
      <div className="card" style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Customer', 'Owner', 'Plan', 'Status', 'Subscription', 'Team', 'Contacts', 'Last seen', 'Joined'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {!d && <tr><td colSpan={9} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Loading…</td></tr>}
            {d && d.rows.length === 0 && <tr><td colSpan={9} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No customers match.</td></tr>}
            {d?.rows.map(r => (
              <tr key={r.id} onClick={() => setSp({ open: r.id })} style={{ cursor: 'pointer' }}>
                <td style={td}><b>{r.name}</b><div style={{ color: 'var(--text-3)', fontSize: 12 }}>{r.slug}</div></td>
                <td style={td}>{r.owner_name}<div style={{ color: 'var(--text-3)', fontSize: 12 }}>{r.owner_email}</div></td>
                <td style={td}><Pill color={PLAN_COLOR[r.plan]}>{r.plan}</Pill></td>
                <td style={td}><Pill color={STATUS_COLOR[r.status]}>{r.status}</Pill></td>
                <td style={td}>{r.sub_status ? <><Pill color={STATUS_COLOR[r.sub_status]}>{r.sub_status}</Pill><div style={{ color: 'var(--text-3)', fontSize: 12 }}>to {fmtDate(r.sub_ends)}</div></> : '—'}</td>
                <td style={td}>{r.users}</td><td style={td}>{r.contacts.toLocaleString('en-IN')}</td><td style={td}>{ago(r.last_login_at)}</td><td style={td}>{fmtDate(r.created_at)}</td>
              </tr>))}
          </tbody>
        </table>
      </div>
      {d && d.total > d.per_page && <div style={{ display: 'flex', gap: 10, justifyContent: 'center', marginTop: 12, alignItems: 'center' }}>
        <button className="btn btn-ghost btn-sm" disabled={f.page <= 1} onClick={() => setF({ ...f, page: f.page - 1 })}>← Prev</button><span>Page {f.page} of {pages} · {d.total} customers</span><button className="btn btn-ghost btn-sm" disabled={f.page >= pages} onClick={() => setF({ ...f, page: f.page + 1 })}>Next →</button></div>}
      {open && <CustomerDrawer id={open} onClose={() => setSp({})} onChanged={load} />}
    </div>
  )
}
