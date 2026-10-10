import { useEffect, useState, useCallback } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { admin } from '../../api/admin'
import { inr } from '../../api/travel'
import { toast } from '../../components/Toast'
import { Kpi, Pill, InviteBox } from './AdminUi'
import { th, td, PARTNER_STATUS_COLOR } from './adminUtil'

const termsText = (p) => `${p.commission_pct}% · ${p.commission_months === 0 ? 'lifetime' : `${p.commission_months} mo`} · ${p.hold_days}d hold`

const BLANK = { name: '', email: '', company: '', phone: '', code: '', commission_pct: 20, commission_months: 12, hold_days: 30, notes: '' }

function NewPartnerForm({ onCreated, onCancel }) {
  const [f, setF] = useState(BLANK)
  const [busy, setBusy] = useState(false)
  const set = (k, v) => setF(x => ({ ...x, [k]: v }))
  async function save(e) {
    e.preventDefault(); setBusy(true)
    try { const r = await admin.createPartner({ ...f, commission_pct: Number(f.commission_pct), commission_months: Number(f.commission_months), hold_days: Number(f.hold_days) }); toast.success('Partner created', r.partner.name); onCreated(r) }
    catch (err) { toast.error('Not saved', err.message) } finally { setBusy(false) }
  }
  const G = ({ label, hint, children }) => <div className="form-group" style={{ margin: 0 }}><label className="form-label">{label}</label>{children}{hint && <div style={{ fontSize: 11.5, color: 'var(--text-3)', marginTop: 3 }}>{hint}</div>}</div>
  return (
    <form onSubmit={save} className="card card-body" style={{ marginBottom: 14, display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit,minmax(210px,1fr))' }}>
      <div style={{ gridColumn: '1/-1', fontWeight: 700 }}>New referral partner</div>
      {G({ label: 'Name', children: <input className="form-input" value={f.name} onChange={e => set('name', e.target.value)} required /> })}
      {G({ label: 'Email (their login)', children: <input className="form-input" type="email" value={f.email} onChange={e => set('email', e.target.value)} required /> })}
      {G({ label: 'Company (optional)', children: <input className="form-input" value={f.company} onChange={e => set('company', e.target.value)} /> })}
      {G({ label: 'Phone (optional)', children: <input className="form-input" value={f.phone} onChange={e => set('phone', e.target.value)} /> })}
      {G({ label: 'Referral code (optional)', hint: 'Leave empty to generate one. 3-20 letters/numbers.', children: <input className="form-input" value={f.code} onChange={e => set('code', e.target.value.toUpperCase())} maxLength={20} /> })}
      {G({ label: 'Commission %', hint: 'Of what each referred customer actually pays.', children: <input className="form-input" type="number" min="1" max="60" step="0.5" value={f.commission_pct} onChange={e => set('commission_pct', e.target.value)} required /> })}
      {G({ label: 'For how many months', hint: '0 = lifetime. Counted from the first commission.', children: <input className="form-input" type="number" min="0" max="120" value={f.commission_months} onChange={e => set('commission_months', e.target.value)} required /> })}
      {G({ label: 'Hold (days)', hint: 'A commission becomes payable this many days after the payment (the refund window).', children: <input className="form-input" type="number" min="0" max="180" value={f.hold_days} onChange={e => set('hold_days', e.target.value)} required /> })}
      <div style={{ gridColumn: '1/-1' }}>{G({ label: 'Internal notes', children: <input className="form-input" value={f.notes} onChange={e => set('notes', e.target.value)} /> })}</div>
      <div style={{ gridColumn: '1/-1', display: 'flex', gap: 8 }}><button className="btn btn-primary" disabled={busy}>{busy ? 'Creating…' : 'Create partner'}</button><button type="button" className="btn btn-ghost" onClick={onCancel}>Cancel</button></div>
    </form>
  )
}

export default function AdminPartnersPage() {
  const nav = useNavigate()
  const [rows, setRows] = useState(null)
  const [form, setForm] = useState(false)
  const [invite, setInvite] = useState(null)
  const load = useCallback(() => admin.partners().then(setRows).catch(e => toast.error('Could not load', e.message)), [])
  useEffect(() => { load() }, [load])
  const tot = (rows || []).reduce((a, p) => ({ ref: a.ref + p.referrals, paying: a.paying + p.paying, avail: a.avail + p.balances.available_paise, paid: a.paid + p.balances.paid_paise }), { ref: 0, paying: 0, avail: 0, paid: 0 })
  return (
    <div className="page">
      <div className="page-header"><h1 className="page-title">Partners</h1>{!form && <button className="btn btn-primary" onClick={() => { setForm(true); setInvite(null) }}>+ New partner</button>}</div>
      <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 14 }}>
        <Kpi label="Partners" value={rows?.length ?? '…'} sub={`${(rows || []).filter(p => p.status === 'active').length} active`} />
        <Kpi label="Referred customers" value={tot.ref} sub={`${tot.paying} paying now`} accent="var(--success)" />
        <Kpi label="Commission to pay" value={inr(tot.avail)} sub="available today" accent={tot.avail > 0 ? 'var(--warning)' : undefined} />
        <Kpi label="Paid out" value={inr(tot.paid)} sub="all time" />
      </div>
      {invite && <InviteBox url={invite} onClose={() => setInvite(null)} />}
      {form && <NewPartnerForm onCancel={() => setForm(false)} onCreated={r => { setForm(false); setInvite(r.invite_url); load() }} />}
      <div className="card" style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }}>
          <thead><tr>{['Partner', 'Code', 'Terms', 'Customers', 'Earned', 'To pay', 'Paid', 'Status'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
          <tbody>
            {!rows && <tr><td colSpan={8} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>Loading…</td></tr>}
            {rows?.length === 0 && <tr><td colSpan={8} style={{ ...td, textAlign: 'center', color: 'var(--text-3)' }}>No partners yet. Add one to start the referral program.</td></tr>}
            {rows?.map(p => (
              <tr key={p.id} style={{ cursor: 'pointer' }} onClick={() => nav(`/admin/partners/${p.id}`)}>
                <td style={td}><Link to={`/admin/partners/${p.id}`} onClick={e => e.stopPropagation()}><b>{p.name}</b></Link><div style={{ fontSize: 12, color: 'var(--text-3)' }}>{p.company ? `${p.company} · ` : ''}{p.email}</div></td>
                <td style={td}><b style={{ fontFamily: 'ui-monospace,monospace' }}>{p.code}</b></td>
                <td style={td}>{termsText(p)}</td>
                <td style={td}>{p.referrals} <span style={{ color: 'var(--text-3)' }}>({p.paying} paying)</span></td>
                <td style={td}>{inr(p.balances.earned_paise)}</td>
                <td style={td}>{p.balances.available_paise > 0 ? <b style={{ color: 'var(--warning)' }}>{inr(p.balances.available_paise)}</b> : <span style={{ color: 'var(--text-3)' }}>—</span>}</td>
                <td style={td}>{inr(p.balances.paid_paise)}</td>
                <td style={td}><Pill color={PARTNER_STATUS_COLOR[p.status]}>{p.status}</Pill>{p.status === 'invited' && !p.invite_pending && <div style={{ fontSize: 11, color: 'var(--danger)' }}>link expired</div>}</td>
              </tr>))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
