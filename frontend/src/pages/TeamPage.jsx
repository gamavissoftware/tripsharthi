import { useState, useEffect, useCallback } from 'react'
import { api } from '../api/client'
import { toast } from '../components/Toast'
import { Crown, Shield, Headphones, Eye, EyeOff, Dices, Send, AlertTriangle, Users } from 'lucide-react'

// ── Inject styles once ──────────────────────────────────────────────────────
if (typeof document !== 'undefined' && !document.getElementById('lp-team-css')) {
  const el = document.createElement('style')
  el.id = 'lp-team-css'
  el.textContent = `
    @keyframes lp-team-in   { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
    @keyframes lp-team-spin { to { transform:rotate(360deg); } }
    @keyframes lp-team-modal{ from { opacity:0; transform:scale(.96); } to { opacity:1; transform:scale(1); } }
    .lp-team-card { background:#fff; border:1px solid #e5e7eb; border-radius:18px; animation: lp-team-in .25s ease both; }
    .lp-team-label { font-size:12.5px; font-weight:700; color:#374151; margin-bottom:.35rem; display:block; }
    .lp-team-input {
      width:100%; padding:.6rem .8rem; border-radius:10px; border:1.5px solid #e5e7eb; background:#f9fafb;
      font-size:14px; color:#111827; outline:none; transition:border-color .15s, background .15s; box-sizing:border-box;
    }
    .lp-team-input:focus { border-color:var(--primary,#0a6cc4); background:#fff; }
    .lp-team-input.invalid { border-color:#fca5a5; background:#fef2f2; }
    .lp-team-err { font-size:12px; color:#dc2626; margin-top:.3rem; }
    .lp-team-row { transition:background .12s; }
    .lp-team-row:hover { background:#fafbff; }
  `
  document.head.appendChild(el)
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

const ROLE_PILL = {
  owner: { label: 'Owner', icon: <Crown size={12} strokeWidth={2} />, bg: 'linear-gradient(135deg,#ede9fe,#ddd6fe)', color: '#6d28d9' },
  admin: { label: 'Admin', icon: <Shield size={12} strokeWidth={2} />, bg: 'linear-gradient(135deg,#dbeafe,#bfdbfe)', color: '#1d4ed8' },
  agent: { label: 'Agent', icon: <Headphones size={12} strokeWidth={2} />, bg: '#f3f4f6', color: '#4b5563' },
}
const AVATAR_TINTS = [
  ['#ede9fe', '#6d28d9'], ['#dbeafe', '#1d4ed8'], ['#dcfce7', '#15803d'],
  ['#fee2e2', '#b91c1c'], ['#fef3c7', '#b45309'], ['#cffafe', '#0e7490'],
]

const initials = (name) => (name || '?').trim().split(/\s+/).map(w => w[0]).join('').slice(0, 2).toUpperCase()
const tintFor = (id) => AVATAR_TINTS[Number(id) % AVATAR_TINTS.length]
const formatDate = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const genPassword = () => {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%'
  return Array.from({ length: 12 }, () => chars[Math.floor(Math.random() * chars.length)]).join('')
}

function Spinner({ light = true }) {
  return <span style={{ width: 14, height: 14, border: `2px solid ${light ? 'rgba(255,255,255,.4)' : 'rgba(0,0,0,.15)'}`, borderTopColor: light ? '#fff' : '#0a6cc4', borderRadius: '50%', animation: 'lp-team-spin .7s linear infinite', display: 'inline-block' }} />
}

// ── Invite panel ────────────────────────────────────────────────────────────
function InvitePanel({ onClose, onInvited }) {
  const [form, setForm] = useState({ name: '', email: '', role: 'agent', password: '' })
  const [errors, setErrors] = useState({})
  const [showPw, setShowPw] = useState(false)
  const [saving, setSaving] = useState(false)

  const set = (k, v) => { setForm(f => ({ ...f, [k]: v })); setErrors(e => ({ ...e, [k]: null })) }

  async function submit(e) {
    e.preventDefault()
    const errs = {}
    if (!form.name.trim()) errs.name = 'Full name is required.'
    if (!form.email.trim()) errs.email = 'Email is required.'
    else if (!EMAIL_RE.test(form.email)) errs.email = 'Enter a valid email.'
    if (!form.password) errs.password = 'Password is required.'
    else if (form.password.length < 8) errs.password = 'At least 8 characters.'
    setErrors(errs)
    if (Object.keys(errs).length) return

    setSaving(true)
    try {
      await api.post('/team', { name: form.name.trim(), email: form.email.trim(), role: form.role, password: form.password })
      toast.success('Invitation sent', `${form.name.trim()} can now sign in with the temporary password.`)
      onInvited()
      onClose()
    } catch (err) {
      if (err.isPlanLimit) toast.error('Seat limit reached', err.message)
      else if (err.errors && typeof err.errors === 'object') setErrors(err.errors)
      else toast.error('Could not invite', err.message ?? 'Please try again.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <form onSubmit={submit} className="lp-team-card" style={{ padding: '1.4rem 1.5rem', marginBottom: '1.25rem' }}>
      <div style={{ fontSize: 15, fontWeight: 800, color: '#111827', marginBottom: '1.1rem' }}>Invite a teammate</div>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(190px,1fr))', gap: '1rem' }}>
        <div>
          <label className="lp-team-label">Full name</label>
          <input className={`lp-team-input ${errors.name ? 'invalid' : ''}`} value={form.name} onChange={e => set('name', e.target.value)} placeholder="Jane Doe" autoComplete="off" />
          {errors.name && <div className="lp-team-err">{errors.name}</div>}
        </div>
        <div>
          <label className="lp-team-label">Email</label>
          <input className={`lp-team-input ${errors.email ? 'invalid' : ''}`} type="email" value={form.email} onChange={e => set('email', e.target.value)} placeholder="jane@company.com" autoComplete="off" />
          {errors.email && <div className="lp-team-err">{errors.email}</div>}
        </div>
        <div>
          <label className="lp-team-label">Role</label>
          <select className="lp-team-input" value={form.role} onChange={e => set('role', e.target.value)} style={{ cursor: 'pointer' }}>
            <option value="agent">Agent — inbox & messaging</option>
            <option value="admin">Admin — full access</option>
          </select>
        </div>
        <div>
          <label className="lp-team-label">Temporary password</label>
          <div style={{ position: 'relative' }}>
            <input className={`lp-team-input ${errors.password ? 'invalid' : ''}`} type={showPw ? 'text' : 'password'} value={form.password} onChange={e => set('password', e.target.value)} placeholder="Min 8 characters" autoComplete="new-password" style={{ paddingRight: 64 }} />
            <button type="button" onClick={() => setShowPw(s => !s)} tabIndex={-1} title={showPw ? 'Hide' : 'Show'} style={{ position: 'absolute', right: 34, top: '50%', transform: 'translateY(-50%)', background: 'none', border: 'none', cursor: 'pointer', fontSize: 14, opacity: .55, display: 'inline-flex', alignItems: 'center' }}>{showPw ? <EyeOff size={15} strokeWidth={2} /> : <Eye size={15} strokeWidth={2} />}</button>
            <button type="button" onClick={() => { set('password', genPassword()); setShowPw(true) }} tabIndex={-1} title="Generate password" style={{ position: 'absolute', right: 8, top: '50%', transform: 'translateY(-50%)', background: 'none', border: 'none', cursor: 'pointer', fontSize: 14, opacity: .6, display: 'inline-flex', alignItems: 'center' }}><Dices size={15} strokeWidth={2} /></button>
          </div>
          {errors.password && <div className="lp-team-err">{errors.password}</div>}
        </div>
      </div>
      <div style={{ display: 'flex', gap: '.7rem', marginTop: '1.25rem' }}>
        <button type="submit" disabled={saving} style={{ padding: '.6rem 1.3rem', borderRadius: 10, border: 'none', background: saving ? '#c7cdd6' : 'var(--primary,#0a6cc4)', color: '#fff', fontWeight: 700, fontSize: 14, cursor: saving ? 'not-allowed' : 'pointer', display: 'inline-flex', alignItems: 'center', gap: '.45rem' }}>
          {saving ? <><Spinner /> Sending…</> : <><Send size={14} strokeWidth={2} /> Send invite</>}
        </button>
        <button type="button" onClick={onClose} disabled={saving} style={{ padding: '.6rem 1.1rem', borderRadius: 10, border: '1.5px solid #e5e7eb', background: '#fff', color: '#374151', fontWeight: 600, fontSize: 14, cursor: 'pointer' }}>Cancel</button>
      </div>
    </form>
  )
}

// ── Remove modal ────────────────────────────────────────────────────────────
function RemoveModal({ member, onConfirm, onClose, removing }) {
  return (
    <div onClick={e => { if (e.target === e.currentTarget && !removing) onClose() }}
      style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.45)', backdropFilter: 'blur(4px)', display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000, padding: '1rem' }}>
      <div style={{ background: '#fff', borderRadius: 18, width: 420, maxWidth: '94vw', overflow: 'hidden', boxShadow: '0 25px 60px rgba(0,0,0,.2)', animation: 'lp-team-modal .18s ease' }}>
        <div style={{ background: 'linear-gradient(135deg,#fef2f2,#fff1f2)', padding: '1.3rem 1.5rem', borderBottom: '1px solid #fecaca' }}>
          <div><AlertTriangle size={26} strokeWidth={1.8} color="#dc2626" /></div>
          <div style={{ fontSize: 17, fontWeight: 800, color: '#991b1b', marginTop: 4 }}>Remove team member</div>
        </div>
        <div style={{ padding: '1.3rem 1.5rem' }}>
          <p style={{ fontSize: 14, color: '#374151', lineHeight: 1.6, margin: '0 0 1.3rem' }}>
            Remove <strong>{member.name}</strong> ({member.email}) from the workspace? They'll lose access immediately. This can't be undone.
          </p>
          <div style={{ display: 'flex', gap: '.7rem' }}>
            <button onClick={onConfirm} disabled={removing} style={{ flex: 1, padding: '.62rem 1rem', borderRadius: 10, border: 'none', background: '#dc2626', color: '#fff', fontWeight: 700, fontSize: 14, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: '.4rem' }}>
              {removing ? <><Spinner /> Removing…</> : 'Remove member'}
            </button>
            <button onClick={onClose} disabled={removing} style={{ flex: 1, padding: '.62rem 1rem', borderRadius: 10, border: '1.5px solid #e5e7eb', background: '#fff', color: '#374151', fontWeight: 600, fontSize: 14, cursor: 'pointer' }}>Keep</button>
          </div>
        </div>
      </div>
    </div>
  )
}

// ── Page ────────────────────────────────────────────────────────────────────
export default function TeamPage() {
  const [members, setMembers] = useState([])
  const [meId, setMeId] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [showInvite, setShowInvite] = useState(false)
  const [confirmRemove, setConfirmRemove] = useState(null)
  const [removing, setRemoving] = useState(false)
  const [roleChanging, setRoleChanging] = useState({})

  const load = useCallback(async () => {
    setLoading(true); setError('')
    try {
      const [membersRes, meRes] = await Promise.all([api.get('/team'), api.get('/auth/me')])
      setMembers(membersRes.data ?? [])
      setMeId(meRes.user?.id)
    } catch (err) {
      setError(err.message || 'Failed to load team.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  async function changeRole(member, newRole) {
    const prev = member.role
    setRoleChanging(r => ({ ...r, [member.id]: true }))
    setMembers(ms => ms.map(m => m.id === member.id ? { ...m, role: newRole } : m))
    try {
      await api.patch(`/team/${member.id}/role`, { role: newRole })
      toast.success('Role updated', `${member.name} is now ${newRole === 'admin' ? 'an Admin' : 'an Agent'}.`)
    } catch (err) {
      setMembers(ms => ms.map(m => m.id === member.id ? { ...m, role: prev } : m))   // revert
      toast.error('Could not change role', err.message ?? 'Please try again.')
    } finally {
      setRoleChanging(r => ({ ...r, [member.id]: false }))
    }
  }

  async function confirmRemoveMember() {
    if (!confirmRemove) return
    setRemoving(true)
    try {
      await api.delete(`/team/${confirmRemove.id}`)
      setMembers(ms => ms.filter(m => m.id !== confirmRemove.id))
      toast.success('Member removed', `${confirmRemove.name} no longer has access.`)
      setConfirmRemove(null)
    } catch (err) {
      toast.error('Could not remove', err.message ?? 'Please try again.')
    } finally {
      setRemoving(false)
    }
  }

  const ownerCount = members.filter(m => m.role === 'owner').length
  const adminCount = members.filter(m => m.role === 'admin').length
  const agentCount = members.filter(m => m.role === 'agent').length

  return (
    <div className="page">
      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', flexWrap: 'wrap', gap: '.75rem', marginBottom: '1.5rem' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>Team</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Invite teammates and manage who can access your workspace.</p>
        </div>
        {!showInvite && (
          <button onClick={() => setShowInvite(true)} style={{ padding: '.6rem 1.2rem', borderRadius: 10, border: 'none', background: 'var(--primary,#0a6cc4)', color: '#fff', fontWeight: 700, fontSize: 14, cursor: 'pointer', boxShadow: '0 2px 10px var(--primary-ring,rgba(10,108,196,.35))' }}>+ Invite member</button>
        )}
      </div>

      {/* Stat chips */}
      {!loading && members.length > 0 && (
        <div style={{ display: 'flex', gap: '.6rem', flexWrap: 'wrap', marginBottom: '1.25rem' }}>
          {[['Total', members.length, '#0a6cc4'], ['Owner', ownerCount, '#6d28d9'], ['Admins', adminCount, '#1d4ed8'], ['Agents', agentCount, '#4b5563']].map(([label, n, c]) => (
            <div key={label} style={{ padding: '.45rem .9rem', borderRadius: 12, background: '#fff', border: '1px solid #e5e7eb', display: 'flex', alignItems: 'center', gap: '.5rem' }}>
              <span style={{ fontSize: 18, fontWeight: 800, color: c }}>{n}</span>
              <span style={{ fontSize: 12.5, color: '#6b7280', fontWeight: 600 }}>{label}</span>
            </div>
          ))}
        </div>
      )}

      {error && (
        <div style={{ background: '#fef2f2', border: '1px solid #fca5a5', borderRadius: 10, padding: '.75rem 1rem', marginBottom: '1rem', fontSize: 13.5, color: '#dc2626', display: 'flex', alignItems: 'flex-start', gap: '.5rem' }}><AlertTriangle size={15} strokeWidth={2} style={{ flexShrink: 0, marginTop: 1 }} /> {error}</div>
      )}

      {showInvite && <InvitePanel onClose={() => setShowInvite(false)} onInvited={load} />}

      {/* Members */}
      <div className="lp-team-card" style={{ overflow: 'hidden' }}>
        {loading ? (
          <div style={{ padding: '1rem 1.25rem' }}>
            {[1, 2, 3].map(i => (
              <div key={i} style={{ display: 'flex', alignItems: 'center', gap: '.85rem', padding: '.7rem 0' }}>
                <div style={{ width: 40, height: 40, borderRadius: '50%', background: '#f3f4f6' }} />
                <div style={{ flex: 1 }}>
                  <div style={{ height: 11, width: '30%', background: '#f3f4f6', borderRadius: 5, marginBottom: 6 }} />
                  <div style={{ height: 9, width: '45%', background: '#f3f4f6', borderRadius: 5 }} />
                </div>
              </div>
            ))}
          </div>
        ) : members.length === 0 ? (
          <div style={{ padding: '3rem 1rem', textAlign: 'center' }}>
            <div style={{ marginBottom: 10, color: '#9ca3af' }}><Users size={40} strokeWidth={1.4} /></div>
            <div style={{ fontWeight: 700, color: '#111827', marginBottom: 4 }}>No team members yet</div>
            <div style={{ color: '#6b7280', fontSize: 13.5 }}>Invite your first teammate to get started.</div>
          </div>
        ) : (
          <div style={{ overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 14, minWidth: 540 }}>
            <thead>
              <tr style={{ textAlign: 'left', color: '#6b7280', fontSize: 11, textTransform: 'uppercase', letterSpacing: '.05em', borderBottom: '1px solid #f0f1f3' }}>
                <th style={{ padding: '.75rem 1.25rem', fontWeight: 700 }}>Member</th>
                <th style={{ padding: '.75rem .6rem', fontWeight: 700 }}>Role</th>
                <th style={{ padding: '.75rem .6rem', fontWeight: 700 }}>Joined</th>
                <th style={{ padding: '.75rem 1.25rem', fontWeight: 700, textAlign: 'right' }}>Actions</th>
              </tr>
            </thead>
            <tbody>
              {members.map(member => {
                const isOwner = member.role === 'owner'
                const isSelf = member.id === meId
                const pill = ROLE_PILL[member.role] ?? ROLE_PILL.agent
                const [abg, acol] = tintFor(member.id)
                const busy = roleChanging[member.id]
                return (
                  <tr key={member.id} className="lp-team-row" style={{ borderBottom: '1px solid #f6f7f9' }}>
                    <td style={{ padding: '.8rem 1.25rem' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: '.8rem' }}>
                        <div style={{ width: 40, height: 40, borderRadius: '50%', background: abg, color: acol, fontWeight: 800, fontSize: 14, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>{initials(member.name)}</div>
                        <div style={{ minWidth: 0 }}>
                          <div style={{ fontWeight: 700, color: '#111827' }}>
                            {member.name}{isSelf && <span style={{ marginLeft: 6, fontSize: 11.5, color: '#9ca3af', fontWeight: 600 }}>(You)</span>}
                          </div>
                          <div style={{ fontSize: 12.5, color: '#6b7280', overflow: 'hidden', textOverflow: 'ellipsis' }}>{member.email}</div>
                        </div>
                      </div>
                    </td>
                    <td style={{ padding: '.8rem .6rem' }}>
                      {isOwner || isSelf ? (
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: '.3rem', padding: '.25rem .7rem', borderRadius: 999, fontSize: 12, fontWeight: 700, background: pill.bg, color: pill.color }}>
                          {pill.icon} {pill.label}
                        </span>
                      ) : (
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: '.4rem' }}>
                          <select value={member.role} disabled={busy} onChange={e => changeRole(member, e.target.value)}
                            style={{ fontSize: 13, padding: '.3rem .6rem', borderRadius: 8, border: '1.5px solid #e5e7eb', background: '#fff', color: '#374151', cursor: busy ? 'wait' : 'pointer', fontWeight: 600 }}>
                            <option value="admin">Admin</option>
                            <option value="agent">Agent</option>
                          </select>
                          {busy && <Spinner light={false} />}
                        </span>
                      )}
                    </td>
                    <td style={{ padding: '.8rem .6rem', color: '#6b7280', fontSize: 13, whiteSpace: 'nowrap' }}>{formatDate(member.created_at)}</td>
                    <td style={{ padding: '.8rem 1.25rem', textAlign: 'right' }}>
                      {!isOwner && !isSelf ? (
                        <button onClick={() => setConfirmRemove(member)} style={{ padding: '.35rem .8rem', borderRadius: 8, fontSize: 12.5, fontWeight: 600, border: '1.5px solid #fecaca', background: '#fff', color: '#dc2626', cursor: 'pointer' }}
                          onMouseEnter={e => e.currentTarget.style.background = '#fef2f2'} onMouseLeave={e => e.currentTarget.style.background = '#fff'}>
                          Remove
                        </button>
                      ) : <span style={{ color: '#d1d5db', fontSize: 13 }}>—</span>}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
          </div>
        )}
      </div>

      {confirmRemove && (
        <RemoveModal member={confirmRemove} removing={removing} onConfirm={confirmRemoveMember} onClose={() => setConfirmRemove(null)} />
      )}
    </div>
  )
}
