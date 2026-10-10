import { useState, useEffect, useCallback } from 'react'
import { Link } from 'react-router-dom'
import { travel, inr, toPaise } from '../api/travel'
import { toast } from '../components/Toast'

const monthLabel = (m) => new Date(m + '-01T00:00').toLocaleDateString('en-IN', { month: 'long', year: 'numeric' })
const monthOptions = () => {
  const out = []; const d = new Date(); d.setDate(1)
  for (let i = -2; i <= 6; i++) { const x = new Date(d.getFullYear(), d.getMonth() + i, 1); out.push(`${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}`) }
  return out
}
const THIS = (() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` })()
const toRows = (members) => members.map(m => ({ ...m, rev: m.revenue ? String(m.revenue / 100) : '', bk: m.bookings ? String(m.bookings) : '', orig: { rev: m.revenue ? String(m.revenue / 100) : '', bk: m.bookings ? String(m.bookings) : '' } }))

export default function SalesTargetsPage({ user }) {
  const manager = !!user && ['owner', 'admin'].includes(user.role)
  const [month, setMonth] = useState(THIS)
  const [rows, setRows] = useState(null)
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState('')

  const load = useCallback(() => {
    if (!manager) return
    travel.targets(month).then(r => { setRows(toRows(r.members)); setErr('') }).catch(e => { setRows([]); setErr(e.message) })
  }, [month, manager])
  useEffect(() => { load() }, [load])

  if (user && !manager) return <div className="page"><h1 className="page-title">Sales targets</h1><p style={{ marginTop: 12 }}>Only owners and admins can set targets. Your own progress is on the <Link to="/dashboard">dashboard</Link>.</p></div>

  const dirty = (r) => r.rev !== r.orig.rev || r.bk !== r.orig.bk
  const changed = (rows || []).filter(dirty)
  const set = (id, k) => (e) => setRows(rs => rs.map(r => r.id === id ? { ...r, [k]: e.target.value } : r))

  async function save() {
    setSaving(true); setErr('')
    try {
      const items = changed.map(r => ({ user_id: r.id, revenue: toPaise(r.rev || 0), bookings: Math.round(Number(r.bk || 0)) }))
      const res = await travel.saveTargets(month, items)
      setRows(toRows(res.members))
      toast.success('Targets saved', monthLabel(month))
    } catch (e) { setErr(e.message) } finally { setSaving(false) }
  }

  const note = (r) => {
    if (dirty(r)) return <span className="hd-tag warn">unsaved</span>
    if (r.source === 'month') return <span className="hd-tag ok">set for {monthLabel(month).split(' ')[0]}</span>
    if (r.source === 'carried') return <span className="hd-tag info">carried over from {monthLabel(r.from).split(' ')[0]}</span>
    return <span className="hd-muted">no target</span>
  }
  const totalRev = (rows || []).reduce((s, r) => s + toPaise(r.rev || 0), 0)
  const totalBk = (rows || []).reduce((s, r) => s + Math.round(Number(r.bk || 0)), 0)

  return (
    <div className="page" style={{ maxWidth: 900 }}>
      <div className="page-header">
        <div>
          <h1 className="page-title">Sales targets</h1>
          <p style={{ marginTop: 4 }}>A monthly target for each person. Progress bars appear on their dashboard and on yours.</p>
        </div>
        <select className="form-select" style={{ width: 'auto' }} value={month} onChange={e => { setRows(null); setMonth(e.target.value) }} aria-label="Month">
          {monthOptions().map(m => <option key={m} value={m}>{monthLabel(m)}{m === THIS ? ' (this month)' : ''}</option>)}
        </select>
      </div>

      <div className="card">
        <div className="card-body" style={{ paddingBottom: 0 }}>
          <p className="text-muted" style={{ lineHeight: 1.55 }}>
            <b>Revenue</b> is the booking value <b>without GST/TCS</b> for bookings made in the month — the same figure as the Business report.
            <b> Bookings</b> is the number of confirmed bookings. Leave a box empty for no target of that kind.
            A target <b>carries over</b> to later months until you change it, so you only set it once. Enter 0 to stop a person's target from this month on.
          </p>
        </div>
        <table className="data-table" style={{ marginTop: '.75rem' }}>
          <thead><tr><th>Person</th><th style={{ width: 190 }}>Revenue target (₹)</th><th style={{ width: 130 }}>Bookings</th><th>Status</th></tr></thead>
          <tbody>
            {rows === null && <tr><td colSpan={4} style={{ padding: 20 }}>Loading…</td></tr>}
            {rows?.map(r => (
              <tr key={r.id} style={{ cursor: 'default' }}>
                <td className="cell-name">{r.name} <span className="hd-muted">{r.role}</span></td>
                <td><input className="form-input" type="number" min="0" step="1000" inputMode="numeric" value={r.rev} onChange={set(r.id, 'rev')} placeholder="e.g. 500000" aria-label={`Revenue target for ${r.name}`} /></td>
                <td><input className="form-input" type="number" min="0" step="1" inputMode="numeric" value={r.bk} onChange={set(r.id, 'bk')} placeholder="e.g. 8" aria-label={`Bookings target for ${r.name}`} /></td>
                <td>{note(r)}</td>
              </tr>
            ))}
            {rows && rows.length > 0 && (
              <tr style={{ cursor: 'default', background: '#f6f7f9' }}>
                <td><b>Team total</b></td><td><b>{inr(totalRev)}</b></td><td><b>{totalBk}</b></td><td />
              </tr>
            )}
          </tbody>
        </table>
        {err && <div className="form-error" style={{ margin: '0 1.25rem 1rem' }}>{err}</div>}
        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, padding: '.9rem 1.25rem' }}>
          <button className="btn btn-ghost" disabled={!changed.length || saving} onClick={() => setRows(rs => rs.map(r => ({ ...r, rev: r.orig.rev, bk: r.orig.bk })))}>Discard changes</button>
          <button className="btn btn-primary" disabled={!changed.length || saving} onClick={save}>{saving ? 'Saving…' : `Save ${changed.length ? `(${changed.length})` : ''}`}</button>
        </div>
      </div>
    </div>
  )
}
