import { cloneElement, useEffect, useMemo, useState, useCallback } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Handshake, Users, Wallet, Hourglass, Landmark, CheckCircle2, Copy, Search } from 'lucide-react'
import { admin } from '../../api/admin'
import { inr } from '../../api/travel'
import { toast } from '../../components/Toast'
import { Kpi, Pill, InviteBox } from './AdminUi'
import { th, td, ago, PARTNER_STATUS_COLOR } from './adminUtil'

const termsText = (p) => `${p.commission_pct}% · ${p.commission_months === 0 ? 'lifetime' : `${p.commission_months} mo`} · ${p.hold_days}d hold`

const BLANK = { name: '', email: '', company: '', phone: '', code: '', commission_pct: 20, commission_months: 12, hold_days: 30, notes: '', send_email: true }

function NewPartnerForm({ onCreated, onCancel }) {
  const [f, setF] = useState(BLANK)
  const [busy, setBusy] = useState(false)
  const set = (k, v) => setF(x => ({ ...x, [k]: v }))
  async function save(e) {
    e.preventDefault(); setBusy(true)
    try { const r = await admin.createPartner({ ...f, commission_pct: Number(f.commission_pct), commission_months: Number(f.commission_months), hold_days: Number(f.hold_days) }); toast.success('Partner created', r.partner.name); onCreated(r) }
    catch (err) { toast.error('Not saved', err.message) } finally { setBusy(false) }
  }
  const G = ({ label, hint, children }) => { const id = 'pt-' + label.replace(/\W+/g, ''); return <div className="form-group" style={{ margin: 0 }}><label className="form-label" htmlFor={id}>{label}{children.props.required ? ' *' : ''}</label>{cloneElement(children, { id })}{hint && <div style={{ fontSize: 11.5, color: 'var(--text-3)', marginTop: 3 }}>{hint}</div>}</div> }
  return (
    <form onSubmit={save} className="card card-body" style={{ marginBottom: 14, display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit,minmax(210px,1fr))' }}>
      <div style={{ gridColumn: '1/-1', fontWeight: 700 }}>New referral partner</div>
      {G({ label: 'Name', children: <input className="form-input" value={f.name} onChange={e => set('name', e.target.value)} required minLength={2} maxLength={120} autoComplete="off" /> })}
      {G({ label: 'Email (their login)', children: <input className="form-input" type="email" inputMode="email" autoCapitalize="none" autoComplete="off" value={f.email} onChange={e => set('email', e.target.value)} required /> })}
      {G({ label: 'Company (optional)', children: <input className="form-input" value={f.company} onChange={e => set('company', e.target.value)} /> })}
      {G({ label: 'Phone (optional)', children: <input className="form-input" type="tel" pattern="[0-9+\-() ]{7,20}" title="Digits only, with an optional + at the start" value={f.phone} onChange={e => set('phone', e.target.value)} /> })}
      {G({ label: 'Referral code (optional)', hint: 'Leave empty to generate one. 3-20 letters/numbers.', children: <input className="form-input" value={f.code} onChange={e => set('code', e.target.value.toUpperCase())} minLength={3} maxLength={20} pattern="[A-Za-z0-9]{3,20}" title="3 to 20 letters or numbers, no spaces" autoCapitalize="characters" /> })}
      {G({ label: 'Commission %', hint: 'Of what each referred customer actually pays.', children: <input className="form-input" type="number" min="1" max="60" step="0.5" value={f.commission_pct} onChange={e => set('commission_pct', e.target.value)} required /> })}
      {G({ label: 'For how many months', hint: '0 = lifetime. Counted from the first commission.', children: <input className="form-input" type="number" min="0" max="120" value={f.commission_months} onChange={e => set('commission_months', e.target.value)} required /> })}
      {G({ label: 'Hold (days)', hint: 'A commission becomes payable this many days after the payment (the refund window).', children: <input className="form-input" type="number" min="0" max="180" value={f.hold_days} onChange={e => set('hold_days', e.target.value)} required /> })}
      <label style={{ gridColumn: '1/-1', display: 'flex', gap: 8, alignItems: 'center', fontSize: 14, cursor: 'pointer' }}><input type="checkbox" checked={f.send_email} onChange={e => set('send_email', e.target.checked)} /> E-mail the invite link to the partner now <span style={{ color: 'var(--text-3)', fontSize: 12.5 }}>(you still get the link to copy)</span></label>
      <div style={{ gridColumn: '1/-1' }}>{G({ label: 'Internal notes', children: <input className="form-input" value={f.notes} onChange={e => set('notes', e.target.value)} /> })}</div>
      <div style={{ gridColumn: '1/-1', display: 'flex', gap: 8 }}><button className="btn btn-primary" disabled={busy}>{busy ? 'Creating…' : 'Create partner'}</button><button type="button" className="btn btn-ghost" onClick={onCancel}>Cancel</button></div>
    </form>
  )
}

const rs = (p) => '₹' + Math.round((p || 0) / 100).toLocaleString('en-IN')
const initials = (n) => String(n || '?').split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase()
const VIEWS = [['all', 'All'], ['active', 'Active'], ['ready', 'Ready to pay'], ['invited', 'Invited'], ['suspended', 'Suspended']]
const SORTS = [['newest', 'Newest first'], ['referrals', 'Most customers'], ['revenue', 'Most revenue'], ['ready', 'Most to pay']]
const inView = (p, v) => v === 'all' ? true : v === 'ready' ? p.balances.available_paise > 0 : p.status === v

async function copy(text, what) { try { await navigator.clipboard.writeText(text); toast.success(`${what} copied`) } catch { toast.error('Could not copy', 'Select it and copy by hand.') } }

export default function AdminPartnersPage() {
  const nav = useNavigate()
  const [rows, setRows] = useState(null)
  const [form, setForm] = useState(false)
  const [invite, setInvite] = useState(null)   // { url, emailed, emailTo, emailError }
  const [view, setView] = useState('all')
  const [q, setQ] = useState('')
  const [sort, setSort] = useState('newest')
  const load = useCallback(() => admin.partners().then(setRows).catch(e => toast.error('Could not load', e.message)), [])
  useEffect(() => { load() }, [load])

  const tot = useMemo(() => (rows || []).reduce((a, p) => ({ ref: a.ref + p.referrals, paying: a.paying + p.paying, recent: a.recent + p.referrals_30d, rev: a.rev + p.revenue_paise, hold: a.hold + p.balances.pending_paise, avail: a.avail + p.balances.available_paise, paid: a.paid + p.balances.paid_paise, active: a.active + (p.status === 'active' ? 1 : 0), invited: a.invited + (p.status === 'invited' ? 1 : 0), toPay: a.toPay + (p.balances.available_paise > 0 ? 1 : 0) }),
    { ref: 0, paying: 0, recent: 0, rev: 0, hold: 0, avail: 0, paid: 0, active: 0, invited: 0, toPay: 0 }), [rows])
  const counts = useMemo(() => Object.fromEntries(VIEWS.map(([k]) => [k, (rows || []).filter(p => inView(p, k)).length])), [rows])
  const shown = useMemo(() => {
    const needle = q.trim().toLowerCase()
    const list = (rows || []).filter(p => inView(p, view) && (!needle || `${p.name} ${p.company || ''} ${p.email} ${p.code}`.toLowerCase().includes(needle)))
    const by = { referrals: (a, b) => b.referrals - a.referrals, revenue: (a, b) => b.revenue_paise - a.revenue_paise, ready: (a, b) => b.balances.available_paise - a.balances.available_paise, newest: (a, b) => b.id - a.id }[sort]
    return [...list].sort(by)
  }, [rows, view, q, sort])

  return (
    <div className="page">
      <div className="page-header">
        <div><h1 className="page-title">Partners</h1><div style={{ color: 'var(--text-3)', fontSize: 13.5 }}>Referral partners: who brings customers, what it earns them, and what you owe.</div></div>
        {!form && <button className="btn btn-primary" onClick={() => { setForm(true); setInvite(null) }}>+ New partner</button>}
      </div>

      <div className="cu-kpis">
        <Kpi icon={Handshake} tone="#0a6cc4" label="Partners" value={rows?.length ?? '…'} sub={rows ? `${tot.active} active${tot.invited ? ` · ${tot.invited} not joined yet` : ''}` : ''} />
        <Kpi icon={Users} tone="#1a9d57" label="Customers referred" value={tot.ref} sub={`${tot.paying} paying now · ${tot.recent} in the last 30 days`} />
        <Kpi icon={Wallet} tone="#12a89e" label="Revenue brought in" value={rs(tot.rev)} sub="price before GST, from referred customers" />
        <Kpi icon={Hourglass} tone="#f59e0b" label="Commission on hold" value={rs(tot.hold)} sub="not yet payable" />
        <Kpi icon={Landmark} tone={tot.avail > 0 ? '#d93025' : '#8a99b3'} label="Ready to pay" value={rs(tot.avail)} accent={tot.avail > 0 ? '#d93025' : undefined} sub={tot.avail > 0 ? `owed to ${tot.toPay} partner${tot.toPay === 1 ? '' : 's'}` : 'nothing owed'} />
        <Kpi icon={CheckCircle2} tone="#1a9d57" label="Paid out" value={rs(tot.paid)} sub="all time" />
      </div>

      {invite && <InviteBox url={invite.url} emailed={invite.emailed} emailTo={invite.emailTo} emailError={invite.emailError} onClose={() => setInvite(null)} />}
      {form && <NewPartnerForm onCancel={() => setForm(false)} onCreated={r => { setForm(false); setInvite({ url: r.invite_url, emailed: r.emailed, emailTo: r.email_to, emailError: r.email_error }); load() }} />}

      {rows?.length > 0 && <>
        <div className="cu-chips">{VIEWS.map(([k, l]) => (k === 'all' || counts[k] > 0 || view === k) && <button key={k} type="button" className={'cu-chip' + (view === k ? ' on' : '')} onClick={() => setView(k)}>{l}<b>{counts[k]}</b></button>)}</div>
        <div className="cu-filters">
          <div className="cu-search"><Search size={15} /><input value={q} onChange={e => setQ(e.target.value)} placeholder="Search name, company, e-mail or referral code" /></div>
          <select className="form-select" value={sort} onChange={e => setSort(e.target.value)}>{SORTS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        </div>
      </>}

      <div className="card" style={{ overflowX: 'auto' }}>
        <table className="cu-table" style={{ minWidth: 1000 }}>
          <thead><tr>{['Partner', 'Referral code', 'Terms', 'Customers', 'Revenue', 'Earned', 'Ready to pay', 'Paid', 'Status'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {!rows && <tr><td colSpan={9} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Loading…</td></tr>}
            {rows?.length === 0 && <tr><td colSpan={9} style={{ ...td, textAlign: 'center', color: 'var(--text-3)', padding: 36 }}>No partners yet. Add one to start the referral programme.</td></tr>}
            {rows?.length > 0 && shown.length === 0 && <tr><td colSpan={9} style={{ ...td, textAlign: 'center', color: 'var(--text-3)', padding: 28 }}>No partners match.</td></tr>}
            {shown.map(p => {
              const conv = p.referrals > 0 ? Math.round((p.paying * 100) / p.referrals) : 0
              return (
                <tr key={p.id} onClick={() => nav(`/admin/partners/${p.id}`)}>
                  <td style={td}>
                    <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                      <span className="pt-av">{initials(p.name)}</span>
                      <span><Link to={`/admin/partners/${p.id}`} className="cu-name" style={{ textDecoration: 'none' }} onClick={e => e.stopPropagation()}>{p.name}</Link><div className="cu-sub">{p.company ? `${p.company} · ` : ''}{p.email}</div></span>
                    </div>
                    {(p.balances.available_paise > 0 && !p.has_payout_details) && <div className="cu-flags"><span className="cu-flag" style={{ '--c': 'var(--warning)' }}>No payout details yet</span></div>}
                  </td>
                  <td style={td} onClick={e => e.stopPropagation()}><span className="of-code"><b>{p.code}</b><button type="button" onClick={() => copy(p.code, 'Code')} title="Copy code" aria-label="Copy code"><Copy size={12} /></button></span><div><button type="button" className="iv-link soft cu-sub" onClick={() => copy(p.links.app_link, 'Sign-up link')}>copy sign-up link</button></div></td>
                  <td style={td}>{termsText(p)}</td>
                  <td style={td}><b>{p.referrals}</b> <span className="cu-sub">({p.paying} paying)</span>{p.referrals_30d > 0 && <div className="cu-sub" style={{ color: 'var(--success)', fontWeight: 600 }}>+{p.referrals_30d} in 30 days</div>}{p.referrals > 0 && <div className="sub-bar" style={{ width: 80 }}><i style={{ width: conv + '%', background: 'var(--success)' }} /></div>}</td>
                  <td style={td}>{p.revenue_paise > 0 ? rs(p.revenue_paise) : <span className="cu-sub">—</span>}</td>
                  <td style={td}>{inr(p.balances.earned_paise)}</td>
                  <td style={td}>{p.balances.available_paise > 0 ? <><b style={{ color: 'var(--danger)' }}>{inr(p.balances.available_paise)}</b><div><Link to={`/admin/partners/${p.id}`} className="cu-sub" style={{ color: 'var(--primary)', fontWeight: 700 }} onClick={e => e.stopPropagation()}>Pay now →</Link></div></> : <span className="cu-sub">—</span>}</td>
                  <td style={td}>{inr(p.balances.paid_paise)}</td>
                  <td style={td}><Pill color={PARTNER_STATUS_COLOR[p.status]}>{p.status}</Pill>
                    <div className="cu-sub">{p.status === 'invited' ? (p.invite_pending ? 'invite sent' : <span style={{ color: 'var(--danger)', fontWeight: 600 }}>link expired</span>) : p.last_login_at ? `seen ${ago(p.last_login_at)}` : 'never logged in'}</div></td>
                </tr>)
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}
