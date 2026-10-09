import { useState, useEffect, useCallback } from 'react'
import { crm } from '../api/crm'
import { api } from '../api/client'
import { settings } from '../api/settings'
import { toast } from '../components/Toast'

const VIS_MODES = [
  ['open', 'Open', 'Everyone sees all records (default).'],
  ['owner', 'Owner only', 'Reps see only records they own or that are shared with them.'],
  ['team', 'Team', 'Reps see records owned by anyone on a team they belong to, plus shares.'],
]

// ── Teams (Phase K3) ─────────────────────────────────────────────────────────
// Group members into teams; the CRM lists offer a "My team" visibility scope.

export default function TeamsPage() {
  const [teams, setTeams] = useState([])
  const [members, setMembers] = useState([])
  const [loading, setLoading] = useState(true)
  const [visMode, setVisMode] = useState('open')

  useEffect(() => {
    settings.getRecordVisibility()
      .then(r => setVisMode(r.data?.record_visibility ?? 'open'))
      .catch(() => {})
  }, [])

  async function saveVisMode(mode) {
    const prev = visMode
    setVisMode(mode)
    try { await settings.updateRecordVisibility(mode); toast.success('Record visibility updated') }
    catch (e) { setVisMode(prev); toast.error('Could not update visibility', e?.message) }
  }

  const load = useCallback(async () => {
    setLoading(true)
    try { const r = await crm.teams(); setTeams(r.data ?? []) }
    catch (e) { toast.error('Failed to load teams', e?.message) }
    setLoading(false)
  }, [])
  useEffect(() => { load(); api.get('/team').then(r => setMembers(r.data ?? [])).catch(() => {}) }, [load])

  const nameOf = (uid) => members.find(m => m.id === uid)?.name ?? `User #${uid}`

  async function addTeam() {
    const name = window.prompt('Team name?')
    if (!name?.trim()) return
    try { await crm.createTeam(name.trim()); load() } catch (e) { toast.error('Could not create', e?.message) }
  }
  async function removeTeam(t) {
    if (!confirm(`Delete team "${t.name}"?`)) return
    try { await crm.deleteTeam(t.id); load() } catch (e) { toast.error('Delete failed', e?.message) }
  }
  async function addMember(teamId, userId) {
    if (!userId) return
    try { await crm.addTeamMember(teamId, Number(userId)); load() } catch (e) { toast.error('Could not add', e?.message) }
  }
  async function removeMember(teamId, userId) {
    try { await crm.removeTeamMember(teamId, userId); load() } catch (e) { toast.error('Could not remove', e?.message) }
  }

  return (
    <div className="page" style={{ maxWidth: 720 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '1.25rem', gap: 12, flexWrap: 'wrap' }}>
        <div>
          <h1 style={{ margin: 0, fontSize: '1.5rem', fontWeight: 800, color: '#111827', letterSpacing: '-.02em' }}>👥 Teams</h1>
          <p style={{ margin: '4px 0 0', fontSize: 13.5, color: '#6b7280' }}>Group reps into teams; lists offer a “My team” scope.</p>
        </div>
        <button onClick={addTeam} style={{ background: 'var(--primary,#0a6cc4)', color: '#fff', border: 'none', borderRadius: 10, padding: '.55rem 1rem', fontWeight: 700, cursor: 'pointer' }}>+ New team</button>
      </div>

      <div style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, padding: '1rem 1.1rem', marginBottom: '1.25rem' }}>
        <div style={{ fontWeight: 800, fontSize: 14, marginBottom: 4 }}>🔒 Record visibility</div>
        <p style={{ margin: '0 0 10px', fontSize: 13, color: '#6b7280' }}>Controls which records reps (non owner/admin) can see and edit. Owners and admins always see everything.</p>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {VIS_MODES.map(([val, label, desc]) => (
            <button key={val} onClick={() => saveVisMode(val)} title={desc}
              style={{ textAlign: 'left', flex: '1 1 200px', cursor: 'pointer', borderRadius: 10, padding: '.6rem .75rem',
                border: visMode === val ? '2px solid var(--primary,#0a6cc4)' : '1px solid #e5e7eb',
                background: visMode === val ? '#e8f3fc' : '#fff' }}>
              <div style={{ fontWeight: 700, fontSize: 13, color: '#111827' }}>{label}</div>
              <div style={{ fontSize: 12, color: '#6b7280', marginTop: 2 }}>{desc}</div>
            </button>
          ))}
        </div>
      </div>

      {loading ? <div style={{ padding: '2rem', color: '#9ca3af' }}>Loading…</div> : teams.length === 0 ? (
        <div style={{ textAlign: 'center', padding: '3rem 2rem', background: '#fff', borderRadius: 14, border: '1px solid #e5e7eb', color: '#6b7280' }}>No teams yet.</div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          {teams.map(t => {
            const inTeam = new Set(t.member_ids ?? [])
            const available = members.filter(m => !inTeam.has(m.id))
            return (
              <div key={t.id} style={{ background: '#fff', border: '1px solid #e5e7eb', borderRadius: 14, padding: '1rem 1.1rem' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 }}>
                  <span style={{ fontWeight: 800, fontSize: 14 }}>{t.name}</span>
                  <button onClick={() => removeTeam(t)} style={{ background: '#fff', border: '1px solid #fecaca', color: '#b91c1c', borderRadius: 8, padding: '.3rem .7rem', fontSize: 12.5, fontWeight: 600, cursor: 'pointer' }}>Delete</button>
                </div>
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 10 }}>
                  {(t.member_ids ?? []).length === 0 ? <span style={{ color: '#9ca3af', fontSize: 13 }}>No members yet.</span>
                    : (t.member_ids ?? []).map(uid => (
                      <span key={uid} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, background: '#e8f3fc', color: '#074a8c', borderRadius: 999, padding: '.2rem .6rem', fontSize: 12.5, fontWeight: 600 }}>
                        {nameOf(uid)}
                        <button onClick={() => removeMember(t.id, uid)} title="Remove" style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'inherit', fontSize: 13, lineHeight: 1, padding: 0 }}>×</button>
                      </span>
                    ))}
                </div>
                {available.length > 0 && (
                  <select defaultValue="" onChange={e => { addMember(t.id, e.target.value); e.target.value = '' }} style={{ border: '1px solid #e5e7eb', borderRadius: 8, padding: '.4rem .5rem', fontSize: 13 }}>
                    <option value="">+ Add member…</option>
                    {available.map(m => <option key={m.id} value={m.id}>{m.name} ({m.role})</option>)}
                  </select>
                )}
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}
