import { useState, useEffect } from 'react'
import { Link } from 'react-router-dom'
import { crm } from '../api/crm'
import { toast } from '../components/Toast'
import CrmListView from '../components/CrmListView'

const TYPE = {
  prospect: { label: 'Prospect', bg: '#e8f3fc', color: '#08569f' },
  customer: { label: 'Customer', bg: '#dcfce7', color: '#15803d' },
  partner:  { label: 'Partner',  bg: '#fef9c3', color: '#a16207' },
  other:    { label: 'Other',    bg: '#f1f5f9', color: '#475569' },
}
const initials = (n) => (n || '#').trim().split(/\s+/).map(w => w[0]).join('').slice(0, 2).toUpperCase()
const blank = () => ({ name: '', industry: '', type: 'prospect', website: '', phone: '' })

function CreateModal({ onClose, onCreated }) {
  const [form, setForm] = useState(blank())
  const [saving, setSaving] = useState(false)
  async function save(e) {
    e.preventDefault()
    if (!form.name.trim()) { toast.error('Company name is required'); return }
    setSaving(true)
    try { const r = await crm.createAccount(form); toast.success('Account created', form.name); onCreated(r.data) }
    catch (e) { toast.error('Could not create account', e.message) }
    finally { setSaving(false) }
  }
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', zIndex: 10000, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, backdropFilter: 'blur(3px)' }}>
      <form onClick={e => e.stopPropagation()} onSubmit={save} style={{ background: '#fff', borderRadius: 16, width: '100%', maxWidth: 460, padding: '1.5rem', boxShadow: '0 24px 64px rgba(0,0,0,.22)' }}>
        <h3 style={{ margin: '0 0 1rem', fontWeight: 800, fontSize: '1.05rem' }}>🏢 New Account</h3>
        {[['name', 'Company name *', 'Acme Corp'], ['industry', 'Industry', 'Software'], ['website', 'Website', 'acme.com'], ['phone', 'Phone', '+91…']].map(([k, label, ph]) => (
          <div key={k} style={{ marginBottom: '.85rem' }}>
            <label style={{ fontSize: '.8rem', fontWeight: 600, color: '#374151', display: 'block', marginBottom: 4 }}>{label}</label>
            <input value={form[k]} onChange={e => setForm(f => ({ ...f, [k]: e.target.value }))} placeholder={ph} autoFocus={k === 'name'}
              style={{ width: '100%', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem .7rem', fontSize: '.9rem' }} />
          </div>
        ))}
        <div style={{ marginBottom: '1.25rem' }}>
          <label style={{ fontSize: '.8rem', fontWeight: 600, color: '#374151', display: 'block', marginBottom: 4 }}>Type</label>
          <select value={form.type} onChange={e => setForm(f => ({ ...f, type: e.target.value }))} style={{ width: '100%', border: '1px solid #e5e7eb', borderRadius: 8, padding: '.5rem', fontSize: '.9rem' }}>
            {Object.keys(TYPE).map(t => <option key={t} value={t}>{TYPE[t].label}</option>)}
          </select>
        </div>
        <div style={{ display: 'flex', gap: 10 }}>
          <button type="button" onClick={onClose} style={{ flex: 1, background: '#fff', border: '1.5px solid #e5e7eb', borderRadius: 10, padding: '.6rem', fontWeight: 600, cursor: 'pointer' }}>Cancel</button>
          <button type="submit" disabled={saving} style={{ flex: 2, background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 10, padding: '.6rem', fontWeight: 700, cursor: 'pointer' }}>{saving ? 'Creating…' : 'Create account'}</button>
        </div>
      </form>
    </div>
  )
}

function DetailDrawer({ id, onClose }) {
  const [acc, setAcc] = useState(null)
  useEffect(() => { crm.getAccount(id).then(r => setAcc(r.data)).catch(() => {}) }, [id])
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.4)', zIndex: 10000, display: 'flex', justifyContent: 'flex-end' }}>
      <div onClick={e => e.stopPropagation()} style={{ width: '100%', maxWidth: 440, height: '100%', background: '#fff', boxShadow: '-12px 0 40px rgba(0,0,0,.18)', overflow: 'auto', padding: '1.5rem' }}>
        {!acc ? <div style={{ color: '#94a3b8' }}>Loading…</div> : (
          <>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <div style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
                <div style={{ width: 46, height: 46, borderRadius: 12, background: '#e8f3fc', color: '#08569f', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800 }}>{initials(acc.name)}</div>
                <div>
                  <div style={{ fontWeight: 800, fontSize: '1.05rem' }}>{acc.name}</div>
                  <div style={{ fontSize: '.78rem', color: '#6b7280' }}>{acc.industry || '—'}</div>
                </div>
              </div>
              <button onClick={onClose} style={{ background: 'none', border: 'none', fontSize: '1.2rem', cursor: 'pointer', color: '#94a3b8' }}>✕</button>
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 10, margin: '1.25rem 0' }}>
              {[['Type', TYPE[acc.type]?.label], ['Website', acc.website], ['Phone', acc.phone], ['Employees', acc.employee_count]].map(([k, v]) => (
                <div key={k}><div style={{ fontSize: '.7rem', color: '#94a3b8', textTransform: 'uppercase', fontWeight: 700 }}>{k}</div><div style={{ fontSize: '.85rem' }}>{v || '—'}</div></div>
              ))}
            </div>
            <div style={{ fontSize: '.72rem', fontWeight: 700, color: '#6b7280', textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: '.5rem' }}>Contacts ({acc.contact_count ?? 0})</div>
            {(acc.contacts ?? []).length === 0 ? (
              <div style={{ color: '#94a3b8', fontSize: '.85rem' }}>No contacts linked yet.</div>
            ) : acc.contacts.map(c => (
              <Link key={c.id} to={`/contacts/${c.id}`} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '.5rem', borderRadius: 8, textDecoration: 'none', color: 'inherit' }}>
                <div style={{ width: 30, height: 30, borderRadius: '50%', background: '#f1f5f9', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: '.7rem', fontWeight: 700 }}>{initials(c.name)}</div>
                <div><div style={{ fontSize: '.85rem', fontWeight: 600 }}>{c.name || c.wa_number}</div><div style={{ fontSize: '.72rem', color: '#94a3b8' }}>{c.job_title || c.lifecycle_stage}</div></div>
              </Link>
            ))}
          </>
        )}
      </div>
    </div>
  )
}

export default function AccountsPage() {
  const [showCreate, setShowCreate] = useState(false)
  const [detailId, setDetailId] = useState(null)
  const [reloadKey, setReloadKey] = useState(0)   // bump to remount the list after a create

  return (
    <div className="page" style={{ maxWidth: 1000 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '1.25rem', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>🏢 Accounts</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Companies and organizations your contacts belong to.</p>
        </div>
        <button onClick={() => setShowCreate(true)} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 10, padding: '.55rem 1rem', fontWeight: 700, cursor: 'pointer' }}>+ New Account</button>
      </div>

      <CrmListView key={reloadKey} entity="account" onRowClick={a => setDetailId(a.id)} />

      {showCreate && <CreateModal onClose={() => setShowCreate(false)} onCreated={() => { setShowCreate(false); setReloadKey(k => k + 1) }} />}
      {detailId && <DetailDrawer id={detailId} onClose={() => setDetailId(null)} />}
    </div>
  )
}
